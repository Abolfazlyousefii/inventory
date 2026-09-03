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
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function warehouseReservationOrphanUser(array $permissions): User
{
    $role = Role::findOrCreate('warehouse-reservation-orphan-'.Str::random(8), 'web');

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::query()->where('key', $permission)->firstOrFail());
    }

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function warehouseReservationOrphanFixture(string $productName, array $reservationOverrides = []): array
{
    $seller = User::factory()->create();
    $category = Category::withoutEvents(fn () => Category::query()->create([
        'name' => 'Orphan category '.Str::uuid(),
    ]));
    $product = Product::withoutEvents(fn () => Product::query()->create([
        'category_id' => $category->id,
        'name' => $productName,
        'sku' => 'ORPHAN-'.Str::uuid(),
        'stock' => 20,
        'reserved' => 5,
        'price' => 100000,
        'is_sellable' => true,
    ]));
    $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'sales_enabled' => true,
        'variant_name' => $productName.' variant',
        'variant_code' => 'ORPHAN-V-'.Str::uuid(),
        'sell_price' => 100000,
        'stock' => 20,
        'reserved' => 5,
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
        'quantity' => 5,
        'expires_at' => now()->addHour(),
        'last_seen_at' => now()->subHour(),
        'reservation_scope' => PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE,
    ], $reservationOverrides));

    return compact('seller', 'product', 'variant', 'warehouseStock', 'reservation');
}

function warehouseReservationOrphanDraft(User $seller, string $token): PreinvoiceOrder
{
    return PreinvoiceOrder::withoutEvents(fn () => PreinvoiceOrder::query()->create([
        'uuid' => (string) Str::uuid(),
        'created_by' => $seller->id,
        'seller_id' => $seller->id,
        'document_date' => now(),
        'status' => PreinvoiceOrder::STATUS_DRAFT,
        'customer_name' => 'Orphan protection customer',
        'customer_mobile' => '09120000000',
        'total_price' => 100000,
        'is_auto_draft' => true,
        'auto_saved_at' => now(),
        'draft_token' => $token,
    ]));
}

it('shows a reservation without a preinvoice or valid heartbeat in the orphan tab', function () {
    $reservation = warehouseReservationOrphanFixture('Visible Orphan Product')['reservation'];
    $viewer = warehouseReservationOrphanUser(['warehouse_reservations.view']);

    $this->actingAs($viewer)
        ->get(route('warehouse-reservations.index', ['tab' => 'orphaned']))
        ->assertOk()
        ->assertViewHas('orphanedCount', 1)
        ->assertViewHas('orphanedReservations', function ($reservations) use ($reservation): bool {
            return $reservations->getPageName() === 'orphan_page'
                && $reservations->contains('id', $reservation->id);
        })
        ->assertSee('Visible Orphan Product')
        ->assertSee('رزرو رها شده');

    $this->actingAs($viewer)
        ->get(route('warehouse-reservations.index'))
        ->assertOk()
        ->assertDontSee('Visible Orphan Product');
});

it('does not show a reservation connected to a preinvoice as orphaned', function () {
    ['seller' => $seller, 'reservation' => $reservation] = warehouseReservationOrphanFixture('Connected Reservation Product');
    $order = warehouseReservationOrphanDraft($seller, (string) Str::uuid());
    $reservation->forceFill(['preinvoice_order_id' => $order->id])->save();

    $this->actingAs(warehouseReservationOrphanUser(['warehouse_reservations.view']))
        ->get(route('warehouse-reservations.index', ['tab' => 'orphaned']))
        ->assertOk()
        ->assertViewHas('orphanedCount', 0)
        ->assertDontSee('Connected Reservation Product');
});

it('protects reservations with an active draft or a valid heartbeat from the orphan list', function () {
    ['seller' => $seller, 'reservation' => $draftReservation] = warehouseReservationOrphanFixture('Active Draft Protected Product');
    warehouseReservationOrphanDraft($seller, $draftReservation->token);
    warehouseReservationOrphanFixture('Heartbeat Protected Product', ['last_seen_at' => now()]);

    $this->actingAs(warehouseReservationOrphanUser(['warehouse_reservations.view']))
        ->get(route('warehouse-reservations.index', ['tab' => 'orphaned']))
        ->assertOk()
        ->assertViewHas('orphanedCount', 0)
        ->assertDontSee('Active Draft Protected Product')
        ->assertDontSee('Heartbeat Protected Product');
});

