<?php

use App\Models\Category;
use App\Models\Invoice;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\ReservationQueryService;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function phaseFourLifecycleUser(array $permissions): User
{
    $role = Role::findOrCreate('phase-four-lifecycle-'.Str::random(10), 'web');

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

function phaseFourLifecycleSeller(): User
{
    $seller = phaseFourLifecycleUser(['page.sales.preinvoices']);
    $seller->forceFill(['is_seller' => true])->save();

    return $seller;
}

function phaseFourLifecycleFinanceUser(): User
{
    return phaseFourLifecycleUser(['page.sales.preinvoice_finance_review']);
}

/** @return array{product:Product,variant:ProductVariant,warehouseStock:WarehouseStock} */
function phaseFourLifecycleInventory(): array
{
    $category = Category::withoutEvents(fn () => Category::query()->create([
        'name' => 'Phase 4 lifecycle '.Str::uuid(),
    ]));
    $product = Product::withoutEvents(fn () => Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Phase 4 lifecycle product '.Str::random(8),
        'sku' => 'P4-LIFE-'.Str::uuid(),
        'stock' => 100,
        'reserved' => 0,
        'price' => 100_000,
        'is_sellable' => true,
    ]));
    $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'sales_enabled' => true,
        'variant_name' => 'Phase 4 lifecycle variant '.Str::random(8),
        'variant_code' => 'P4-LIFE-V-'.Str::uuid(),
        'sell_price' => 100_000,
        'stock' => 100,
        'reserved' => 0,
    ]));
    $warehouseStock = WarehouseStock::withoutEvents(fn () => WarehouseStock::query()->create([
        'warehouse_id' => WarehouseStockService::centralWarehouseId(),
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'quantity' => 100,
    ]));

    return compact('product', 'variant', 'warehouseStock');
}

function phaseFourPhysicalQuantity(int $productId, int $variantId): int
{
    return (int) WarehouseStock::query()
        ->where('warehouse_id', WarehouseStockService::centralWarehouseId())
        ->where('product_id', $productId)
        ->where('product_variant_id', $variantId)
        ->value('quantity');
}

function phaseFourCanonicalReserved(int $productId, int $variantId): int
{
    return (int) app(ReservationQueryService::class)
        ->quantitiesByVariant($productId, [$variantId])
        ->get($variantId, 0);
}

