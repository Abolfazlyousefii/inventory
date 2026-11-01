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

it('quarantines a newly created sale-enabled variant without a selling price', function () {
    $variant = inv05Variant(inv05Product(), 0);

    expect($variant->fresh()->sales_enabled)->toBeFalse()
        ->and((int) $variant->fresh()->sell_price)->toBe(0);
});

it('disables sales if an existing priced variant is changed to zero', function () {
    $variant = inv05Variant(inv05Product(), 1_000_000);

    expect($variant->fresh()->sales_enabled)->toBeTrue();

    $variant->sell_price = 0;
    $variant->save();

    expect($variant->fresh()->sales_enabled)->toBeFalse()
        ->and((int) $variant->fresh()->sell_price)->toBe(0);
});

it('does not enable sale automatically merely because pricing was completed', function () {
    $variant = inv05Variant(inv05Product(), 0);

    $variant->sell_price = 1_000_000;
    $variant->save();

    expect($variant->fresh()->sales_enabled)->toBeFalse()
        ->and((int) $variant->fresh()->sell_price)->toBe(1_000_000);

    $variant->sales_enabled = true;
    $variant->save();
    expect($variant->fresh()->sales_enabled)->toBeTrue();
});

it('keeps an already zero-priced legacy variant unsellable when stock is later received', function () {
    $product = inv05Product();
    $variant = ProductVariant::withoutEvents(fn () => inv05Variant($product, 0));

    expect($variant->fresh()->sales_enabled)->toBeTrue();

    $variant->stock = 2;
    $variant->save();

    expect($variant->fresh()->sales_enabled)->toBeFalse()
        ->and((int) $variant->fresh()->stock)->toBe(2);
});
