<?php

use App\Models\Category;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\InventoryReservationReleaseService;
use App\Support\PageAccessCatalog;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function warehouseReservationUiUser(array $permissions): User
{
    $role = Role::findOrCreate('warehouse-reservation-ui-'.Str::random(8), 'web');

    foreach ($permissions as $key) {
        $role->givePermissionTo(Permission::query()->where('key', $key)->firstOrFail());
    }

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function warehouseReservationUiFixture(array $reservationOverrides = []): array
{
    $seller = User::factory()->create(['name' => 'فروشنده تست رزرو']);
    $category = Category::withoutEvents(fn () => Category::query()->create([
        'name' => 'دسته رزرو '.Str::uuid(),
    ]));
    $product = Product::withoutEvents(fn () => Product::query()->create([
        'category_id' => $category->id,
        'name' => 'کالای نمایشی رزرو',
        'sku' => 'UI-RES-'.Str::uuid(),
        'stock' => 20,
        'reserved' => 5,
        'price' => 100000,
        'is_sellable' => true,
    ]));
    $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'sales_enabled' => true,
        'variant_name' => 'تنوع نمایشی رزرو',
        'variant_code' => 'UI-VARIANT-100',
        'sell_price' => 100000,
        'stock' => 20,
        'reserved' => 5,
    ]));
    $reservation = PreinvoiceDraftReservation::query()->create(array_merge([
        'token' => (string) Str::uuid(),
        'user_id' => $seller->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => 5,
        'expires_at' => now()->addHour(),
        'last_seen_at' => now(),
        'reservation_scope' => PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE,
    ], $reservationOverrides));

    return compact('seller', 'product', 'variant', 'reservation');
}

it('shows the reservation management page and sidebar item to a warehouse manager', function () {
    $manager = warehouseReservationUiUser(['warehouse_reservations.view']);

    $this->actingAs($manager)
        ->get(route('warehouse-reservations.index'))
        ->assertOk()
        ->assertViewIs('warehouse-reservations.index')
        ->assertSee('مدیریت رزرو موجودی')
        ->assertSee('رزرو فعال')
        ->assertSee('نیاز بررسی')
        ->assertSee('قابل آزادسازی');
});

it('registers reservation view and release actions with the reservation page', function () {
    expect(PageAccessCatalog::page('warehouse.reservations')['action_permissions'])
        ->toBe(['warehouse_reservations.view', 'warehouse_reservations.release']);
});

it('assigns both reservation permissions to the warehouse manager preset', function () {
    $this->seed(RoleSeeder::class);

    expect(Role::findByName('warehouse_manager', 'web')->permissions()->pluck('key')->all())
        ->toContain('warehouse_reservations.view', 'warehouse_reservations.release');
});

it('forbids a user without reservation view permission', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('warehouse-reservations.index'))
        ->assertForbidden();
});

it('displays an active reservation with its product and variant', function () {
    warehouseReservationUiFixture();

    $this->actingAs(warehouseReservationUiUser(['warehouse_reservations.view']))
        ->get(route('warehouse-reservations.index'))
        ->assertOk()
        ->assertSee('کالای نمایشی رزرو')
        ->assertSee('تنوع نمایشی رزرو')
        ->assertSee('UI-VARIANT-100')
        ->assertSee('فعال');
});

it('displays the seller and connected preinvoice reference', function () {
    ['seller' => $seller, 'reservation' => $reservation] = warehouseReservationUiFixture();
    $order = PreinvoiceOrder::withoutEvents(fn () => PreinvoiceOrder::query()->create([
        'uuid' => '22222222-2222-4222-8222-222222222222',
        'created_by' => $seller->id,
        'seller_id' => $seller->id,
        'document_date' => now(),
        'status' => PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE,
        'customer_name' => 'مشتری رابط کاربری',
        'customer_mobile' => '09120000000',
        'total_price' => 100000,
    ]));
    $reservation->forceFill(['preinvoice_order_id' => $order->id])->save();

    $this->actingAs(warehouseReservationUiUser(['warehouse_reservations.view']))
        ->get(route('warehouse-reservations.index'))
        ->assertOk()
        ->assertSee('فروشنده تست رزرو')
        ->assertSee('22222222-2222-4222-8222-222222222222')
        ->assertSee('متصل به پیش‌فاکتور');
});

it('does not show the release action to a user without release permission', function () {
    warehouseReservationUiFixture([
        'expires_at' => now()->subHour(),
        'last_seen_at' => now()->subHour(),
    ]);

    $this->actingAs(warehouseReservationUiUser(['warehouse_reservations.view']))
        ->get(route('warehouse-reservations.index'))
        ->assertOk()
        ->assertSee('قابل آزادسازی')
        ->assertDontSee('آزادسازی موجودی');
});

it('delegates release requests to the inventory reservation release service', function () {
    ['reservation' => $reservation] = warehouseReservationUiFixture([
        'expires_at' => now()->subHour(),
        'last_seen_at' => now()->subHour(),
    ]);
    $manager = warehouseReservationUiUser(['warehouse_reservations.view', 'warehouse_reservations.release']);

    $this->mock(InventoryReservationReleaseService::class, function (MockInterface $mock) use ($manager, $reservation): void {
        $mock->shouldReceive('releaseDraftReservation')
            ->once()
            ->withArgs(fn (PreinvoiceDraftReservation $givenReservation, User $actor, string $reason, ?string $note): bool =>
                $givenReservation->is($reservation)
                && $actor->is($manager)
                && $reason === 'تست سرویس آزادسازی'
                && $note === null
            );
    });

    $this->actingAs($manager)
        ->post(route('warehouse-reservations.release', $reservation), [
            'release_reason' => 'تست سرویس آزادسازی',
        ])
        ->assertRedirect();
});
