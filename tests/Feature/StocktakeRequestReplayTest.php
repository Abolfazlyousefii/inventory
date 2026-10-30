<?php

namespace Tests\Feature;

use App\Models\{Category, Product, ProductVariant, StockCountDocument, StockMovement, User, Warehouse, WarehouseStock};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StocktakeRequestReplayTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        // Match the nullable production draft schema; recovered SQLite legacy
        // migrations still declare the original non-null count column.
        \Illuminate\Support\Facades\Schema::table('stock_count_document_items',
            fn (\Illuminate\Database\Schema\Blueprint $table) =>
                $table->integer('actual_quantity')->nullable()->change()
        );
        $user = User::factory()->create(['is_active'=>true, 'can_access_erp'=>true]);
        $user->assignRole(Role::findOrCreate('Owner', 'web'));
        $this->actingAs($user);
        $root = Category::withoutEvents(fn () => Category::create(['name'=>'Bulk '.Str::random(8)]));
        $child = Category::withoutEvents(fn () => Category::create(['name'=>'Child '.Str::random(8), 'parent_id'=>$root->id]));
        $outside = Category::withoutEvents(fn () => Category::create(['name'=>'Outside '.Str::random(8)]));
        $warehouse = Warehouse::where('type', 'central')->first()
            ?? Warehouse::withoutEvents(fn () => Warehouse::create(['name'=>'انبار مرکزی', 'type'=>'central', 'is_active'=>true]));
        $make = function (Category $category) use ($warehouse): array {
            $product = Product::withoutEvents(fn () => Product::create([
                'name'=>'Bulk product', 'category_id'=>$category->id, 'sku'=>Str::random(12),
                'stock'=>8, 'price'=>150000, 'is_sellable'=>false,
            ]));
            $variant = ProductVariant::withoutEvents(fn () => ProductVariant::create([
                'product_id'=>$product->id, 'variant_name'=>'Black', 'variant_code'=>Str::random(12),
                'stock'=>8, 'sell_price'=>150000, 'is_active'=>false, 'sales_enabled'=>false,
            ]));
            WarehouseStock::withoutEvents(fn () => WarehouseStock::create([
                'warehouse_id'=>$warehouse->id, 'product_id'=>$product->id,
                'product_variant_id'=>$variant->id, 'quantity'=>8,
            ]));
            return [$product, $variant];
        };
        return [$root, $make($root), $make($child), $make($outside)];
    }

    private function payload(Category $category, array $pairs): array
    {
        return ['request_id'=>(string) Str::uuid(), 'category_id'=>$category->id, 'document_date'=>'2026-10-30',
            'products'=>array_map(fn ($pair) => ['product_id'=>$pair[0]->id,
                'actual_quantities'=>[$pair[1]->id=>6]], $pairs)];
    }

    private function snapshot(): array
    {
        return collect(['stock_count_documents', 'stock_count_document_items', 'stock_count_document_history',
            'stock_movements', 'warehouse_stocks', 'product_variants', 'products'])
            ->mapWithKeys(fn ($table) => [$table=>\Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    public function test_bulk_save_retry_after_lost_response_replays_original_response_without_duplicate_drafts(): void
    {
        [$root, $first, $second] = $this->fixture();
        $payload = $this->payload($root, [$first, $second]);
        $firstResponse = $this->postJson('/stock-count-documents/category-drafts', $payload)->assertSuccessful();
        $before = $this->snapshot();
        $response = $this->postJson('/stock-count-documents/category-drafts', $payload)->assertSuccessful();
        $this->assertSame($firstResponse->status(), $response->status());
        $this->assertSame($firstResponse->json(), $response->json());
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(2, StockCountDocument::count());
    }

    public function test_reused_request_id_with_changed_counts_returns_conflict_without_changing_drafts(): void
    {
        [$root, $first] = $this->fixture();
        $payload = $this->payload($root, [$first]);
        $this->postJson('/stock-count-documents/category-drafts', $payload)->assertSuccessful();
        $before = $this->snapshot();
        $payload['products'][0]['actual_quantities'][$first[1]->id] = 3;
        $this->postJson('/stock-count-documents/category-drafts', $payload)->assertStatus(409);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_request_ids_are_scoped_to_authenticated_owner(): void
    {
        [$root, $first] = $this->fixture();
        $payload = $this->payload($root, [$first]);
        $a = $this->postJson('/stock-count-documents/category-drafts', $payload)->assertSuccessful()->json();
        $other = User::factory()->create(['is_active'=>true, 'can_access_erp'=>true]);
        $other->assignRole(Role::findOrCreate('Owner', 'web'));
        $b = $this->actingAs($other)->postJson('/stock-count-documents/category-drafts', $payload)->assertSuccessful()->json();
        $this->assertNotSame($a['documents'][0]['document_id'], $b['documents'][0]['document_id']);
        $this->assertSame(2, StockCountDocument::count());
        $this->assertSame($other->id, (int) StockCountDocument::findOrFail($b['documents'][0]['document_id'])->created_by);
    }

    public function test_lost_bulk_save_response_replayed_after_midnight_uses_original_date_and_documents(): void
    {
        [$root, $first] = $this->fixture();
        $payload = $this->payload($root, [$first]);
        try {
            \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-30 20:00:00', 'UTC'));
            $a = $this->postJson('/stock-count-documents/category-drafts', $payload)->assertSuccessful();
            $before = $this->snapshot();
            \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-30 22:00:00', 'UTC'));
            $b = $this->postJson('/stock-count-documents/category-drafts', $payload)->assertSuccessful();
            $this->assertSame($a->json(), $b->json());
            $this->assertSame($before, $this->snapshot());
            $this->assertSame('2026-10-30', StockCountDocument::sole()->document_date->toDateString());
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_finalize_can_reuse_save_request_id_as_different_operation_and_retries_without_duplicate_movements(): void
    {
        [$root, $first, $second] = $this->fixture();
        $save = $this->payload($root, [$first, $second]);
        $saved = $this->postJson('/stock-count-documents/category-drafts', $save)->assertSuccessful()->json('documents');
        $payload = ['request_id'=>$save['request_id'], 'category_id'=>$root->id,
            'document_ids'=>array_column($saved, 'document_id'),
            'review_tokens'=>array_column($saved, 'review_token', 'document_id')];
        $a = $this->postJson('/stock-count-documents/category-finalize', $payload)->assertOk();
        $before = $this->snapshot();
        $b = $this->postJson('/stock-count-documents/category-finalize', $payload)->assertOk();
        $this->assertSame($a->json(), $b->json());
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(2, StockMovement::count());
    }

    public function test_stale_expected_review_token_prevents_bulk_update_from_overwriting_more_recent_counts(): void
    {
        [$root, $first, $second] = $this->fixture();
        $saved = $this->postJson('/stock-count-documents/category-drafts', $this->payload($root, [$first, $second]))
            ->assertSuccessful()->json('documents');
        $payload = $this->payload($root, [$first, $second]);
        foreach ($payload['products'] as $index => &$entry) {
            $entry['document_id'] = $saved[$index]['document_id'];
            $entry['expected_review_token'] = $saved[$index]['review_token'];
            $entry['actual_quantities'] = array_map(fn () => 2, $entry['actual_quantities']);
        }
        unset($entry);
        app(\App\Services\StockCountDocumentService::class)->updateProductDraft(
            StockCountDocument::findOrFail($saved[1]['document_id']), [
                'document_date'=>'2026-10-30', 'actual_quantities'=>[$second[1]->id=>7],
            ], auth()->id());
        $before = $this->snapshot();
        $this->postJson('/stock-count-documents/category-drafts', $payload)->assertStatus(409);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_recovery_status_returns_original_result_only_to_its_owner(): void
    {
        [$root, $first] = $this->fixture();
        $payload = $this->payload($root, [$first]);
        $saved = $this->postJson('/stock-count-documents/category-drafts', $payload)->assertOk()->json();
        $url = '/stock-count-documents/requests/'.$payload['request_id'].'?operation=drafts';
        $this->getJson($url)->assertOk()->assertExactJson($saved);
        $this->getJson('/stock-count-documents/requests/'.Str::uuid().'?operation=drafts')->assertNotFound();
        $other = User::factory()->create(['is_active'=>true, 'can_access_erp'=>true]);
        $other->assignRole(Role::findOrCreate('Owner', 'web'));
        $this->actingAs($other)->getJson($url)->assertNotFound();
    }

    public function test_changed_notes_invalidate_the_previous_review_version(): void
    {
        [$root, $first] = $this->fixture();
        $saved = $this->postJson('/stock-count-documents/category-drafts', $this->payload($root, [$first]))->assertOk()->json('documents.0');
        $document = StockCountDocument::findOrFail($saved['document_id']);
        app(\App\Services\StockCountDocumentService::class)->updateProductDraft($document, [
            'document_date'=>$document->document_date->toDateString(), 'notes'=>[$first[1]->id=>'updated note'],
        ], auth()->id());
        $payload = $this->payload($root, [$first]);
        $payload['products'][0] += ['document_id'=>$document->id, 'expected_review_token'=>$saved['review_token']];
        $before = $this->snapshot();
        $this->postJson('/stock-count-documents/category-drafts', $payload)->assertStatus(409);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_old_single_product_form_cannot_overwrite_or_finalize_a_newer_draft(): void
    {
        [$root, $first] = $this->fixture();
        $saved = $this->postJson('/stock-count-documents/category-drafts', $this->payload($root, [$first]))->assertOk()->json('documents.0');
        $document = StockCountDocument::findOrFail($saved['document_id']);
        app(\App\Services\StockCountDocumentService::class)->updateProductDraft($document, [
            'document_date'=>$document->document_date->toDateString(), 'actual_quantities'=>[$first[1]->id=>7],
        ], auth()->id());
        $payload = ['expected_review_token'=>$saved['review_token'], 'actual_quantities'=>[$first[1]->id=>2]];
        $before = $this->snapshot();
        $this->putJson(route('stock-count-documents.update', $document), $payload)->assertStatus(409);
        $this->patchJson(route('stock-count-documents.finalize', $document), $payload)->assertStatus(409);
        $this->assertSame($before, $this->snapshot());
        $this->get(route('stock-count-documents.edit', $document))->assertOk()->assertSee('name="expected_review_token"', false);
        $payload['expected_review_token'] = app(\App\Services\StockCountDocumentService::class)->reviewToken($document->fresh());
        $this->putJson(route('stock-count-documents.update', $document), $payload)->assertRedirect();
        $this->assertSame(2, (int) $document->items()->sole()->actual_quantity);
    }
}
