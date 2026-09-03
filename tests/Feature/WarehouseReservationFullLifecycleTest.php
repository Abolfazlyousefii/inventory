<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Invoice;
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

function fullLifecycleUser(array $permissions): User
{
    $role = Role::findOrCreate('full-lifecycle-'.Str::random(10), 'web');

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

function fullLifecycleSeller(): User
{
    $seller = fullLifecycleUser(['page.sales.preinvoices']);
    $seller->forceFill(['is_seller' => true])->save();

    return $seller;
}

function fullLifecycleManager(bool $canRelease = true): User
{
    $permissions = ['warehouse_reservations.view'];
    if ($canRelease) {
        $permissions[] = 'warehouse_reservations.release';
    }

    return fullLifecycleUser($permissions);
}

function fullLifecycleFinanceUser(): User
{
    return fullLifecycleUser(['page.sales.preinvoice_finance_review']);
}

function fullLifecycleInventory(int $stock = 20): array
{
    $category = Category::withoutEvents(fn () => Category::query()->create([
        'name' => 'Full lifecycle '.Str::uuid(),
    ]));
    $product = Product::withoutEvents(fn () => Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Full lifecycle product '.Str::random(8),
        'sku' => 'FULL-'.Str::uuid(),
        'stock' => $stock,
        'reserved' => 0,
        'price' => 100_000,
        'is_sellable' => true,
    ]));
    $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'sales_enabled' => true,
        'variant_name' => 'Full lifecycle variant '.Str::random(8),
        'variant_code' => 'FULL-V-'.Str::uuid(),
        'sell_price' => 100_000,
        'stock' => $stock,
        'reserved' => 0,
    ]));
    $warehouseStock = WarehouseStock::withoutEvents(fn () => WarehouseStock::query()->create([
        'warehouse_id' => WarehouseStockService::centralWarehouseId(),
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'quantity' => $stock,
    ]));

    return compact('product', 'variant', 'warehouseStock');
}

function fullLifecycleSyncReservation($test, User $seller, array $inventory, int $quantity = 4, ?string $token = null): array
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

function fullLifecyclePreinvoicePayload(array $inventory, string $token, int $quantity, ?int $itemId = null): array
{
    $row = [
        'item_id' => $itemId,
        'id' => $inventory['product']->id,
        'product_id' => $inventory['product']->id,
        'variety_id' => $inventory['variant']->id,
        'variant_id' => $inventory['variant']->id,
        'quantity' => $quantity,
        'price' => 100_000,
        'line_discount_amount' => 0,
    ];

    return [
        'intent' => 'submit',
        'reservation_token' => $token,
        'customer_name' => 'Full lifecycle customer',
        'customer_mobile' => '09120000000',
        'is_in_person' => 0,
        'discount_amount' => 0,
        'products_payload' => json_encode([$row], JSON_THROW_ON_ERROR),
        'products_payload_count' => 1,
        'products_payload_version' => 1,
        'products_payload_complete' => 1,
        'products_payload_total_quantity' => $quantity,
        'products_payload_gross_total' => $quantity * 100_000,
    ];
}

function fullLifecycleSubmitPreinvoice($test, User $seller, array $inventory, int $quantity = 4): array
{
    ['token' => $token, 'reservation' => $reservation] = fullLifecycleSyncReservation(
        $test,
        $seller,
        $inventory,
        $quantity,
    );

    $test->actingAs($seller)
        ->post(route('preinvoice.draft.save'), fullLifecyclePreinvoicePayload($inventory, $token, $quantity))
        ->assertSessionHasNoErrors();

    $order = PreinvoiceOrder::query()->where('created_by', $seller->id)->latest('id')->firstOrFail();

    return compact('token', 'reservation', 'order');
}