function phaseFourSyncReservation($test, User $seller, array $inventory, int $quantity, ?string $token = null): array
{
    $token ??= (string) Str::uuid();

    $test->actingAs($seller)
        ->postJson(route('preinvoice.api.reservations.sync'), [
            'reservation_token' => $token,
            'submission_token' => $token,
            'items' => [[
                'product_id' => $inventory['product']->id,
                'variant_id' => $inventory['variant']->id,
                'quantity' => $quantity,
            ]],
            'is_in_person' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.reserved.0.quantity', $quantity);

    $reservation = PreinvoiceDraftReservation::query()
        ->where('token', $token)
        ->where('product_id', $inventory['product']->id)
        ->where('variant_id', $inventory['variant']->id)
        ->sole();

    return compact('token', 'reservation');
}

function phaseFourPreinvoicePayload(array $inventory, string $token, int $quantity): array
{
    return [
        'intent' => 'submit',
        'reservation_token' => $token,
        'customer_name' => 'Phase 4 lifecycle customer',
        'customer_mobile' => '09120000000',
        'is_in_person' => 0,
        'discount_amount' => 0,
        'products_payload' => json_encode([[
            'item_id' => null,
            'id' => $inventory['product']->id,
            'product_id' => $inventory['product']->id,
            'variety_id' => $inventory['variant']->id,
            'variant_id' => $inventory['variant']->id,
            'quantity' => $quantity,
            'price' => 100_000,
            'line_discount_amount' => 0,
        ]], JSON_THROW_ON_ERROR),
        'products_payload_count' => 1,
        'products_payload_version' => 1,
        'products_payload_complete' => 1,
        'products_payload_total_quantity' => $quantity,
        'products_payload_gross_total' => $quantity * 100_000,
    ];
}

it('preserves physical stock while a temporary reservation becomes official and then consumed', function () {
    $seller = phaseFourLifecycleSeller();
    $finance = phaseFourLifecycleFinanceUser();
    $inventory = phaseFourLifecycleInventory();

    ['token' => $token, 'reservation' => $reservation] = phaseFourSyncReservation($this, $seller, $inventory, 10);

    expect(phaseFourPhysicalQuantity($inventory['product']->id, $inventory['variant']->id))->toBe(90)
        ->and(phaseFourCanonicalReserved($inventory['product']->id, $inventory['variant']->id))->toBe(10);

    $movementsAfterTemporaryReserve = DB::table('stock_movements')->count();
    $payload = phaseFourPreinvoicePayload($inventory, $token, 10);

    $this->actingAs($seller)
        ->post(route('preinvoice.draft.save'), $payload)
        ->assertSessionHasNoErrors();

    $order = PreinvoiceOrder::query()->where('created_by', $seller->id)->latest('id')->firstOrFail();
    $reservation->refresh();

    expect($order->status)->toBe(PreinvoiceOrder::STATUS_PENDING_FINANCE)
        ->and($reservation->preinvoice_order_id)->toBe($order->id)
        ->and($reservation->reservation_scope)->toBe(PreinvoiceDraftReservation::SCOPE_OFFICIAL)
        ->and(phaseFourPhysicalQuantity($inventory['product']->id, $inventory['variant']->id))->toBe(90)
        ->and(phaseFourCanonicalReserved($inventory['product']->id, $inventory['variant']->id))->toBe(10)
        ->and(DB::table('stock_movements')->count())->toBe($movementsAfterTemporaryReserve);

    $this->actingAs($finance)
        ->post(route('preinvoice.draft.finalize', $order->uuid), [])
        ->assertSessionHasNoErrors();

    $reservation->refresh();

    expect(Invoice::query()->where('preinvoice_order_id', $order->id)->exists())->toBeTrue()
        ->and($reservation->release_reason)->toBe('consumed')
        ->and($reservation->converted_at)->not->toBeNull()
        ->and(phaseFourPhysicalQuantity($inventory['product']->id, $inventory['variant']->id))->toBe(90)
        ->and(phaseFourCanonicalReserved($inventory['product']->id, $inventory['variant']->id))->toBe(0)
        ->and(DB::table('stock_movements')->count())->toBe($movementsAfterTemporaryReserve)
        ->and(PreinvoiceDraftReservation::query()
            ->where('preinvoice_order_id', $order->id)
            ->whereNull('released_at')
            ->whereNull('release_reason')
            ->exists())->toBeFalse();
});

it('applies only the exact physical stock delta when temporary quantity changes', function () {
    $seller = phaseFourLifecycleSeller();
    $inventory = phaseFourLifecycleInventory();
    ['token' => $token] = phaseFourSyncReservation($this, $seller, $inventory, 10);

    expect(phaseFourPhysicalQuantity($inventory['product']->id, $inventory['variant']->id))->toBe(90)
        ->and(phaseFourCanonicalReserved($inventory['product']->id, $inventory['variant']->id))->toBe(10);

    phaseFourSyncReservation($this, $seller, $inventory, 15, $token);

    expect(phaseFourPhysicalQuantity($inventory['product']->id, $inventory['variant']->id))->toBe(85)
        ->and(phaseFourCanonicalReserved($inventory['product']->id, $inventory['variant']->id))->toBe(15);

    phaseFourSyncReservation($this, $seller, $inventory, 6, $token);

    expect(phaseFourPhysicalQuantity($inventory['product']->id, $inventory['variant']->id))->toBe(94)
        ->and(phaseFourCanonicalReserved($inventory['product']->id, $inventory['variant']->id))->toBe(6);
});

it('returns temporary stock exactly once on cancellation and repeated release', function () {
    $seller = phaseFourLifecycleSeller();
    $inventory = phaseFourLifecycleInventory();
    ['token' => $token, 'reservation' => $reservation] = phaseFourSyncReservation($this, $seller, $inventory, 10);

    $this->actingAs($seller)
        ->postJson(route('preinvoice.reservations.release-token'), ['token' => $token])
        ->assertOk();

    expect($reservation->fresh()->released_at)->not->toBeNull()
        ->and(phaseFourPhysicalQuantity($inventory['product']->id, $inventory['variant']->id))->toBe(100)
        ->and(phaseFourCanonicalReserved($inventory['product']->id, $inventory['variant']->id))->toBe(0);

    $this->actingAs($seller)
        ->postJson(route('preinvoice.reservations.release-token'), ['token' => $token])
        ->assertOk();
    $this->artisan('reservations:cleanup')->assertSuccessful();

    expect(phaseFourPhysicalQuantity($inventory['product']->id, $inventory['variant']->id))->toBe(100)
        ->and(phaseFourCanonicalReserved($inventory['product']->id, $inventory['variant']->id))->toBe(0);
});

it('returns genuinely expired temporary stock exactly once', function () {
    $seller = phaseFourLifecycleSeller();
    $inventory = phaseFourLifecycleInventory();
    ['reservation' => $reservation] = phaseFourSyncReservation($this, $seller, $inventory, 10);
    $reservation->forceFill([
        'expires_at' => now()->subMinute(),
        'last_seen_at' => now()->subMinutes(10),
    ])->save();

    $this->artisan('reservations:cleanup')->assertSuccessful();

    expect($reservation->fresh()->release_reason)->toBe('temporary_session_lost')
        ->and(phaseFourPhysicalQuantity($inventory['product']->id, $inventory['variant']->id))->toBe(100)
        ->and(phaseFourCanonicalReserved($inventory['product']->id, $inventory['variant']->id))->toBe(0);

    $this->artisan('reservations:cleanup')->assertSuccessful();

    expect(phaseFourPhysicalQuantity($inventory['product']->id, $inventory['variant']->id))->toBe(100)
        ->and(phaseFourCanonicalReserved($inventory['product']->id, $inventory['variant']->id))->toBe(0);
});

it('does not double reserve when final submission is retried', function () {
    $seller = phaseFourLifecycleSeller();
    $inventory = phaseFourLifecycleInventory();
    ['token' => $token] = phaseFourSyncReservation($this, $seller, $inventory, 10);
    $payload = phaseFourPreinvoicePayload($inventory, $token, 10);

    $this->actingAs($seller)
        ->post(route('preinvoice.draft.save'), $payload)
        ->assertSessionHasNoErrors();
    $this->actingAs($seller)
        ->post(route('preinvoice.draft.save'), $payload)
        ->assertSessionHasErrors('preinvoice');

    expect(phaseFourPhysicalQuantity($inventory['product']->id, $inventory['variant']->id))->toBe(90)
        ->and(phaseFourCanonicalReserved($inventory['product']->id, $inventory['variant']->id))->toBe(10)
        ->and(PreinvoiceDraftReservation::query()->where('token', $token)->count())->toBe(1)
        ->and(PreinvoiceOrder::query()->where('created_by', $seller->id)->where('is_auto_draft', false)->count())->toBe(1);
});

it('uses physical stock and reservation rows instead of corrupted reserved projections', function () {
    $seller = phaseFourLifecycleSeller();
    $inventory = phaseFourLifecycleInventory();
    ['token' => $token] = phaseFourSyncReservation($this, $seller, $inventory, 10);

    $inventory['product']->forceFill(['reserved' => 0])->save();
    $inventory['variant']->forceFill(['reserved' => 999])->save();

    phaseFourSyncReservation($this, $seller, $inventory, 15, $token);

    expect(phaseFourPhysicalQuantity($inventory['product']->id, $inventory['variant']->id))->toBe(85)
        ->and(phaseFourCanonicalReserved($inventory['product']->id, $inventory['variant']->id))->toBe(15);

    $inventory['product']->forceFill(['reserved' => 999])->save();
    $inventory['variant']->forceFill(['reserved' => 0])->save();

    $this->actingAs($seller)
        ->postJson(route('preinvoice.api.reservations.sync'), [
            'reservation_token' => $token,
            'submission_token' => $token,
            'items' => [[
                'product_id' => $inventory['product']->id,
                'variant_id' => $inventory['variant']->id,
                'quantity' => 101,
            ]],
            'is_in_person' => false,
        ])
        ->assertUnprocessable();

    expect(phaseFourPhysicalQuantity($inventory['product']->id, $inventory['variant']->id))->toBe(85)
        ->and(phaseFourCanonicalReserved($inventory['product']->id, $inventory['variant']->id))->toBe(15);
});
