<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\PreinvoiceDraftReservation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\WarehouseStockService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function warehouseReservationLifecycleFixture(int $quantity = 4): array
{
    $seller = User::factory()->create(['name' => 'فروشنده چرخه رزرو']);
    $category = Category::withoutEvents(fn () => Category::query()->create([
        'name' => 'دسته چرخه رزرو '.Str::uuid(),
    ]));
    $product = Product::withoutEvents(fn () => Product::query()->create([
        'category_id' => $category->id,
        'name' => 'کالای چرخه کامل رزرو',
        'sku' => 'LIFECYCLE-'.Str::uuid(),
        'stock' => 20,
        'reserved' => $quantity,
        'price' => 100000,
        'is_sellable' => true,
    ]));
    $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'sales_enabled' => true,
        'variant_name' => 'تنوع چرخه کامل رزرو',
        'variant_code' => 'LIFECYCLE-VARIANT',
        'sell_price' => 100000,
        'stock' => 20,
        'reserved' => $quantity,
    ]));
    $warehouseStock = WarehouseStock::withoutEvents(fn () => WarehouseStock::query()->create([
        'warehouse_id' => WarehouseStockService::centralWarehouseId(),
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'quantity' => 20,
    ]));
    $reservation = PreinvoiceDraftReservation::query()->create([
        'token' => (string) Str::uuid(),
        'user_id' => $seller->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => $quantity,
        'expires_at' => now()->addHour(),
        'last_seen_at' => now(),
        'reservation_scope' => PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE,
    ]);

    return compact('seller', 'product', 'variant', 'warehouseStock', 'reservation');
}

it('runs the abandoned reservation lifecycle once from cleanup through activity logging', function () {
    ['product' => $product, 'variant' => $variant, 'warehouseStock' => $warehouseStock, 'reservation' => $reservation]
        = warehouseReservationLifecycleFixture();

    expect($reservation->isActiveTemporary())->toBeTrue();

    $reservation->forceFill(['last_seen_at' => now()->subHour()])->save();

    expect($reservation->fresh()->isAbandoned())->toBeTrue();

    $this->artisan('reservations:cleanup')->assertSuccessful();

    $released = $reservation->fresh();
    $activity = ActivityLog::query()
        ->where('action', 'reservation_auto_release')
        ->where('subject_id', $reservation->id)
        ->sole();

    expect($released->released_at)->not->toBeNull()
        ->and($released->released_by)->toBeNull()
        ->and($released->release_reason)->toBe('temporary_session_lost')
        ->and($product->fresh()->reserved)->toBe(0)
        ->and($variant->fresh()->reserved)->toBe(0)
        ->and($warehouseStock->fresh()->quantity)->toBe(24)
        ->and($activity->user_id)->toBeNull()
        ->and($activity->properties['reservation_id'])->toBe($reservation->id)
        ->and($activity->properties['product'])->toBe('کالای چرخه کامل رزرو')
        ->and($activity->properties['variant'])->toBe('تنوع چرخه کامل رزرو')
        ->and($activity->properties['quantity'])->toBe(4)
        ->and($activity->properties['reason'])->toBe('temporary_session_lost');

    $this->artisan('reservations:cleanup')->assertSuccessful();

    expect($product->fresh()->reserved)->toBe(0)
        ->and($variant->fresh()->reserved)->toBe(0)
        ->and($warehouseStock->fresh()->quantity)->toBe(24)
        ->and(ActivityLog::query()
            ->where('action', 'reservation_auto_release')
            ->where('subject_id', $reservation->id)
            ->count())->toBe(1);
});

it('shows released reservation history with actor and release reason', function () {
    ['seller' => $seller, 'reservation' => $reservation] = warehouseReservationLifecycleFixture();
    $reservation->forceFill([
        'released_at' => now(),
        'released_by' => $seller->id,
        'release_reason' => 'انصراف مشتری',
        'release_note' => 'درخواست ثبت‌شده توسط فروشنده',
    ])->save();

    $role = Role::findOrCreate('warehouse-lifecycle-viewer', 'web');
    $role->givePermissionTo(Permission::query()->where('key', 'warehouse_reservations.view')->firstOrFail());
    $manager = User::factory()->create();
    $manager->assignRole($role);

    $this->actingAs($manager)
        ->get(route('warehouse-reservations.index'))
        ->assertOk()
        ->assertSee('تاریخچه آزادسازی رزروها')
        ->assertSee('کالای چرخه کامل رزرو')
        ->assertSee('تنوع چرخه کامل رزرو')
        ->assertSee('فروشنده چرخه رزرو')
        ->assertSee('انصراف مشتری')
        ->assertSee('درخواست ثبت‌شده توسط فروشنده');
});

it('schedules cleanup every ten minutes with an overlap lock', function () {
    $cleanupEvent = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => $event->description === 'warehouse-reservation-cleanup');

    expect($cleanupEvent)->not->toBeNull()
        ->and($cleanupEvent->expression)->toBe('*/10 * * * *')
        ->and($cleanupEvent->withoutOverlapping)->toBeTrue()
        ->and($cleanupEvent->expiresAt)->toBe(15);
});
