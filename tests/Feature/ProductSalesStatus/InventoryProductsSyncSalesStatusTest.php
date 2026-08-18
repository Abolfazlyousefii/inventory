<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ProductSalesStatusService;
use App\Services\Sync\InventoryProductsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('syncs aggregate structural and commercial sales flags including inactive variants', function (): void {
    config()->set('services.sales_server.sync_enabled', true);
    config()->set('services.sales_server.api_url', 'https://sales.test/products/sync');
    config()->set('services.sales_server.api_token', 'test-token');
    Http::fake(['https://sales.test/*' => Http::response(['ok' => true])]);
    $category = Category::query()->create(['name' => 'همگام‌سازی', 'code' => uniqid('SC-')]);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'name' => 'کالای همگام‌سازی',
        'sku' => uniqid('SP-'),
        'stock' => 0,
        'price' => 1000,
        'is_sellable' => true,
        'inventory_to_site_synced' => false,
        'site_to_inventory_verified' => false,
    ]);
    $active = ProductVariant::query()->create(['product_id' => $product->id, 'variant_name' => 'فعال', 'variant_code' => uniqid('SV-'), 'sell_price' => 1000, 'stock' => 0, 'reserved' => 0, 'is_active' => true, 'sales_enabled' => false]);
    $inactive = ProductVariant::query()->create(['product_id' => $product->id, 'variant_name' => 'ساختاری خاموش', 'variant_code' => uniqid('SV-'), 'sell_price' => 1000, 'stock' => 0, 'reserved' => 0, 'is_active' => false, 'sales_enabled' => false]);

    app(InventoryProductsSyncService::class)->syncAll();

    Http::assertSent(function (Request $request) use ($product, $active, $inactive): bool {
        $sentProduct = collect($request['products'])->firstWhere('id', $product->id);
        $variants = collect($sentProduct['variants']);

        return $sentProduct['is_sellable'] === true
            && $variants->firstWhere('id', $active->id)['is_active'] === true
            && $variants->firstWhere('id', $active->id)['sales_enabled'] === false
            && $variants->firstWhere('id', $inactive->id)['is_active'] === false
            && $variants->firstWhere('id', $inactive->id)['sales_enabled'] === false;
    });
});

it('does not make an immediate external call when a local sales status changes', function (): void {
    Http::fake();
    $category = Category::query()->create(['name' => 'بدون ارسال', 'code' => uniqid('NC-')]);
    $product = Product::query()->create(['category_id' => $category->id, 'name' => 'بدون ارسال', 'sku' => uniqid('NP-'), 'stock' => 0, 'price' => 1000, 'is_sellable' => true]);
    $variant = ProductVariant::query()->create(['product_id' => $product->id, 'variant_name' => 'تنوع', 'variant_code' => uniqid('NV-'), 'sell_price' => 1000, 'stock' => 0, 'reserved' => 0, 'is_active' => true, 'sales_enabled' => true]);

    app(ProductSalesStatusService::class)->change($product->id, 'deactivate', 'variants', [$variant->id], 'management_decision', null, User::factory()->create());

    Http::assertNothingSent();
    expect($product->fresh()->inventory_to_site_synced)->toBeFalse();
});
