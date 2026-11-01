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
use App\Support\DocumentCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('saves and approves finance discounts and cash plus cheque once without decrementing stock twice', function (int $quantity) {
    $seller = fullLifecycleSeller();
    $finance = fullLifecycleFinanceUser();
    $inventory = fullLifecycleInventory();
    ['reservation' => $reservation, 'order' => $order] = fullLifecycleSubmitPreinvoice($this, $seller, $inventory);
    $item = $order->items()->sole();
    $customer = \App\Models\Customer::create(['first_name'=>'تست','last_name'=>'مالی','mobile'=>'09129998877','is_active'=>true]);
    $order->update(['customer_id'=>$customer->id]);
    $payload = [
        'finance_version'=>\App\Support\FinanceDocumentVersion::token($order->fresh('items')),
        'items'=>[['id'=>$item->id,'quantity'=>$quantity,'price'=>100_000]],
        'product_discounts'=>[['product_id'=>$item->product_id,'type'=>'percent','value'=>'10']],
        'invoice_discount_type'=>'percent','invoice_discount_value'=>'5','edit_reason'=>'بررسی مالی و پرداخت تستی','intent'=>'save_and_finalize',
        'payments'=>[
            ['method'=>'cash','amount'=>100_000,'paid_at'=>'2026-10-26','note'=>'دریافت نقدی'],
            ['method'=>'cheque','amount'=>200_000,'paid_at'=>'2026-10-26','cheque_received_at'=>'2026-10-26','cheque_due_date'=>'2026-11-26','cheque_number'=>'TEST-123','cheque_bank_name'=>'بانک تست','cheque_status'=>'unregistered'],
        ],
    ];
    $beforeStock = $inventory['warehouseStock']->fresh()->quantity;
    for ($attempt=0; $attempt<2; $attempt++) {
        $this->actingAs($finance)->post(route('preinvoice.draft.finance.save-and-finalize',$order->uuid),[
            'finance_payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'finance_payload_item_count'=>1,
        ])->assertRedirect()->assertSessionHasNoErrors();
    }
    $invoice = Invoice::where('preinvoice_order_id',$order->id)->sole();
    expect((int)$invoice->total)->toBe($quantity*85_500)
        ->and((int)$invoice->discount_amount)->toBe($quantity*14_500)
        ->and($invoice->payments()->count())->toBe(2)
        ->and((int)$invoice->payments()->sum('amount'))->toBe(300_000)
        ->and(\App\Models\Cheque::where('cheque_number','TEST-123')->count())->toBe(1)
        ->and((int)$customer->ledgers()->where('type','debit')->sum('amount'))->toBe($quantity*85_500)
        ->and((int)$customer->ledgers()->where('type','credit')->sum('amount'))->toBe(300_000)
        ->and($inventory['warehouseStock']->fresh()->quantity)->toBe($beforeStock-($quantity-4))
        ->and($inventory['variant']->fresh()->sell_price)->toBe(100_000)
        ->and($inventory['product']->fresh()->price)->toBe(100_000)
        ->and($inventory['variant']->fresh()->reserved)->toBe(0)
        ->and($reservation->fresh()->release_reason)->toBe('consumed');
})->with([4]);

