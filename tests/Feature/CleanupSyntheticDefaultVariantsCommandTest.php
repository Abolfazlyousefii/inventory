<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function phaseFiveCleanupCommandFixture(bool $proven): ProductVariant
{
    $root = Category::query()->firstOrCreate(['name' => 'برقیجات'], ['code' => uniqid('CC')]);
    $productCode = '90'.str_pad((string) (Product::query()->count() + 1), 4, '0', STR_PAD_LEFT);
    $product = Product::query()->create([
        'category_id' => $root->id,
        'name' => 'Cleanup command product',
        'sku' => uniqid('CLEAN-CMD-'),
        'code' => $productCode,
        'stock' => 0,
        'reserved' => 0,
        'price' => 100,
        'is_sellable' => true,
        'models' => ['use_models' => false, 'model_list_ids' => [], 'use_designs' => false, 'design_count' => 0],
    ]);
    ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => 'Base',
        'variety_name' => '—',
        'variety_code' => '0000',
        'variant_code' => $productCode.'00000',
        'sell_price' => 100,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ]);
    $synthetic = ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => $product->name.' مشکی',
        'variety_name' => 'مشکی',
        'variety_code' => '0001',
        'variant_code' => $productCode.'00001',
        'sell_price' => 0,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ]);
    WarehouseStock::query()->create([
        'warehouse_id' => WarehouseStockService::centralWarehouseId(),
        'product_id' => $product->id,
        'product_variant_id' => $synthetic->id,
        'quantity' => 0,
    ]);
    if ($proven) {
        ActivityLog::query()->create([
            'action' => 'electric_default_color_created',
            'subject_type' => Product::class,
            'subject_id' => $product->id,
            'description' => 'proof',
            'properties' => ['product_id' => (int) $product->id, 'variant_id' => (int) $synthetic->id],
            'occurred_at' => now(),
        ]);
    }

    return $synthetic;
}

it('requires exactly one selector and both apply confirmation flags', function (): void {
    $this->artisan('inventory:cleanup-synthetic-default-variants')->assertFailed();
    $this->artisan('inventory:cleanup-synthetic-default-variants --ids=1 --all-safe')->assertFailed();
    $this->artisan('inventory:cleanup-synthetic-default-variants --ids=1 --apply')->assertFailed();
    $this->artisan('inventory:cleanup-synthetic-default-variants --ids=1 --confirm')->assertFailed();
});

it('is dry run by default and all-safe excludes probable candidates', function (): void {
    $proven = phaseFiveCleanupCommandFixture(true);
    $probable = phaseFiveCleanupCommandFixture(false);

    $this->artisan('inventory:cleanup-synthetic-default-variants --all-safe')
        ->expectsOutputToContain((string) $proven->id)
        ->expectsOutputToContain('ELIGIBLE')
        ->assertSuccessful();

    expect(ProductVariant::query()->whereKey($proven->id)->exists())->toBeTrue()
        ->and(ProductVariant::query()->whereKey($probable->id)->exists())->toBeTrue();
});

it('allows an explicitly reviewed probable id but still revalidates safety', function (): void {
    $probable = phaseFiveCleanupCommandFixture(false);

    $this->artisan('inventory:cleanup-synthetic-default-variants --ids='.$probable->id.' --apply --confirm')
        ->expectsOutputToContain('DELETED')
        ->assertSuccessful();

    expect(ProductVariant::query()->whereKey($probable->id)->exists())->toBeFalse();
});
