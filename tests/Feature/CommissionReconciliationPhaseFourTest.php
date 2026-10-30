<?php

use App\Models\Category;
use App\Models\CommissionCorrectionEntry;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPeriod;
use App\Models\CommissionRateRevision;
use App\Models\CommissionReconciliationWarning;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\SalesReturnDocument;
use App\Models\SalesReturnDocumentItem;
use App\Models\SellerReassignmentAudit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Commissions\CommissionCalculationService;
use App\Services\Commissions\CommissionDocumentService;
use App\Services\Commissions\CommissionReconciliationService;
use App\Services\Commissions\CommissionTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function p4Seller(string $name): User
{
    return User::factory()->create(['name' => $name, 'is_active' => true, 'can_access_erp' => true, 'is_seller' => true]);
}

function p4Period(string $start, string $end, string $status = 'open'): CommissionPeriod
{
    return CommissionPeriod::query()->create(['label' => "$start/$end", 'start_at' => $start, 'end_at' => $end, 'cycle_day_snapshot' => 10, 'status' => $status]);
}

function p4Sale(User $seller, CommissionPeriod $period, int $quantity = 10, string $rate = '10.0000'): Invoice
{
    $customer = Customer::query()->create(['first_name' => 'Test', 'last_name' => 'Customer', 'mobile' => '09'.random_int(100000000, 999999999)]);
    $category = Category::query()->create(['name' => Str::uuid()]);
    $product = Product::query()->create(['name' => 'P4', 'sku' => Str::uuid(), 'category_id' => $category->id, 'stock' => 20, 'reserved' => 0, 'price' => 1_000_000]);
    CommissionRateRevision::query()->create(array_merge(['target_type' => 'product', 'target_id' => $product->id,
        'target_key' => CommissionTarget::key('product', $product->id), 'active_marker' => 1, 'percentage' => $rate,
        'effective_from' => '2026-01-01', 'created_by' => $seller->id], CommissionTarget::foreignKeys('product', $product->id)));
    $preinvoice = PreinvoiceOrder::query()->create(['uuid' => Str::uuid(), 'created_by' => $seller->id, 'seller_id' => $seller->id,
        'customer_name' => 'Customer', 'customer_mobile' => '09120000000', 'customer_address' => 'Tehran', 'province_id' => 1,
        'shipping_id' => 0, 'shipping_price' => 0, 'discount_amount' => 0, 'total_price' => $quantity * 1_000_000,
        'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE]);
    $invoice = Invoice::query()->create(['uuid' => 'P4-'.Str::random(8), 'preinvoice_order_id' => $preinvoice->id, 'seller_id' => $seller->id, 'customer_id' => $customer->id,
        'customer_name' => 'Customer', 'document_date' => $period->start_at->copy()->addDay(), 'shipping_price' => 0, 'discount_amount' => 0,
        'invoice_discount_amount' => 0, 'product_discount_amount' => 0, 'discount_allocation_mode' => 'separate',
        'subtotal' => $quantity * 1_000_000, 'total' => $quantity * 1_000_000, 'status' => Invoice::STATUS_SHIPPED]);
    InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'product_id' => $product->id, 'quantity' => $quantity, 'price' => 1_000_000, 'line_discount_amount' => 0]);
    app(CommissionCalculationService::class)->recalculate($period);

    return $invoice->fresh('items');
}

function p4Return(Invoice $invoice, int $quantity, string $effect = 'commercial', string $status = 'applied', string $date = '2026-09-10'): SalesReturnDocument
{
    $warehouse = Warehouse::query()->firstOrCreate(['name' => 'P4 Return Warehouse'], ['type' => 'return', 'is_active' => true]);
    $document = SalesReturnDocument::query()->create(['document_number' => 'SR-'.Str::random(8), 'source_type' => 'internal_invoice',
        'status' => $status, 'customer_id' => $invoice->customer_id, 'invoice_id' => $invoice->id, 'commission_effect_type' => $effect,
        'default_destination_warehouse_id' => $warehouse->id, 'total_quantity' => $quantity, 'items_count' => 1, 'total_refund_amount' => $quantity * 1_000_000,
        'created_by' => $invoice->seller_id, 'applied_by' => $invoice->seller_id, 'applied_at' => $date]);
    $source = $invoice->items->first();
    SalesReturnDocumentItem::query()->create(['document_id' => $document->id, 'invoice_item_id' => $source->id,
        'product_id' => $source->product_id, 'item_source' => 'invoice_item', 'item_condition' => 'healthy',
        'destination_warehouse_id' => $warehouse->id, 'sold_quantity_snapshot' => $source->quantity, 'previously_returned_quantity_snapshot' => 0,
        'return_quantity' => $quantity, 'unit_price_snapshot' => $source->price, 'refund_unit_price' => 1_000_000,
        'refund_amount' => $quantity * 1_000_000]);

    return $document->fresh('items');
}

