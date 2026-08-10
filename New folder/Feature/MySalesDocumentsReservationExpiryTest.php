<?php

use App\Models\Category;
use App\Models\Invoice;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\PreinvoiceOrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\MySalesDocumentsService;
use App\Services\PreinvoiceReservationService;
use App\Services\WarehouseStockService;
use App\Http\Middleware\RoutePermissionMiddleware;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
});

function mySalesExpiryProduct(int $stock = 10): array
{
    $category = Category::query()->create(['name' => 'Reservation expiry '.uniqid()]);
    $product = Product::withoutEvents(fn () => Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Expired reservation product',
        'sku' => 'EXP-'.uniqid(),
        'stock' => $stock,
        'reserved' => 0,
        'price' => 100000,
        'is_sellable' => true,
    ]));
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'sales_enabled' => true,
        'variant_name' => 'Default',
        'variant_code' => 'EXP-'.uniqid(),
        'sell_price' => 100000,
        'stock' => $stock,
        'reserved' => 0,
    ]);
    WarehouseStock::query()->create([
        'warehouse_id' => WarehouseStockService::centralWarehouseId(),
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'quantity' => $stock,
    ]);

    return compact('product', 'variant');
}

function mySalesExpiryOrder(User $seller, string $status, ?\Carbon\CarbonInterface $frozenUntil = null, ?\Carbon\CarbonInterface $releasedAt = null): array
{
    ['product' => $product, 'variant' => $variant] = mySalesExpiryProduct();
    $order = PreinvoiceOrder::query()->create([
        'uuid' => 'EXP-'.uniqid(),
        'created_by' => $seller->id,
        'seller_id' => $seller->id,
        'status' => $status,
        'customer_name' => 'مشتری تست',
        'customer_mobile' => '09120000000',
        'total_price' => 200000,
        'stock_frozen_until' => $frozenUntil,
        'stock_released_at' => $releasedAt,
    ]);
    $item = PreinvoiceOrderItem::query()->create([
        'preinvoice_order_id' => $order->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => 2,
        'price' => 100000,
        'line_total' => 200000,
    ]);

    return compact('order', 'product', 'variant', 'item');
}

it('expired_preinvoice_is_visible_in_active_tab', function () {
    $seller = User::factory()->create();
    ['order' => $order] = mySalesExpiryOrder($seller, PreinvoiceOrder::STATUS_RESERVATION_EXPIRED, null, now()->subMinute());

    $this->actingAs($seller)->get(route('preinvoice.my.index', ['tab' => MySalesDocumentsService::TAB_ACTIVE]))
        ->assertOk()
        ->assertSee($order->uuid)
        ->assertSee('نیاز به بررسی')
        ->assertSee('رزرو منقضی')
        ->assertSee('بررسی و ثبت مجدد');
});

it('expired_preinvoice_is_not_duplicated_in_needs_correction', function () {
    $seller = User::factory()->create();
    ['order' => $order] = mySalesExpiryOrder($seller, PreinvoiceOrder::STATUS_RESERVATION_EXPIRED, null, now()->subMinute());

    $service = app(MySalesDocumentsService::class);
    expect($service->counts($seller->id)[MySalesDocumentsService::TAB_ACTIVE])->toBe(1)
        ->and($service->counts($seller->id)[MySalesDocumentsService::TAB_NEEDS_CORRECTION])->toBe(0);

    $this->actingAs($seller)->get(route('preinvoice.my.index', ['tab' => MySalesDocumentsService::TAB_NEEDS_CORRECTION]))
        ->assertOk()
        ->assertDontSee($order->uuid);
});

it('active_preinvoice_shows_remaining_reservation_timer', function () {
    $seller = User::factory()->create();
    mySalesExpiryOrder($seller, PreinvoiceOrder::STATUS_PENDING_FINANCE, now()->addMinutes(30));

    $this->actingAs($seller)->get(route('preinvoice.my.index', ['tab' => MySalesDocumentsService::TAB_ACTIVE]))
        ->assertOk()
        ->assertSee('data-reservation-timer', false)
        ->assertSee('data-total-seconds=', false)
        ->assertSee('فعال');
});

it('expired_preinvoice_shows_expired_timer_state', function () {
    $seller = User::factory()->create();
    mySalesExpiryOrder($seller, PreinvoiceOrder::STATUS_RESERVATION_EXPIRED, null, now()->subMinute());

    $this->actingAs($seller)->get(route('preinvoice.my.index', ['tab' => MySalesDocumentsService::TAB_ACTIVE]))
        ->assertOk()
        ->assertSee('data-reservation-timer', false)
        ->assertSee('منقضی‌شده');
});

