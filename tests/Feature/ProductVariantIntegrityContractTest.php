<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\ProductSalesStatusService;
use App\Services\ReservationQueryService;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function phaseFiveContractProduct(): array
{
    $category = Category::query()->create(['name' => 'Phase 5 contract', 'code' => uniqid('C-')]);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Phase 5 product',
        'sku' => uniqid('P-'),
        'code' => '123456',
        'stock' => 999,
        'reserved' => 999,
        'price' => 100,
        'is_sellable' => true,
    ]);
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => 'Main',
        'variety_code' => '0000',
        'variant_code' => '12345600000',
        'sell_price' => 100,
        'stock' => 999,
        'reserved' => 999,
        'is_active' => true,
        'sales_enabled' => true,
    ]);
    WarehouseStock::query()->create([
        'warehouse_id' => WarehouseStockService::centralWarehouseId(),
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'quantity' => 17,
    ]);

    return compact('product', 'variant');
}

it('treats warehouse quantity and canonical reservations as authorities instead of caches', function (): void {
    ['product' => $product, 'variant' => $variant] = phaseFiveContractProduct();

    $physical = (int) WarehouseStock::query()
        ->where('warehouse_id', WarehouseStockService::centralWarehouseId())
        ->where('product_variant_id', $variant->id)
        ->value('quantity');
    $reserved = (int) app(ReservationQueryService::class)
        ->quantitiesByVariant($product->id, [$variant->id])
        ->get($variant->id, 0);

    expect($physical)->toBe(17)
        ->and($reserved)->toBe(0)
        ->and($product->reserved)->toBe(999)
        ->and($variant->reserved)->toBe(999);
});

it('keeps explicit sales status changes separate from structural activity', function (): void {
    ['product' => $product, 'variant' => $variant] = phaseFiveContractProduct();
    $actor = User::factory()->create();

    app(ProductSalesStatusService::class)->change(
        $product->id,
        'deactivate',
        'variants',
        [$variant->id],
        'management_decision',
        null,
        $actor,
    );

    expect($variant->fresh()->is_active)->toBeTrue()
        ->and($variant->fresh()->sales_enabled)->toBeFalse()
        ->and($product->fresh()->is_sellable)->toBeFalse();
});
