<?php

use App\Models\Category;
use App\Models\Invoice;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\WarehouseStockService;
use App\Support\JalaliDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function warehouseReservationBusinessUser(array $permissions): User
{
    $role = Role::findOrCreate('reservation-business-'.Str::random(10), 'web');

    foreach ($permissions as $key) {
        $permission = Permission::query()->where('key', $key)->first()
            ?? Permission::findOrCreate($key, 'web');

        if (($permission->key ?? null) !== $key) {
            $permission->forceFill(['key' => $key])->save();
        }

        $role->givePermissionTo($permission);
    }

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function warehouseReservationBusinessFixture(
    string $productName,
    int $ageHours = 1,
    int $lastSeenMinutesAgo = 0,
    bool $withPreinvoice = false,
    bool $withInvoice = false,
): array {
    $quantity = 4;
    $seller = User::factory()->create();
    $category = Category::withoutEvents(fn () => Category::query()->create([
        'name' => 'Business rule '.Str::uuid(),
    ]));
    $product = Product::withoutEvents(fn () => Product::query()->create([
        'category_id' => $category->id,
        'name' => $productName,
        'sku' => 'BUSINESS-'.Str::uuid(),
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
        'variant_code' => 'BUSINESS-V-'.Str::uuid(),
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
        'expires_at' => now()->addHour(),
        'last_seen_at' => now()->subMinutes($lastSeenMinutesAgo),
        'reservation_scope' => PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE,
    ]);
    $reservation->forceFill([
        'created_at' => now()->subHours($ageHours),
        'updated_at' => now()->subHours($ageHours),
    ])->saveQuietly();

    $order = null;
    $invoice = null;

    if ($withPreinvoice || $withInvoice) {
        $order = PreinvoiceOrder::withoutEvents(fn () => PreinvoiceOrder::query()->create([
            'uuid' => 'PI-'.Str::random(10),
            'created_by' => $seller->id,
            'seller_id' => $seller->id,
            'document_date' => now()->subHours($ageHours),
            'status' => PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE,
            'customer_name' => 'Business rule customer',
            'customer_mobile' => '09120000000',
            'total_price' => 400_000,
        ]));
        $reservation->forceFill([
            'preinvoice_order_id' => $order->id,
            'converted_at' => now()->subHours($ageHours)->addMinute(),
            'reservation_scope' => 'official',
        ])->saveQuietly();

        if ($withInvoice) {
            $invoice = Invoice::withoutEvents(fn () => Invoice::query()->create([
                'uuid' => 'INV-'.Str::random(10),
                'preinvoice_order_id' => $order->id,
                'seller_id' => $seller->id,
                'customer_name' => 'Business rule customer',
                'document_date' => now(),
                'subtotal' => 400_000,
                'total' => 400_000,
                'status' => Invoice::STATUS_PENDING_WAREHOUSE_APPROVAL,
            ]));
        }
    }

    return compact('seller', 'product', 'variant', 'warehouseStock', 'reservation', 'order', 'invoice');
}

it('shows a healthy temporary reservation', function () {
    $fixture = warehouseReservationBusinessFixture('Temporary business reservation');

    $this->actingAs(warehouseReservationBusinessUser(['warehouse_reservations.view']))
        ->get(route('warehouse-reservations.index'))
        ->assertOk()
        ->assertSee($fixture['product']->name)
        ->assertSee('فعال')
        ->assertSee('در حال ثبت پیش‌فاکتور');
});

it('keeps an abandoned temporary reservation eligible for cleanup', function () {
    $fixture = warehouseReservationBusinessFixture('Abandoned business reservation', lastSeenMinutesAgo: 30);
    $reservation = $fixture['reservation'];

    expect(PreinvoiceDraftReservation::query()->cleanupCandidates()->whereKey($reservation)->exists())->toBeTrue();

    $this->artisan('reservations:cleanup')->assertSuccessful();

    expect($reservation->fresh()->released_at)->not->toBeNull()
        ->and($fixture['warehouseStock']->fresh()->quantity)->toBe(20)
        ->and($fixture['variant']->fresh()->reserved)->toBe(0);
});

it('shows a reservation connected to a preinvoice without an invoice', function () {
    $fixture = warehouseReservationBusinessFixture('Preinvoice business reservation', withPreinvoice: true);

    $this->actingAs(warehouseReservationBusinessUser(['warehouse_reservations.view']))
        ->get(route('warehouse-reservations.index'))
        ->assertOk()
        ->assertSee($fixture['product']->name)
        ->assertSee('پیش‌فاکتور فعال')
        ->assertSee('متصل به پیش‌فاکتور شماره '.$fixture['order']->uuid);
});

it('never auto releases an old preinvoice reservation', function () {
    $fixture = warehouseReservationBusinessFixture('Old preinvoice business reservation', 80, 80 * 60, true);
    $reservation = $fixture['reservation'];

    expect($reservation->businessStatus())->toBe(PreinvoiceDraftReservation::STATUS_CRITICAL);

    $this->artisan('reservations:cleanup')->assertSuccessful();

    expect($reservation->fresh()->released_at)->toBeNull()
        ->and($fixture['warehouseStock']->fresh()->quantity)->toBe(16)
        ->and($fixture['variant']->fresh()->reserved)->toBe(4);
});

it('does not show a reservation whose preinvoice has an invoice', function () {
    $fixture = warehouseReservationBusinessFixture('Invoiced reservation must be hidden', withInvoice: true);

    $this->actingAs(warehouseReservationBusinessUser(['warehouse_reservations.view']))
        ->getJson(route('warehouse-reservations.index', ['search' => $fixture['product']->name]))
        ->assertOk()
        ->assertJsonPath('total', 0)
        ->assertJsonMissing(['id' => $fixture['reservation']->id]);
});

it('classifies preinvoice reservation age at the 24 and 72 hour boundaries', function () {
    $recent = warehouseReservationBusinessFixture('Recent preinvoice age', 23, withPreinvoice: true)['reservation'];
    $review = warehouseReservationBusinessFixture('Review preinvoice age', 24, withPreinvoice: true)['reservation'];
    $boundary = warehouseReservationBusinessFixture('Boundary preinvoice age', 72, withPreinvoice: true)['reservation'];
    $critical = warehouseReservationBusinessFixture('Critical preinvoice age', 73, withPreinvoice: true)['reservation'];

    expect($recent->businessStatus())->toBe(PreinvoiceDraftReservation::STATUS_PREINVOICE_ACTIVE)
        ->and($recent->preinvoiceAgeHours())->toBe(23)
        ->and($review->businessStatus())->toBe(PreinvoiceDraftReservation::STATUS_NEEDS_REVIEW)
        ->and($boundary->businessStatus())->toBe(PreinvoiceDraftReservation::STATUS_NEEDS_REVIEW)
        ->and($critical->businessStatus())->toBe(PreinvoiceDraftReservation::STATUS_CRITICAL);
});

it('renders reservation dates with the existing Jalali date helper', function () {
    $fixture = warehouseReservationBusinessFixture('Jalali business reservation');
    $reservation = $fixture['reservation'];
    $reservation->forceFill(['created_at' => Carbon::parse('2026-03-21 08:30:00')])->saveQuietly();
    $expected = JalaliDate::dateTime($reservation->fresh()->created_at);

    $this->actingAs(warehouseReservationBusinessUser(['warehouse_reservations.view']))
        ->get(route('warehouse-reservations.index', ['search' => $fixture['product']->name]))
        ->assertOk()
        ->assertSee($expected)
        ->assertSee('1405/01/01');
});

it('keeps the existing warehouse reservation view permission unchanged', function () {
    warehouseReservationBusinessFixture('Permission business reservation');
    $viewer = warehouseReservationBusinessUser(['warehouse_reservations.view']);

    $this->actingAs($viewer)
        ->get(route('warehouse-reservations.index'))
        ->assertOk();

    $this->actingAs(User::factory()->create())
        ->get(route('warehouse-reservations.index'))
        ->assertForbidden();

    expect(Permission::query()->where('key', 'warehouse_reservations.view')->exists())->toBeTrue()
        ->and(Permission::query()->where('key', 'warehouse_reservations.release')->exists())->toBeTrue();
});
