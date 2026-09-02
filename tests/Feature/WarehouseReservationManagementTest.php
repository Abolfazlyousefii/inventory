<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function warehouseReservationManager(array $permissions = ['warehouse_reservations.view']): User
{
    $role = Role::findOrCreate('warehouse-reservation-'.Str::random(8), 'web');

    foreach ($permissions as $key) {
        $role->givePermissionTo(Permission::query()->where('key', $key)->firstOrFail());
    }

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function warehouseReservationFixture(array $reservationOverrides = [], array $itemOverrides = []): array
{
    $creator = User::factory()->create();
    $category = Category::withoutEvents(fn () => Category::query()->create([
        'name' => 'Reservation management '.Str::uuid(),
    ]));
    $product = Product::withoutEvents(fn () => Product::query()->create([
        'category_id' => $category->id,
        'name' => $itemOverrides['product_name'] ?? 'Managed reservation product',
        'sku' => 'RES-'.Str::uuid(),
        'stock' => $itemOverrides['stock'] ?? 20,
        'reserved' => $itemOverrides['reserved'] ?? 5,
        'price' => 100000,
        'is_sellable' => true,
    ]));
    $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'sales_enabled' => true,
        'variant_name' => $itemOverrides['variant_name'] ?? 'Managed reservation variant',
        'variant_code' => $itemOverrides['variant_code'] ?? 'RES-V-'.Str::uuid(),
        'sell_price' => 100000,
        'stock' => $itemOverrides['stock'] ?? 20,
        'reserved' => $itemOverrides['reserved'] ?? 5,
    ]));
    $warehouseStock = WarehouseStock::withoutEvents(fn () => WarehouseStock::query()->create([
        'warehouse_id' => WarehouseStockService::centralWarehouseId(),
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'quantity' => $itemOverrides['stock'] ?? 20,
    ]));
    $reservation = PreinvoiceDraftReservation::query()->create(array_merge([
        'token' => (string) Str::uuid(),
        'user_id' => $creator->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => 5,
        'expires_at' => now()->addHour(),
        'last_seen_at' => now(),
        'reservation_scope' => PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE,
    ], $reservationOverrides));

    return compact('creator', 'product', 'variant', 'warehouseStock', 'reservation');
}

it('allows an authorized warehouse manager to access the index', function () {
    $this->actingAs(warehouseReservationManager())
        ->getJson(route('warehouse-reservations.index'))
        ->assertOk()
        ->assertJsonStructure(['data', 'current_page', 'per_page', 'total']);
});

it('keeps the existing warehouse reservation page permission compatible with index access', function () {
    $this->actingAs(warehouseReservationManager(['page.warehouse.reservations']))
        ->getJson(route('warehouse-reservations.index'))
        ->assertOk();
});

it('forbids a user without view permission from accessing the index', function () {
    $this->actingAs(User::factory()->create())
        ->getJson(route('warehouse-reservations.index'))
        ->assertForbidden();
});

it('filters the index by canonical reservation status', function () {
    $active = warehouseReservationFixture()['reservation'];
    $abandoned = warehouseReservationFixture([
        'expires_at' => now()->addHour(),
        'last_seen_at' => now()->subHour(),
    ])['reservation'];
    $manager = warehouseReservationManager();

    $this->actingAs($manager)
        ->getJson(route('warehouse-reservations.index', ['status' => PreinvoiceDraftReservation::STATUS_ACTIVE]))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.id', $active->id)
        ->assertJsonPath('data.0.status', PreinvoiceDraftReservation::STATUS_ACTIVE);

    $this->actingAs($manager)
        ->getJson(route('warehouse-reservations.index', ['status' => PreinvoiceDraftReservation::STATUS_ABANDONED]))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.id', $abandoned->id)
        ->assertJsonPath('data.0.status', PreinvoiceDraftReservation::STATUS_ABANDONED)
        ->assertJsonPath('data.0.releasable', true);
});

it('searches reservations by product name variant code and token', function () {
    $match = warehouseReservationFixture(
        ['token' => '11111111-1111-4111-8111-111111111111'],
        ['product_name' => 'Needle Search Product', 'variant_code' => 'VARIANT-NEEDLE'],
    )['reservation'];
    warehouseReservationFixture([], ['product_name' => 'Unrelated Product', 'variant_code' => 'UNRELATED']);
    $manager = warehouseReservationManager();

    foreach (['Needle Search', 'VARIANT-NEEDLE', '11111111-1111'] as $search) {
        $this->actingAs($manager)
            ->getJson(route('warehouse-reservations.index', ['search' => $search]))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $match->id);
    }
});

