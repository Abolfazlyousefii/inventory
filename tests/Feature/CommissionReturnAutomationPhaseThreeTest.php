<?php

use App\Models\Category;
use App\Models\CommissionCorrectionEntry;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPeriod;
use App\Models\CommissionRateRevision;
use App\Models\CommissionReconciliationWarning;
use App\Models\CommissionReturnSyncOutbox;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesReturnDocument;
use App\Models\SalesReturnDocumentItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Commissions\CommissionReconciliationService;
use App\Services\Commissions\CommissionReturnSyncOutboxService;
use App\Services\Commissions\CommissionTarget;
use App\Services\SalesReturnCommissionPolicy;
use App\Services\SalesReturnService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->artisan('migrate:fresh', ['--force' => true])
        ->assertExitCode(0);

    Carbon::setTestNow('2026-08-20 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function p3Seller(string $name = 'Phase3 Seller'): User
{
    return User::factory()->create([
        'name' => $name,
        'is_active' => true,
        'can_access_erp' => true,
        'is_seller' => true,
    ]);
}

function p3Period(): CommissionPeriod
{
    return CommissionPeriod::query()->create([
        'label' => 'Phase 3 August',
        'start_at' => '2026-08-01 00:00:00',
        'end_at' => '2026-09-01 00:00:00',
        'cycle_day_snapshot' => 10,
        'status' => CommissionPeriod::STATUS_OPEN,
        'needs_recalculation' => false,
    ]);
}

function p3Sale(User $seller, CommissionPeriod $period, int $quantity = 2): Invoice
{
    $customer = Customer::query()->create([
        'first_name' => 'Phase',
        'last_name' => 'Three',
        'mobile' => '09'.random_int(100000000, 999999999),
    ]);

    $category = Category::query()->create(['name' => 'P3 '.Str::uuid()]);
    $product = Product::query()->create([
        'name' => 'P3 Product',
        'sku' => (string) Str::uuid(),
        'category_id' => $category->id,
        'stock' => 50,
        'reserved' => 0,
        'price' => 10_000_000,
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => 'P3 Default',
        'variety_name' => 'P3 Default',
        'variety_code' => '01',
        'variant_code' => 'P3'.Str::upper(Str::random(8)),
        'buy_price' => 5_000_000,
        'sell_price' => 10_000_000,
        'stock' => 50,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ]);

    CommissionRateRevision::query()->create(array_merge([
        'target_type' => 'product',
        'target_id' => $product->id,
        'target_key' => CommissionTarget::key('product', $product->id),
        'active_marker' => 1,
        'percentage' => '2.0000',
        'effective_from' => '2026-01-01 00:00:00',
        'created_by' => $seller->id,
    ], CommissionTarget::foreignKeys('product', $product->id)));

    $preinvoice = PreinvoiceOrder::query()->create([
        'uuid' => (string) Str::uuid(),
        'created_by' => $seller->id,
        'seller_id' => $seller->id,
        'customer_name' => 'Phase Three',
        'customer_mobile' => '09120000000',
        'customer_address' => 'Tehran',
        'province_id' => 1,
        'shipping_id' => 0,
        'shipping_price' => 0,
        'discount_amount' => 0,
        'total_price' => $quantity * 10_000_000,
        'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
    ]);

    $invoice = Invoice::query()->create([
        'uuid' => 'P3-'.Str::random(10),
        'preinvoice_order_id' => $preinvoice->id,
        'seller_id' => $seller->id,
        'customer_id' => $customer->id,
        'customer_name' => 'Phase Three',
        'document_date' => $period->start_at->copy()->addDays(14),
        'shipping_price' => 0,
        'discount_amount' => 0,
        'invoice_discount_amount' => 0,
        'product_discount_amount' => 0,
        'discount_allocation_mode' => 'separate',
        'subtotal' => $quantity * 10_000_000,
        'total' => $quantity * 10_000_000,
        'status' => Invoice::STATUS_SHIPPED,
    ]);

    InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => $quantity,
        'price' => 10_000_000,
        'line_discount_amount' => 0,
    ]);

    return $invoice->fresh('items');
}

function p3ReturnWarehouse(): Warehouse
{
    return Warehouse::query()->create([
        'name' => 'P3 Return '.Str::uuid(),
        'type' => 'return',
        'is_active' => true,
    ]);
}