it('converted_preinvoice_does_not_show_preinvoice_timer', function () {
    $seller = User::factory()->create();
    ['order' => $order] = mySalesExpiryOrder($seller, PreinvoiceOrder::STATUS_PENDING_FINANCE, now()->addMinutes(30));
    Invoice::query()->create([
        'uuid' => 'INV-'.uniqid(),
        'preinvoice_order_id' => $order->id,
        'customer_name' => 'مشتری تست',
        'customer_mobile' => '09120000000',
        'total' => 200000,
        'status' => Invoice::STATUS_PENDING_WAREHOUSE_APPROVAL,
    ]);

    $this->actingAs($seller)->get(route('preinvoice.my.index', ['tab' => MySalesDocumentsService::TAB_ACTIVE]))
        ->assertOk()
        ->assertDontSee('data-reservation-timer', false);
});

it('expiration_notification_links_to_active_tab', function () {
    $seller = User::factory()->create();
    ['order' => $order, 'product' => $product, 'variant' => $variant] = mySalesExpiryOrder($seller, PreinvoiceOrder::STATUS_PENDING_FINANCE, now()->subMinute());
    PreinvoiceDraftReservation::query()->create([
        'token' => (string) Illuminate\Support\Str::uuid(),
        'user_id' => $seller->id,
        'preinvoice_order_id' => $order->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => 1,
        'expires_at' => now()->subMinute(),
        'converted_at' => now()->subHour(),
        'reservation_scope' => 'official',
    ]);

    app(PreinvoiceReservationService::class)->expirePreinvoiceReservations($order);

    $this->assertDatabaseHas('notifications', [
        'user_id' => $seller->id,
        'link' => route('preinvoice.my.index', ['tab' => MySalesDocumentsService::TAB_ACTIVE]),
    ]);
});

it('seller_can_recheck_and_resubmit_expired_preinvoice', function () {
    $seller = User::factory()->create();
    ['order' => $order, 'product' => $product, 'variant' => $variant, 'item' => $item] = mySalesExpiryOrder($seller, PreinvoiceOrder::STATUS_RESERVATION_EXPIRED, null, now()->subMinute());
    $row = [
        'id' => $product->id,
        'variety_id' => $variant->id,
        'quantity' => 2,
        'price' => 100000,
        'item_id' => $item->id,
    ];

    $response = $this->actingAs($seller)->put(route('preinvoice.draft.update', $order->uuid), [
        'intent' => 'submit',
        'customer_name' => 'مشتری تست',
        'customer_mobile' => '09120000000',
        'products_payload' => json_encode([$row], JSON_THROW_ON_ERROR),
        'products_payload_count' => 1,
        'products_payload_version' => 1,
        'products_payload_complete' => 1,
        'products_payload_total_quantity' => 2,
        'products_payload_gross_total' => 200000,
    ]);
    $response->assertRedirect();

    $order->refresh();
    expect($order->status)->toBe(PreinvoiceOrder::STATUS_PENDING_FINANCE)
        ->and($order->stock_frozen_until)->not->toBeNull()
        ->and($order->stock_released_at)->toBeNull()
        ->and($order->invoice)->toBeNull()
        ->and(PreinvoiceDraftReservation::query()->where('preinvoice_order_id', $order->id)->where('reservation_scope', 'official')->whereNull('released_at')->count())->toBe(1);
});

it('expired_preinvoice_resubmit_fails_atomically_when_stock_is_insufficient', function () {
    $seller = User::factory()->create();
    ['order' => $order, 'product' => $product, 'variant' => $variant, 'item' => $item] = mySalesExpiryOrder($seller, PreinvoiceOrder::STATUS_RESERVATION_EXPIRED, null, now()->subMinute());
    $variant->forceFill(['stock' => 1, 'reserved' => 0])->save();
    $product->forceFill(['stock' => 1, 'reserved' => 0])->save();

    $this->actingAs($seller)->from(route('preinvoice.draft.edit', $order->uuid))->put(route('preinvoice.draft.update', $order->uuid), [
        'intent' => 'submit',
        'customer_name' => 'مشتری تست',
        'customer_mobile' => '09120000000',
        'products' => [[
            'id' => $product->id,
            'variety_id' => $variant->id,
            'quantity' => 2,
            'price' => 100000,
            'item_id' => $item->id,
        ]],
    ])->assertSessionHasErrors();

    $order->refresh();
    $variant->refresh();
    $product->refresh();
    expect($order->status)->toBe(PreinvoiceOrder::STATUS_RESERVATION_EXPIRED)
        ->and($order->stock_released_at)->not->toBeNull()
        ->and(PreinvoiceDraftReservation::query()->where('preinvoice_order_id', $order->id)->where('reservation_scope', 'official')->whereNull('released_at')->count())->toBe(0)
        ->and((int) $variant->reserved)->toBe(0)
        ->and((int) $product->reserved)->toBe(0);
});

function mySalesExpiryOfficialReservation(PreinvoiceOrder $order, Product $product, ProductVariant $variant, int $quantity = 2): PreinvoiceDraftReservation
{
    $product->forceFill(['reserved' => (int) $product->reserved + $quantity])->save();
    $variant->forceFill(['reserved' => (int) $variant->reserved + $quantity])->save();

    return PreinvoiceDraftReservation::query()->create([
        'token' => (string) Illuminate\Support\Str::uuid(),
        'user_id' => $order->created_by,
        'preinvoice_order_id' => $order->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => $quantity,
        'expires_at' => $order->stock_frozen_until,
        'converted_at' => now()->subHour(),
        'reservation_scope' => 'official',
    ]);
}

