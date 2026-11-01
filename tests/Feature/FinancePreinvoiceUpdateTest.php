<?php

use App\Models\CustomerLedger;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\PreinvoiceOrderItem;
use App\Models\PreinvoiceOrderReview;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\FinancePreinvoiceEditorService;
use App\Services\PreinvoiceBundleStockService;
use App\Services\WarehouseStockService;
use App\Support\FinanceDocumentVersion;
use App\Support\ReservationSideEffects;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Http\Middleware\RoutePermissionMiddleware;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
});

function financeEditorUser(): User
{
    $user = User::factory()->create();
    $role = Role::findOrCreate('finance', 'web');
    $permission = Permission::query()->where('key', 'page.sales.preinvoice_finance_review')->first()
        ?? Permission::findOrCreate('page.sales.preinvoice_finance_review', 'web');
    if ($permission->key !== 'page.sales.preinvoice_finance_review') {
        $permission->forceFill(['key' => 'page.sales.preinvoice_finance_review'])->save();
    }
    $role->givePermissionTo($permission);
    $user->assignRole($role);

    return $user;
}

function financeEditableOrderFixture(): array
{
    $category = Category::query()->create(['name' => 'Finance category '.uniqid()]);

    $product = Product::withoutEvents(fn () => Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Finance product',
        'sku' => 'FIN-PRODUCT-'.uniqid(),
        'stock' => 50,
        'reserved' => 0,
        'price' => 12_000_000,
    ]));

    $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'sales_enabled' => true,
        'variant_name' => 'Default',
        'variant_code' => 'FIN-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'sell_price' => 12_000_000,
        'stock' => 50,
        'reserved' => 0,
    ]));

    $order = PreinvoiceOrder::query()->create([
        'uuid' => 'finance-edit-'.uniqid(),
        'status' => PreinvoiceOrder::STATUS_PENDING_FINANCE,
        'customer_name' => 'مشتری مالی',
        'customer_mobile' => '09120000000',
        'shipping_price' => 0,
        'discount_amount' => 500_000,
        'total_price' => 19_500_000,
        'discount_allocation_mode' => 'product_lines',
    ]);

    $item = PreinvoiceOrderItem::query()->create([
        'preinvoice_order_id' => $order->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => 2,
        'price' => 10_000_000,
        'line_discount_amount' => 500_000,
        'line_total' => 19_500_000,
    ]);

    return compact('product', 'variant', 'order', 'item');
}

it('saves finance edits behaviorally without finalizing or creating invoices', function () {
    ['product' => $product, 'order' => $order, 'item' => $item] = financeEditableOrderFixture();
    $user = financeEditorUser();
    $oldStatus = $order->status;

    $response = $this->actingAs($user)->put(route('preinvoice.draft.finance.update', $order->uuid), [
        'intent' => 'save',
        'action' => 'save',
        'finance_version' => \App\Support\FinanceDocumentVersion::token($order->fresh('items')),
        'items' => [[
            'id' => $item->id,
            'quantity' => 2,
            'price' => '11,000,000',
        ]],
        'product_discounts' => [[
            'product_id' => $product->id,
            'type' => 'percent',
            'value' => '10',
        ]],
        'invoice_discount_type' => 'percent',
        'invoice_discount_value' => '5',
        'edit_reason' => 'اصلاح تست مالی',
    ]);

    $response->assertRedirect(route('preinvoice.draft.finance.edit', $order->uuid));

    $item->refresh();
    $order->refresh();

    expect($item->quantity)->toBe(2)
        ->and($item->price)->toBe(11_000_000)
        ->and($item->line_discount_amount)->toBe(2_200_000)
        ->and($item->line_total)->toBe(19_800_000)
        ->and($order->status)->toBe($oldStatus)
        ->and($order->product_discount_amount)->toBe(2_200_000)
        ->and($order->invoice_discount_type)->toBe('percent')
        ->and((float) $order->invoice_discount_value)->toBe(5.0)
        ->and($order->invoice_discount_amount)->toBe(990_000)
        ->and($order->discount_amount)->toBe(3_190_000)
        ->and($order->total_price)->toBe(18_810_000)
        ->and($order->discount_breakdown['groups'][0]['discount_type'])->toBe('percent')
        ->and($order->discount_breakdown['groups'][0]['discount_value'])->toBe(10)
        ->and(Invoice::query()->count())->toBe(0)
        ->and(InvoiceItem::query()->count())->toBe(0)
        ->and(CustomerLedger::query()->count())->toBe(0)
        ->and(PreinvoiceDraftReservation::query()->where('preinvoice_order_id', $order->id)->count())->toBe(0)
        ->and(PreinvoiceOrderReview::query()->where('preinvoice_order_id', $order->id)->where('action', 'finance_edited')->exists())->toBeTrue();
});

