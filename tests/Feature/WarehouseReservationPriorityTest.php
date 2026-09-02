<?php

use App\Models\Category;
use App\Models\PreinvoiceDraftReservation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

function warehouseReservationPriorityViewer(): User
{
    $role = Role::findOrCreate('warehouse-reservation-priority-'.Str::random(8), 'web');
    $role->givePermissionTo(Permission::query()->where('key', 'warehouse_reservations.view')->firstOrFail());
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function warehouseReservationPriorityFixture(
    string $name,
    int $ageMinutes,
    string $scope = PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE,
    ?int $lastSeenMinutesAgo = 0,
): PreinvoiceDraftReservation {
    $seller = User::factory()->create();
    $category = Category::withoutEvents(fn () => Category::query()->create([
        'name' => 'Priority category '.Str::uuid(),
    ]));
    $product = Product::withoutEvents(fn () => Product::query()->create([
        'category_id' => $category->id,
        'name' => $name,
        'sku' => 'PRIORITY-'.Str::uuid(),
        'stock' => 20,
        'reserved' => 5,
        'price' => 100000,
        'is_sellable' => true,
    ]));
    $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'sales_enabled' => true,
        'variant_name' => $name.' variant',
        'variant_code' => 'PRIORITY-V-'.Str::uuid(),
        'sell_price' => 100000,
        'stock' => 20,
        'reserved' => 5,
    ]));
    $reservation = PreinvoiceDraftReservation::query()->create([
        'token' => (string) Str::uuid(),
        'user_id' => $seller->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => 5,
        'expires_at' => now()->addHour(),
        'last_seen_at' => $lastSeenMinutesAgo === null ? null : now()->subMinutes($lastSeenMinutesAgo),
        'reservation_scope' => $scope,
    ]);
    $reservation->forceFill([
        'created_at' => now()->subMinutes($ageMinutes),
        'updated_at' => now()->subMinutes($ageMinutes),
    ])->saveQuietly();

    return $reservation->fresh();
}

it('sorts reservations by management priority and then oldest first', function () {
    $active = warehouseReservationPriorityFixture('Priority Active', 180);
    $review = warehouseReservationPriorityFixture('Priority Review', 90, 'legacy_unknown', null);
    $newerActionable = warehouseReservationPriorityFixture('Priority New Action', 30, lastSeenMinutesAgo: 30);
    $olderActionable = warehouseReservationPriorityFixture('Priority Old Action', 120, lastSeenMinutesAgo: 120);

    $response = $this->actingAs(warehouseReservationPriorityViewer())
        ->getJson(route('warehouse-reservations.index'))
        ->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toBe([$olderActionable->id, $newerActionable->id, $review->id, $active->id]);
});

it('shows an actionable releasable reservation before other statuses', function () {
    warehouseReservationPriorityFixture('Priority Normal Active', 200);
    warehouseReservationPriorityFixture('Priority Manual Review', 150, 'legacy_unknown', null);
    $actionable = warehouseReservationPriorityFixture('Priority Releasable First', 20, lastSeenMinutesAgo: 20);

    $this->actingAs(warehouseReservationPriorityViewer())
        ->getJson(route('warehouse-reservations.index'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $actionable->id)
        ->assertJsonPath('data.0.releasable', true)
        ->assertJsonPath('data.0.priority', 1);
});

it('displays a readable reservation age', function () {
    warehouseReservationPriorityFixture('Priority Age Product', 125);

    $this->actingAs(warehouseReservationPriorityViewer())
        ->get(route('warehouse-reservations.index', ['search' => 'Priority Age Product']))
        ->assertOk()
        ->assertSee('2 ساعت قبل');
});

it('shows old reservation warnings only when the reservation is old', function () {
    $old = warehouseReservationPriorityFixture('Priority Old Warning', 120, lastSeenMinutesAgo: 120);
    $recent = warehouseReservationPriorityFixture('Priority Recent Safe', 35);

    expect($old->managementWarning())->toBe('رزرو قدیمی و قابل آزادسازی است.')
        ->and($old->managementImportance())->toBe(PreinvoiceDraftReservation::IMPORTANCE_CRITICAL)
        ->and($recent->managementWarning())->toBeNull();

    $this->actingAs(warehouseReservationPriorityViewer())
        ->get(route('warehouse-reservations.index', ['search' => 'Priority Old Warning']))
        ->assertOk()
        ->assertSee('رزرو قدیمی و قابل آزادسازی است.')
        ->assertSee('فوری');
});

it('does not change reservation data while displaying the prioritized list', function () {
    warehouseReservationPriorityFixture('Priority Read Only Action', 120, lastSeenMinutesAgo: 120);
    warehouseReservationPriorityFixture('Priority Read Only Active', 10);
    $before = DB::table('preinvoice_draft_reservations')->orderBy('id')->get()->toJson();

    $this->actingAs(warehouseReservationPriorityViewer())
        ->get(route('warehouse-reservations.index'))
        ->assertOk();

    expect(DB::table('preinvoice_draft_reservations')->orderBy('id')->get()->toJson())->toBe($before);
});