it('my_index_expires_overdue_pending_finance_preinvoice', function () {
    $seller = User::factory()->create();
    ['order' => $order, 'product' => $product, 'variant' => $variant] = mySalesExpiryOrder($seller, PreinvoiceOrder::STATUS_PENDING_FINANCE, now()->subMinute());
    mySalesExpiryOfficialReservation($order, $product, $variant);

    $response = $this->actingAs($seller)->get(route('preinvoice.my.index', ['tab' => MySalesDocumentsService::TAB_ACTIVE]));

    $order->refresh();
    $response->assertOk()
        ->assertSee($order->uuid)
        ->assertSee('نیاز به بررسی')
        ->assertSee('بررسی و ثبت مجدد')
        ->assertSee(route('preinvoice.draft.edit', $order->uuid), false)
        ->assertDontSee(route('preinvoice.draft.finance', $order->uuid), false);
    expect($order->status)->toBe(PreinvoiceOrder::STATUS_RESERVATION_EXPIRED)
        ->and($order->stock_released_at)->not->toBeNull();
});

it('active_non_expired_preinvoice_stays_pending_finance', function () {
    $seller = User::factory()->create();
    ['order' => $order] = mySalesExpiryOrder($seller, PreinvoiceOrder::STATUS_PENDING_FINANCE, now()->addMinutes(20));

    $this->actingAs($seller)->get(route('preinvoice.my.index', ['tab' => MySalesDocumentsService::TAB_ACTIVE]))
        ->assertOk()
        ->assertSee($order->uuid)
        ->assertSee('data-reservation-timer', false)
        ->assertSee('مشاهده');

    expect($order->fresh()->status)->toBe(PreinvoiceOrder::STATUS_PENDING_FINANCE);
});

it('invoice_prevents_expiry_fallback', function () {
    $seller = User::factory()->create();
    ['order' => $order, 'product' => $product, 'variant' => $variant] = mySalesExpiryOrder($seller, PreinvoiceOrder::STATUS_PENDING_FINANCE, now()->subMinute());
    $reservation = mySalesExpiryOfficialReservation($order, $product, $variant);
    Invoice::query()->create([
        'uuid' => 'INV-'.uniqid(),
        'preinvoice_order_id' => $order->id,
        'customer_name' => 'مشتری تست',
        'customer_mobile' => '09120000000',
        'total' => 200000,
        'status' => Invoice::STATUS_PENDING_WAREHOUSE_APPROVAL,
    ]);

    $this->actingAs($seller)->get(route('preinvoice.my.index', ['tab' => MySalesDocumentsService::TAB_ACTIVE]))
        ->assertOk()
        ->assertDontSee('data-reservation-timer', false);

    expect($order->fresh()->status)->toBe(PreinvoiceOrder::STATUS_PENDING_FINANCE)
        ->and($order->fresh()->stock_released_at)->toBeNull()
        ->and($reservation->fresh()->released_at)->toBeNull();
});

it('direct_edit_expires_order_before_access_check', function () {
    $seller = User::factory()->create();
    ['order' => $order, 'product' => $product, 'variant' => $variant] = mySalesExpiryOrder($seller, PreinvoiceOrder::STATUS_PENDING_FINANCE, now()->subMinute());
    mySalesExpiryOfficialReservation($order, $product, $variant);

    $this->actingAs($seller)->get(route('preinvoice.draft.edit', $order->uuid))->assertOk();

    expect($order->fresh()->status)->toBe(PreinvoiceOrder::STATUS_RESERVATION_EXPIRED);
});

it('fallback_does_not_release_twice', function () {
    $seller = User::factory()->create();
    ['order' => $order, 'product' => $product, 'variant' => $variant] = mySalesExpiryOrder($seller, PreinvoiceOrder::STATUS_PENDING_FINANCE, now()->subMinute());
    $reservation = mySalesExpiryOfficialReservation($order, $product, $variant, 2);
    $initialProductReserved = (int) $product->fresh()->reserved;
    $initialVariantReserved = (int) $variant->fresh()->reserved;

    $this->actingAs($seller)->get(route('preinvoice.my.index', ['tab' => MySalesDocumentsService::TAB_ACTIVE]))->assertOk();
    $this->actingAs($seller)->get(route('preinvoice.my.index', ['tab' => MySalesDocumentsService::TAB_ACTIVE]))->assertOk();

    expect($reservation->fresh()->released_at)->not->toBeNull()
        ->and($reservation->fresh()->release_reason)->toBe('reservation_expired_my_sales_page')
        ->and((int) $product->fresh()->reserved)->toBe($initialProductReserved - 2)
        ->and((int) $variant->fresh()->reserved)->toBe($initialVariantReserved - 2);
});
