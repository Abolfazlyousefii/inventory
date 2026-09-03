<?php

use App\Models\Category;
use App\Models\CommissionCalculationWarning;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPeriod;
use App\Models\CommissionRateRevision;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\User;
use App\Services\Commissions\CommissionCalculationService;
use App\Services\Commissions\CommissionDocumentService;
use App\Services\Commissions\CommissionTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function commissionFlowRegressionSeller(string $name = 'Seller'): User
{
    return User::factory()->create([
        'name' => $name,
        'is_active' => true,
        'can_access_erp' => true,
        'is_seller' => true,
    ]);
}

function commissionFlowRegressionOperator(string $name = 'Operator'): User
{
    return User::factory()->create([
        'name' => $name,
        'is_active' => true,
        'can_access_erp' => true,
        'is_seller' => false,
    ]);
}

function commissionFlowRegressionPeriod(): CommissionPeriod
{
    return CommissionPeriod::query()->create([
        'label' => 'Commission seller flow regression',
        'start_at' => '2026-08-01 00:00:00',
        'end_at' => '2026-09-01 00:00:00',
        'cycle_day_snapshot' => 10,
        'status' => CommissionPeriod::STATUS_OPEN,
    ]);
}

function commissionFlowRegressionProduct(User $actor): Product
{
    $category = Category::query()->create(['name' => 'Commission flow '.Str::random(6)]);
    $product = Product::query()->create([
        'name' => 'Commission flow product',
        'sku' => (string) Str::uuid(),
        'category_id' => $category->id,
        'stock' => 10,
        'reserved' => 0,
        'price' => 10_000_000,
    ]);

    CommissionRateRevision::query()->create(array_merge([
        'target_type' => 'product',
        'target_id' => $product->id,
        'target_key' => CommissionTarget::key('product', $product->id),
        'active_marker' => 1,
        'percentage' => '2.0000',
        'effective_from' => '2026-01-01 00:00:00',
        'created_by' => $actor->id,
    ], CommissionTarget::foreignKeys('product', $product->id)));

    return $product;
}

function commissionFlowRegressionInvoice(
    ?User $operator,
    Product $product,
    ?User $invoiceSeller = null,
    ?User $preinvoiceSeller = null,
    ?Customer $customer = null,
): Invoice {
    $preinvoice = PreinvoiceOrder::query()->create([
        'uuid' => (string) Str::uuid(),
        'created_by' => $operator?->id,
        'seller_id' => $preinvoiceSeller?->id,
        'customer_id' => $customer?->id,
        'customer_name' => $customer?->display_name ?? 'Commission customer',
        'customer_mobile' => $customer?->mobile ?? '09120000000',
        'customer_address' => 'Tehran',
        'province_id' => 1,
        'shipping_id' => 0,
        'shipping_price' => 0,
        'discount_amount' => 0,
        'total_price' => 10_000_000,
        'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
    ]);

    $invoice = Invoice::query()->create([
        'uuid' => 'CSR-'.Str::random(8),
        'preinvoice_order_id' => $preinvoice->id,
        'seller_id' => $invoiceSeller?->id,
        'customer_id' => $customer?->id,
        'customer_name' => $customer?->display_name ?? 'Commission customer',
        'document_date' => '2026-08-15 12:00:00',
        'shipping_price' => 0,
        'discount_amount' => 0,
        'invoice_discount_amount' => 0,
        'product_discount_amount' => 0,
        'discount_allocation_mode' => 'separate',
        'subtotal' => 10_000_000,
        'total' => 10_000_000,
        'status' => Invoice::STATUS_SHIPPED,
    ]);

    InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 10_000_000,
        'line_discount_amount' => 0,
    ]);

    return $invoice->fresh();
}

