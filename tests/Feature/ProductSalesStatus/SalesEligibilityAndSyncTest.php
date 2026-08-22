<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\PreinvoiceProductFinderService;
use App\Services\ProductSalesStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('services.sales_server.sync_enabled', false);
});

function eligibilityFixture(bool $productEnabled = true, bool $structural = true, bool $sales = true): array
{
    $category = Category::query()->create(['name' => 'فروش '.uniqid(), 'code' => uniqid('EC-')]);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'name' => 'محصول واجد شرایط '.uniqid(),
        'code' => uniqid('EP-'),
        'sku' => uniqid('ES-'),
        'stock' => 8,
        'reserved' => 2,
        'price' => 1000,
        'is_sellable' => $productEnabled,
        'inventory_to_site_synced' => true,
        'site_to_inventory_verified' => true,
    ]);
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => 'تنوع واجد شرایط',
        'variant_code' => uniqid('EV-'),
        'sell_price' => 1000,
        'stock' => 8,
        'reserved' => 2,
        'is_active' => $structural,
        'sales_enabled' => $sales,
    ]);
    Product::query()->whereKey($product->id)->update([
        'inventory_to_site_synced' => true,
        'site_to_inventory_verified' => true,
    ]);

    return [$product->fresh(), $variant->fresh()];
}

it('excludes product-disabled sales-disabled and structurally inactive targets from new preinvoice search', function (): void {
    [$productOff] = eligibilityFixture(false, true, true);
    [$variantOffProduct] = eligibilityFixture(true, true, false);
    [$structuralOffProduct] = eligibilityFixture(true, false, true);
    [$eligibleProduct] = eligibilityFixture(true, true, true);
    $service = app(PreinvoiceProductFinderService::class);

    foreach ([$productOff, $variantOffProduct, $structuralOffProduct] as $blocked) {
        $result = $service->search(['q' => $blocked->code, 'in_stock_only' => true]);
        expect(collect($result['data'])->pluck('id'))->not->toContain($blocked->id);
    }
    $result = $service->search(['q' => $eligibleProduct->code, 'in_stock_only' => true]);
    expect(collect($result['data'])->pluck('id'))->toContain($eligibleProduct->id);
});

it('makes a reactivated variant eligible again without changing inventory or reservations', function (): void {
    [$product, $variant] = eligibilityFixture(true, true, true);
    $before = [$product->stock, $product->reserved, $variant->stock, $variant->reserved];
    $service = app(ProductSalesStatusService::class);
    $actor = User::factory()->create();
    $service->change($product->id, 'deactivate', 'variants', [$variant->id], 'management_decision', null, $actor);
    expect(app(PreinvoiceProductFinderService::class)->search(['q' => $product->code, 'in_stock_only' => true])['data'])->toBeEmpty();

    $service->change($product->id, 'activate', 'variants', [$variant->id], 'restocked', null, $actor);
    $result = app(PreinvoiceProductFinderService::class)->search(['q' => $product->code, 'in_stock_only' => true]);

    expect(collect($result['data'])->pluck('id'))->toContain($product->id)
        ->and([$product->fresh()->stock, $product->fresh()->reserved, $variant->fresh()->stock, $variant->fresh()->reserved])->toBe($before);
});

it('marks site synchronization pending for both variant and aggregate status changes', function (): void {
    [$product, $variant] = eligibilityFixture();

    app(ProductSalesStatusService::class)->change(
        $product->id, 'deactivate', 'variants', [$variant->id], 'management_decision', null, User::factory()->create(),
    );

    expect($product->fresh()->inventory_to_site_synced)->toBeFalse()
        ->and($product->fresh()->site_to_inventory_verified)->toBeFalse()
        ->and($variant->fresh()->is_active)->toBeTrue();
});
