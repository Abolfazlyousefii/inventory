<?php

use App\Http\Middleware\RoutePermissionMiddleware;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\WarehouseStock;
use App\Services\WarehouseStockService;
use App\Models\User;
use App\Services\InvoiceSalesCorrectionService;
use App\Services\CustomerLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function sellerCorrectionFixture(): array
{
    $seller = User::factory()->create();
    $customer = Customer::query()->create(['first_name' => 'مشتری', 'last_name' => 'تست', 'mobile' => '09120000000']);
    $order = PreinvoiceOrder::query()->create([
        'uuid' => 'CORRECTION-ORDER-1',
        'created_by' => $seller->id,
        'customer_id' => $customer->id,
        'customer_name' => $customer->display_name,
        'customer_mobile' => $customer->mobile,
        'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
        'total_price' => 10000,
    ]);
    $category = Category::query()->create(['name' => 'Correction']);
    $product = Product::withoutEvents(fn () => Product::query()->create([
        'category_id' => $category->id,
        'name' => 'کالای اصلاحی',
        'sku' => 'CORRECTION-ITEM',
        'stock' => 20,
        'price' => 5000,
        'is_sellable' => true,
    ]));
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'sales_enabled' => true,
        'variant_name' => 'تنوع اصلاحی',
        'variant_code' => 'CORRECTION-VARIANT',
        'sell_price' => 5000,
        'stock' => 20,
    ]);
    $invoice = Invoice::query()->create([
        'uuid' => 'CORRECTION-INVOICE-1',
        'preinvoice_order_id' => $order->id,
        'customer_id' => $customer->id,
        'customer_name' => $customer->display_name,
        'customer_mobile' => $customer->mobile,
        'status' => Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION,
        'total' => 10000,
        'subtotal' => 10000,
    ]);
    $item = InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => 2,
        'price' => 5000,
        'line_discount_amount' => 0,
        'line_total' => 10000,
    ]);

    return compact('seller', 'customer', 'order', 'product', 'variant', 'invoice', 'item');
}

function correctionPayload(array $fixture, array $overrides = []): array
{
    return array_replace_recursive([
        'opened_fingerprint' => app(InvoiceSalesCorrectionService::class)->fingerprint($fixture['invoice']->fresh('items')),
        'customer_id' => $fixture['customer']->id,
        'customer_address' => 'آدرس اصلاح‌شده',
        'shipping_id' => 'keep',
        'payment_terms_note' => 'پرداخت توافقی',
        'is_in_person' => 0,
        'invoice_discount_type' => 'amount',
        'invoice_discount_value' => 0,
        'items' => [[
            'id' => $fixture['item']->id,
            'product_id' => $fixture['product']->id,
            'variant_id' => $fixture['variant']->id,
            'quantity' => 2,
            'price' => 5000,
            'line_discount_amount' => 0,
        ]],
        'change_note' => 'بررسی فروشنده',
    ], $overrides);
}

it('lets the owner finalize a price correction on the same invoice into finance reapproval', function () {
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
    $f = sellerCorrectionFixture();
    $this->actingAs($f['seller'])->get(route('preinvoice.my.index', ['tab' => 'needs-correction']))
        ->assertOk()->assertSee(route('preinvoice.my.invoice-correction.edit', $f['invoice']->uuid));
    $this->get(route('preinvoice.my.invoice-correction.edit', $f['invoice']->uuid))
        ->assertOk()->assertSee('ثبت نهایی و ارسال برای تأیید مجدد مالی')
        ->assertSee('productFinderModal', false)
        ->assertDontSee('name="customer_id"', false)
        ->assertDontSee('name="shipping_id"', false);

    $this->post(route('preinvoice.my.invoice-correction.submit', $f['invoice']->uuid), correctionPayload($f, [
        'items' => [[
            'id' => $f['item']->id,
            'product_id' => $f['product']->id,
            'variant_id' => $f['variant']->id,
            'quantity' => 2,
            'price' => 6000,
            'line_discount_amount' => 0,
        ]],
    ]))->assertRedirect();

    expect($f['invoice']->fresh()->status)->toBe(Invoice::STATUS_PENDING_FINANCE_REAPPROVAL)
        ->and((int) $f['invoice']->fresh()->total)->toBe(12000)
        ->and((int) $f['item']->fresh()->price)->toBe(6000)
        ->and($f['order']->fresh()->status)->toBe(PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE)
        ->and(DB::table('invoice_collection_revisions')->where('invoice_id', $f['invoice']->id)->count())->toBe(1);
});

it('accepts a reviewed invoice without item changes and blocks non owners', function () {
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
    $f = sellerCorrectionFixture();
    $other = User::factory()->create();
    $this->actingAs($other)->get(route('preinvoice.my.invoice-correction.edit', $f['invoice']->uuid))->assertForbidden();
    $this->actingAs($f['seller'])->post(route('preinvoice.my.invoice-correction.submit', $f['invoice']->uuid), correctionPayload($f))
        ->assertRedirect();
    expect($f['invoice']->fresh()->status)->toBe(Invoice::STATUS_PENDING_FINANCE_REAPPROVAL)
        ->and((int) $f['invoice']->fresh()->total)->toBe(10000);
});