it('includes all five seller invoices once in the commission document', function () {
    $seller = commissionFlowRegressionSeller();
    $actor = commissionFlowRegressionSeller('Commission manager');
    $period = commissionFlowRegressionPeriod();
    $product = commissionFlowRegressionProduct($actor);
    $invoices = collect(range(1, 5))->map(
        fn () => commissionFlowRegressionInvoice($seller, $product, $seller, $seller),
    );

    app(CommissionCalculationService::class)->recalculate($period);
    $document = app(CommissionDocumentService::class)->create($seller, $period, $actor);

    $activeInvoiceIds = CommissionLedgerEntry::query()
        ->where('commission_period_id', $period->id)
        ->where('seller_id', $seller->id)
        ->active()
        ->distinct()
        ->pluck('invoice_id');
    $documentInvoiceIds = $document->items()->pluck('invoice_id');

    expect($activeInvoiceIds)->toHaveCount(5)
        ->and($documentInvoiceIds)->toHaveCount(5)
        ->and($documentInvoiceIds->unique())->toHaveCount(5)
        ->and($documentInvoiceIds->sort()->values()->all())
        ->toBe($invoices->pluck('id')->sort()->values()->all());
});

it('credits the assigned seller when an internal operator created the invoice', function () {
    $seller = commissionFlowRegressionSeller();
    $operator = commissionFlowRegressionOperator();
    $period = commissionFlowRegressionPeriod();
    $product = commissionFlowRegressionProduct($seller);
    $invoice = commissionFlowRegressionInvoice($operator, $product, null, $seller);

    app(CommissionCalculationService::class)->recalculate($period);

    expect($invoice->commissionSellerId())->toBe($seller->id)
        ->and(CommissionLedgerEntry::query()->where('invoice_id', $invoice->id)->active()->firstOrFail()->seller_id)
        ->toBe($seller->id);
});

it('does not turn an employee operator for an internal customer into a commission seller', function () {
    $operator = commissionFlowRegressionOperator();
    $period = commissionFlowRegressionPeriod();
    $product = commissionFlowRegressionProduct($operator);
    $customer = Customer::query()->create([
        'first_name' => 'Internal',
        'last_name' => 'Company',
        'company_name' => 'Internal Company',
        'mobile' => '0912'.random_int(1000000, 9999999),
    ]);
    $invoice = commissionFlowRegressionInvoice($operator, $product, null, null, $customer);

    app(CommissionCalculationService::class)->recalculate($period);

    expect($invoice->commissionSellerId())->toBeNull()
        ->and(CommissionLedgerEntry::query()->where('invoice_id', $invoice->id)->active()->exists())->toBeFalse()
        ->and(CommissionCalculationWarning::query()
            ->where('commission_period_id', $period->id)
            ->where('invoice_id', $invoice->id)
            ->where('code', 'missing_seller')
            ->exists())->toBeTrue();
});

it('audits an invoice without a seller and creates no commission ledger entry', function () {
    $actor = commissionFlowRegressionOperator();
    $period = commissionFlowRegressionPeriod();
    $product = commissionFlowRegressionProduct($actor);
    $invoice = commissionFlowRegressionInvoice(null, $product);

    app(CommissionCalculationService::class)->recalculate($period);

    $warning = CommissionCalculationWarning::query()
        ->where('commission_period_id', $period->id)
        ->where('invoice_id', $invoice->id)
        ->where('code', 'missing_seller')
        ->firstOrFail();

    expect($invoice->commissionSellerId())->toBeNull()
        ->and(CommissionLedgerEntry::query()->where('invoice_id', $invoice->id)->active()->exists())->toBeFalse()
        ->and($warning->context)->toMatchArray([
            'invoice_seller_id' => null,
            'preinvoice_seller_id' => null,
            'operator_id' => null,
        ]);
});

it('keeps repeated commission recalculation idempotent', function () {
    $seller = commissionFlowRegressionSeller();
    $period = commissionFlowRegressionPeriod();
    $product = commissionFlowRegressionProduct($seller);
    $invoice = commissionFlowRegressionInvoice($seller, $product, $seller, $seller);
    $service = app(CommissionCalculationService::class);

    $service->recalculate($period);
    $service->recalculate($period->fresh());
    $service->recalculate($period->fresh());

    expect(CommissionLedgerEntry::query()->where('invoice_id', $invoice->id)->count())->toBe(1)
        ->and(CommissionLedgerEntry::query()->where('invoice_id', $invoice->id)->active()->count())->toBe(1);
});