it('creates exact full and partial signed reversals from historical sale snapshots', function () {
    $seller = p4Seller('A');
    $salePeriod = p4Period('2026-08-01', '2026-09-01');
    $returnPeriod = p4Period('2026-09-01', '2026-10-01');
    $invoice = p4Sale($seller, $salePeriod);
    $return = p4Return($invoice, 3);
    app(CommissionReconciliationService::class)->reconcileReturn($return, $seller->id);
    $entry = CommissionCorrectionEntry::query()->firstOrFail();
    expect($entry->commission_period_id)->toBe($returnPeriod->id)->and($entry->source_period_id)->toBe($salePeriod->id)
        ->and($entry->quantity_delta)->toBe(-3)->and($entry->net_amount)->toBe(-3_000_000)
        ->and($entry->base_commission_amount)->toBe(-300_000)->and($entry->total_commission_amount)->toBe(-300_000);

    $full = p4Return($invoice, 10, 'commercial', 'applied', '2026-09-11');
    app(CommissionReconciliationService::class)->reconcileReturn($full, $seller->id);
    expect(CommissionCorrectionEntry::query()->where('sales_return_document_id', $full->id)->sum('total_commission_amount'))->toBe(-1_000_000);
});

it('is idempotent supports multiple returns and defensively caps over reversal', function () {
    $seller = p4Seller('A');
    $salePeriod = p4Period('2026-08-01', '2026-09-01');
    p4Period('2026-09-01', '2026-10-01');
    $invoice = p4Sale($seller, $salePeriod);
    $first = p4Return($invoice, 3);
    $service = app(CommissionReconciliationService::class);
    $service->reconcileReturn($first, $seller->id);
    $service->reconcileReturn($first, $seller->id);
    expect(CommissionCorrectionEntry::query()->count())->toBe(1);
    $second = p4Return($invoice, 5);
    $second->items->first()->update(['previously_returned_quantity_snapshot' => 8]);
    $service->reconcileReturn($second->fresh('items'), $seller->id);
    expect(CommissionCorrectionEntry::query()->sum('total_commission_amount'))->toBe(-500_000)
        ->and(abs((int) CommissionCorrectionEntry::query()->sum('total_commission_amount')))->toBeLessThanOrEqual(1_000_000);
});

it('keeps warranty and draft returns at zero and appends a counter entry when applied return is cancelled', function () {
    $seller = p4Seller('A');
    $salePeriod = p4Period('2026-08-01', '2026-09-01');
    $returnPeriod = p4Period('2026-09-01', '2026-10-01');
    $cancellationPeriod = p4Period('2026-10-01', '2026-11-01');
    $invoice = p4Sale($seller, $salePeriod);
    $service = app(CommissionReconciliationService::class);
    $service->reconcileReturn(p4Return($invoice, 5, 'warranty'), $seller->id);
    $service->reconcileReturn(p4Return($invoice, 5, 'commercial', 'draft'), $seller->id);
    expect(CommissionCorrectionEntry::query()->count())->toBe(0);
    $return = p4Return($invoice, 4);
    $service->reconcileReturn($return, $seller->id);
    $return->update(['status' => 'cancelled', 'cancelled_at' => '2026-10-12']);
    $service->reconcileReturn($return->fresh('items'), $seller->id);
    expect(CommissionCorrectionEntry::query()->where('sales_return_document_id', $return->id)->count())->toBe(2)
        ->and(CommissionCorrectionEntry::query()->where('sales_return_document_id', $return->id)->sum('total_commission_amount'))->toBe(0)
        ->and(CommissionCorrectionEntry::query()->where('sales_return_document_id', $return->id)->where('total_commission_amount', '<', 0)->value('commission_period_id'))->toBe($returnPeriod->id)
        ->and(CommissionCorrectionEntry::query()->where('sales_return_document_id', $return->id)->where('total_commission_amount', '>', 0)->value('commission_period_id'))->toBe($cancellationPeriod->id);
});

