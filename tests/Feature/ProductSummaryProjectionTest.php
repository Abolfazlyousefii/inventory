<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductVariantStructureService;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function phaseFiveSummaryProduct(string $code, int $price = 999): Product
{
    $category = Category::query()->create(['name' => 'Summary '.$code, 'code' => substr($code, 0, 2)]);

    return Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Summary '.$code,
        'sku' => 'SUMMARY-'.$code,
        'code' => $code,
        'stock' => 0,
        'reserved' => 0,
        'price' => $price,
        'is_sellable' => true,
        'models' => [
            'use_models' => false,
            'model_list_ids' => [],
            'use_designs' => false,
            'design_count' => 0,
        ],
    ]);
}

function phaseFiveSummaryVariant(Product $product, string $suffix, int $price, array $attributes = []): ProductVariant
{
    return ProductVariant::query()->create(array_merge([
        'product_id' => $product->id,
        'variant_name' => 'Variant '.$suffix,
        'variety_name' => $suffix === '00' ? '—' : 'Design',
        'variety_code' => $suffix === '00' ? '0000' : '0001',
        'variant_code' => $product->code.'000'.$suffix,
        'sell_price' => $price,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ], $attributes));
}

it('projects price only from active sales-enabled structurally usable positive variants', function (): void {
    $product = phaseFiveSummaryProduct('710001');
    $base = phaseFiveSummaryVariant($product, '00', 100);
    phaseFiveSummaryVariant($product, '01', 1); // Structurally unusable design placeholder.
    phaseFiveSummaryVariant($product, '02', 2, ['is_active' => false, 'variety_code' => '0000']);
    phaseFiveSummaryVariant($product, '03', 3, ['sales_enabled' => false, 'variety_code' => '0000']);
    phaseFiveSummaryVariant($product, '04', 0, ['variety_code' => '0000']);
    $other = phaseFiveSummaryProduct('720001');
    phaseFiveSummaryVariant($other, '00', 1);

    app(ProductVariantStructureService::class)->recalculateProductSummary($product);

    expect($product->fresh()->price)->toBe(100)
        ->and($product->fresh()->is_sellable)->toBeTrue()
        ->and($base->fresh()->sell_price)->toBe(100)
        ->and($other->fresh()->price)->toBe(999);
});

it('writes an explicit zero when no usable positive price exists without changing sales status', function (): void {
    $product = phaseFiveSummaryProduct('730001', 500);
    phaseFiveSummaryVariant($product, '00', 0);
    phaseFiveSummaryVariant($product, '01', 800, ['sales_enabled' => false, 'variety_code' => '0000']);

    app(ProductVariantStructureService::class)->recalculateProductSummary($product);

    expect($product->fresh()->price)->toBe(0)
        ->and($product->fresh()->is_sellable)->toBeTrue();
});

it('keeps a valid product price stable when a zero-price unused row disappears', function (): void {
    $product = phaseFiveSummaryProduct('740001');
    phaseFiveSummaryVariant($product, '00', 100);
    $placeholder = phaseFiveSummaryVariant($product, '01', 0, ['variety_code' => '0000']);

    app(ProductVariantStructureService::class)->recalculateProductSummary($product);
    $before = $product->fresh()->price;
    $placeholder->delete();
    app(ProductVariantStructureService::class)->recalculateProductSummary($product);

    expect($before)->toBe(100)
        ->and($product->fresh()->price)->toBe(100);
});

it('keeps the legacy warehouse summary compatibility semantics separate from the canonical projection', function (): void {
    $product = phaseFiveSummaryProduct('750001', 777);
    phaseFiveSummaryVariant($product, '00', 0, ['stock' => 4, 'reserved' => 3, 'sales_enabled' => false]);
    phaseFiveSummaryVariant($product, '01', 250, ['stock' => 6, 'reserved' => 5, 'is_active' => false]);

    WarehouseStockService::syncProductSummaryFromVariants($product->id);

    expect($product->fresh()->stock)->toBe(10)
        ->and($product->fresh()->reserved)->toBe(0)
        ->and($product->fresh()->price)->toBe(777);
});
