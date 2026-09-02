<?php

use App\Models\Category;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\ReservationHealthService;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function warehouseReservationHealthViewer(): User
{
    $role = Role::findOrCreate('warehouse-reservation-health-'.Str::random(8), 'web');
    $role->givePermissionTo(Permission::query()->where('key', 'warehouse_reservations.view')->firstOrFail());
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function warehouseReservationHealthFixture(
    string $productName,
    array $reservationOverrides = [],
    ?int $cachedReserved = null,
): array {
    $quantity = (int) ($reservationOverrides['quantity'] ?? 5);
    $cachedReserved ??= $quantity;
    $seller = User::factory()->create();
    $category = Category::withoutEvents(fn () => Category::query()->create([
        'name' => 'Health category '.Str::uuid(),
    ]));
    $product = Product::withoutEvents(fn () => Product::query()->create([
        'category_id' => $category->id,
        'name' => $productName,
        'sku' => 'HEALTH-'.Str::uuid(),
        'stock' => 20,
        'reserved' => $cachedReserved,
        'price' => 100000,
        'is_sellable' => true,
    ]));
    $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'sales_enabled' => true,
        'variant_name' => $productName.' variant',
        'variant_code' => 'HEALTH-V-'.Str::uuid(),
        'sell_price' => 100000,
        'stock' => 20,
        'reserved' => $cachedReserved,
    ]));
    $warehouseStock = WarehouseStock::withoutEvents(fn () => WarehouseStock::query()->create([
        'warehouse_id' => WarehouseStockService::centralWarehouseId(),
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'quantity' => 20,
    ]));
    $reservation = PreinvoiceDraftReservation::query()->create(array_merge([
        'token' => (string) Str::uuid(),
        'user_id' => $seller->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => $quantity,
        'expires_at' => now()->addHour(),
        'last_seen_at' => now(),
        'reservation_scope' => PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE,
    ], $reservationOverrides));

    return compact('seller', 'product', 'variant', 'warehouseStock', 'reservation');
}

function warehouseReservationHealthOrder(User $seller): PreinvoiceOrder
{
    return PreinvoiceOrder::withoutEvents(fn () => PreinvoiceOrder::query()->create([
        'uuid' => (string) Str::uuid(),
        'created_by' => $seller->id,
        'seller_id' => $seller->id,
        'document_date' => now(),
        'status' => PreinvoiceOrder::STATUS_PENDING_FINANCE,
        'customer_name' => 'Health report customer',
        'customer_mobile' => '09120000000',
        'total_price' => 100000,
        'stock_frozen_until' => now()->addHour(),
    ]));
}

it('calculates reservation health statistics correctly', function () {
    warehouseReservationHealthFixture('Healthy Session Product');
    warehouseReservationHealthFixture('Orphan Health Product', ['last_seen_at' => now()->subHours(2)]);
    ['seller' => $seller, 'reservation' => $oldReservation] = warehouseReservationHealthFixture('Old Owned Product', [
        'last_seen_at' => now()->subHours(2),
        'reservation_scope' => 'official',
        'converted_at' => now()->subHours(2),
    ]);
    $oldReservation->forceFill([
        'preinvoice_order_id' => warehouseReservationHealthOrder($seller)->id,
        'created_at' => now()->subHours(2),
    ])->saveQuietly();

    $summary = app(ReservationHealthService::class)->summary();

    expect($summary)->toBe([
        'healthy' => 2,
        'old' => 1,
        'orphaned' => 1,
        'cache_mismatch' => 0,
    ]);

    $this->actingAs(warehouseReservationHealthViewer())
        ->get(route('warehouse-reservations.index', ['tab' => 'health']))
        ->assertOk()
        ->assertSee('رزرو سالم')
        ->assertSee('رزرو قدیمی')
        ->assertSee('رزرو رها شده')
        ->assertSee('اختلاف موجودی');
});

it('detects orphan reservations in the health report', function () {
    $reservation = warehouseReservationHealthFixture('Health Orphan Detection', [
        'last_seen_at' => null,
    ])['reservation'];
    $reservation->forceFill([
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ])->saveQuietly();
    $service = app(ReservationHealthService::class);

    expect($service->summary()['orphaned'])->toBe(1);

    $issues = $service->paginateIssues();
    expect($issues->total())->toBe(1)
        ->and($issues->first()->reservation_id)->toBe($reservation->id)
        ->and($issues->first()->issue_type)->toBe(ReservationHealthService::ISSUE_ORPHANED);
});

it('does not report a healthy reservation as an issue', function () {
    warehouseReservationHealthFixture('Healthy Not An Issue');
    $service = app(ReservationHealthService::class);

    expect($service->summary()['healthy'])->toBe(1)
        ->and($service->summary()['orphaned'])->toBe(0)
        ->and($service->paginateIssues()->total())->toBe(0);
});

it('reports a cache mismatch without changing reservation or inventory data', function () {
    warehouseReservationHealthFixture('Cache Mismatch Product', cachedReserved: 2);
    $before = warehouseReservationHealthSnapshot();
    $service = app(ReservationHealthService::class);

    $summary = $service->summary();
    $issues = $service->paginateIssues();

    expect($summary['cache_mismatch'])->toBe(1)
        ->and($summary['healthy'])->toBe(1)
        ->and($issues->total())->toBe(1)
        ->and($issues->first()->issue_type)->toBe(ReservationHealthService::ISSUE_CACHE_MISMATCH)
        ->and((int) $issues->first()->quantity)->toBe(5)
        ->and((int) $issues->first()->cached_quantity)->toBe(2)
        ->and(warehouseReservationHealthSnapshot())->toBe($before);
});

it('exports a formula-safe health report as csv without changing data', function () {
    warehouseReservationHealthFixture('=CSV Health Product', cachedReserved: 1);
    $before = warehouseReservationHealthSnapshot();

    $response = $this->actingAs(warehouseReservationHealthViewer())
        ->get(route('warehouse-reservations.health.export'))
        ->assertOk()
        ->assertDownload();
    $csv = $response->streamedContent();

    expect($csv)->toContain("'=CSV Health Product")
        ->and($csv)->toContain('اختلاف cache رزرو')
        ->and(warehouseReservationHealthSnapshot())->toBe($before);
});

function warehouseReservationHealthSnapshot(): array
{
    return [
        'reservations' => DB::table('preinvoice_draft_reservations')->orderBy('id')->get()->toJson(),
        'products' => DB::table('products')->orderBy('id')->get()->toJson(),
        'variants' => DB::table('product_variants')->orderBy('id')->get()->toJson(),
        'warehouse_stocks' => DB::table('warehouse_stocks')->orderBy('id')->get()->toJson(),
    ];
}
