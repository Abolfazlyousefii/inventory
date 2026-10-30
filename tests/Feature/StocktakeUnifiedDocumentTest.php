<?php

namespace Tests\Feature;

use App\Models\{Category, Product, ProductVariant, StockCountDocument, StockMovement, User, Warehouse, WarehouseStock};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StocktakeUnifiedDocumentTest extends TestCase
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

    private function payload(Category $category, array $first, array $second): array
    {
        return ['request_id'=>(string) Str::uuid(), 'category_id'=>$category->id,
            'actual_quantities'=>[$first[1]->id=>0, $second[1]->id=>6]];
    }

    private function save(array $payload)
    {
        return $this->postJson('/stock-count-documents/category-document', $payload);
    }

    private function finalize(array $document, ?string $requestId = null)
    {
        return $this->postJson('/stock-count-documents/category-document/'.$document['id'].'/finalize', [
            'request_id'=>$requestId ?? (string) Str::uuid(), 'expected_review_token'=>$document['review_token'],
        ]);
    }

    private function snapshot(): array
    {
        return collect(['stock_count_documents', 'stock_count_document_items', 'stock_count_document_history',
            'stock_movements', 'warehouse_stocks', 'product_variants', 'products'])
            ->mapWithKeys(fn ($table) => [$table=>\Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    private function addUnselectedVariant(array $pair): ProductVariant
    {
        $variant = ProductVariant::withoutEvents(fn () => ProductVariant::create([
            'product_id'=>$pair[0]->id, 'variant_name'=>'Unselected blue', 'variant_code'=>Str::random(12),
            'stock'=>8, 'sell_price'=>150000, 'is_active'=>false, 'sales_enabled'=>false,
        ]));
        WarehouseStock::withoutEvents(fn () => WarehouseStock::create([
            'warehouse_id'=>Warehouse::where('type', 'central')->value('id'), 'product_id'=>$pair[0]->id,
            'product_variant_id'=>$variant->id, 'quantity'=>8,
        ]));
        return $variant;
    }

    public function test_one_category_document_snapshots_every_variant_with_zero_default_for_untouched_rows(): void
    {
        [$root, $first, $second] = $this->fixture();
        $unselected = $this->addUnselectedVariant($first);
        $response = $this->save($this->payload($root, $first, $second))->assertSuccessful()
            ->assertJsonStructure(['document_id', 'view_url', 'review_token', 'document'=>['id', 'document_number', 'edit_url', 'review_url', 'review_token',
                'variants_count', 'counted_count', 'total_before', 'total_actual', 'total_increase', 'total_decrease', 'items']])
            ->assertJsonPath('document.variants_count', 3)->assertJsonPath('document.counted_count', 3)
            ->assertJsonPath('document.total_before', 24)->assertJsonPath('document.total_actual', 6)
            ->assertJsonPath('document.total_increase', 0)->assertJsonPath('document.total_decrease', 18);
        $this->assertSame(1, StockCountDocument::count());
        $document = StockCountDocument::sole();
        $this->assertSame($document->id, $response->json('document_id'));
        $this->assertSame($response->json('document.review_token'), $response->json('review_token'));
        $this->assertSame($response->json('document.review_url'), $response->json('view_url'));
        $this->assertSame('category', $document->type);
        $this->assertNull($document->product_id);
        $this->assertSame($root->id, (int) $document->category_id);
        $this->assertSame($root->name, $document->scope_name);
        $this->assertEqualsCanonicalizing([$first[1]->id, $second[1]->id, $unselected->id], $document->items()->pluck('product_variant_id')->all());
        $this->assertSame(0, (int) $document->items()->where('product_variant_id', $first[1]->id)->sole()->actual_quantity);
        $this->assertSame(0, (int) $document->items()->where('product_variant_id', $unselected->id)->sole()->actual_quantity);
        $this->assertSame(0, $document->items()->whereNull('actual_quantity')->count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, WarehouseStock::where('quantity', '<>', 8)->count());
    }

    public function test_resume_updates_same_document_and_preserves_previously_counted_rows_not_in_current_payload(): void
    {
        [$root, $first, $second] = $this->fixture();
        $saved = $this->save($this->payload($root, $first, $second))->assertSuccessful()->json('document');
        $this->save(['request_id'=>(string) Str::uuid(), 'category_id'=>$root->id, 'document_id'=>$saved['id'],
            'expected_review_token'=>$saved['review_token'], 'actual_quantities'=>[$second[1]->id=>5]])
            ->assertSuccessful()->assertJsonPath('document.id', $saved['id'])->assertJsonPath('document.counted_count', 2);
        $document = StockCountDocument::sole();
        $this->assertSame(0, (int) $document->items()->where('product_variant_id', $first[1]->id)->sole()->actual_quantity);
        $this->assertSame(5, (int) $document->items()->where('product_variant_id', $second[1]->id)->sole()->actual_quantity);
        $this->assertSame(1, StockCountDocument::count());
    }

    public function test_starting_same_category_again_resumes_the_existing_draft_instead_of_creating_another(): void
    {
        [$root,$first,$second]=$this->fixture();
        $saved=$this->save($this->payload($root,$first,$second))->assertSuccessful()->json('document');
        $this->get(route('stock-count-documents.category-workspace',['category_id'=>$root->id]))
            ->assertRedirect($saved['edit_url']);
        $before=$this->snapshot();
        $this->save($this->payload($root,$first,$second))->assertStatus(409);
        $this->assertSame($before,$this->snapshot());
        $this->assertSame(1,StockCountDocument::count());
    }

    public function test_finalize_applies_zero_to_untouched_category_variants_and_keeps_category_frozen(): void
    {
        [$root, $first, $second, $outside] = $this->fixture();
        $unselected = $this->addUnselectedVariant($first);
        \Illuminate\Support\Facades\DB::table('inventory_count_freezes')->where('id', 1)->update([
            'enabled'=>true, 'all_products'=>false, 'category_ids'=>json_encode([$root->id]),
            'product_ids'=>json_encode([$first[0]->id, $second[0]->id]),
        ]);
        $saved = $this->save($this->payload($root, $first, $second))->assertSuccessful()->json('document');
        $this->finalize($saved)->assertSuccessful();
        $this->assertSame('finalized', StockCountDocument::sole()->status);
        $this->assertSame(0, (int) WarehouseStock::where('product_variant_id', $first[1]->id)->value('quantity'));
        $this->assertSame(6, (int) WarehouseStock::where('product_variant_id', $second[1]->id)->value('quantity'));
        $this->assertSame(0, (int) WarehouseStock::where('product_variant_id', $unselected->id)->value('quantity'));
        $this->assertSame(8, (int) WarehouseStock::where('product_variant_id', $outside[1]->id)->value('quantity'));
        $this->assertSame(0, (int) $first[0]->fresh()->stock);
        $this->assertSame(6, (int) $second[0]->fresh()->stock);
        $this->assertSame(3, StockMovement::count());
        $this->assertTrue((bool) app(\App\Services\InventoryCountFreezeService::class)->state()->enabled);
        $this->assertFalse((bool) $first[1]->fresh()->is_active);
        $this->assertFalse((bool) $first[1]->fresh()->sales_enabled);
    }

    public function test_foreign_category_variant_rejects_entire_new_document_without_writes(): void
    {
        [$root, $first, , $outside] = $this->fixture();
        $before = $this->snapshot();
        $this->save($this->payload($root, $first, $outside))->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_stale_review_token_prevents_overwriting_more_recent_counts(): void
    {
        [$root, $first, $second] = $this->fixture();
        $saved = $this->save($this->payload($root, $first, $second))->assertSuccessful()->json('document');
        $this->save(['request_id'=>(string) Str::uuid(), 'category_id'=>$root->id, 'document_id'=>$saved['id'],
            'expected_review_token'=>$saved['review_token'], 'actual_quantities'=>[$first[1]->id=>3]])->assertSuccessful();
        $before = $this->snapshot();
        $this->save(['request_id'=>(string) Str::uuid(), 'category_id'=>$root->id, 'document_id'=>$saved['id'],
            'expected_review_token'=>$saved['review_token'], 'actual_quantities'=>[$second[1]->id=>5]])->assertStatus(409);
        $this->assertSame($before, $this->snapshot());
        $this->finalize($saved)->assertStatus(409);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_response_loss_retries_replay_save_and_finalize_without_duplicate_documents_or_movements(): void
    {
        [$root, $first, $second] = $this->fixture();
        $payload = $this->payload($root, $first, $second);
        $firstSave = $this->save($payload)->assertSuccessful();
        $beforeSaveRetry = $this->snapshot();
        $saveRetry = $this->save($payload)->assertSuccessful();
        $this->assertSame($firstSave->json(), $saveRetry->json());
        $this->assertSame($beforeSaveRetry, $this->snapshot());
        $finalizeId = (string) Str::uuid();
        $firstFinalize = $this->finalize($firstSave->json('document'), $finalizeId)->assertSuccessful();
        $beforeFinalizeRetry = $this->snapshot();
        $finalizeRetry = $this->finalize($firstSave->json('document'), $finalizeId)->assertSuccessful();
        $this->assertSame($firstFinalize->json(), $finalizeRetry->json());
        $this->assertSame($beforeFinalizeRetry, $this->snapshot());
        $this->assertSame(1, StockCountDocument::count());
        $this->assertSame(2, StockMovement::count());
    }

    public function test_archive_contains_one_resumable_category_entry_and_review_lists_only_positive_rows(): void
    {
        [$root, $first, $second] = $this->fixture();
        Product::withoutEvents(fn () => $first[0]->update(['name'=>'Selected zero product']));
        Product::withoutEvents(fn () => $second[0]->update(['name'=>'Selected nested product']));
        $saved = $this->save($this->payload($root, $first, $second))->assertSuccessful()->json('document');
        $this->getJson(route('stocktake.index'))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $saved['id'])->assertJsonPath('data.0.type', 'category');
        $this->get($saved['edit_url'])->assertOk()->assertSee($root->name);
        $this->get($saved['review_url'])->assertOk()->assertSee($root->name)
            ->assertDontSee('Selected zero product')->assertSee('Selected nested product');
        $this->assertSame(1, (int) StockCountDocument::sole()->zeroed_count);
    }

    public function test_empty_or_null_category_counts_normalize_to_zero_without_changing_original_product_rules(): void
    {
        [$root, $first, $second] = $this->fixture();
        $saved = $this->save(['request_id'=>(string) Str::uuid(), 'category_id'=>$root->id,
            'actual_quantities'=>[$first[1]->id=>null, $second[1]->id=>'']])->assertSuccessful()->json('document');
        $document = StockCountDocument::sole();
        $this->assertSame(2, $document->items()->count());
        $this->assertSame(0, $document->items()->whereNull('actual_quantity')->count());
        $this->assertSame(0, (int) $document->items()->sum('actual_quantity'));
        $this->assertSame(2, (int) $document->zeroed_count);
        $this->assertSame(0, StockMovement::count());
        $this->finalize($saved)->assertSuccessful();
        $this->assertSame(0, (int) WarehouseStock::whereIn('product_variant_id', [$first[1]->id, $second[1]->id])->sum('quantity'));
    }

    public function test_stock_conflict_rolls_back_all_selected_products_in_unified_document(): void
    {
        [$root, $first, $second] = $this->fixture();
        $saved = $this->save($this->payload($root, $first, $second))->assertSuccessful()->json('document');
        \Illuminate\Support\Facades\DB::table('warehouse_stocks')->where('product_variant_id', $second[1]->id)->update(['quantity'=>7]);
        $before = $this->snapshot();
        $this->finalize($saved)->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('draft', StockCountDocument::sole()->status);
    }

    public function test_user_without_stocktake_access_cannot_create_or_finalize_unified_document(): void
    {
        [$root, $first, $second] = $this->fixture();
        $saved = $this->save($this->payload($root, $first, $second))->assertSuccessful()->json('document');
        $before = $this->snapshot();
        $this->actingAs(User::factory()->create(['is_active'=>true, 'can_access_erp'=>true]));
        $this->save($this->payload($root, $first, $second))->assertForbidden();
        $this->finalize($saved)->assertForbidden();
        $this->assertSame($before, $this->snapshot());
    }
}
