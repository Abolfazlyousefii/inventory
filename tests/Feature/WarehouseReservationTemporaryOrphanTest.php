<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\PreinvoiceDraftReservationService;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-03 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function temporaryOrphanViewer(): User
{
    $permission = Permission::query()->where('key', 'warehouse_reservations.view')->firstOrFail();
    $role = Role::findOrCreate('temporary-orphan-viewer-'.Str::random(8), 'web');
    $role->givePermissionTo($permission);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function temporaryOrphanFixture(string $productName): array
{
    $quantity = 4;
    $seller = User::factory()->create();
    $category = Category::withoutEvents(fn () => Category::query()->create([
        'name' => 'Temporary orphan '.Str::uuid(),
    ]));
    $product = Product::withoutEvents(fn () => Product::query()->create([
        'category_id' => $category->id,
        'name' => $productName,
        'sku' => 'TEMP-ORPHAN-'.Str::uuid(),
        'stock' => 20,
        'reserved' => $quantity,
        'price' => 100_000,
        'is_sellable' => true,
    ]));
    $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'sales_enabled' => true,
        'variant_name' => $productName.' variant',
        'variant_code' => 'TEMP-ORPHAN-V-'.Str::uuid(),
        'sell_price' => 100_000,
        'stock' => 20,
        'reserved' => $quantity,
    ]));
    $warehouseStock = WarehouseStock::withoutEvents(fn () => WarehouseStock::query()->create([
        'warehouse_id' => WarehouseStockService::centralWarehouseId(),
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'quantity' => 20 - $quantity,
    ]));
    $reservation = PreinvoiceDraftReservation::query()->create([
        'token' => (string) Str::uuid(),
        'user_id' => $seller->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => $quantity,
        'expires_at' => null,
        'last_seen_at' => null,
        'reservation_scope' => null,
    ]);
    $reservation->forceFill([
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ])->saveQuietly();

    return compact('seller', 'product', 'variant', 'warehouseStock', 'reservation');
}

function connectTemporaryOrphanToPreinvoice(array $fixture, bool $withInvoice = false): array
{
    $order = PreinvoiceOrder::withoutEvents(fn () => PreinvoiceOrder::query()->create([
        'uuid' => 'TEMP-PI-'.Str::random(10),
        'created_by' => $fixture['seller']->id,
        'seller_id' => $fixture['seller']->id,
        'document_date' => now()->subHours(2),
        'status' => PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE,
        'customer_name' => 'Temporary orphan customer',
        'customer_mobile' => '09120000000',
        'total_price' => 400_000,
    ]));
    $fixture['reservation']->forceFill([
        'preinvoice_order_id' => $order->id,
        'converted_at' => $withInvoice ? now()->subHours(2) : null,
        'reservation_scope' => 'official',
    ])->saveQuietly();

    $invoice = null;
    if ($withInvoice) {
        $invoice = Invoice::withoutEvents(fn () => Invoice::query()->create([
            'uuid' => 'TEMP-INV-'.Str::random(10),
            'preinvoice_order_id' => $order->id,
            'seller_id' => $fixture['seller']->id,
            'customer_name' => 'Temporary orphan customer',
            'document_date' => now(),
            'subtotal' => 400_000,
            'total' => 400_000,
            'status' => Invoice::STATUS_PENDING_WAREHOUSE_APPROVAL,
        ]));
    }

    return compact('order', 'invoice');
}

it('shows an old temporary reservation without preinvoice as an orphan token group', function () {
    $fixture = temporaryOrphanFixture('Legacy temporary orphan product');
    $reservation = $fixture['reservation'];

    $this->actingAs(temporaryOrphanViewer())
        ->get(route('warehouse-reservations.index', ['tab' => 'orphaned']))
        ->assertOk()
        ->assertViewHas('orphanedCount', 1)
        ->assertSee($fixture['product']->name)
        ->assertSee($reservation->token)
        ->assertSee('2 ساعت قبل')
        ->assertSee('1 ردیف در این گروه')
        ->assertSee('رزرو رها شده');
});

it('cleans up a stale temporary reservation with null expiry using created at fallback', function () {
    $fixture = temporaryOrphanFixture('Cleanup null expiry orphan');
    $reservation = $fixture['reservation'];

    expect($reservation->isCleanupCandidate())->toBeTrue()
        ->and(PreinvoiceDraftReservation::query()->cleanupCandidates()->whereKey($reservation)->exists())->toBeTrue();

    $result = app(PreinvoiceDraftReservationService::class)->cleanupStaleTemporaryReservations();

    expect($result['released_reservations'])->toBe(1)
        ->and($reservation->fresh()->released_at)->not->toBeNull()
        ->and($fixture['warehouseStock']->fresh()->quantity)->toBe(20)
        ->and($fixture['product']->fresh()->reserved)->toBe(0)
        ->and($fixture['variant']->fresh()->reserved)->toBe(0);
});

it('never cleans up a temporary reservation after it is connected to a preinvoice', function () {
    $fixture = temporaryOrphanFixture('Preinvoice protected temporary reservation');
    connectTemporaryOrphanToPreinvoice($fixture);

    $result = app(PreinvoiceDraftReservationService::class)->cleanupStaleTemporaryReservations();

    expect($result['released_reservations'])->toBe(0)
        ->and($fixture['reservation']->fresh()->released_at)->toBeNull()
        ->and($fixture['warehouseStock']->fresh()->quantity)->toBe(16)
        ->and($fixture['variant']->fresh()->reserved)->toBe(4);
});

it('never cleans up a reservation whose preinvoice has an invoice', function () {
    $fixture = temporaryOrphanFixture('Invoice protected temporary reservation');
    ['invoice' => $invoice] = connectTemporaryOrphanToPreinvoice($fixture, true);

    $result = app(PreinvoiceDraftReservationService::class)->cleanupStaleTemporaryReservations();

    expect($invoice)->not->toBeNull()
        ->and($result['released_reservations'])->toBe(0)
        ->and($fixture['reservation']->fresh()->released_at)->toBeNull()
        ->and($fixture['warehouseStock']->fresh()->quantity)->toBe(16)
        ->and($fixture['variant']->fresh()->reserved)->toBe(4);
});

it('does not increase stock or write another release log when cleanup runs twice', function () {
    $fixture = temporaryOrphanFixture('Idempotent temporary orphan cleanup');
    $reservation = $fixture['reservation'];
    $service = app(PreinvoiceDraftReservationService::class);

    $first = $service->cleanupStaleTemporaryReservations();
    $stockAfterFirstCleanup = $fixture['warehouseStock']->fresh()->quantity;
    $second = $service->cleanupStaleTemporaryReservations();

    expect($first['released_reservations'])->toBe(1)
        ->and($second['released_reservations'])->toBe(0)
        ->and($fixture['warehouseStock']->fresh()->quantity)->toBe($stockAfterFirstCleanup)
        ->and($stockAfterFirstCleanup)->toBe(20)
        ->and(ActivityLog::query()
            ->where('subject_type', PreinvoiceDraftReservation::class)
            ->where('subject_id', $reservation->id)
            ->where('action', 'reservation_auto_release')
            ->count())->toBe(1);
});