function p3Draft(
    Invoice $invoice,
    Warehouse $warehouse,
    string $reason,
    ?string $requestedEffect = null,
): SalesReturnDocument {
    $item = $invoice->items->firstOrFail();

    return app(SalesReturnService::class)->createDraft([
        'source_type' => SalesReturnDocument::SOURCE_INTERNAL_INVOICE,
        'customer_id' => $invoice->customer_id,
        'invoice_id' => $invoice->id,
        'default_destination_warehouse_id' => $warehouse->id,
        'return_reason' => $reason,
        'commission_effect_type' => $requestedEffect,
        'description' => null,
        'items' => [[
            'invoice_item_id' => $item->id,
            'item_source' => SalesReturnDocumentItem::SOURCE_INVOICE_ITEM,
            'return_quantity' => 1,
            'item_condition' => SalesReturnDocumentItem::CONDITION_DAMAGED,
            'destination_warehouse_id' => $warehouse->id,
        ]],
    ], $invoice->seller_id);
}

it('derives commission effect from known return reasons and prevents manual bypass', function () {
    $policy = app(SalesReturnCommissionPolicy::class);

    expect($policy->resolve('wrong_collection', SalesReturnDocument::COMMISSION_WARRANTY))
        ->toBe(SalesReturnDocument::COMMISSION_COMMERCIAL)
        ->and($policy->resolve('wrong_dispatch', SalesReturnDocument::COMMISSION_WARRANTY))
        ->toBe(SalesReturnDocument::COMMISSION_COMMERCIAL)
        ->and($policy->resolve('customer_cancellation', SalesReturnDocument::COMMISSION_WARRANTY))
        ->toBe(SalesReturnDocument::COMMISSION_COMMERCIAL)
        ->and($policy->resolve('warranty', SalesReturnDocument::COMMISSION_COMMERCIAL))
        ->toBe(SalesReturnDocument::COMMISSION_WARRANTY)
        ->and($policy->resolve('damaged_product', SalesReturnDocument::COMMISSION_COMMERCIAL))
        ->toBe(SalesReturnDocument::COMMISSION_WARRANTY)
        ->and($policy->resolve('technical_issue', SalesReturnDocument::COMMISSION_COMMERCIAL))
        ->toBe(SalesReturnDocument::COMMISSION_WARRANTY)
        ->and($policy->resolve('other', SalesReturnDocument::COMMISSION_SERVICE))
        ->toBe(SalesReturnDocument::COMMISSION_SERVICE);
});

it('persists automatic effect on internal drafts including wrong collection', function () {
    $seller = p3Seller();
    $period = p3Period();
    $invoice = p3Sale($seller, $period);
    $warehouse = p3ReturnWarehouse();

    $commercial = p3Draft(
        $invoice,
        $warehouse,
        'wrong_collection',
        SalesReturnDocument::COMMISSION_WARRANTY,
    );

    expect($commercial->commission_effect_type)->toBe(SalesReturnDocument::COMMISSION_COMMERCIAL)
        ->and(SalesReturnDocument::returnReasonLabels())->toHaveKey('wrong_collection');

    $warranty = p3Draft(
        $invoice,
        $warehouse,
        'warranty',
        SalesReturnDocument::COMMISSION_COMMERCIAL,
    );

    expect($warranty->commission_effect_type)->toBe(SalesReturnDocument::COMMISSION_WARRANTY);
});

it('deducts wrong-collection commission only after the business transaction commits', function () {
    $seller = p3Seller();
    $period = p3Period();
    $invoice = p3Sale($seller, $period);
    $warehouse = p3ReturnWarehouse();
    $draft = p3Draft($invoice, $warehouse, 'wrong_collection');

    expect(CommissionLedgerEntry::query()
        ->where('invoice_id', $invoice->id)
        ->where('active_marker', 1)
        ->firstOrFail()
        ->total_commission_amount)->toBe(400_000);

    DB::transaction(function () use ($draft, $seller): void {
        app(SalesReturnService::class)->apply($draft, $seller->id);

        expect(CommissionCorrectionEntry::query()
            ->where('sales_return_document_id', $draft->id)
            ->exists())->toBeFalse()
            ->and(CommissionReturnSyncOutbox::query()
                ->where('sales_return_document_id', $draft->id)
                ->exists())->toBeTrue();
    });

    expect($draft->fresh()->isApplied())->toBeTrue()
        ->and(CommissionCorrectionEntry::query()
            ->where('sales_return_document_id', $draft->id)
            ->sum('total_commission_amount'))->toBe(-200_000)
        ->and(CommissionReturnSyncOutbox::query()
            ->where('sales_return_document_id', $draft->id)
            ->exists())->toBeFalse();
});

it('keeps warranty and damaged-product returns at zero commission impact', function (string $reason) {
    $seller = p3Seller();
    $period = p3Period();
    $invoice = p3Sale($seller, $period);
    $warehouse = p3ReturnWarehouse();
    $draft = p3Draft(
        $invoice,
        $warehouse,
        $reason,
        SalesReturnDocument::COMMISSION_COMMERCIAL,
    );

    app(SalesReturnService::class)->apply($draft, $seller->id);

    expect($draft->fresh()->commission_effect_type)->toBe(SalesReturnDocument::COMMISSION_WARRANTY)
        ->and(CommissionCorrectionEntry::query()
            ->where('sales_return_document_id', $draft->id)
            ->count())->toBe(0)
        ->and(CommissionReturnSyncOutbox::query()->count())->toBe(0);
})->with(['warranty', 'damaged_product', 'technical_issue', 'appearance_issue']);

