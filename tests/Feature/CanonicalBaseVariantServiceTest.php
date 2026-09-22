<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use App\Services\CanonicalBaseVariantService;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\PurchaseVariantResolver;

uses(RefreshDatabase::class);

function phaseFiveSimpleProduct(array $attributes = []): Product
{
    $category = Category::query()->create(['name' => 'Base '.uniqid(), 'code' => uniqid('B')]);

    return Product::query()->create(array_merge([
        'category_id' => $category->id,
        'name' => 'Simple product',
        'sku' => uniqid('BASE-'),
        'code' => '830001',
        'stock' => 0,
        'reserved' => 0,
        'price' => 250,
        'is_sellable' => true,
        'has_colors' => false,
        'models' => [
            'use_models' => false,
            'model_list_ids' => [],
            'use_designs' => false,
            'design_count' => 0,
        ],
    ], $attributes));
}

it('creates one deterministic base variant and is idempotent', function (): void {
    $product = phaseFiveSimpleProduct();
    $service = app(CanonicalBaseVariantService::class);
    $beforeStocks = DB::table('warehouse_stocks')->get()->toJson();

    $first = $service->createIfSafe($product);
    $second = $service->createIfSafe($product->fresh());
    $variant = $first['variant']->fresh();

    expect($first['state'])->toBe(CanonicalBaseVariantService::CREATED)
        ->and($first['created'])->toBeTrue()
        ->and($second['state'])->toBe(CanonicalBaseVariantService::AVAILABLE)
        ->and($second['created'])->toBeFalse()
        ->and($product->variants()->count())->toBe(1)
        ->and($variant->model_list_id)->toBeNull()
        ->and($variant->variety_code)->toBe('0000')
        ->and($variant->variant_code)->toBe('83000100000')
        ->and($variant->is_active)->toBeTrue()
        ->and($variant->sales_enabled)->toBeTrue()
        ->and($variant->sell_price)->toBe(250)
        ->and($variant->buy_price)->toBeNull()
        ->and($variant->stock)->toBe(0)
        ->and($variant->reserved)->toBe(0)
        ->and(DB::table('warehouse_stocks')->get()->toJson())->toBe($beforeStocks);
});

it('does not invent a price when the same product has no positive price', function (): void {
    $result = app(CanonicalBaseVariantService::class)->createIfSafe(phaseFiveSimpleProduct(['price' => 0, 'is_sellable' => false]));

    expect($result['variant']->sell_price)->toBe(0)
        ->and($result['variant']->sales_enabled)->toBeFalse();
});

it('blocks even a zero product-level warehouse row because ownership is ambiguous', function (): void {
    $product = phaseFiveSimpleProduct();
    WarehouseStock::query()->create([
        'warehouse_id' => WarehouseStockService::centralWarehouseId(),
        'product_id' => $product->id,
        'product_variant_id' => null,
        'quantity' => 0,
    ]);

    $result = app(CanonicalBaseVariantService::class)->createIfSafe($product);

    expect($result['state'])->toBe(CanonicalBaseVariantService::BLOCKED_FOR_REVIEW)
        ->and($result['blocking_reasons'])->toContain('ambiguous_product_level_warehouse_row')
        ->and($product->variants()->count())->toBe(0);
});

it('blocks variant-owned stock or business history rather than reassigning it', function (string $case): void {
    $product = phaseFiveSimpleProduct();
    $legacy = ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => 'Legacy',
        'variety_name' => 'Legacy',
        'variety_code' => '0001',
        'variant_code' => '83000100001',
        'sell_price' => 0,
        'stock' => $case === 'stock' ? 1 : 0,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ]);
    if ($case === 'mapping') {
        $legacy->forceFill(['variety_id' => 123])->save();
    }

    $result = app(CanonicalBaseVariantService::class)->createIfSafe($product);

    expect($result['state'])->toBe(CanonicalBaseVariantService::BLOCKED_FOR_REVIEW)
        ->and($product->variants()->where('variant_code', '83000100000')->exists())->toBeFalse();
})->with(['stock', 'mapping']);

it('returns not simple for model design or color products', function (array $attributes): void {
    $product = phaseFiveSimpleProduct($attributes);

    expect(app(CanonicalBaseVariantService::class)->inspect($product)['state'])
        ->toBe(CanonicalBaseVariantService::NOT_SIMPLE);
})->with([
    'models' => [['models' => ['use_models' => true, 'model_list_ids' => [99], 'use_designs' => false, 'design_count' => 0]]],
    'designs' => [['models' => ['use_models' => false, 'model_list_ids' => [], 'use_designs' => true, 'design_count' => 2]]],
    'colors' => [['has_colors' => true]],
]);

it('blocks an inactive canonical base variant without reactivating it', function (): void {
    $product = phaseFiveSimpleProduct(['code' => '830002']);
    $base = ProductVariant::query()->create([
        'product_id' => $product->id,
        'model_list_id' => null,
        'variant_name' => 'Inactive base',
        'variety_name' => '—',
        'variety_code' => '0000',
        'variant_code' => '83000200000',
        'sell_price' => 250,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => false,
        'sales_enabled' => true,
    ]);

    $inspection = app(CanonicalBaseVariantService::class)->inspect($product);

    expect($inspection['state'])->toBe(CanonicalBaseVariantService::BLOCKED_FOR_REVIEW)
        ->and($inspection['blocking_reasons'])->toContain('inactive_base_variant');

    expect(fn () => app(PurchaseVariantResolver::class)->resolve($product, null))
        ->toThrow(ValidationException::class);

    expect($base->fresh()->is_active)->toBeFalse();
});
