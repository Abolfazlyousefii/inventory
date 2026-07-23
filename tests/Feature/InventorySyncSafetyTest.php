<?php

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use App\Services\InventorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

function createInventorySyncSafetyProduct(): array
{
    $categoryId = DB::table('categories')->insertGetId([
        'name' => 'Sync Safety Category',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $product = Product::query()->create([
        'category_id' => $categoryId,
        'name' => 'Sync Safety Product',
        'sku' => 'SYNC-SAFETY-001',
        'code' => 'SYNC-SAFETY-CODE',
        'short_barcode' => 'SYNC001',
        'stock' => 9,
        'reserved' => 2,
        'price' => 123000,
        'is_sellable' => true,
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => 'Default',
        'variant_code' => 'SYNC-SAFETY-VAR-001',
        'variety_name' => 'Default Variety',
        'variety_code' => 'DEFAULT',
        'sell_price' => 123000,
        'buy_price' => 100000,
        'stock' => 9,
        'reserved' => 2,
        'is_active' => true,
        'sales_enabled' => true,
    ]);

    WarehouseStock::query()->create([
        'warehouse_id' => DB::table('warehouses')->value('id'),
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'quantity' => 9,
    ]);

    return [$product, $variant];
}

function inventorySyncTableSnapshot(): array
{
    return [
        'products' => DB::table('products')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'product_variants' => DB::table('product_variants')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'warehouse_stocks' => DB::table('warehouse_stocks')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
    ];
}

it('does not register the public test route', function (): void {
    $testRoute = collect(Route::getRoutes())->first(fn ($route) => in_array('GET', $route->methods(), true)
        && trim($route->uri(), '/') === 'test');

    expect($testRoute)->toBeNull();
});

it('skips sync and sends no HTTP requests when sync is disabled', function (): void {
    Http::fake();
    createInventorySyncSafetyProduct();
    $before = inventorySyncTableSnapshot();

    config()->set('services.sales_server.sync_enabled', false);
    config()->set('services.sales_server.api_url', 'https://sales.example.test/inventory');
    config()->set('services.sales_server.api_token', 'test-token');

    $result = app(InventorySyncService::class)->syncAll();

    expect($result)->toBe([
        'skipped' => true,
        'reason' => 'inventory_sync_disabled_or_not_configured',
    ]);
    Http::assertNothingSent();
    expect(inventorySyncTableSnapshot())->toBe($before);
});

it('skips sync and sends no HTTP requests when URL or token is missing', function (?string $apiUrl, ?string $apiToken): void {
    Http::fake();
    createInventorySyncSafetyProduct();
    $before = inventorySyncTableSnapshot();

    config()->set('services.sales_server.sync_enabled', true);
    config()->set('services.sales_server.api_url', $apiUrl);
    config()->set('services.sales_server.api_token', $apiToken);

    $result = app(InventorySyncService::class)->syncAll();

    expect($result)->toBe([
        'skipped' => true,
        'reason' => 'inventory_sync_disabled_or_not_configured',
    ]);
    Http::assertNothingSent();
    expect(inventorySyncTableSnapshot())->toBe($before);
})->with([
    'missing URL' => [null, 'test-token'],
    'empty URL' => ['', 'test-token'],
    'missing token' => ['https://sales.example.test/inventory', null],
    'empty token' => ['https://sales.example.test/inventory', ''],
]);

it('sends HTTP with a fake client when sync is enabled and configured without changing local inventory data', function (): void {
    Http::fake([
        'sales.example.test/*' => Http::response(['ok' => true], 200),
    ]);
    createInventorySyncSafetyProduct();
    $before = inventorySyncTableSnapshot();

    config()->set('services.sales_server.sync_enabled', true);
    config()->set('services.sales_server.api_url', 'https://sales.example.test/inventory');
    config()->set('services.sales_server.api_token', 'test-token');

    $result = app(InventorySyncService::class)
        ->setDelayBetweenChunks(0)
        ->syncAll();

    expect($result['total_chunks'])->toBe(1)
        ->and($result['success_count'])->toBe(1)
        ->and($result['failed_chunks'])->toBe([]);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://sales.example.test/inventory'
        && $request->hasHeader('Authorization', 'Bearer test-token')
        && isset($request['products'][0]['variants'][0]['stock']));
    expect(inventorySyncTableSnapshot())->toBe($before);
});