it('reconciles pending reapproval and final reapply idempotently through the return outbox', function () {
    $seller = p3Seller();
    $period = p3Period();
    $invoice = p3Sale($seller, $period);
    $warehouse = p3ReturnWarehouse();
    $return = p3Draft($invoice, $warehouse, 'wrong_collection');
    app(SalesReturnService::class)->apply($return, $seller->id);

    expect(CommissionCorrectionEntry::query()
        ->where('sales_return_document_id', $return->id)
        ->sum('total_commission_amount'))->toBe(-200_000);

    DB::transaction(function () use ($return, $seller): void {
        $return->update([
            'status' => SalesReturnDocument::STATUS_PENDING_WAREHOUSE,
            'applied_at' => null,
            'applied_by' => null,
        ]);
        app(CommissionReturnSyncOutboxService::class)->stage($return->id, $seller->id);
    });

    expect(CommissionCorrectionEntry::query()
        ->where('sales_return_document_id', $return->id)
        ->sum('total_commission_amount'))->toBe(0);

    DB::transaction(function () use ($return, $seller): void {
        $return->update([
            'status' => SalesReturnDocument::STATUS_APPLIED,
            'applied_at' => now(),
            'applied_by' => $seller->id,
        ]);
        app(CommissionReturnSyncOutboxService::class)->stage($return->id, $seller->id);
        app(CommissionReturnSyncOutboxService::class)->stage($return->id, $seller->id);
    });

    expect(CommissionCorrectionEntry::query()
        ->where('sales_return_document_id', $return->id)
        ->sum('total_commission_amount'))->toBe(-200_000)
        ->and(CommissionReturnSyncOutbox::query()->count())->toBe(0);
});

it('creates a counter correction when an applied return is voided', function () {
    $seller = p3Seller();
    $period = p3Period();
    $invoice = p3Sale($seller, $period);
    $warehouse = p3ReturnWarehouse();
    $return = p3Draft($invoice, $warehouse, 'wrong_collection');
    app(SalesReturnService::class)->apply($return, $seller->id);

    DB::transaction(function () use ($return, $seller): void {
        $return->update([
            'status' => SalesReturnDocument::STATUS_CANCELLED,
            'cancelled_by' => $seller->id,
            'cancelled_at' => now()->addHour(),
            'cancel_reason' => 'test void',
        ]);
        app(CommissionReturnSyncOutboxService::class)->stage($return->id, $seller->id);
    });

    expect(CommissionCorrectionEntry::query()
        ->where('sales_return_document_id', $return->id)
        ->count())->toBe(2)
        ->and(CommissionCorrectionEntry::query()
            ->where('sales_return_document_id', $return->id)
            ->sum('total_commission_amount'))->toBe(0);
});

it('retains retry work warns and dirties the invoice period when return sync fails', function () {
    $seller = p3Seller();
    $period = p3Period();
    $invoice = p3Sale($seller, $period);
    $warehouse = p3ReturnWarehouse();
    $return = p3Draft($invoice, $warehouse, 'wrong_collection');

    $return->update([
        'status' => SalesReturnDocument::STATUS_APPLIED,
        'applied_by' => $seller->id,
        'applied_at' => now(),
    ]);

    $period->update(['needs_recalculation' => false]);

    $mock = Mockery::mock(CommissionReconciliationService::class);
    $mock->shouldReceive('reconcileReturn')
        ->atLeast()
        ->once()
        ->andThrow(new RuntimeException('forced return sync failure'));
    app()->instance(CommissionReconciliationService::class, $mock);

    DB::transaction(function () use ($return, $seller): void {
        app(CommissionReturnSyncOutboxService::class)->stage($return->id, $seller->id);
    });

    $outbox = CommissionReturnSyncOutbox::query()
        ->where('sales_return_document_id', $return->id)
        ->firstOrFail();

    expect($outbox->attempts)->toBe(1)
        ->and($outbox->last_error)->toContain('forced return sync failure')
        ->and(CommissionReconciliationWarning::query()
            ->where('identity_key', 'return-sync-failed:'.$return->id)
            ->where('code', 'return_sync_failed')
            ->exists())->toBeTrue()
        ->and($period->fresh()->needs_recalculation)->toBeTrue();
});

it('registers a scheduler recovery path for return outbox retries', function () {
    $console = file_get_contents(base_path('routes/console.php'));

    expect($console)
        ->toContain('CommissionReturnSyncOutboxService')
        ->toContain("commission-return-sync-outbox")
        ->toContain('->everyTenSeconds()');
});
