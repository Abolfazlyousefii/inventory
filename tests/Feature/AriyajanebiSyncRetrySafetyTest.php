<?php

use App\Models\Category;
use App\Models\InventoryWebhookLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use App\Models\Warehouse;
use App\Services\AutomaticBundleAvailabilityService;
use App\Services\AriyajanebiSyncService;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('services.ariyajanebi_sync', [
        'username' => 'inv04-test-username',
        'password' => 'inv04-test-password',
    ]);
});

/** @return array{0: ProductVariant, 1: WarehouseStock} */
function inv04VariantFixture(): array
{
    $category = Category::query()->create(['name' => 'INV04 '.uniqid()]);
    $product = Product::withoutEvents(fn () => Product::query()->create([
        'category_id' => $category->id,
        'name' => 'INV04 test product',
        'sku' => 'INV04-'.uniqid(),
        'price' => 2_000,
        'stock' => 20,
        'reserved' => 0,
    ]));

    $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => 'INV04 variant',
        'variant_code' => 'INV04-'.uniqid(),
        'variety_id' => 998877,
        'sell_price' => 2_000,
        'stock' => 20,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ]));

    $stock = WarehouseStock::withoutEvents(fn () => WarehouseStock::query()->create([
        'warehouse_id' => WarehouseStockService::centralWarehouseId(),
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'quantity' => 20,
    ]));

    return [$variant, $stock];
}

function inv04LegacyLog(ProductVariant $variant, int $oldBalance = 20, int $oldPrice = 2_000): InventoryWebhookLog
{
    return InventoryWebhookLog::query()->create([
        'event' => 'ariya.multi_varieties_update_lite',
        'status' => 'pending',
        'attempts' => 1,
        'next_retry_at' => now()->subMinute(),
        'payload' => [
            'payload' => [
                '_method' => 'PUT',
                'varieties[0][id]' => (string) $variant->variety_id,
                'varieties[0][price]' => (string) $oldPrice,
                'varieties[0][balance]' => (string) $oldBalance,
            ],
            'variants' => [
                ['id' => (string) $variant->variety_id, 'variant_id' => $variant->id],
            ],
        ],
    ]);
}

function inv04FakeApi(array &$sent, int $updateStatus = 200): void
{
    Http::fake(function (Request $request) use (&$sent, $updateStatus) {
        if (str_ends_with($request->url(), '/login')) {
            return Http::response(['token' => 'inv04-test-token'], 200);
        }
        $sent[] = $request->data();
        return Http::response(['ok' => $updateStatus === 200], $updateStatus);
    });
}

it('retries with central stock 5 and live price 1000 instead of saved stock 20 and price 2000', function (): void {
    [$variant, $stock] = inv04VariantFixture();
    $log = inv04LegacyLog($variant);
    DB::table('product_variants')->where('id', $variant->id)->update(['sell_price' => 1_000]);
    DB::table('warehouse_stocks')->where('id', $stock->id)->update(['quantity' => 5]);
    // This cache is intentionally stale: the canonical stock is the central row.
    DB::table('product_variants')->where('id', $variant->id)->update(['stock' => 20]);

    $sent = [];
    inv04FakeApi($sent);
    AriyajanebiSyncService::processPending();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['varieties[0][id]'])->toBe((string) $variant->variety_id)
        ->and($sent[0]['varieties[0][balance]'])->toBe('5')
        ->and($sent[0]['varieties[0][price]'])->toBe('1000')
        ->and($log->fresh()->status)->toBe('success')
        ->and($log->fresh()->payload['payload']['varieties[0][balance]'])->toBe('5');
});

it('syncs automatic bundle stock from protected component availability rather than a bundle warehouse row', function (): void {
    [$variant, $stock] = inv04VariantFixture();
    DB::table('products')->where('id', $variant->product_id)->update([
        'is_bundle' => true,
        'bundle_stock_mode' => 'automatic',
    ]);
    // The warehouse row is deliberately unrelated to the three kits that can
    // actually be fulfilled from their protected component allocations.
    DB::table('warehouse_stocks')->where('id', $stock->id)->update(['quantity' => 20]);
    $this->mock(AutomaticBundleAvailabilityService::class)
        ->shouldReceive('available')->once()->with((int) $variant->id)->andReturn(3);
    inv04LegacyLog($variant);

    $sent = [];
    inv04FakeApi($sent);
    AriyajanebiSyncService::processPending();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['varieties[0][balance]'])->toBe('3')
        ->and($sent[0]['varieties[0][price]'])->toBe('2000');
});

it('replays duplicate and older pending logs using the same current values', function (): void {
    [$variant, $stock] = inv04VariantFixture();
    inv04LegacyLog($variant, 20, 2_000);
    inv04LegacyLog($variant, 19, 1_900);
    DB::table('product_variants')->where('id', $variant->id)->update(['sell_price' => 1_000]);
    DB::table('warehouse_stocks')->where('id', $stock->id)->update(['quantity' => 5]);

    $sent = [];
    inv04FakeApi($sent);
    AriyajanebiSyncService::processPending();

    expect($sent)->toHaveCount(2);
    foreach ($sent as $payload) {
        expect($payload['varieties[0][price]'])->toBe('1000')
            ->and($payload['varieties[0][balance]'])->toBe('5');
    }
    expect(InventoryWebhookLog::query()->where('status', 'success')->count())->toBe(2);
});