it('allows orphan release only with the release permission', function () {
    ['reservation' => $reservation] = warehouseReservationOrphanFixture('Permission Protected Orphan');
    $viewOnlyUser = warehouseReservationOrphanUser(['warehouse_reservations.view']);

    $this->actingAs($viewOnlyUser)
        ->get(route('warehouse-reservations.index', ['tab' => 'orphaned']))
        ->assertOk()
        ->assertDontSee('release-orphan-'.$reservation->id, false);

    $this->actingAs($viewOnlyUser)
        ->post(route('warehouse-reservations.release', $reservation), ['release_reason' => 'رزرو رها شده'])
        ->assertForbidden();

    $releaseUser = warehouseReservationOrphanUser([
        'warehouse_reservations.view',
        'warehouse_reservations.release',
    ]);

    $this->actingAs($releaseUser)
        ->post(route('warehouse-reservations.release', $reservation), ['release_reason' => 'رزرو رها شده'])
        ->assertRedirect();

    expect($reservation->fresh()->released_by)->toBe($releaseUser->id);
});

it('delegates orphan release to the existing inventory reservation release service', function () {
    ['reservation' => $reservation] = warehouseReservationOrphanFixture('Service Delegation Orphan');
    $manager = warehouseReservationOrphanUser([
        'warehouse_reservations.view',
        'warehouse_reservations.release',
    ]);

    $this->mock(InventoryReservationReleaseService::class, function (MockInterface $mock) use ($manager, $reservation): void {
        $mock->shouldReceive('releaseDraftReservation')
            ->once()
            ->withArgs(function (PreinvoiceDraftReservation $givenReservation, User $actor, string $reason, ?string $note) use ($manager, $reservation): bool {
                return $givenReservation->is($reservation)
                    && $actor->is($manager)
                    && $reason === 'رزرو رها شده'
                    && $note === null;
            });
    });

    $this->actingAs($manager)
        ->post(route('warehouse-reservations.release', $reservation), ['release_reason' => 'رزرو رها شده'])
        ->assertRedirect();
});

it('does not release the same orphan reservation twice', function () {
    ['reservation' => $reservation, 'warehouseStock' => $warehouseStock] = warehouseReservationOrphanFixture('Idempotent Orphan');
    $manager = warehouseReservationOrphanUser([
        'warehouse_reservations.view',
        'warehouse_reservations.release',
    ]);
    $payload = ['release_reason' => 'رزرو رها شده'];

    $this->actingAs($manager)->postJson(route('warehouse-reservations.release', $reservation), $payload)->assertOk();
    $this->actingAs($manager)
        ->postJson(route('warehouse-reservations.release', $reservation), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reservation');

    expect($warehouseStock->fresh()->quantity)->toBe(25)
        ->and(ActivityLog::query()
            ->where('action', 'reservation_manual_release')
            ->where('subject_id', $reservation->id)
            ->count())->toBe(1);
});

it('does not mutate reservation or inventory data while displaying orphaned reservations', function () {
    warehouseReservationOrphanFixture('Read Only Orphan');
    $before = [
        'reservations' => DB::table('preinvoice_draft_reservations')->orderBy('id')->get()->toJson(),
        'products' => DB::table('products')->orderBy('id')->get()->toJson(),
        'variants' => DB::table('product_variants')->orderBy('id')->get()->toJson(),
        'warehouse_stocks' => DB::table('warehouse_stocks')->orderBy('id')->get()->toJson(),
    ];

    $this->actingAs(warehouseReservationOrphanUser(['warehouse_reservations.view']))
        ->get(route('warehouse-reservations.index', ['tab' => 'orphaned']))
        ->assertOk();

    expect([
        'reservations' => DB::table('preinvoice_draft_reservations')->orderBy('id')->get()->toJson(),
        'products' => DB::table('products')->orderBy('id')->get()->toJson(),
        'variants' => DB::table('product_variants')->orderBy('id')->get()->toJson(),
        'warehouse_stocks' => DB::table('warehouse_stocks')->orderBy('id')->get()->toJson(),
    ])->toBe($before);
});