it('keeps request intent and action contracts aligned with save and save_and_finalize', function () {
    $rules = (new App\Http\Requests\FinanceUpdatePreinvoiceRequest())->rules();

    expect($rules['intent'])->toContain('in:save,save_and_finalize')
        ->and($rules['action'])->toContain('in:save,save_and_finalize');
});


/** A pending-finance document with two officially reserved items and 18 free units. */
function financeReservedOrderFixture(): array
{
    $fixture = financeEditableOrderFixture();
    ['product' => $product, 'variant' => $variant, 'order' => $order] = $fixture;
    $order->forceFill(['stock_frozen_until' => now()->addHour()])->save();

    $warehouseId = WarehouseStockService::centralWarehouseId();
    WarehouseStockService::set($warehouseId, $product->id, $variant->id, 20);

    ReservationSideEffects::transaction(function () use ($product, $variant, $order): void {
        app(PreinvoiceBundleStockService::class)->reserve($product->id, $variant->id, 2);
        PreinvoiceDraftReservation::query()->create([
            'token' => (string) Str::uuid(),
            'preinvoice_order_id' => $order->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
            'expires_at' => $order->stock_frozen_until,
            'last_seen_at' => now(),
            'reservation_scope' => PreinvoiceDraftReservation::SCOPE_OFFICIAL,
        ]);
        ReservationSideEffects::touchProduct($product->id);
    });

    return $fixture;
}

function financeReservedEditPayload(PreinvoiceOrder $order, PreinvoiceOrderItem $item, int $quantity, int $price = 10_000_000): array
{
    return [
        'intent' => 'save',
        'action' => 'save',
        'finance_version' => FinanceDocumentVersion::token($order->fresh('items')),
        'items' => [['id' => $item->id, 'quantity' => $quantity, 'price' => $price]],
        'product_discounts' => [],
        'invoice_discount_type' => 'none',
        'invoice_discount_value' => '0',
        'edit_reason' => 'اصلاح تعداد در تست رزرو مالی',
    ];
}

function financeActiveOfficialQuantity(PreinvoiceOrder $order): int
{
    return (int) PreinvoiceDraftReservation::query()
        ->where('preinvoice_order_id', $order->id)
        ->where('reservation_scope', PreinvoiceDraftReservation::SCOPE_OFFICIAL)
        ->whereNull('released_at')
        ->whereNull('release_reason')
        ->sum('quantity');
}

it('reserves exactly the additional quantity when finance increases a row', function (): void {
    ['product' => $product, 'variant' => $variant, 'order' => $order, 'item' => $item] = financeReservedOrderFixture();

    $this->actingAs(financeEditorUser())
        ->put(route('preinvoice.draft.finance.update', $order->uuid), financeReservedEditPayload($order, $item, 5))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('preinvoice.draft.finance.edit', $order->uuid));

    expect((int) $item->fresh()->quantity)->toBe(5)
        ->and(WarehouseStockService::available(WarehouseStockService::centralWarehouseId(), $product->id, $variant->id))->toBe(15)
        ->and((int) $variant->fresh()->stock)->toBe(15)
        ->and((int) $variant->fresh()->reserved)->toBe(5)
        ->and((int) $product->fresh()->reserved)->toBe(5)
        ->and(financeActiveOfficialQuantity($order))->toBe(5)
        ->and(Invoice::query()->count())->toBe(0);
});

it('releases only the removed quantity when finance decreases a row', function (): void {
    ['product' => $product, 'variant' => $variant, 'order' => $order, 'item' => $item] = financeReservedOrderFixture();

    $this->actingAs(financeEditorUser())
        ->put(route('preinvoice.draft.finance.update', $order->uuid), financeReservedEditPayload($order, $item, 1))
        ->assertSessionHasNoErrors();

    expect((int) $item->fresh()->quantity)->toBe(1)
        ->and(WarehouseStockService::available(WarehouseStockService::centralWarehouseId(), $product->id, $variant->id))->toBe(19)
        ->and((int) $variant->fresh()->reserved)->toBe(1)
        ->and((int) $product->fresh()->reserved)->toBe(1)
        ->and(financeActiveOfficialQuantity($order))->toBe(1)
        ->and((int) PreinvoiceDraftReservation::query()
            ->where('preinvoice_order_id', $order->id)
            ->where('release_reason', 'finance_quantity_decreased')->sum('quantity'))->toBe(1);
});