it('keeps a failed delivery pending without altering a committed inventory change', function (): void {
    [$variant, $stock] = inv04VariantFixture();
    $log = inv04LegacyLog($variant);
    DB::table('warehouse_stocks')->where('id', $stock->id)->update(['quantity' => 5]);

    // Use a single HTTP fake for both attempts. Calling Http::fake() again
    // inside this test can retain the earlier 503 callback and replay it.
    $sent = [];
    $updateStatus = 503;
    Http::fake(function (Request $request) use (&$sent, &$updateStatus) {
        if (str_ends_with($request->url(), '/login')) {
            return Http::response(['token' => 'inv04-test-token'], 200);
        }

        $sent[] = $request->data();

        return Http::response(['ok' => $updateStatus === 200], $updateStatus);
    });
    AriyajanebiSyncService::processPending();

    expect($sent)->toHaveCount(1);
    expect($log->fresh()->status)->toBe('pending')
        ->and($log->fresh()->response_code)->toBe(503)
        ->and($log->fresh()->error_message)->toBe('update HTTP failure')
        ->and((int) $stock->fresh()->quantity)->toBe(5);

    DB::table('inventory_webhook_logs')->where('id', $log->id)
        ->update(['next_retry_at' => now()->subMinute()]);
    $sent = [];
    $updateStatus = 200;
    AriyajanebiSyncService::processPending();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['varieties[0][balance]'])->toBe('5')
        ->and($log->fresh()->status)->toBe('success')
        ->and((int) $stock->fresh()->quantity)->toBe(5);
});

it('never replays stored prices or balances for a deleted or remapped variant', function (): void {
    [$variant] = inv04VariantFixture();
    $log = inv04LegacyLog($variant);
    DB::table('product_variants')->where('id', $variant->id)->update(['variety_id' => 112233]);

    $sent = [];
    inv04FakeApi($sent);
    AriyajanebiSyncService::processPending();

    expect($sent)->toBe([])
        ->and($log->fresh()->status)->toBe('skipped')
        ->and($log->fresh()->next_retry_at)->toBeNull();
});

it('resolves old payloads without variant metadata through a unique current remote id', function (): void {
    [$variant, $stock] = inv04VariantFixture();
    $log = inv04LegacyLog($variant);
    $stored = $log->payload;
    unset($stored['variants']);
    $log->update(['payload' => $stored]);
    DB::table('product_variants')->where('id', $variant->id)->update(['sell_price' => 1_000]);
    DB::table('warehouse_stocks')->where('id', $stock->id)->update(['quantity' => 5]);

    $sent = [];
    inv04FakeApi($sent);
    AriyajanebiSyncService::processPending();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['varieties[0][price]'])->toBe('1000')
        ->and($sent[0]['varieties[0][balance]'])->toBe('5');
});

it('reads the canonical snapshot after login rather than before authentication', function (): void {
    [$variant, $stock] = inv04VariantFixture();
    inv04LegacyLog($variant);
    $sent = [];
    Http::fake(function (Request $request) use (&$sent, $variant, $stock) {
        if (str_ends_with($request->url(), '/login')) {
            DB::table('product_variants')->where('id', $variant->id)->update(['sell_price' => 1_000]);
            DB::table('warehouse_stocks')->where('id', $stock->id)->update(['quantity' => 5]);
            return Http::response(['token' => 'inv04-test-token'], 200);
        }
        $sent[] = $request->data();
        return Http::response(['ok' => true], 200);
    });

    AriyajanebiSyncService::processPending();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['varieties[0][price]'])->toBe('1000')
        ->and($sent[0]['varieties[0][balance]'])->toBe('5');
});

it('uses zero when the central warehouse has no current row even if cached stock is positive', function (): void {
    [$variant, $stock] = inv04VariantFixture();
    inv04LegacyLog($variant);
    DB::table('warehouse_stocks')->where('id', $stock->id)->delete();

    $sent = [];
    inv04FakeApi($sent);
    AriyajanebiSyncService::processPending();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['varieties[0][balance]'])->toBe('0');
});


it('excludes noncentral stock unless the site warehouse is explicitly allowed', function (): void {
    [$variant, $centralStock] = inv04VariantFixture();
    $outside = Warehouse::query()->create([
        'name' => 'Returns for sync scope', 'type' => 'return', 'is_active' => true,
    ]);
    WarehouseStock::withoutEvents(fn () => WarehouseStock::query()->create([
        'warehouse_id' => $outside->id, 'product_id' => $variant->product_id,
        'product_variant_id' => $variant->id, 'quantity' => 90,
    ]));
    $centralStock->update(['quantity' => 5]);
    inv04LegacyLog($variant);
    $sent = [];
    inv04FakeApi($sent);
    AriyajanebiSyncService::processPending();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['varieties[0][balance]'])->toBe('5');

    config()->set('inventory.site_sellable_warehouse_ids', [
        WarehouseStockService::centralWarehouseId(), $outside->id,
    ]);
    inv04LegacyLog($variant);
    $sent = [];
    AriyajanebiSyncService::processPending();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['varieties[0][balance]'])->toBe('95');
});

it('fails closed if a configured sellable warehouse has become inactive', function (): void {
    [$variant] = inv04VariantFixture();
    $outside = Warehouse::query()->create([
        'name' => 'Inactive returns for sync scope', 'type' => 'return', 'is_active' => false,
    ]);
    config()->set('inventory.site_sellable_warehouse_ids', [
        WarehouseStockService::centralWarehouseId(), $outside->id,
    ]);
    expect(fn () => WarehouseStockService::siteSellableWarehouseIds())
        ->toThrow(\RuntimeException::class);
});