it('moves open-period economic ownership and releases the old document claim without carrying approval', function () {
    $old = p4Seller('A');
    $new = p4Seller('B');
    $actor = p4Seller('Actor');
    $period = p4Period('2026-08-01', '2026-09-01');
    $invoice = p4Sale($old, $period);
    $service = app(CommissionDocumentService::class);
    $documentA = $service->create($old, $period, $actor);
    $item = $documentA->items()->firstOrFail();
    $service->approve($item, $actor);
    $invoice->update(['seller_id' => $new->id]);
    $audit = SellerReassignmentAudit::query()->create(['invoice_id' => $invoice->id, 'preinvoice_id' => $invoice->preinvoice_order_id,
        'old_seller_id' => $old->id, 'new_seller_id' => $new->id, 'changed_by' => $actor->id, 'reason' => 'correct owner', 'source' => 'test', 'changed_at' => now()]);
    app(CommissionReconciliationService::class)->reconcileSellerReassignment($invoice->fresh(), $new, $audit);
    $item = $item->fresh();
    expect($item->status)->toBe('removed')->and($item->active_invoice_id)->toBeNull();
    app(CommissionCalculationService::class)->recalculate($period->fresh());
    $documentB = $service->create($new, $period->fresh(), $actor);
    expect($documentB->items()->where('invoice_id', $invoice->id)->firstOrFail()->status)->toBe('pending');
});

it('preserves immutable sale period and creates idempotent debit credit historical corrections', function () {
    $old = p4Seller('A');
    $new = p4Seller('B');
    $actor = p4Seller('Actor');
    $closed = p4Period('2026-08-01', '2026-09-01');
    $invoice = p4Sale($old, $closed);
    $closed->update(['status' => 'closed']);
    $target = p4Period('2026-09-01', '2026-10-01');
    $invoice->update(['seller_id' => $new->id]);
    $audit = SellerReassignmentAudit::query()->create(['invoice_id' => $invoice->id, 'preinvoice_id' => $invoice->preinvoice_order_id,
        'old_seller_id' => $old->id, 'new_seller_id' => $new->id, 'changed_by' => $actor->id, 'reason' => 'history correction', 'source' => 'test', 'changed_at' => '2026-09-10']);
    $service = app(CommissionReconciliationService::class);
    $service->reconcileSellerReassignment($invoice, $new, $audit);
    $service->reconcileSellerReassignment($invoice, $new, $audit);
    expect(CommissionCorrectionEntry::query()->count())->toBe(2)
        ->and(CommissionCorrectionEntry::query()->where('seller_id', $old->id)->sum('total_commission_amount'))->toBe(-1_000_000)
        ->and(CommissionCorrectionEntry::query()->where('seller_id', $new->id)->sum('total_commission_amount'))->toBe(1_000_000)
        ->and(CommissionCorrectionEntry::query()->where('commission_period_id', $target->id)->count())->toBe(2);
});

it('imports system corrections pending for financial review and keeps ledger after rejection', function () {
    $seller = p4Seller('A');
    $actor = p4Seller('Actor');
    $salePeriod = p4Period('2026-08-01', '2026-09-01');
    $returnPeriod = p4Period('2026-09-01', '2026-10-01');
    $invoice = p4Sale($seller, $salePeriod);
    $return = p4Return($invoice, 5);
    app(CommissionReconciliationService::class)->reconcileReturn($return, $actor->id);
    $document = app(CommissionDocumentService::class)->create($seller, $returnPeriod, $actor);
    $row = $document->corrections()->firstOrFail();
    expect($row->status)->toBe('pending')->and($row->total_amount)->toBe(-500_000);
    app(CommissionDocumentService::class)->reviewCorrection($row, $actor, false, 'not accepted');
    expect($row->fresh()->status)->toBe('rejected')->and(CommissionCorrectionEntry::query()->count())->toBe(1);
});

it('audits reconciliation in dry-run without mutation and apply remains idempotent', function () {
    $seller = p4Seller('A');
    $salePeriod = p4Period('2026-08-01', '2026-09-01');
    p4Period('2026-09-01', '2026-10-01');
    $invoice = p4Sale($seller, $salePeriod);
    p4Return($invoice, 2);
    $this->artisan('commissions:audit-reconciliation')->assertSuccessful()->expectsOutputToContain('Dry-run only');
    expect(CommissionCorrectionEntry::query()->count())->toBe(0);
    $this->artisan('commissions:audit-reconciliation', ['--apply' => true])->assertSuccessful();
    $this->artisan('commissions:audit-reconciliation', ['--apply' => true])->assertSuccessful();
    expect(CommissionCorrectionEntry::query()->count())->toBe(1);
});