function fullLifecycleAutosave($test, User $seller, array $inventory, string $token, int $quantity = 3): PreinvoiceOrder
{
    $response = $test->actingAs($seller)
        ->postJson(route('preinvoice.autosave'), [
            'reservation_token' => $token,
            'customer_name' => 'Full lifecycle draft customer',
            'customer_mobile' => '09120000000',
            'is_in_person' => false,
            'products' => [[
                'id' => $inventory['product']->id,
                'variety_id' => $inventory['variant']->id,
                'quantity' => $quantity,
                'price' => 100_000,
                'line_discount_amount' => 0,
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('ok', true);

    return PreinvoiceOrder::query()->where('uuid', $response->json('uuid'))->sole();
}

function fullLifecycleSnapshot(): array
{
    return [
        'reservations' => DB::table('preinvoice_draft_reservations')->orderBy('id')->get()->toJson(),
        'orders' => DB::table('preinvoice_orders')->orderBy('id')->get()->toJson(),
        'products' => DB::table('products')->orderBy('id')->get()->toJson(),
        'variants' => DB::table('product_variants')->orderBy('id')->get()->toJson(),
        'warehouse_stocks' => DB::table('warehouse_stocks')->orderBy('id')->get()->toJson(),
        'activities' => DB::table('activity_logs')->orderBy('id')->get()->toJson(),
    ];
}

it('creates a reservation through the real reservation endpoint', function () {
    $seller = fullLifecycleSeller();
    $inventory = fullLifecycleInventory();

    ['token' => $token, 'reservation' => $reservation] = fullLifecycleSyncReservation($this, $seller, $inventory);

    expect($reservation->token)->toBe($token)
        ->and($reservation->user_id)->toBe($seller->id)
        ->and($reservation->quantity)->toBe(4)
        ->and($reservation->reservation_scope)->toBe(PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE)
        ->and($reservation->released_at)->toBeNull()
        ->and($reservation->isActiveTemporary())->toBeTrue();
});

it('decreases stock and increases both reserved caches', function () {
    $seller = fullLifecycleSeller();
    $inventory = fullLifecycleInventory();

    fullLifecycleSyncReservation($this, $seller, $inventory, 4);

    expect($inventory['warehouseStock']->fresh()->quantity)->toBe(16)
        ->and($inventory['variant']->fresh()->stock)->toBe(16)
        ->and($inventory['product']->fresh()->stock)->toBe(16)
        ->and($inventory['variant']->fresh()->reserved)->toBe(4)
        ->and($inventory['product']->fresh()->reserved)->toBe(4);
});

it('keeps a heartbeating reservation safe from cleanup', function () {
    $seller = fullLifecycleSeller();
    $inventory = fullLifecycleInventory();
    ['token' => $token, 'reservation' => $reservation] = fullLifecycleSyncReservation($this, $seller, $inventory, 4);
    $reservation->forceFill(['last_seen_at' => now()->subHour()])->save();

    $this->actingAs($seller)
        ->postJson(route('preinvoice.reservations.heartbeat'), [
            'token' => $token,
            'browser_session_id' => 'full-lifecycle-browser',
        ])
        ->assertOk()
        ->assertJsonPath('updated', 1);

    $this->artisan('reservations:cleanup')->assertSuccessful();

    expect($reservation->fresh()->released_at)->toBeNull()
        ->and($reservation->fresh()->browser_session_id)->toBe('full-lifecycle-browser')
        ->and($inventory['warehouseStock']->fresh()->quantity)->toBe(16)
        ->and($inventory['variant']->fresh()->reserved)->toBe(4)
        ->and(ActivityLog::query()
            ->where('subject_type', PreinvoiceDraftReservation::class)
            ->where('subject_id', $reservation->id)
            ->where('action', 'reservation_auto_release')
            ->count())->toBe(0);
});

it('connects a temporary reservation to a preinvoice through the submission flow', function () {
    $seller = fullLifecycleSeller();
    $inventory = fullLifecycleInventory();
    ['reservation' => $reservation, 'order' => $order] = fullLifecycleSubmitPreinvoice($this, $seller, $inventory);
    $reservation = $reservation->fresh();

    expect($order->status)->toBe(PreinvoiceOrder::STATUS_PENDING_FINANCE)
        ->and($reservation->preinvoice_order_id)->toBe($order->id)
        ->and($reservation->converted_at)->toBeNull()
        ->and($reservation->reservation_scope)->toBe('official')
        ->and($reservation->quantity)->toBe(4)
        ->and($inventory['warehouseStock']->fresh()->quantity)->toBe(16)
        ->and($inventory['variant']->fresh()->reserved)->toBe(4);
});

it('closes the reservation lifecycle when a preinvoice becomes an invoice', function () {
    $seller = fullLifecycleSeller();
    $finance = fullLifecycleFinanceUser();
    $inventory = fullLifecycleInventory();
    ['reservation' => $reservation, 'order' => $order] = fullLifecycleSubmitPreinvoice($this, $seller, $inventory);

    $warehouseBefore = $inventory['warehouseStock']->fresh()->quantity;

    $this->actingAs($finance)
        ->post(route('preinvoice.draft.finalize', $order->uuid), [])
        ->assertSessionHasNoErrors();

    $invoice = Invoice::query()->where('preinvoice_order_id', $order->id)->sole();
    $reservation = $reservation->fresh();

    expect($invoice)->not->toBeNull()
        ->and($reservation->converted_at)->not->toBeNull()
        ->and($reservation->released_at)->not->toBeNull()
        ->and($reservation->release_reason)->toBe('consumed')
        ->and($inventory['product']->fresh()->reserved)->toBe(0)
        ->and($inventory['variant']->fresh()->reserved)->toBe(0)
        ->and($inventory['warehouseStock']->fresh()->quantity)->toBe($warehouseBefore);
});

it('repairs only verified converted reservations and rebuilds product and variant caches without changing invoice or stock', function () {
    $seller = fullLifecycleSeller();
    $finance = fullLifecycleFinanceUser();
    $inventory = fullLifecycleInventory();
    ['reservation' => $reservation, 'order' => $order] = fullLifecycleSubmitPreinvoice($this, $seller, $inventory, 4);

    $this->actingAs($finance)
        ->post(route('preinvoice.draft.finalize', $order->uuid), [])
        ->assertSessionHasNoErrors();

    $invoice = Invoice::query()->where('preinvoice_order_id', $order->id)->sole();
    $reservation->refresh()->forceFill(['released_at' => null])->save();

    PreinvoiceDraftReservation::query()->create([
        'token' => (string) Str::uuid(),
        'user_id' => $seller->id,
        'product_id' => $inventory['product']->id,
        'variant_id' => $inventory['variant']->id,
        'quantity' => 2,
        'expires_at' => now()->addHour(),
        'last_seen_at' => now(),
        'reservation_scope' => PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE,
    ]);
    $inventory['product']->forceFill(['reserved' => 99])->save();
    $inventory['variant']->forceFill(['reserved' => 99])->save();

    $invoiceBefore = DB::table('invoices')->where('id', $invoice->id)->first();
    $warehouseBefore = DB::table('warehouse_stocks')->orderBy('id')->get()->toJson();
    $movementsBefore = DB::table('stock_movements')->count();
    $activitiesBefore = DB::table('activity_logs')->count();

    $this->artisan('preinvoice:repair-converted-reservations --dry-run')
        ->expectsOutputToContain('"records": 1')
        ->assertSuccessful();
    expect($reservation->fresh()->released_at)->toBeNull();

    $this->artisan('preinvoice:repair-converted-reservations --apply')
        ->expectsOutputToContain('"released_records": 1')
        ->assertSuccessful();
    $this->artisan('inventory:repair-reserved-cache --apply --output=testing/converted-reservation-cache')
        ->assertSuccessful();

    expect($reservation->fresh()->released_at)->not->toBeNull()
        ->and($inventory['product']->fresh()->reserved)->toBe(2)
        ->and($inventory['variant']->fresh()->reserved)->toBe(2)
        ->and(DB::table('invoices')->where('id', $invoice->id)->first())->toEqual($invoiceBefore)
        ->and(DB::table('warehouse_stocks')->orderBy('id')->get()->toJson())->toBe($warehouseBefore)
        ->and(DB::table('stock_movements')->count())->toBe($movementsBefore)
        ->and(DB::table('activity_logs')->count())->toBe($activitiesBefore);

    $this->artisan('preinvoice:repair-converted-reservations --apply')
        ->expectsOutputToContain('"released_records": 0')
        ->assertSuccessful();
});

it('shows a connected reservation in warehouse management', function () {
    $seller = fullLifecycleSeller();
    $manager = fullLifecycleManager(false);
    $inventory = fullLifecycleInventory();
    ['reservation' => $reservation, 'order' => $order] = fullLifecycleSubmitPreinvoice($this, $seller, $inventory);
    $before = fullLifecycleSnapshot();

    $this->actingAs($manager)
        ->get(route('warehouse-reservations.index'))
        ->assertOk()
        ->assertSee($inventory['product']->name)
        ->assertSee($inventory['variant']->variant_name)
        ->assertSee($order->uuid);

    expect($reservation->fresh()->managementStatus())->toBe(PreinvoiceDraftReservation::STATUS_CONNECTED)
        ->and(fullLifecycleSnapshot())->toBe($before);
});

it('recalculates the official reservation after a real preinvoice quantity edit', function () {
    $seller = fullLifecycleSeller();
    $finance = fullLifecycleFinanceUser();
    $inventory = fullLifecycleInventory();
    ['order' => $order] = fullLifecycleSubmitPreinvoice($this, $seller, $inventory, 4);

    $this->actingAs($finance)
        ->post(route('preinvoice.draft.return', $order->uuid), ['reason' => 'Quantity correction'])
        ->assertSessionHasNoErrors();

    expect($inventory['warehouseStock']->fresh()->quantity)->toBe(20)
        ->and($inventory['variant']->fresh()->reserved)->toBe(0);

    $itemId = $order->fresh('items')->items->sole()->id;
    $newToken = (string) Str::uuid();
    $this->actingAs($seller)
        ->put(route('preinvoice.draft.update', $order->uuid), fullLifecyclePreinvoicePayload($inventory, $newToken, 2, $itemId))
        ->assertSessionHasNoErrors();

    $activeReservations = PreinvoiceDraftReservation::query()
        ->where('preinvoice_order_id', $order->id)
        ->whereNull('released_at')
        ->whereNull('release_reason');

    expect((int) $activeReservations->sum('quantity'))->toBe(2)
        ->and($activeReservations->count())->toBe(1)
        ->and($order->fresh()->items()->sole()->quantity)->toBe(2)
        ->and($inventory['warehouseStock']->fresh()->quantity)->toBe(18)
        ->and($inventory['variant']->fresh()->stock)->toBe(18)
        ->and($inventory['variant']->fresh()->reserved)->toBe(2)
        ->and($inventory['product']->fresh()->reserved)->toBe(2);
});

it('releases the reservation and deletes an autosaved preinvoice through real endpoints', function () {
    $seller = fullLifecycleSeller();
    $inventory = fullLifecycleInventory();
    ['token' => $token, 'reservation' => $reservation] = fullLifecycleSyncReservation($this, $seller, $inventory, 3);
    $draft = fullLifecycleAutosave($this, $seller, $inventory, $token, 3);

    $this->actingAs($seller)
        ->postJson(route('preinvoice.reservations.release-token'), ['token' => $token])
        ->assertOk();
    $this->actingAs($seller)
        ->postJson(route('preinvoice.autosave.discard', $draft->uuid))
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(PreinvoiceOrder::query()->whereKey($draft->id)->exists())->toBeFalse()
        ->and($reservation->fresh()->released_at)->not->toBeNull()
        ->and($reservation->fresh()->release_reason)->toBe('temporary_session_lost')
        ->and($inventory['warehouseStock']->fresh()->quantity)->toBe(20)
        ->and($inventory['variant']->fresh()->reserved)->toBe(0)
        ->and($inventory['product']->fresh()->reserved)->toBe(0);
});

it('cleans up an orphan reservation and records one activity', function () {
    $seller = fullLifecycleSeller();
    $inventory = fullLifecycleInventory();
    ['reservation' => $reservation] = fullLifecycleSyncReservation($this, $seller, $inventory, 4);
    $reservation->forceFill([
        'last_seen_at' => now()->subHour(),
        'expires_at' => now()->subHour(),
    ])->save();

    expect($reservation->fresh()->isOrphaned())->toBeTrue();

    $this->artisan('reservations:cleanup')->assertSuccessful();

    expect($reservation->fresh()->released_at)->not->toBeNull()
        ->and($reservation->fresh()->release_reason)->toBe('temporary_session_lost')
        ->and($inventory['warehouseStock']->fresh()->quantity)->toBe(20)
        ->and($inventory['variant']->fresh()->reserved)->toBe(0)
        ->and($inventory['product']->fresh()->reserved)->toBe(0)
        ->and(ActivityLog::query()
            ->where('subject_type', PreinvoiceDraftReservation::class)
            ->where('subject_id', $reservation->id)
            ->where('action', 'reservation_auto_release')
            ->count())->toBe(1);
});

it('does not clean up a stale reservation while an active draft owns its token', function () {
    $seller = fullLifecycleSeller();
    $inventory = fullLifecycleInventory();
    ['token' => $token, 'reservation' => $reservation] = fullLifecycleSyncReservation($this, $seller, $inventory, 3);
    $draft = fullLifecycleAutosave($this, $seller, $inventory, $token, 3);
    $reservation->forceFill([
        'last_seen_at' => now()->subHour(),
        'expires_at' => now()->subHour(),
    ])->save();

    expect($draft->status)->toBe(PreinvoiceOrder::STATUS_DRAFT)
        ->and($reservation->fresh()->hasActiveRelatedDraft())->toBeTrue()
        ->and($reservation->fresh()->isOrphaned())->toBeFalse();

    $this->artisan('reservations:cleanup')->assertSuccessful();

    expect($reservation->fresh()->released_at)->toBeNull()
        ->and($inventory['warehouseStock']->fresh()->quantity)->toBe(17)
        ->and($inventory['variant']->fresh()->reserved)->toBe(3)
        ->and(ActivityLog::query()
            ->where('subject_type', PreinvoiceDraftReservation::class)
            ->where('subject_id', $reservation->id)
            ->where('action', 'reservation_auto_release')
            ->count())->toBe(0);
});

it('does not classify a reservation with a preinvoice as orphaned', function () {
    $seller = fullLifecycleSeller();
    $inventory = fullLifecycleInventory();
    ['reservation' => $reservation, 'order' => $order] = fullLifecycleSubmitPreinvoice($this, $seller, $inventory, 4);

    $reservation->forceFill(['last_seen_at' => now()->subHours(2)])->save();

    expect($reservation->fresh()->preinvoice_order_id)->toBe($order->id)
        ->and($reservation->fresh()->isOrphaned())->toBeFalse()
        ->and(PreinvoiceDraftReservation::query()->orphaned()->whereKey($reservation)->exists())->toBeFalse();
});

it('prevents releasing the same reservation twice', function () {
    $seller = fullLifecycleSeller();
    $manager = fullLifecycleManager();
    $inventory = fullLifecycleInventory();
    ['reservation' => $reservation] = fullLifecycleSyncReservation($this, $seller, $inventory, 4);
    $reservation->forceFill(['last_seen_at' => now()->subHour()])->save();
    $payload = ['release_reason' => 'Full lifecycle idempotency'];

    $this->actingAs($manager)
        ->postJson(route('warehouse-reservations.release', $reservation), $payload)
        ->assertOk();
    $this->actingAs($manager)
        ->postJson(route('warehouse-reservations.release', $reservation), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reservation');

    expect($inventory['warehouseStock']->fresh()->quantity)->toBe(20)
        ->and($inventory['variant']->fresh()->reserved)->toBe(0)
        ->and($inventory['product']->fresh()->reserved)->toBe(0)
        ->and(ActivityLog::query()
            ->where('subject_type', PreinvoiceDraftReservation::class)
            ->where('subject_id', $reservation->id)
            ->where('action', 'reservation_manual_release')
            ->count())->toBe(1);
});

it('requires the release permission without mutating stock on a forbidden request', function () {
    $seller = fullLifecycleSeller();
    $viewer = fullLifecycleManager(false);
    $manager = fullLifecycleManager();
    $inventory = fullLifecycleInventory();
    ['reservation' => $reservation] = fullLifecycleSyncReservation($this, $seller, $inventory, 4);
    $reservation->forceFill(['last_seen_at' => now()->subHour()])->save();
    $before = fullLifecycleSnapshot();

    $this->actingAs($viewer)
        ->postJson(route('warehouse-reservations.release', $reservation), ['release_reason' => 'Forbidden'])
        ->assertForbidden();

    expect(fullLifecycleSnapshot())->toBe($before);

    $this->actingAs($manager)
        ->postJson(route('warehouse-reservations.release', $reservation), ['release_reason' => 'Authorized'])
        ->assertOk();

    expect($reservation->fresh()->released_by)->toBe($manager->id)
        ->and($inventory['warehouseStock']->fresh()->quantity)->toBe(20);
});

it('reports healthy orphaned and cache mismatch states without mutation', function () {
    $seller = fullLifecycleSeller();
    $manager = fullLifecycleManager(false);
    $healthyInventory = fullLifecycleInventory();
    $orphanInventory = fullLifecycleInventory();
    fullLifecycleSyncReservation($this, $seller, $healthyInventory, 2);
    ['reservation' => $orphan] = fullLifecycleSyncReservation($this, $seller, $orphanInventory, 3);
    $orphan->forceFill(['last_seen_at' => now()->subHour()])->save();
    $orphanInventory['variant']->forceFill(['reserved' => 1])->save();
    $before = fullLifecycleSnapshot();

    $summary = app(ReservationHealthService::class)->summary();

    expect($summary['healthy'])->toBe(1)
        ->and($summary['orphaned'])->toBe(1)
        ->and($summary['cache_mismatch'])->toBe(1);

    $this->actingAs($manager)
        ->get(route('warehouse-reservations.index', ['tab' => 'health']))
        ->assertOk()
        ->assertDontSee($healthyInventory['product']->name)
        ->assertSee($orphanInventory['product']->name);

    expect(fullLifecycleSnapshot())->toBe($before);
});
