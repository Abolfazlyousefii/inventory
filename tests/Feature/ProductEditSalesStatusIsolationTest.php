<?php

use App\Http\Controllers\ProductController;
use App\Models\Category;
use App\Models\ModelList;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

function phaseFiveSalesState(Product $product, array $variantIds = []): array
{
    return [
        'product' => (bool) $product->fresh()->is_sellable,
        'variants' => $product->variants()
            ->when($variantIds !== [], fn ($query) => $query->whereKey($variantIds))
            ->orderBy('id')
            ->get(['id', 'is_active', 'sales_enabled'])
            ->map(fn (ProductVariant $variant) => [
                'id' => (int) $variant->id,
                'is_active' => (bool) $variant->is_active,
                'sales_enabled' => (bool) $variant->sales_enabled,
            ])->all(),
    ];
}

it('preserves sales state during ordinary product edits', function (array $change): void {
    $category = Category::query()->create(['name' => 'Status isolation', 'code' => '61']);
    $otherCategory = Category::query()->create(['name' => 'Status isolation other', 'code' => '62']);
    $model = ModelList::query()->create(['brand' => 'Phase 5', 'model_name' => 'Legacy', 'code' => '901']);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Status product',
        'sku' => uniqid('STATUS-'),
        'code' => '610001',
        'short_barcode' => '0001',
        'stock' => 0,
        'reserved' => 0,
        'price' => 500,
        'is_sellable' => true,
        'models' => [
            'use_models' => true,
            'model_list_ids' => [$model->id],
            'use_designs' => false,
            'design_count' => 0,
        ],
    ]);
    ProductVariant::query()->create([
        'product_id' => $product->id,
        'model_list_id' => $model->id,
        'variant_name' => 'Historical model',
        'variety_name' => '—',
        'variety_code' => '0000',
        'variant_code' => '61000190100',
        'sell_price' => 500,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ]);

    $originalVariantIds = $product->variants()->pluck('id')->map(fn ($id) => (int) $id)->all();
    $before = phaseFiveSalesState($product, $originalVariantIds);
    $payload = array_merge([
        'category_id' => $category->id,
        'name' => $product->name,
        'use_models' => false,
        'use_designs' => false,
        'sell_price' => 500,
        'buy_price' => 300,
    ], $change === ['category' => true]
        ? ['category_id' => $otherCategory->id]
        : $change);

    app(ProductController::class)->update(
        Request::create(route('products.update', $product), 'PUT', $payload),
        $product,
    );

    expect(phaseFiveSalesState($product, $originalVariantIds))->toBe($before);
})->with([
    'name' => [['name' => 'Status product renamed']],
    'category' => [['category' => true]],
    'price' => [['sell_price' => 750]],
    'structure metadata' => [['design_count' => 2, 'use_designs' => true]],
]);

it('preserves an explicitly submitted inactive variant during ordinary edits', function (): void {
    $category = Category::query()->create(['name' => 'Explicit inactive', 'code' => '63']);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Explicit inactive product',
        'sku' => uniqid('EXPLICIT-INACTIVE-'),
        'code' => '630001',
        'short_barcode' => '0001',
        'stock' => 0,
        'reserved' => 0,
        'price' => 500,
        'is_sellable' => false,
        'models' => ['use_models' => false, 'model_list_ids' => [], 'use_designs' => false, 'design_count' => 0],
    ]);
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'model_list_id' => null,
        'variant_name' => 'Explicit inactive variant',
        'variety_name' => '—',
        'variety_code' => '0000',
        'variant_code' => '63000100000',
        'sell_price' => 500,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => false,
        'sales_enabled' => false,
    ]);

    app(ProductController::class)->update(Request::create(route('products.update', $product), 'PUT', [
        'category_id' => $category->id,
        'name' => 'Explicit inactive renamed',
        'use_models' => false,
        'use_designs' => false,
        'sell_price' => 700,
        'variants' => [[
            'id' => $variant->id,
            'variant_name' => 'Explicit inactive renamed variant',
            'variety_name' => '—',
            'variety_code' => '0000',
            'sell_price' => 700,
        ]],
    ]), $product);

    expect($variant->fresh()->is_active)->toBeFalse()
        ->and($variant->fresh()->sales_enabled)->toBeFalse()
        ->and($product->fresh()->is_sellable)->toBeFalse();
});

it('preserves an inactive variant matched by structural synchronization', function (): void {
    $category = Category::query()->create(['name' => 'Structural inactive', 'code' => '64']);
    $otherCategory = Category::query()->create(['name' => 'Structural inactive other', 'code' => '65']);
    $model = ModelList::query()->create(['brand' => 'Phase 5', 'model_name' => 'Inactive model', 'code' => '902']);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Structural inactive product',
        'sku' => uniqid('STRUCTURAL-INACTIVE-'),
        'code' => '640001',
        'short_barcode' => '0001',
        'stock' => 0,
        'reserved' => 0,
        'price' => 500,
        'is_sellable' => false,
        'models' => ['use_models' => true, 'model_list_ids' => [$model->id], 'use_designs' => false, 'design_count' => 0],
    ]);
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'model_list_id' => $model->id,
        'variant_name' => 'Structural inactive variant',
        'variety_name' => '—',
        'variety_code' => '0000',
        'variant_code' => '64000190200',
        'sell_price' => 500,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => false,
        'sales_enabled' => false,
    ]);

    app(ProductController::class)->update(Request::create(route('products.update', $product), 'PUT', [
        'category_id' => $otherCategory->id,
        'name' => 'Structural inactive renamed',
        'use_models' => true,
        'model_list_ids' => [$model->id],
        'use_designs' => false,
        'sell_price' => 750,
        'buy_price' => 300,
    ]), $product);

    expect($variant->fresh()->is_active)->toBeFalse()
        ->and($variant->fresh()->sales_enabled)->toBeFalse()
        ->and($product->fresh()->is_sellable)->toBeFalse();
});
