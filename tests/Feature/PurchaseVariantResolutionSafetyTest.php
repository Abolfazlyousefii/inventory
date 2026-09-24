<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CanonicalBaseVariantService;
use App\Services\PurchaseVariantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function phaseFivePurchaseProduct(string $code, bool $simple = false): Product
{
    $category = Category::query()->create(['name' => 'Purchase '.$code, 'code' => substr($code, 0, 2)]);

    return Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Purchase '.$code,
        'sku' => 'PURCHASE-'.$code,
        'code' => $code,
        'stock' => 0,
        'reserved' => 0,
        'price' => 100,
        'is_sellable' => true,
        'models' => $simple ? [
            'use_models' => false,
            'model_list_ids' => [],
            'use_designs' => false,
            'design_count' => 0,
        ] : [
            'use_models' => false,
            'model_list_ids' => [],
            'use_designs' => true,
            'design_count' => 2,
        ],
    ]);
}

function phaseFivePurchaseVariant(Product $product, string $suffix, bool $active = true): ProductVariant
{
    return ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => 'Purchase variant '.$suffix,
        'variety_name' => 'Design '.$suffix,
        'variety_code' => '00'.$suffix,
        'variant_code' => $product->code.'000'.$suffix,
        'sell_price' => 100,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => $active,
        'sales_enabled' => true,
    ]);
}

it('resolves only an explicit eligible variant for a multi-variant product regardless of row order', function (): void {
    $product = phaseFivePurchaseProduct('840001');
    $second = phaseFivePurchaseVariant($product, '02');
    $first = phaseFivePurchaseVariant($product, '01');

    expect(app(PurchaseVariantResolver::class)->resolve($product, $second->id)->is($second))->toBeTrue()
        ->and(app(PurchaseVariantResolver::class)->resolve($product, $first->id)->is($first))->toBeTrue();
});

it('resolves a missing selection only to an already existing canonical base variant', function (): void {
    $product = phaseFivePurchaseProduct('850001', true);
    $base = app(CanonicalBaseVariantService::class)->createIfSafe($product)['variant'];

    expect(app(PurchaseVariantResolver::class)->resolve($product->fresh(), null)->is($base))->toBeTrue()
        ->and(app(PurchaseVariantResolver::class)->resolve($product->fresh(), 0)->is($base))->toBeTrue()
        ->and($product->variants()->count())->toBe(1);
});

it('never creates a base variant as an incidental purchase fallback', function (): void {
    $product = phaseFivePurchaseProduct('860001', true);

    try {
        app(PurchaseVariantResolver::class)->resolve($product, null);
        test()->fail('Expected missing safe base to fail.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('variant_id')
            ->and($product->variants()->count())->toBe(0);
    }
});

it('rejects missing multi-variant foreign inactive and structurally ineligible selections', function (string $case): void {
    $product = phaseFivePurchaseProduct('870001');
    $valid = phaseFivePurchaseVariant($product, '01');
    $value = match ($case) {
        'missing' => null,
        'zero' => 0,
        'foreign' => phaseFivePurchaseVariant(phaseFivePurchaseProduct('880001'), '01')->id,
        'inactive' => phaseFivePurchaseVariant($product, '02', false)->id,
        'ineligible' => ProductVariant::query()->create([
            'product_id' => $product->id,
            'variant_name' => 'Out of structure',
            'variety_name' => 'Out',
            'variety_code' => '0099',
            'variant_code' => '87000100099',
            'sell_price' => 100,
            'stock' => 0,
            'reserved' => 0,
            'is_active' => true,
            'sales_enabled' => true,
        ])->id,
    };

    app(PurchaseVariantResolver::class)->resolve($product, $value);
})->with(['missing', 'zero', 'foreign', 'inactive', 'ineligible'])->throws(ValidationException::class);
