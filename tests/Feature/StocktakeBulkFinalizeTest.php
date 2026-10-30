<?php

namespace Tests\Feature;

use App\Models\{Category, Product, ProductVariant, StockCountDocument, StockMovement, User, Warehouse, WarehouseStock};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StocktakeBulkFinalizeTest extends TestCase
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

    private function draft(array $pair, ?int $actual): StockCountDocument
    {
        return app(\App\Services\StockCountDocumentService::class)->createProductDraft([
            'product_id'=>$pair[0]->id, 'document_date'=>'2026-10-30',
            'actual_quantities'=>[$pair[1]->id=>$actual],
        ], auth()->id());
    }

    private function finalizeBatch(Category $category, array $documents, ?array $tokens = null)
    {
        $tokens ??= collect($documents)->mapWithKeys(fn ($doc) => [
            $doc->id=>app(\App\Services\StockCountDocumentService::class)->reviewToken($doc),
        ])->all();
        return $this->postJson('/stock-count-documents/category-finalize', [
            'category_id'=>$category->id,
            'document_ids'=>array_map(fn ($doc) => $doc->id, $documents),
            'review_tokens'=>$tokens,
        ]);
    }

    private function snapshot(): array
    {
        return collect(['stock_count_documents', 'stock_count_document_items', 'stock_count_document_history',
            'stock_movements', 'warehouse_stocks', 'product_variants', 'products'])
            ->mapWithKeys(fn ($table) => [$table=>\Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    public function test_complete_inactive_drafts_including_explicit_zero_finalize_together_while_category_stays_frozen(): void
    {
        [$root, $first, $second] = $this->fixture();
        \Illuminate\Support\Facades\DB::table('inventory_count_freezes')->where('id', 1)->update([
            'enabled'=>true, 'all_products'=>false, 'category_ids'=>json_encode([$root->id]),
            'product_ids'=>json_encode([$first[0]->id, $second[0]->id]),
        ]);
        $a = $this->draft($first, 0);
        $b = $this->draft($second, 6);
        $response = $this->finalizeBatch($root, [$a, $b])->assertOk()->assertJsonCount(2, 'documents');
        $this->assertNotEmpty($response->json('message'));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($response->json('documents'), 'document_id'));
        foreach ($response->json('documents') as $row) {
            $document = StockCountDocument::findOrFail($row['document_id']);
            $this->assertSame('finalized', $document->status);
            $this->assertSame((int) $document->product_id, $row['product_id']);
            $this->assertSame(route('stock-count-documents.view', $document), $row['view_url']);
        }
        $this->assertSame(0, (int) WarehouseStock::where('product_variant_id', $first[1]->id)->value('quantity'));
        $this->assertSame(6, (int) WarehouseStock::where('product_variant_id', $second[1]->id)->value('quantity'));
        $this->assertSame(0, (int) $first[0]->fresh()->stock);
        $this->assertSame(6, (int) $second[0]->fresh()->stock);
        $this->assertSame(2, StockMovement::count());
        $this->assertTrue((bool) app(\App\Services\InventoryCountFreezeService::class)->state()->enabled);
        foreach ([$first, $second] as [$product, $variant]) {
            $this->assertFalse((bool) $product->fresh()->is_sellable);
            $this->assertFalse((bool) $variant->fresh()->is_active);
            $this->assertFalse((bool) $variant->fresh()->sales_enabled);
        }
    }

    public function test_bulk_draft_response_provides_review_tokens_for_the_saved_counts(): void
    {
        [$root, $first, $second] = $this->fixture();
        $response = $this->postJson('/stock-count-documents/category-drafts', [
            'category_id'=>$root->id, 'document_date'=>'2026-10-30',
            'products'=>array_map(fn ($pair) => [
                'product_id'=>$pair[0]->id, 'actual_quantities'=>[$pair[1]->id=>0],
            ], [$first, $second]),
        ])->assertSuccessful()->assertJsonCount(2, 'documents');
        foreach ($response->json('documents') as $row) {
            $this->assertArrayHasKey('review_token', $row);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row['review_token']);
            $this->assertSame(app(\App\Services\StockCountDocumentService::class)->reviewToken(
                StockCountDocument::findOrFail($row['document_id'])
            ), $row['review_token']);
        }
        $this->assertSame(0, StockMovement::count());
    }

    public function test_incomplete_second_draft_rolls_back_first_stock_movement_document_items_and_history(): void
    {
        [$root, $first, $second] = $this->fixture();
        $a = $this->draft($first, 0);
        $b = $this->draft($second, null);
        $before = $this->snapshot();
        $response = $this->finalizeBatch($root, [$a, $b])->assertUnprocessable();
        $this->assertNotEmpty($response->json('errors'));
        $this->assertNotEmpty($response->json('conflicts'));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_stale_second_stock_rolls_back_every_change_to_the_first_draft(): void
    {
        [$root, $first, $second] = $this->fixture();
        $a = $this->draft($first, 2);
        $b = $this->draft($second, 4);
        WarehouseStock::withoutEvents(fn () => WarehouseStock::where('product_variant_id', $second[1]->id)->update(['quantity'=>7]));
        $before = $this->snapshot();
        $response = $this->finalizeBatch($root, [$a, $b])->assertUnprocessable();
        $this->assertNotEmpty($response->json('conflicts'));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_outside_category_draft_rejects_batch_without_any_changes(): void
    {
        [$root, $first, , $outside] = $this->fixture();
        $a = $this->draft($first, 1);
        $b = $this->draft($outside, 2);
        $before = $this->snapshot();
        $this->finalizeBatch($root, [$a, $b])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_batch_containing_already_finalized_document_cannot_apply_another_draft(): void
    {
        [$root, $first, $second] = $this->fixture();
        $a = $this->draft($first, 3);
        $b = $this->draft($second, 4);
        app(\App\Services\StockCountDocumentService::class)->finalize($b, auth()->id(), true);
        $before = $this->snapshot();
        $this->finalizeBatch($root, [$a, $b])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_duplicate_document_ids_are_rejected_without_writes(): void
    {
        [$root, $first] = $this->fixture();
        $a = $this->draft($first, 3);
        $before = $this->snapshot();
        $this->finalizeBatch($root, [$a, $a])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_count_edited_after_review_rejects_entire_batch_without_stock_or_document_changes(): void
    {
        [$root, $first, $second] = $this->fixture();
        $a = $this->draft($first, 2);
        $b = $this->draft($second, 4);
        $service = app(\App\Services\StockCountDocumentService::class);
        $reviewedTokens = [$a->id=>$service->reviewToken($a), $b->id=>$service->reviewToken($b)];
        $service->updateProductDraft($b, [
            'document_date'=>'2026-10-30', 'actual_quantities'=>[$second[1]->id=>7],
        ], auth()->id());
        $before = $this->snapshot();
        $this->finalizeBatch($root, [$a, $b], $reviewedTokens)->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('draft', $a->fresh()->status);
        $this->assertSame('draft', $b->fresh()->status);
        $this->assertSame(7, (int) $b->items()->sole()->actual_quantity);
    }

    public function test_missing_review_token_cannot_finalize_any_document(): void
    {
        [$root, $first] = $this->fixture();
        $a = $this->draft($first, 2);
        $before = $this->snapshot();
        $this->finalizeBatch($root, [$a], [])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_user_without_stocktake_access_cannot_finalize_a_batch(): void
    {
        [$root, $first] = $this->fixture();
        $a = $this->draft($first, 3);
        $before = $this->snapshot();
        $this->actingAs(User::factory()->create(['is_active'=>true, 'can_access_erp'=>true]));
        $this->finalizeBatch($root, [$a])->assertForbidden();
        $this->assertSame($before, $this->snapshot());
    }
}