it('does not touch stock or reservations during a finance price-only edit', function (): void {
    ['product' => $product, 'variant' => $variant, 'order' => $order, 'item' => $item] = financeReservedOrderFixture();

    $this->actingAs(financeEditorUser())
        ->put(route('preinvoice.draft.finance.update', $order->uuid), financeReservedEditPayload($order, $item, 2, 11_000_000))
        ->assertSessionHasNoErrors();

    expect((int) $item->fresh()->quantity)->toBe(2)
        ->and((int) $item->fresh()->price)->toBe(11_000_000)
        ->and(WarehouseStockService::available(WarehouseStockService::centralWarehouseId(), $product->id, $variant->id))->toBe(18)
        ->and((int) $variant->fresh()->reserved)->toBe(2)
        ->and(financeActiveOfficialQuantity($order))->toBe(2)
        ->and(PreinvoiceDraftReservation::query()->where('preinvoice_order_id', $order->id)->count())->toBe(1);
});

it('rejects insufficient free stock without partially changing the finance document', function (): void {
    ['product' => $product, 'variant' => $variant, 'order' => $order, 'item' => $item] = financeReservedOrderFixture();
    $oldTotal = (int) $order->total_price;

    $this->actingAs(financeEditorUser())
        ->put(route('preinvoice.draft.finance.update', $order->uuid), financeReservedEditPayload($order, $item, 21))
        ->assertSessionHasErrors("items.{$item->id}.quantity");

    expect((int) $item->fresh()->quantity)->toBe(2)
        ->and((int) $order->fresh()->total_price)->toBe($oldTotal)
        ->and(WarehouseStockService::available(WarehouseStockService::centralWarehouseId(), $product->id, $variant->id))->toBe(18)
        ->and(financeActiveOfficialQuantity($order))->toBe(2)
        ->and(PreinvoiceOrderReview::query()->where('preinvoice_order_id', $order->id)->count())->toBe(0);
});

it('rejects stale finance versions without reserving additional stock', function (): void {
    ['product' => $product, 'variant' => $variant, 'order' => $order, 'item' => $item] = financeReservedOrderFixture();
    $payload = financeReservedEditPayload($order, $item, 5);
    $payload['finance_version'] = str_repeat('0', 64);

    $this->actingAs(financeEditorUser())
        ->put(route('preinvoice.draft.finance.update', $order->uuid), $payload)
        ->assertSessionHasErrors('finance_version');

    expect((int) $item->fresh()->quantity)->toBe(2)
        ->and(WarehouseStockService::available(WarehouseStockService::centralWarehouseId(), $product->id, $variant->id))->toBe(18)
        ->and(financeActiveOfficialQuantity($order))->toBe(2);
});

it('rejects expired document or official reservations before applying quantity changes', function (string $expired): void {
    ['product' => $product, 'variant' => $variant, 'order' => $order, 'item' => $item] = financeReservedOrderFixture();

    if ($expired === 'document') {
        $order->forceFill(['stock_frozen_until' => now()->subMinute()])->save();
    } else {
        PreinvoiceDraftReservation::query()->where('preinvoice_order_id', $order->id)
            ->update(['expires_at' => now()->subMinute()]);
    }

    $this->actingAs(financeEditorUser())
        ->put(route('preinvoice.draft.finance.update', $order->uuid), financeReservedEditPayload($order, $item, 5))
        ->assertSessionHasErrors('preinvoice');

    expect((int) $item->fresh()->quantity)->toBe(2)
        ->and(WarehouseStockService::available(WarehouseStockService::centralWarehouseId(), $product->id, $variant->id))->toBe(18)
        ->and(financeActiveOfficialQuantity($order))->toBe(2)
        ->and(PreinvoiceOrderReview::query()->where('preinvoice_order_id', $order->id)->count())->toBe(0);
})->with(['document' => 'document', 'official reservation' => 'reservation']);

it('rolls back stock and reservations when a later finance calculation fails', function (): void {
    ['product' => $product, 'variant' => $variant, 'order' => $order, 'item' => $item] = financeReservedOrderFixture();
    $user = financeEditorUser();
    $payload = financeReservedEditPayload($order, $item, 5);
    // Bypass HTTP validation deliberately: fail *after* the reservation delta is written.
    $payload['invoice_discount_type'] = 'percent';
    $payload['invoice_discount_value'] = '101';

    expect(fn () => app(FinancePreinvoiceEditorService::class)->update($order, $payload, $user))
        ->toThrow(ValidationException::class);

    expect((int) $item->fresh()->quantity)->toBe(2)
        ->and(WarehouseStockService::available(WarehouseStockService::centralWarehouseId(), $product->id, $variant->id))->toBe(18)
        ->and((int) $variant->fresh()->reserved)->toBe(2)
        ->and(financeActiveOfficialQuantity($order))->toBe(2)
        ->and(PreinvoiceDraftReservation::query()->where('preinvoice_order_id', $order->id)->count())->toBe(1)
        ->and(PreinvoiceOrderReview::query()->where('preinvoice_order_id', $order->id)->count())->toBe(0);
});