it('does not release an active reservation or change inventory', function () {
    ['product' => $product, 'variant' => $variant, 'warehouseStock' => $warehouseStock, 'reservation' => $reservation]
        = warehouseReservationFixture();
    $manager = warehouseReservationManager(['warehouse_reservations.view', 'warehouse_reservations.release']);

    $this->actingAs($manager)
        ->postJson(route('warehouse-reservations.release', $reservation), ['release_reason' => 'Active reservation test'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reservation');

    expect($reservation->fresh()->released_at)->toBeNull()
        ->and($product->fresh()->reserved)->toBe(5)
        ->and($variant->fresh()->reserved)->toBe(5)
        ->and($warehouseStock->fresh()->quantity)->toBe(20)
        ->and(ActivityLog::query()->where('subject_id', $reservation->id)->count())->toBe(0);
});

it('releases an abandoned reservation through the release service with an audit record', function () {
    ['product' => $product, 'variant' => $variant, 'warehouseStock' => $warehouseStock, 'reservation' => $reservation]
        = warehouseReservationFixture([
            'expires_at' => now()->addHour(),
            'last_seen_at' => now()->subHour(),
        ]);
    $manager = warehouseReservationManager(['warehouse_reservations.view', 'warehouse_reservations.release']);

    $this->actingAs($manager)
        ->postJson(route('warehouse-reservations.release', $reservation), [
            'release_reason' => 'Abandoned reservation test',
            'release_note' => 'Released by warehouse management endpoint.',
        ])
        ->assertOk()
        ->assertJsonPath('reservation_id', $reservation->id);

    $released = $reservation->fresh();
    $audit = ActivityLog::query()
        ->where('action', 'reservation_manual_release')
        ->where('subject_id', $reservation->id)
        ->first();

    expect($released->released_at)->not->toBeNull()
        ->and($released->released_by)->toBe($manager->id)
        ->and($released->release_reason)->toBe('Abandoned reservation test')
        ->and($product->fresh()->reserved)->toBe(0)
        ->and($variant->fresh()->reserved)->toBe(0)
        ->and($warehouseStock->fresh()->quantity)->toBe(25)
        ->and($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe($manager->id);
});

it('cannot release an already released reservation twice', function () {
    ['product' => $product, 'variant' => $variant, 'warehouseStock' => $warehouseStock, 'reservation' => $reservation]
        = warehouseReservationFixture([
            'expires_at' => now()->subHour(),
            'last_seen_at' => now()->subHour(),
        ]);
    $manager = warehouseReservationManager(['warehouse_reservations.view', 'warehouse_reservations.release']);
    $payload = ['release_reason' => 'Idempotency test'];

    $this->actingAs($manager)->postJson(route('warehouse-reservations.release', $reservation), $payload)->assertOk();
    $this->actingAs($manager)
        ->postJson(route('warehouse-reservations.release', $reservation), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reservation');

    expect($product->fresh()->reserved)->toBe(0)
        ->and($variant->fresh()->reserved)->toBe(0)
        ->and($warehouseStock->fresh()->quantity)->toBe(25)
        ->and(ActivityLog::query()
            ->where('action', 'reservation_manual_release')
            ->where('subject_id', $reservation->id)
            ->count())->toBe(1);
});

it('requires the separate release permission and rejects connected reservations', function () {
    ['creator' => $creator, 'reservation' => $reservation] = warehouseReservationFixture([
        'expires_at' => now()->subHour(),
        'last_seen_at' => now()->subHour(),
    ]);
    $viewOnlyManager = warehouseReservationManager();

    $this->actingAs($viewOnlyManager)
        ->postJson(route('warehouse-reservations.release', $reservation), ['release_reason' => 'No release grant'])
        ->assertForbidden();

    $order = PreinvoiceOrder::withoutEvents(fn () => PreinvoiceOrder::query()->create([
        'uuid' => (string) Str::uuid(),
        'created_by' => $creator->id,
        'seller_id' => $creator->id,
        'document_date' => now(),
        'status' => PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE,
        'customer_name' => 'Connected reservation customer',
        'customer_mobile' => '09120000000',
        'total_price' => 100000,
    ]));
    $reservation->forceFill(['preinvoice_order_id' => $order->id])->save();
    $releaseManager = warehouseReservationManager(['warehouse_reservations.view', 'warehouse_reservations.release']);

    $this->actingAs($releaseManager)
        ->postJson(route('warehouse-reservations.release', $reservation), ['release_reason' => 'Connected reservation'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reservation');

    expect($reservation->fresh()->released_at)->toBeNull();
});
