<?php

use App\Http\Controllers\ProductController;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

function phaseFiveElectricalCategory(): Category
{
    $root = Category::query()->create([
        'name' => 'برقیجات',
        'code' => '51',
    ]);

    return Category::query()->create([
        'name' => 'Phase 5 electrical child',
        'code' => '52',
        'parent_id' => $root->id,
    ]);
}

function phaseFiveProductUpdate(Product $product, array $overrides = []): void
{
    $request = Request::create(route('products.update', $product), 'PUT', array_merge([
        'category_id' => $product->category_id,
        'name' => $product->name,
        'use_models' => false,
        'use_designs' => false,
        'sell_price' => 125,
        'buy_price' => 75,
    ], $overrides));

    app(ProductController::class)->update($request, $product);
}

it('does not create implicit black or white variants when an electrical product is created', function (): void {
    $category = phaseFiveElectricalCategory();

    $request = Request::create(route('products.store'), 'POST', [
        'category_id' => $category->id,
        'name' => 'Electrical product',
        'use_models' => false,
        'use_designs' => false,
        'sell_price' => 125,
        'buy_price' => 75,
        'is_sellable' => true,
    ]);

    app(ProductController::class)->store($request);

    $product = Product::query()->where('name', 'Electrical product')->firstOrFail();

    expect($product->variants()->count())->toBe(1)
        ->and($product->variants()->whereIn('variety_name', ['مشکی', 'سفید'])->count())->toBe(0)
        ->and(WarehouseStock::query()->where('product_id', $product->id)->count())->toBe(0)
        ->and(ActivityLog::query()->where('action', 'electric_default_color_created')->count())->toBe(0);
});

it('does not create implicit colors on category change or repeated ordinary edits', function (): void {
    $ordinary = Category::query()->create(['name' => 'Ordinary', 'code' => '53']);
    $electrical = phaseFiveElectricalCategory();
    $product = Product::query()->create([
        'category_id' => $ordinary->id,
        'name' => 'Existing product',
        'sku' => 'PHASE5-EDIT',
        'code' => '530001',
        'short_barcode' => '0001',
        'stock' => 0,
        'reserved' => 0,
        'price' => 125,
        'is_sellable' => true,
        'models' => [],
    ]);

    phaseFiveProductUpdate($product, ['category_id' => $electrical->id]);
    phaseFiveProductUpdate($product->fresh(), ['name' => 'Existing product renamed']);
    phaseFiveProductUpdate($product->fresh(), ['sell_price' => 130]);

    expect($product->variants()->count())->toBe(1)
        ->and($product->variants()->whereIn('variety_name', ['مشکی', 'سفید'])->count())->toBe(0)
        ->and(WarehouseStock::query()->where('product_id', $product->id)->count())->toBe(0)
        ->and(ActivityLog::query()->where('action', 'electric_default_color_created')->count())->toBe(0);
});