it('reverses base and campaign snapshots without consulting the current rate', function () {
    $seller = p4Seller('A');
    $salePeriod = p4Period('2026-08-01', '2026-09-01');
    p4Period('2026-09-01', '2026-10-01');
    $invoice = p4Sale($seller, $salePeriod, 10, '2.0000');
    $ledger = CommissionLedgerEntry::query()->firstOrFail();
    $ledger->update(['campaign_rate_snapshot' => '5.0000', 'campaign_commission_amount' => 500_000,
        'effective_rate_snapshot' => '7.0000', 'total_commission_amount' => 700_000]);
    CommissionRateRevision::query()->where('product_id', $invoice->items->first()->product_id)->update(['percentage' => '9.0000']);
    app(CommissionReconciliationService::class)->reconcileReturn(p4Return($invoice, 5), $seller->id);
    $reversal = CommissionCorrectionEntry::query()->firstOrFail();
    expect($reversal->base_commission_amount)->toBe(-100_000)->and($reversal->campaign_commission_amount)->toBe(-250_000)
        ->and($reversal->total_commission_amount)->toBe(-350_000);
});

it('keeps multi-hop open-period ownership only on the final seller', function () {
    $a = p4Seller('A');
    $b = p4Seller('B');
    $c = p4Seller('C');
    $actor = p4Seller('Actor');
    $period = p4Period('2026-08-01', '2026-09-01');
    $invoice = p4Sale($a, $period);
    $service = app(CommissionReconciliationService::class);
    foreach ([[$b, $a], [$c, $b]] as [$target, $source]) {
        $invoice->update(['seller_id' => $target->id]);
        $audit = SellerReassignmentAudit::query()->create(['invoice_id' => $invoice->id, 'preinvoice_id' => $invoice->preinvoice_order_id,
            'old_seller_id' => $source->id, 'new_seller_id' => $target->id, 'changed_by' => $actor->id, 'reason' => 'hop', 'source' => 'test', 'changed_at' => now()]);
        $service->reconcileSellerReassignment($invoice->fresh(), $target, $audit);
        app(CommissionCalculationService::class)->recalculate($period->fresh());
    }
    expect(CommissionLedgerEntry::query()->where('status', 'active')->firstOrFail()->seller_id)->toBe($c->id)
        ->and(CommissionLedgerEntry::query()->where('status', 'superseded')->whereIn('seller_id', [$a->id, $b->id])->count())->toBe(2);
});

it('queues immutable reassignment when no correction period exists', function () {
    $a = p4Seller('A');
    $b = p4Seller('B');
    $actor = p4Seller('Actor');
    $closed = p4Period('2026-08-01', '2026-09-01');
    $invoice = p4Sale($a, $closed);
    $closed->update(['status' => 'paid']);
    $invoice->update(['seller_id' => $b->id]);
    $audit = SellerReassignmentAudit::query()->create(['invoice_id' => $invoice->id, 'preinvoice_id' => $invoice->preinvoice_order_id,
        'old_seller_id' => $a->id, 'new_seller_id' => $b->id, 'changed_by' => $actor->id, 'reason' => 'late correction', 'source' => 'test', 'changed_at' => '2026-10-10']);
    app(CommissionReconciliationService::class)->reconcileSellerReassignment($invoice, $b, $audit);
    expect(CommissionCorrectionEntry::query()->where('status', 'pending_unassigned_period')->count())->toBe(2)
        ->and(CommissionReconciliationWarning::query()->where('code', 'correction_without_period')->exists())->toBeTrue();
});

it('reverses the corrected economic owner after immutable seller reassignment', function () {
    $a = p4Seller('A');
    $b = p4Seller('B');
    $actor = p4Seller('Actor');
    $closed = p4Period('2026-08-01', '2026-09-01');
    $invoice = p4Sale($a, $closed);
    $closed->update(['status' => 'paid']);
    $correctionPeriod = p4Period('2026-09-01', '2026-10-01');
    $invoice->update(['seller_id' => $b->id]);
    $audit = SellerReassignmentAudit::query()->create(['invoice_id' => $invoice->id, 'preinvoice_id' => $invoice->preinvoice_order_id,
        'old_seller_id' => $a->id, 'new_seller_id' => $b->id, 'changed_by' => $actor->id, 'reason' => 'owner correction', 'source' => 'test', 'changed_at' => '2026-09-05']);
    $service = app(CommissionReconciliationService::class);
    $service->reconcileSellerReassignment($invoice, $b, $audit);
    $correctionPeriod->update(['status' => 'closed']);
    p4Period('2026-10-01', '2026-11-01');
    $return = p4Return($invoice, 2, 'commercial', 'applied', '2026-10-10');
    $service->reconcileReturn($return, $actor->id);
    expect(CommissionCorrectionEntry::query()->where('sales_return_document_id', $return->id)->firstOrFail()->seller_id)->toBe($b->id);
});
