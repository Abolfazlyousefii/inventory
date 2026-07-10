<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockCountDocument;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockCountDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_and_update_draft_document(): void
    {
        [$user, $warehouse, $products, $variants] = $this->seedBaseData();

        $response = $this->actingAs($user)->post(route('stock-count-documents.store'), [
            'warehouse_id' => $warehouse->id,
            'document_date' => '2026-04-05',
            'description' => 'شمارش اولیه',
            'items' => [
                ['product_id' => $products[0]->id, 'variant_id' => $variants[0]->id, 'actual_quantity' => 8, 'description' => 'ردیف 1'],
                ['product_id' => $products[1]->id, 'variant_id' => $variants[1]->id, 'actual_quantity' => 13, 'description' => 'ردیف 2'],
            ],
        ]);

        $response->assertRedirect();
        $document = StockCountDocument::query()->firstOrFail();
        $this->assertSame('draft', $document->status);
        $this->assertCount(2, $document->items);

        $updateResponse = $this->actingAs($user)->put(route('stock-count-documents.update', $document), [
            'warehouse_id' => $warehouse->id,
            'document_date' => '2026-04-06',
            'description' => 'ویرایش سند',
            'items' => [
                ['product_id' => $products[0]->id, 'variant_id' => $variants[0]->id, 'actual_quantity' => 9, 'description' => 'اصلاح شد'],
            ],
        ]);

        $updateResponse->assertRedirect();
        $document->refresh();
        $this->assertSame('ویرایش سند', $document->description);
        $this->assertCount(1, $document->items);
    }

    public function test_finalize_with_negative_and_positive_difference_creates_adjustments(): void
    {
        [$user, $warehouse, $products, $variants] = $this->seedBaseData();

        $this->actingAs($user)->post(route('stock-count-documents.store'), [
            'warehouse_id' => $warehouse->id,
            'document_date' => '2026-04-05',
            'items' => [
                ['product_id' => $products[0]->id, 'variant_id' => $variants[0]->id, 'actual_quantity' => 7],
                ['product_id' => $products[1]->id, 'variant_id' => $variants[1]->id, 'actual_quantity' => 12],
            ],
        ]);

        $document = StockCountDocument::query()->firstOrFail();
        $this->actingAs($user)->patch(route('stock-count-documents.finalize', $document))->assertRedirect();

        $document->refresh();
        $this->assertSame('finalized', $document->status);

        $this->assertDatabaseHas('stock_movements', [
            'reference_id' => $document->id,
            'reference_type' => 'stock_count_document',
            'transaction_type' => 'stock_adjustment_out',
            'product_id' => $products[0]->id,
            'quantity' => 3,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'reference_id' => $document->id,
            'reference_type' => 'stock_count_document',
            'transaction_type' => 'stock_adjustment_in',
            'product_id' => $products[1]->id,
            'quantity' => 2,
        ]);

        $this->assertEquals(7, WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $products[0]->id)->value('quantity'));
        $this->assertEquals(12, WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $products[1]->id)->value('quantity'));
    }

    public function test_finalize_zero_difference_changes_nothing_but_finalizes(): void
    {
        [$user, $warehouse, $products, $variants] = $this->seedBaseData();

        $this->actingAs($user)->post(route('stock-count-documents.store'), [
            'warehouse_id' => $warehouse->id,
            'document_date' => '2026-04-05',
            'items' => [
                ['product_id' => $products[0]->id, 'variant_id' => $variants[0]->id, 'actual_quantity' => 10],
            ],
        ]);

        $document = StockCountDocument::query()->firstOrFail();
        $this->actingAs($user)->patch(route('stock-count-documents.finalize', $document));

        $document->refresh();
        $this->assertSame('finalized', $document->status);
        $this->assertDatabaseMissing('stock_movements', [
            'reference_id' => $document->id,
            'reference_type' => 'stock_count_document',
        ]);
        $this->assertEquals(10, WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $products[0]->id)->value('quantity'));
    }

    public function test_cannot_edit_or_finalize_again_after_finalize(): void
    {
        [$user, $warehouse, $products, $variants] = $this->seedBaseData();

        $this->actingAs($user)->post(route('stock-count-documents.store'), [
            'warehouse_id' => $warehouse->id,
            'document_date' => '2026-04-05',
            'items' => [
                ['product_id' => $products[0]->id, 'variant_id' => $variants[0]->id, 'actual_quantity' => 8],
            ],
        ]);

        $document = StockCountDocument::query()->firstOrFail();
        $this->actingAs($user)->patch(route('stock-count-documents.finalize', $document));

        $this->actingAs($user)->put(route('stock-count-documents.update', $document), [
            'warehouse_id' => $warehouse->id,
            'document_date' => '2026-04-05',
            'items' => [
                ['product_id' => $products[0]->id, 'variant_id' => $variants[0]->id, 'actual_quantity' => 9],
            ],
        ])->assertStatus(422);

        $this->actingAs($user)
            ->patch(route('stock-count-documents.finalize', $document))
            ->assertStatus(422);
    }

    public function test_finalize_is_atomic_and_rolls_back_on_failure(): void
    {
        [$user, $warehouse, $products, $variants] = $this->seedBaseData();

        $this->actingAs($user)->post(route('stock-count-documents.store'), [
            'warehouse_id' => $warehouse->id,
            'document_date' => '2026-04-05',
            'items' => [
                ['product_id' => $products[0]->id, 'variant_id' => $variants[0]->id, 'actual_quantity' => 12],
                ['product_id' => $products[1]->id, 'variant_id' => $variants[1]->id, 'actual_quantity' => 5],
            ],
        ]);

        $document = StockCountDocument::query()->firstOrFail();

        WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $products[1]->id)
            ->update(['quantity' => 0]);

        $this->actingAs($user)
            ->patch(route('stock-count-documents.finalize', $document))
            ->assertStatus(422);

        $document->refresh();
        $this->assertSame('draft', $document->status);

        $this->assertEquals(10, WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $products[0]->id)->value('quantity'));
        $this->assertDatabaseCount('stock_movements', 0);
    }


    public function test_zeroing_one_variant_only_changes_that_variant_and_warehouse(): void
    {
        [$user, $warehouse, $products, $variants] = $this->seedBaseData();
        $otherWarehouse = Warehouse::query()->create(['name' => 'انبار دیگر', 'type' => 'branch', 'is_active' => true]);

        WarehouseStock::query()->where('warehouse_id', $warehouse->id)->delete();
        WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $products[0]->id, 'product_variant_id' => $variants[0]->id, 'quantity' => 10]);
        $whiteVariant = ProductVariant::query()->create(['product_id' => $products[0]->id, 'variant_name' => 'سفید', 'variety_id' => 1003, 'sell_price' => 1000]);
        WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $products[0]->id, 'product_variant_id' => $whiteVariant->id, 'quantity' => 7]);
        WarehouseStock::query()->create(['warehouse_id' => $otherWarehouse->id, 'product_id' => $products[0]->id, 'product_variant_id' => $variants[0]->id, 'quantity' => 4]);

        $this->actingAs($user)->post(route('stock-count-documents.store'), [
            'warehouse_id' => $warehouse->id,
            'document_date' => '2026-04-05',
            'items' => [
                ['product_id' => $products[0]->id, 'variant_id' => $variants[0]->id, 'actual_quantity' => 0],
            ],
        ])->assertRedirect();

        $document = StockCountDocument::query()->firstOrFail();
        $this->actingAs($user)->patch(route('stock-count-documents.finalize', $document))->assertRedirect();

        $this->assertSame(0, (int) WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $variants[0]->id)->value('quantity'));
        $this->assertSame(7, (int) WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_variant_id', $whiteVariant->id)->value('quantity'));
        $this->assertSame(4, (int) WarehouseStock::query()->where('warehouse_id', $otherWarehouse->id)->where('product_variant_id', $variants[0]->id)->value('quantity'));
    }

    private function seedBaseData(): array
    {
        $user = User::factory()->create();
        $category = Category::query()->create(['name' => 'عمومی']);
        $warehouse = Warehouse::query()->create([
            'name' => 'انبار تست',
            'type' => 'central',
            'is_active' => true,
        ]);

        $productA = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'کالای A',
            'sku' => 'A-001',
            'stock' => 0,
            'price' => 1000,
        ]);

        $productB = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'کالای B',
            'sku' => 'B-001',
            'stock' => 0,
            'price' => 1000,
        ]);

        WarehouseStock::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $productA->id,
            'quantity' => 10,
        ]);

        WarehouseStock::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $productB->id,
            'quantity' => 10,
        ]);

        $variantA = ProductVariant::query()->create([
            'product_id' => $productA->id,
            'variant_name' => 'تنوع A',
            'variety_id' => 1001,
            'sell_price' => 1000,
        ]);

        $variantB = ProductVariant::query()->create([
            'product_id' => $productB->id,
            'variant_name' => 'تنوع B',
            'variety_id' => 1002,
            'sell_price' => 1000,
        ]);

        StockMovement::query()->delete();

        return [$user, $warehouse, [$productA, $productB], [$variantA, $variantB]];
    }
}
