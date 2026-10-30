<?php

namespace Tests\Feature;

use App\Models\{Category, Product, ProductVariant, StockCountDocument, StockMovement, User, Warehouse, WarehouseStock};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StocktakeBulkDraftTest extends TestCase
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

    private function save(array $payload)
    {
        return $this->postJson('/stock-count-documents/category-drafts', $payload);
    }

    private function payload(Category $root, array $pairs): array
    {
        return ['category_id'=>$root->id, 'document_date'=>'2026-10-30',
            'products'=>array_map(fn ($pair) => ['product_id'=>$pair[0]->id,
                'actual_quantities'=>[$pair[1]->id=>0]], $pairs)];
    }

    private function assertStockUnchanged(): void
    {
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, WarehouseStock::where('quantity', '<>', 8)->count());
        $this->assertSame(0, ProductVariant::where('stock', '<>', 8)->count());
        $this->assertSame(0, Product::where('stock', '<>', 8)->count());
    }

    public function test_one_save_creates_reviewable_drafts_for_inactive_products_and_descendants_without_stock_changes(): void
    {
        [$root, $first, $second] = $this->fixture();
        $this->save($this->payload($root, [$first, $second]))->assertSuccessful();
        $this->assertSame(2, StockCountDocument::count());
        foreach ([$first, $second] as [$product, $variant]) {
            $document = StockCountDocument::where('product_id', $product->id)->sole();
            $this->assertSame('draft', $document->status);
            $this->assertSame(0, (int) $document->items()->where('product_variant_id', $variant->id)->value('actual_quantity'));
        }
        $this->assertStockUnchanged();
    }

    public function test_outside_category_product_rejects_the_whole_batch(): void
    {
        [$root, $first, , $outside] = $this->fixture();
        $this->save($this->payload($root, [$first, $outside]))->assertUnprocessable();
        $this->assertSame(0, StockCountDocument::count());
        $this->assertStockUnchanged();
    }

    public function test_invalid_count_in_later_product_does_not_partially_save_the_batch(): void
    {
        [$root, $first, $second] = $this->fixture();
        $payload = $this->payload($root, [$first, $second]);
        $payload['products'][1]['actual_quantities'][$second[1]->id] = -1;
        $this->save($payload)->assertUnprocessable();
        $this->assertSame(0, StockCountDocument::count());
        $this->assertStockUnchanged();
    }

    public function test_resaving_document_ids_updates_drafts_without_creating_duplicates(): void
    {
        [$root, $first, $second] = $this->fixture();
        $payload = $this->payload($root, [$first, $second]);
        $this->save($payload)->assertSuccessful();
        foreach ($payload['products'] as &$entry) {
            $entry['document_id'] = StockCountDocument::where('product_id', $entry['product_id'])->sole()->id;
            $entry['actual_quantities'] = array_map(fn () => 6, $entry['actual_quantities']);
        }
        unset($entry);
        $this->save($payload)->assertSuccessful();
        $this->assertSame(2, StockCountDocument::count());
        foreach (StockCountDocument::all() as $document) {
            $this->assertSame('draft', $document->status);
            $this->assertSame(6, (int) $document->items()->sole()->actual_quantity);
        }
        $this->assertStockUnchanged();
    }

    public function test_mismatched_second_document_rolls_back_an_earlier_valid_draft_update(): void
    {
        [$root, $first, $second, $outside] = $this->fixture();
        $service = app(\App\Services\StockCountDocumentService::class);
        $existing = $service->createProductDraft(['product_id'=>$first[0]->id, 'document_date'=>'2026-10-30',
            'actual_quantities'=>[$first[1]->id=>3]], auth()->id());
        $foreign = $service->createProductDraft(['product_id'=>$outside[0]->id, 'document_date'=>'2026-10-30',
            'actual_quantities'=>[$outside[1]->id=>7]], auth()->id());
        $historyBefore = $existing->history()->count();
        $payload = $this->payload($root, [$first, $second]);
        $payload['products'][0]['document_id'] = $existing->id;
        $payload['products'][1]['document_id'] = $foreign->id;
        $this->save($payload)->assertUnprocessable()->assertJsonValidationErrors('products.1.document_id');
        $this->assertSame(2, StockCountDocument::count());
        $this->assertSame(3, (int) $existing->items()->sole()->actual_quantity);
        $this->assertSame(7, (int) $foreign->items()->sole()->actual_quantity);
        $this->assertSame($historyBefore, $existing->history()->count());
        $this->assertStockUnchanged();
    }

    public function test_foreign_variant_key_in_second_product_rolls_back_first_created_draft(): void
    {
        [$root, $first, $second] = $this->fixture();
        $payload = $this->payload($root, [$first, $second]);
        $payload['products'][1]['actual_quantities'] = [$first[1]->id=>2];
        $this->save($payload)->assertUnprocessable()->assertJsonValidationErrors('products.1.actual_quantities');
        $this->assertSame(0, StockCountDocument::count());
        $this->assertSame(0, \App\Models\StockCountDocumentHistory::count());
        $this->assertStockUnchanged();
    }

    public function test_missing_second_document_id_does_not_create_the_first_draft(): void
    {
        [$root, $first, $second] = $this->fixture();
        $payload = $this->payload($root, [$first, $second]);
        $payload['products'][1]['document_id'] = 999999999;
        $this->save($payload)->assertUnprocessable()->assertJsonValidationErrors('products.1.document_id');
        $this->assertSame(0, StockCountDocument::count());
        $this->assertStockUnchanged();
    }

    public function test_null_bulk_count_remains_uncounted_instead_of_becoming_explicit_zero(): void
    {
        [$root, $first] = $this->fixture();
        $payload = $this->payload($root, [$first]);
        $payload['products'][0]['actual_quantities'][$first[1]->id] = null;
        $this->save($payload)->assertSuccessful();
        $document = StockCountDocument::where('product_id', $first[0]->id)->sole();
        $this->assertNull($document->items()->sole()->actual_quantity);
        $this->assertSame(0, (int) $document->counted_count);
        $this->assertStockUnchanged();
    }

    public function test_foreign_note_key_is_rejected_atomically(): void
    {
        [$root, $first, $second] = $this->fixture();
        $payload = $this->payload($root, [$first, $second]);
        $payload['products'][1]['notes'] = [$first[1]->id=>'foreign note'];
        $this->save($payload)->assertUnprocessable()->assertJsonValidationErrors('products.1.notes');
        $this->assertSame(0, StockCountDocument::count());
        $this->assertStockUnchanged();
    }

    public function test_user_without_stocktake_access_cannot_bulk_save(): void
    {
        [$root, $first] = $this->fixture();
        $this->actingAs(User::factory()->create(['is_active'=>true, 'can_access_erp'=>true]));
        $this->save($this->payload($root, [$first]))->assertForbidden();
        $this->assertSame(0, StockCountDocument::count());
        $this->assertStockUnchanged();
    }

    public function test_inactive_product_can_be_counted_and_finalized_during_freeze_without_reenabling_sales(): void
    {
        [$root, [$product, $variant]] = $this->fixture();
        \Illuminate\Support\Facades\DB::table('inventory_count_freezes')->where('id', 1)->update([
            'enabled'=>true, 'all_products'=>false, 'category_ids'=>json_encode([$root->id]),
            'product_ids'=>json_encode([$product->id]),
        ]);
        $freeze = app(\App\Services\InventoryCountFreezeService::class);
        $this->assertTrue($freeze->blocked($product->id));
        $service = app(\App\Services\StockCountDocumentService::class);
        $document = $service->createProductDraft(['product_id'=>$product->id, 'document_date'=>'2026-10-30',
            'actual_quantities'=>[$variant->id=>5]], auth()->id());
        $this->assertSame(8, (int) WarehouseStock::where('product_variant_id', $variant->id)->value('quantity'));
        $service->finalize($document, auth()->id(), true);
        $this->assertSame('finalized', $document->fresh()->status);
        $this->assertSame(5, (int) WarehouseStock::where('product_variant_id', $variant->id)->value('quantity'));
        $this->assertFalse((bool) $product->fresh()->is_sellable);
        $this->assertFalse((bool) $variant->fresh()->is_active);
        $this->assertFalse((bool) $variant->fresh()->sales_enabled);
        $this->assertTrue((bool) $freeze->state()->enabled);
        $this->assertSame(1, StockMovement::count());
    }
}