it('rolls back discount changes and invoice creation when payment posting fails after validation', function () {
    $seller = fullLifecycleSeller(); $finance = fullLifecycleFinanceUser(); $inventory = fullLifecycleInventory();
    ['reservation'=>$reservation,'order'=>$order] = fullLifecycleSubmitPreinvoice($this,$seller,$inventory);
    $item = $order->items()->sole();
    $version = \App\Support\FinanceDocumentVersion::token($order->fresh('items'));
    $this->mock(\App\Services\PaymentRegistrationService::class,function ($mock) {
        $mock->shouldReceive('registerForInvoice')->once()->andThrow(\Illuminate\Validation\ValidationException::withMessages(['payments'=>'ثبت پرداخت تستی ناموفق بود']));
    });
    $beforeStock = $inventory['warehouseStock']->fresh()->quantity;
    $this->actingAs($finance)->post(route('preinvoice.draft.finance.save-and-finalize',$order->uuid),[
        'finance_version'=>$version,'items'=>[['id'=>$item->id,'quantity'=>4,'price'=>100_000]],
        'product_discounts'=>[['product_id'=>$item->product_id,'type'=>'amount','value'=>5000]],'invoice_discount_type'=>'none','invoice_discount_value'=>0,
        'edit_reason'=>'تست بازگشت کامل عملیات','intent'=>'save_and_finalize',
        'payments'=>[['method'=>'cash','amount'=>1000,'paid_at'=>'2026-10-26']],
    ])->assertSessionHasErrors('payments')->assertSessionHasInput('finance_version',$version);
    expect($inventory['warehouseStock']->fresh()->quantity)->toBe($beforeStock)
        ->and($inventory['variant']->fresh()->reserved)->toBe(4)
        ->and($item->fresh()->quantity)->toBe(4)
        ->and($item->fresh()->price)->toBe(100_000)
        ->and($reservation->fresh()->released_at)->toBeNull()
        ->and(Invoice::where('preinvoice_order_id',$order->id)->count())->toBe(0);
});

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
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $order = PreinvoiceOrder::query()->where('created_by', $seller->id)->latest('id')->firstOrFail();

    $reservation = PreinvoiceDraftReservation::where('preinvoice_order_id', $order->id)->where('reservation_scope', 'official')->whereNull('released_at')->sole();
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

it('accepts a repeated final submit idempotently without changing stock twice', function () {
    $seller = fullLifecycleSeller();
    $inventory = fullLifecycleInventory();
    $token = (string) Str::uuid();
    fullLifecycleSyncReservation($this, $seller, $inventory, 4, $token);
    $payload = fullLifecyclePreinvoicePayload($inventory, $token, 4);

    $this->actingAs($seller)
        ->post(route('preinvoice.draft.save'), $payload)
        ->assertSessionHasNoErrors();

    $stockAfterFirstSubmit = $inventory['warehouseStock']->fresh()->quantity;

    $this->actingAs($seller)
        ->post(route('preinvoice.draft.save'), $payload)
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(PreinvoiceOrder::query()->where('created_by', $seller->id)->where('is_auto_draft', false)->count())->toBe(1)
        ->and($inventory['warehouseStock']->fresh()->quantity)->toBe($stockAfterFirstSubmit)
        ->and($inventory['variant']->fresh()->reserved)->toBe(4)
        ->and(PreinvoiceDraftReservation::query()->where('token', $token)->count())->toBe(1);
});

