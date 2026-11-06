<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function auditStatusFixture(bool $aggregate, bool $structural, bool $sales): array
{
    $category = Category::query()->create(['name' => 'ممیزی '.uniqid(), 'code' => uniqid('AC-')]);
    $product = Product::query()->create(['category_id' => $category->id, 'name' => 'ممیزی', 'sku' => uniqid('AP-'), 'stock' => 0, 'price' => 1000, 'is_sellable' => $aggregate]);
    $variant = ProductVariant::query()->create(['product_id' => $product->id, 'variant_name' => 'ممیزی', 'variant_code' => uniqid('AV-'), 'sell_price' => 1000, 'stock' => 0, 'reserved' => 0, 'is_active' => $structural, 'sales_enabled' => $sales]);

    return [$product, $variant];
}

it('reports all inconsistency classes in dry run without changing data', function (): void {
    [$falseAggregate, $sellable] = auditStatusFixture(false, true, true);
    [$trueAggregate, $disabled] = auditStatusFixture(true, true, false);
    [$structuralMismatch, $invalid] = auditStatusFixture(true, false, true);

    $this->artisan('product-sales-status:audit')
        ->expectsOutputToContain('Dry-run')
        ->assertSuccessful();

    expect($falseAggregate->fresh()->is_sellable)->toBeFalse()
        ->and($sellable->fresh()->sales_enabled)->toBeTrue()
        ->and($trueAggregate->fresh()->is_sellable)->toBeTrue()
        ->and($disabled->fresh()->sales_enabled)->toBeFalse()
        ->and($structuralMismatch->fresh()->is_sellable)->toBeTrue()
        ->and($invalid->fresh()->sales_enabled)->toBeTrue();
});

it('rejects --apply rather than silently modifying commercial status without documents', function (): void {
    [$product, $variant] = auditStatusFixture(true, false, true);
    $before = [$product->is_sellable, $variant->sales_enabled, $variant->is_active, $variant->stock];

    $this->artisan('product-sales-status:audit --apply')->assertFailed();

    expect([
        $product->fresh()->is_sellable,
        $variant->fresh()->sales_enabled,
        $variant->fresh()->is_active,
        $variant->fresh()->stock,
    ])->toBe($before);
});
