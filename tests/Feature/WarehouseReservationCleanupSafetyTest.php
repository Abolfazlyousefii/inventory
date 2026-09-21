<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\InventoryReservationReleaseService;
use App\Services\PreinvoiceDraftReservationService;
use App\Services\PreinvoiceReservationService;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function warehouseCleanupSafetyFixture(
    int $quantity = 5,
    int $productReserved = 5,
    int $variantReserved = 5,
    int $availableStock = 20,
): array {
    $user = User::factory()->create();
    $category = Category::withoutEvents(fn () => Category::query()->create([
        'name' => 'Cleanup safety '.Str::uuid(),
    ]));
    $product = Product::withoutEvents(fn () => Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Cleanup safety product',
        'sku' => 'CLEANUP-'.Str::uuid(),
        'stock' => $availableStock,
        'reserved' => $productReserved,
        'price' => 100000,
        'is_sellable' => true,
    ]));
    $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'sales_enabled' => true,
        'variant_name' => 'Cleanup safety variant',
        'variant_code' => 'CLEANUP-V-'.Str::uuid(),
        'sell_price' => 100000,
        'stock' => $availableStock,
        'reserved' => $variantReserved,
    ]));
    $warehouseStock = WarehouseStock::withoutEvents(fn () => WarehouseStock::query()->create([
        'warehouse_id' => WarehouseStockService::centralWarehouseId(),
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'quantity' => $availableStock,
    ]));
    $reservation = PreinvoiceDraftReservation::query()->create([
        'token' => (string) Str::uuid(),
        'user_id' => $user->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => $quantity,
        'expires_at' => now()->subHour(),
        'last_seen_at' => now()->subHour(),
        'reservation_scope' => PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE,
    ]);

    return compact('user', 'product', 'variant', 'warehouseStock', 'reservation');
}

it('releases a valid abandoned reservation exactly once', function () {
    ['product' => $product, 'variant' => $variant, 'warehouseStock' => $warehouseStock, 'reservation' => $reservation]
        = warehouseCleanupSafetyFixture();

    $service = app(PreinvoiceDraftReservationService::class);
    $first = $service->cleanupStaleTemporaryReservations();

    expect($first['released_reservations'])->toBe(1)
        ->and($first['released_quantity'])->toBe(5)
        ->and($first['warnings'])->toBe(0)
        ->and($reservation->fresh()->released_at)->not->toBeNull()
        ->and($reservation->fresh()->released_by)->toBeNull()
        ->and($product->fresh()->reserved)->toBe(0)
        ->and($variant->fresh()->reserved)->toBe(0)
        ->and($warehouseStock->fresh()->quantity)->toBe(25);

    $second = $service->cleanupStaleTemporaryReservations();

    expect($second['released_reservations'])->toBe(0)
        ->and($second['released_quantity'])->toBe(0)
        ->and($product->fresh()->reserved)->toBe(0)
        ->and($variant->fresh()->reserved)->toBe(0)
        ->and($warehouseStock->fresh()->quantity)->toBe(25)
        ->and(ActivityLog::query()
            ->where('action', 'reservation_auto_release')
            ->where('subject_id', $reservation->id)
            ->count())->toBe(1);
});

it('never returns stock for a temporary reservation beyond the legacy boundary', function () {
    ['product' => $product, 'variant' => $variant, 'warehouseStock' => $warehouseStock, 'reservation' => $reservation]
        = warehouseCleanupSafetyFixture();
    $reservation->forceFill([
        'created_at' => now()->subHours(PreinvoiceDraftReservation::LEGACY_STALE_HOURS + 1),
        'updated_at' => now()->subHours(PreinvoiceDraftReservation::LEGACY_STALE_HOURS + 1),
        'last_seen_at' => now()->subHours(PreinvoiceDraftReservation::LEGACY_STALE_HOURS + 1),
    ])->save();

    $result = app(PreinvoiceDraftReservationService::class)->cleanupStaleTemporaryReservations();

    expect($result['released_reservations'])->toBe(0)
        ->and($reservation->fresh()->released_at)->toBeNull()
        ->and($product->fresh()->reserved)->toBe(5)
        ->and($variant->fresh()->reserved)->toBe(5)
        ->and($warehouseStock->fresh()->quantity)->toBe(20);
});