it('closes the reservation lifecycle when a preinvoice becomes an invoice', function () {
    $seller = fullLifecycleSeller();
    $finance = fullLifecycleFinanceUser();
    $inventory = fullLifecycleInventory();
    ['reservation' => $reservation, 'order' => $order] = fullLifecycleSubmitPreinvoice($this, $seller, $inventory);

    $warehouseBefore = $inventory['warehouseStock']->fresh()->quantity;

    $this->actingAs($finance)
        ->post(route('preinvoice.draft.finalize', $order->uuid), ['finance_version' => \App\Support\FinanceDocumentVersion::token($order->fresh('items'))])
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

it('does not let another order reservation cover a finance approval shortfall', function () {
    $seller = fullLifecycleSeller();
    $finance = fullLifecycleFinanceUser();
    $inventory = fullLifecycleInventory();
    ['reservation' => $reservation, 'order' => $order] = fullLifecycleSubmitPreinvoice($this, $seller, $inventory, 4);

    $otherOrder = $order->replicate();
    $otherOrder->uuid = DocumentCodeGenerator::generateUnique5DigitCode(PreinvoiceOrder::class);
    $otherOrder->save();
    $reservation->forceFill(['quantity' => 1])->save();
    PreinvoiceDraftReservation::query()->create([
        'token' => (string) Str::uuid(),
        'user_id' => $seller->id,
        'preinvoice_order_id' => $otherOrder->id,
        'product_id' => $inventory['product']->id,
        'variant_id' => $inventory['variant']->id,
        'quantity' => 3,
        'expires_at' => now()->addHour(),
        'reservation_scope' => PreinvoiceDraftReservation::SCOPE_OFFICIAL,
    ]);
    $before = fullLifecycleSnapshot();

    $this->actingAs($finance)
        ->post(route('preinvoice.draft.finalize', $order->uuid), ['finance_version' => \App\Support\FinanceDocumentVersion::token($order->fresh('items'))])
        ->assertSessionHasErrors('preinvoice');

    expect(Invoice::query()->where('preinvoice_order_id', $order->id)->exists())->toBeFalse()
        ->and(fullLifecycleSnapshot())->toBe($before);
});

it('repairs only verified converted reservations and rebuilds product and variant caches without changing invoice or stock', function () {
    $seller = fullLifecycleSeller();
    $finance = fullLifecycleFinanceUser();
    $inventory = fullLifecycleInventory();
    ['reservation' => $reservation, 'order' => $order] = fullLifecycleSubmitPreinvoice($this, $seller, $inventory, 4);

    $this->actingAs($finance)
        ->post(route('preinvoice.draft.finalize', $order->uuid), ['finance_version' => \App\Support\FinanceDocumentVersion::token($order->fresh('items'))])
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
    $this->artisan('inventory:repair-reserved-cache --apply --confirm --output=testing/converted-reservation-cache')
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
        ->and($reservation->fresh()->release_reason)->toBe('draft_discarded')
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

it('does not clean up a live reservation while an active draft owns its token', function () {
    $seller = fullLifecycleSeller();
    $inventory = fullLifecycleInventory();
    ['token' => $token, 'reservation' => $reservation] = fullLifecycleSyncReservation($this, $seller, $inventory, 3);
    $draft = fullLifecycleAutosave($this, $seller, $inventory, $token, 3);
    $reservation->forceFill([
        'last_seen_at' => now(),
        'expires_at' => now()->addHour(),
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

it('keeps manual release endpoints disabled and cleanup remains idempotent', function () {
    $seller = fullLifecycleSeller();
    $manager = fullLifecycleManager();
    $inventory = fullLifecycleInventory();
    ['reservation' => $reservation] = fullLifecycleSyncReservation($this, $seller, $inventory, 4);
    $reservation->forceFill(['last_seen_at' => now()->subHour(), 'expires_at' => now()->subHour()])->save();
    $before = fullLifecycleSnapshot();
    expect(\Illuminate\Support\Facades\Route::has('warehouse-reservations.release'))->toBeFalse();
    foreach ([$manager, fullLifecycleManager(false)] as $actor) {
        $this->actingAs($actor)->postJson('/warehouse-reservations/'.$reservation->id.'/release', ['release_reason' => 'Disabled'])->assertNotFound();
        expect(fullLifecycleSnapshot())->toBe($before);
    }
    foreach (range(1, 2) as $_) $this->artisan('reservations:cleanup')->assertSuccessful();
    expect($inventory['warehouseStock']->fresh()->quantity)->toBe(20)
        ->and($inventory['variant']->fresh()->reserved)->toBe(0)
        ->and($inventory['product']->fresh()->reserved)->toBe(0)
        ->and(ActivityLog::where('subject_type', PreinvoiceDraftReservation::class)->where('subject_id', $reservation->id)->where('action', 'reservation_auto_release')->count())->toBe(1);
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
        ->assertDontSee($orphanInventory['product']->name); // This document report intentionally excludes unsubmitted sessions.

    expect(fullLifecycleSnapshot())->toBe($before);
});