it('keeps the customer ledger unchanged until finance reviews a discount-only correction', function () {
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
    $f = sellerCorrectionFixture();
    app(CustomerLedgerService::class)->syncInvoiceDebit($f['invoice']);

    $this->actingAs($f['seller'])->post(
        route('preinvoice.my.invoice-correction.submit', $f['invoice']->uuid),
        correctionPayload($f, ['invoice_discount_value' => 1000]),
    )->assertRedirect()->assertSessionHasNoErrors();

    expect((int) $f['invoice']->fresh()->total)->toBe(9000)
        ->and($f['invoice']->fresh()->status)->toBe(Invoice::STATUS_PENDING_FINANCE_REAPPROVAL)
        ->and((int) DB::table('customer_ledgers')->where('reference_type', Invoice::class)->where('reference_id', $f['invoice']->id)->value('amount'))->toBe(10000)
        ->and(DB::table('invoice_collection_revisions')->where('invoice_id', $f['invoice']->id)->count())->toBe(1);
});

it('ignores customer, sale mode, shipping, address and payment terms posted by the seller', function () {
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
    $f = sellerCorrectionFixture();
    $f['order']->update(['payment_terms_note' => 'شرایط اولیه']);
    $f['invoice']->update(['customer_address' => 'آدرس اولیه']);
    $nextCustomer = Customer::query()->create(['first_name' => 'مشتری', 'last_name' => 'جدید', 'mobile' => '09121111111']);
    $shipping = ShippingMethod::query()->create(['name' => 'ارسال ویژه', 'price' => 500]);

    $this->actingAs($f['seller'])->post(route('preinvoice.my.invoice-correction.submit', $f['invoice']->uuid), correctionPayload($f, [
        'customer_id' => $nextCustomer->id,
        'customer_address' => 'آدرس جعلی',
        'shipping_id' => $shipping->id,
        'payment_terms_note' => 'نقدی هنگام تحویل',
        'is_in_person' => 1,
        'invoice_discount_value' => 1000,
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $invoice = $f['invoice']->fresh();
    expect((int) $invoice->customer_id)->toBe($f['customer']->id)
        ->and($invoice->customer_address)->toBe('آدرس اولیه')
        ->and($invoice->shipping_id)->toBeNull()
        ->and((int) $invoice->shipping_price)->toBe(0)
        ->and((int) $invoice->total)->toBe(9000)
        ->and($invoice->status)->toBe(Invoice::STATUS_PENDING_FINANCE_REAPPROVAL)
        ->and($f['order']->fresh()->payment_terms_note)->toBe('شرایط اولیه')
        ->and((bool) $f['order']->fresh()->is_in_person)->toBeFalse()
        ->and(DB::table('sales_havaleh_histories')->where('invoice_id', $f['invoice']->id)->where('action_type', 'seller_invoice_header_changed')->whereIn('field_name', ['customer', 'customer_address', 'shipping', 'shipping_price', 'payment_terms', 'sale_mode'])->exists())->toBeFalse()
        ->and(DB::table('sales_havaleh_histories')->where('invoice_id', $f['invoice']->id)->where('action_type', 'seller_invoice_header_changed')->where('field_name', 'discount_value')->exists())->toBeTrue();
});

it('rejects a stale form and never changes the customer of an invoice with recorded payments', function () {
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
    $f = sellerCorrectionFixture();
    $payload = correctionPayload($f);
    $f['item']->update(['price' => 5500, 'line_total' => 11000]);
    $this->actingAs($f['seller'])->post(route('preinvoice.my.invoice-correction.submit', $f['invoice']->uuid), $payload)
        ->assertSessionHasErrors('invoice');
    expect($f['invoice']->fresh()->status)->toBe(Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION);

    InvoicePayment::query()->create(['invoice_id' => $f['invoice']->id, 'method' => 'cash', 'amount' => 1000]);
    $nextCustomer = Customer::query()->create(['first_name' => 'مشتری', 'last_name' => 'دیگر', 'mobile' => '09122222222']);
    $this->post(route('preinvoice.my.invoice-correction.submit', $f['invoice']->uuid), correctionPayload($f, [
        'customer_id' => $nextCustomer->id,
        'items' => [[
            'id' => $f['item']->id,
            'product_id' => $f['product']->id,
            'variant_id' => $f['variant']->id,
            'quantity' => 2,
            'price' => 5500,
            'line_discount_amount' => 0,
        ]],
    ]))->assertRedirect()->assertSessionHasNoErrors();
    expect((int) $f['invoice']->fresh()->customer_id)->toBe($f['customer']->id)
        ->and($f['invoice']->fresh()->status)->toBe(Invoice::STATUS_PENDING_FINANCE_REAPPROVAL);
});

it('adds a product only on final submission and records its stock and finance difference', function () {
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
    $f = sellerCorrectionFixture();
    $extra = ProductVariant::query()->create([
        'product_id' => $f['product']->id,
        'is_active' => true,
        'sales_enabled' => true,
        'variant_name' => 'تنوع افزوده',
        'variant_code' => 'CORRECTION-EXTRA',
        'sell_price' => 3000,
        'stock' => 5,
    ]);
    $stock = WarehouseStock::query()->create([
        'warehouse_id' => WarehouseStockService::centralWarehouseId(),
        'product_id' => $f['product']->id,
        'product_variant_id' => $extra->id,
        'quantity' => 5,
    ]);
    $payload = correctionPayload($f, ['items' => [
        [
            'id' => $f['item']->id,
            'product_id' => $f['product']->id,
            'variant_id' => $f['variant']->id,
            'quantity' => 2,
            'price' => 5000,
            'line_discount_amount' => 0,
        ],
        [
            'product_id' => $f['product']->id,
            'variant_id' => $extra->id,
            'quantity' => 1,
            'price' => 3000,
            'line_discount_amount' => 0,
        ],
    ]]);
    expect($f['invoice']->fresh()->status)->toBe(Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION)
        ->and($f['invoice']->items()->count())->toBe(1);
    $this->actingAs($f['seller'])->post(route('preinvoice.my.invoice-correction.submit', $f['invoice']->uuid), $payload)
        ->assertRedirect();
    expect($f['invoice']->fresh()->status)->toBe(Invoice::STATUS_PENDING_FINANCE_REAPPROVAL)
        ->and((int) $f['invoice']->fresh()->total)->toBe(13000)
        ->and($f['invoice']->items()->count())->toBe(2)
        ->and((int) $stock->fresh()->quantity)->toBe(4)
        ->and(DB::table('invoice_collection_revision_items')->where('change_type', 'added')->exists())->toBeTrue();
});

it('uses the reassigned seller as the invoice correction owner', function () {
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
    $f = sellerCorrectionFixture();
    $newSeller = User::factory()->create();
    $f['invoice']->update(['seller_id' => $newSeller->id]);
    $f['order']->update(['seller_id' => $newSeller->id]);

    $this->actingAs($f['seller'])->get(route('preinvoice.my.invoice-correction.edit', $f['invoice']->uuid))
        ->assertForbidden();
    $this->actingAs($newSeller)->get(route('preinvoice.my.index', ['tab' => 'needs-correction']))
        ->assertOk()->assertSee(route('preinvoice.my.invoice-correction.edit', $f['invoice']->uuid));
    $this->get(route('preinvoice.my.invoice-correction.edit', $f['invoice']->uuid))->assertOk();
    $this->get(route('vouchers.sales.show', $f['invoice']->uuid))->assertOk();
});

it('calculates row and percentage discounts before returning the invoice to finance', function () {
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
    $f = sellerCorrectionFixture();
    $this->actingAs($f['seller'])->post(route('preinvoice.my.invoice-correction.submit', $f['invoice']->uuid), correctionPayload($f, [
        'items' => [[
            'id' => $f['item']->id,
            'product_id' => $f['product']->id,
            'variant_id' => $f['variant']->id,
            'quantity' => 2,
            'price' => 5000,
            'line_discount_amount' => 1000,
        ]],
        'invoice_discount_type' => 'percent',
        'invoice_discount_value' => 10,
    ]))->assertRedirect();

    expect((int) $f['item']->fresh()->line_discount_amount)->toBe(1000)
        ->and((int) $f['invoice']->fresh()->invoice_discount_amount)->toBe(900)
        ->and((int) $f['invoice']->fresh()->total)->toBe(8100)
        ->and($f['invoice']->fresh()->status)->toBe(Invoice::STATUS_PENDING_FINANCE_REAPPROVAL);
});

it('completes the real finance return and seller resubmission round trip', function () {
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
    $f = sellerCorrectionFixture();
    $f['invoice']->update(['status' => Invoice::STATUS_PENDING_FINANCE_REAPPROVAL]);
    $this->actingAs($f['seller'])->post(route('finance.invoices.return-to-sales', $f['invoice']->uuid), [
        'reason' => 'مغایرت قیمت',
        'note' => 'قیمت را بررسی کنید',
    ])->assertRedirect();
    expect($f['invoice']->fresh()->status)->toBe(Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION);
    $this->get(route('preinvoice.my.index', ['tab' => 'needs-correction']))
        ->assertOk()->assertSee('اصلاح فاکتور و ارسال مجدد');
    $this->post(route('preinvoice.my.invoice-correction.submit', $f['invoice']->uuid), correctionPayload($f))
        ->assertRedirect();
    expect($f['invoice']->fresh()->status)->toBe(Invoice::STATUS_PENDING_FINANCE_REAPPROVAL);
    $this->get(route('preinvoice.draft.index', ['tab' => 'reapprovals']))
        ->assertOk()->assertSee($f['invoice']->uuid);
});