it('draft expired cleanup refuses a canonical historical ambiguous reservation', function () {
    ['product' => $product, 'variant' => $variant, 'warehouseStock' => $warehouseStock, 'reservation' => $reservation]
        = warehouseCleanupSafetyFixture();
    $reservation->forceFill([
        'created_at' => now()->subHours(PreinvoiceDraftReservation::LEGACY_STALE_HOURS + 1),
        'updated_at' => now()->subHours(PreinvoiceDraftReservation::LEGACY_STALE_HOURS + 1),
        'last_seen_at' => now()->subHours(PreinvoiceDraftReservation::LEGACY_STALE_HOURS + 1),
    ])->save();
    $movements = DB::table('stock_movements')->count();

    app(PreinvoiceDraftReservationService::class)->releaseExpiredDraftReservations(
        $reservation->token,
        (int) $reservation->user_id,
    );

    expect($reservation->fresh()->released_at)->toBeNull()
        ->and($product->fresh()->reserved)->toBe(5)
        ->and($variant->fresh()->reserved)->toBe(5)
        ->and($warehouseStock->fresh()->quantity)->toBe(20)
        ->and(DB::table('stock_movements')->count())->toBe($movements);
});

it('overdue expired cleanup refuses a canonical active draft owned reservation', function () {
    ['user' => $user, 'product' => $product, 'variant' => $variant, 'warehouseStock' => $warehouseStock, 'reservation' => $reservation]
        = warehouseCleanupSafetyFixture();
    PreinvoiceOrder::withoutEvents(fn () => PreinvoiceOrder::query()->create([
        'uuid' => (string) Str::uuid(),
        'draft_token' => $reservation->token,
        'created_by' => $user->id,
        'seller_id' => $user->id,
        'document_date' => now(),
        'status' => PreinvoiceOrder::STATUS_DRAFT,
        'customer_name' => 'Cleanup safety draft',
        'customer_mobile' => '09120000000',
        'total_price' => 100000,
    ]));
    $movements = DB::table('stock_movements')->count();

    $result = app(PreinvoiceReservationService::class)->expireTemporaryOnlineReservations();

    expect($result['released_reservations'])->toBe(0)
        ->and($result['released_quantity'])->toBe(0)
        ->and($reservation->fresh()->released_at)->toBeNull()
        ->and($product->fresh()->reserved)->toBe(5)
        ->and($variant->fresh()->reserved)->toBe(5)
        ->and($warehouseStock->fresh()->quantity)->toBe(20)
        ->and(DB::table('stock_movements')->count())->toBe($movements);
});

it('uses canonical reservation rows when the product reserved projection is too low', function () {
    ['product' => $product, 'variant' => $variant, 'warehouseStock' => $warehouseStock, 'reservation' => $reservation]
        = warehouseCleanupSafetyFixture(quantity: 10, productReserved: 5, variantReserved: 10);

    $result = app(PreinvoiceDraftReservationService::class)->cleanupStaleTemporaryReservations();
    $warning = ActivityLog::query()
        ->where('action', 'reservation_cleanup_warning')
        ->where('subject_id', $reservation->id)
        ->latest('id')
        ->first();

    expect($result['released_reservations'])->toBe(1)
        ->and($result['warnings'])->toBe(0)
        ->and($reservation->fresh()->released_at)->not->toBeNull()
        ->and($product->fresh()->reserved)->toBe(0)
        ->and($variant->fresh()->reserved)->toBe(0)
        ->and($warehouseStock->fresh()->quantity)->toBe(30)
        ->and($warning)->toBeNull();
});

it('uses canonical reservation rows when the variant reserved projection is too low', function () {
    ['product' => $product, 'variant' => $variant, 'warehouseStock' => $warehouseStock, 'reservation' => $reservation]
        = warehouseCleanupSafetyFixture(quantity: 10, productReserved: 10, variantReserved: 5);

    $result = app(PreinvoiceDraftReservationService::class)->cleanupStaleTemporaryReservations();
    $warning = ActivityLog::query()
        ->where('action', 'reservation_cleanup_warning')
        ->where('subject_id', $reservation->id)
        ->latest('id')
        ->first();

    expect($result['released_reservations'])->toBe(1)
        ->and($result['warnings'])->toBe(0)
        ->and($reservation->fresh()->released_at)->not->toBeNull()
        ->and($product->fresh()->reserved)->toBe(0)
        ->and($variant->fresh()->reserved)->toBe(0)
        ->and($warehouseStock->fresh()->quantity)->toBe(30)
        ->and($warning)->toBeNull();
});

