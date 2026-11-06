<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function inv05Product(): Product
{
    $category = Category::query()->create(['name' => 'INV05 '.uniqid()]);

    return Product::query()->create([
        'category_id' => $category->id,
        'name' => 'INV05 zero-price guard',
        'sku' => 'INV05-'.uniqid(),
        'price' => 0,
        'stock' => 0,
        'reserved' => 0,
        'is_sellable' => true,
    ]);
}

function inv05Variant(Product $product, int $price): ProductVariant
{
    return ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => 'INV05 variant',
        'variant_code' => 'INV05-'.uniqid(),
        'is_active' => true,
        'sales_enabled' => true,
        'sell_price' => $price,
        'stock' => 0,
        'reserved' => 0,
    ]);
}

it('preserves an explicitly enabled sales flag when a new variant has no price', function () {
    $variant = inv05Variant(inv05Product(), 0);

    expect($variant->fresh()->sales_enabled)->toBeTrue()
        ->and((int) $variant->fresh()->sell_price)->toBe(0);
});

it('does not secretly disable a variant when its sale price changes to zero', function () {
    $variant = inv05Variant(inv05Product(), 1_000_000);

    $variant->update(['sell_price' => 0]);

    expect($variant->fresh()->sales_enabled)->toBeTrue()
        ->and((int) $variant->fresh()->sell_price)->toBe(0);
});

it('does not override an explicit sales deactivation when pricing is completed', function () {
    $variant = inv05Variant(inv05Product(), 0);
    $variant->update(['sales_enabled' => false]);

    $variant->update(['sell_price' => 1_000_000]);

    expect($variant->fresh()->sales_enabled)->toBeFalse()
        ->and((int) $variant->fresh()->sell_price)->toBe(1_000_000);
});

it('does not alter the manual sales flag when stock arrives for an unpriced variant', function () {
    $variant = inv05Variant(inv05Product(), 0);

    $variant->update(['stock' => 2]);

    expect($variant->fresh()->sales_enabled)->toBeTrue()
        ->and((int) $variant->fresh()->stock)->toBe(2);
});
