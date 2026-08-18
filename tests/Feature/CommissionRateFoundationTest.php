<?php

use App\Models\Category;
use App\Models\CommissionRateRevision;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Commissions\CommissionRateResolver;
use App\Services\Commissions\CommissionRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function commissionProductTree(): array
{
    $root = Category::query()->create(['name' => 'لوازم جانبی']);
    $child = Category::query()->create(['name' => 'گارد', 'parent_id' => $root->id]);
    $product = Product::query()->create([
        'name' => 'Guard X', 'sku' => 'COM-GUARD-X', 'category_id' => $child->id,
        'stock' => 1, 'reserved' => 0, 'price' => 1000,
    ]);
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id, 'variant_name' => 'مشکی', 'variant_code' => 'COM-BLACK',
        'is_active' => true, 'sales_enabled' => true, 'stock' => 1, 'reserved' => 0, 'sell_price' => 1000,
    ]);

    return compact('root', 'child', 'product', 'variant');
}

it('resolves nearest category product and variant overrides including explicit zero', function () {
    ['root' => $root, 'child' => $child, 'product' => $product, 'variant' => $variant] = commissionProductTree();
    $actor = User::factory()->create();
    $service = app(CommissionRateService::class);
    $resolver = app(CommissionRateResolver::class);

    $service->setRate('category', $root->id, '1', $actor, now()->subMinutes(4));
    $service->setRate('category', $child->id, '2', $actor, now()->subMinutes(3));

    $inherited = $resolver->resolve($product, $variant, now());
    expect($inherited->percentage)->toBe('2.0000')
        ->and($inherited->sourceType)->toBe('category')
        ->and($inherited->sourceId)->toBe($child->id);

    $service->setRate('product', $product->id, '3', $actor, now()->subMinutes(2));
    expect($resolver->resolve($product, $variant, now())->percentage)->toBe('3.0000');

    $service->setRate('variant', $variant->id, '3.5', $actor, now()->subMinute());
    expect($resolver->resolve($product, $variant, now())->percentage)->toBe('3.5000');

    $service->removeRate('variant', $variant->id, $actor, now());
    $service->setRate('product', $product->id, '۰', $actor, now());
    $zero = $resolver->resolve($product, $variant, now()->addSecond());
    expect($zero->percentage)->toBe('0.0000')
        ->and($zero->isExplicitZero)->toBeTrue()
        ->and($zero->isMissing)->toBeFalse();
});

it('preserves rate history and falls back after removing an override', function () {
    ['root' => $root, 'product' => $product] = commissionProductTree();
    $actor = User::factory()->create();
    $service = app(CommissionRateService::class);
    $resolver = app(CommissionRateResolver::class);
    $oldTime = now()->subDays(2);
    $newTime = now()->subDay();

    $service->setRate('category', $root->id, '2', $actor, $oldTime);
    $service->setRate('product', $product->id, '2', $actor, $oldTime);
    $service->setRate('product', $product->id, '3', $actor, $newTime);

    expect($resolver->resolve($product, null, $oldTime->copy()->addHour())->percentage)->toBe('2.0000')
        ->and($resolver->resolve($product, null, now())->percentage)->toBe('3.0000')
        ->and(CommissionRateRevision::query()->where('target_type', 'product')->where('product_id', $product->id)->count())->toBe(2);

    $service->removeRate('product', $product->id, $actor, now());
    $fallback = $resolver->resolve($product, null, now()->addSecond());
    expect($fallback->percentage)->toBe('2.0000')
        ->and($fallback->sourceType)->toBe('category')
        ->and(CommissionRateRevision::query()->where('target_type', 'product')->where('product_id', $product->id)->count())->toBe(2);
});

it('distinguishes a missing rate and validates percentage bounds', function () {
    ['product' => $product] = commissionProductTree();
    $missing = app(CommissionRateResolver::class)->resolve($product);

    expect($missing->percentage)->toBe('0.0000')
        ->and($missing->isMissing)->toBeTrue()
        ->and($missing->isExplicitZero)->toBeFalse();

    expect(fn () => app(CommissionRateService::class)->setRate('product', $product->id, '100.01', User::factory()->create()))
        ->toThrow(ValidationException::class);
});