it('reports abandoned reservations in dry run without changing the database', function () {
    ['product' => $product, 'variant' => $variant, 'warehouseStock' => $warehouseStock, 'reservation' => $reservation]
        = warehouseCleanupSafetyFixture();
    $activityCount = ActivityLog::query()->count();

    $this->artisan('reservations:cleanup --dry-run')
        ->expectsOutputToContain('#'.$reservation->id)
        ->expectsOutputToContain('NO DATA CHANGED')
        ->assertSuccessful();

    expect($reservation->fresh()->released_at)->toBeNull()
        ->and($product->fresh()->reserved)->toBe(5)
        ->and($variant->fresh()->reserved)->toBe(5)
        ->and($warehouseStock->fresh()->quantity)->toBe(20)
        ->and(ActivityLog::query()->count())->toBe($activityCount);
});

it('does not report an expired row with a fresh canonical heartbeat as releasable', function () {
    ['product' => $product, 'variant' => $variant, 'warehouseStock' => $warehouseStock, 'reservation' => $reservation]
        = warehouseCleanupSafetyFixture();
    $reservation->forceFill(['last_seen_at' => now()])->save();

    $this->artisan('reservations:cleanup --dry-run')
        ->doesntExpectOutputToContain('#'.$reservation->id)
        ->expectsOutputToContain('Stale temporary reservations: 0')
        ->assertSuccessful();

    expect($reservation->fresh()->released_at)->toBeNull()
        ->and($product->fresh()->reserved)->toBe(5)
        ->and($variant->fresh()->reserved)->toBe(5)
        ->and($warehouseStock->fresh()->quantity)->toBe(20);
});

it('ignores reservations connected to a preinvoice order', function () {
    ['user' => $user, 'product' => $product, 'variant' => $variant, 'warehouseStock' => $warehouseStock, 'reservation' => $reservation]
        = warehouseCleanupSafetyFixture();
    $order = PreinvoiceOrder::withoutEvents(fn () => PreinvoiceOrder::query()->create([
        'uuid' => (string) Str::uuid(),
        'created_by' => $user->id,
        'seller_id' => $user->id,
        'document_date' => now(),
        'status' => PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE,
        'customer_name' => 'Cleanup safety customer',
        'customer_mobile' => '09120000000',
        'total_price' => 100000,
    ]));
    $reservation->forceFill(['preinvoice_order_id' => $order->id])->save();

    $result = app(PreinvoiceDraftReservationService::class)->cleanupStaleTemporaryReservations();

    expect($result['released_reservations'])->toBe(0)
        ->and($result['warnings'])->toBe(0)
        ->and($reservation->fresh()->released_at)->toBeNull()
        ->and($product->fresh()->reserved)->toBe(5)
        ->and($variant->fresh()->reserved)->toBe(5)
        ->and($warehouseStock->fresh()->quantity)->toBe(20)
        ->and(ActivityLog::query()->where('subject_id', $reservation->id)->count())->toBe(0);
});

it('manual release repairs stale projections from canonical lifecycle state', function () {
    ['user' => $user, 'product' => $product, 'variant' => $variant, 'warehouseStock' => $warehouseStock, 'reservation' => $reservation]
        = warehouseCleanupSafetyFixture(quantity: 10, productReserved: 5, variantReserved: 10);

    app(InventoryReservationReleaseService::class)
        ->releaseDraftReservation($reservation, $user, 'cleanup safety test');

    expect($reservation->fresh()->released_at)->not->toBeNull()
        ->and($product->fresh()->reserved)->toBe(0)
        ->and($variant->fresh()->reserved)->toBe(0)
        ->and($warehouseStock->fresh()->quantity)->toBe(30);
});

it('records the explicit manual release actor without relying on the auth facade', function () {
    ['user' => $user, 'reservation' => $reservation] = warehouseCleanupSafetyFixture();

    app(InventoryReservationReleaseService::class)
        ->releaseDraftReservation($reservation, $user, 'cleanup safety actor test');

    $activity = ActivityLog::query()
        ->where('action', 'reservation_manual_release')
        ->where('subject_id', $reservation->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->user_id)->toBe($user->id)
        ->and($activity->properties['released_by'])->toBe($user->id)
        ->and($activity->properties['actor_type'])->toBe('user');
});
