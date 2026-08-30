<?php

use App\Models\Category;
use App\Models\CommissionCampaign;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPeriod;
use App\Models\CommissionRateRevision;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\User;
use App\Services\Commissions\CommissionCalculationService;
use App\Services\Commissions\CommissionCampaignResolver;
use App\Services\Commissions\CommissionPeriodDirtyMarker;
use App\Services\Commissions\CommissionRateService;
use App\Services\Commissions\CommissionReportService;
use App\Services\Commissions\CommissionTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function reportSeller(string $name = 'Seller'): User
{
    return User::factory()->create(['name' => $name, 'is_active' => true, 'can_access_erp' => true, 'is_seller' => true]);
}

function reportCatalog(): array
{
    $category = Category::query()->create(['name' => 'Report Category']);
    $product = Product::query()->create(['name' => 'Report Product', 'sku' => Str::uuid(), 'category_id' => $category->id, 'stock' => 1, 'reserved' => 0, 'price' => 1000]);

    return [$category, $product];
}

function reportPeriod(string $start = '2026-08-01', string $end = '2026-09-01'): CommissionPeriod
{
    return CommissionPeriod::query()->create(['label' => 'Report period', 'start_at' => $start, 'end_at' => $end, 'cycle_day_snapshot' => 10, 'status' => CommissionPeriod::STATUS_OPEN]);
}

function reportInvoice(User $seller, Product $product, ?bool $withoutCommissionEvents = false): Invoice
{
    $preinvoice = PreinvoiceOrder::query()->create([
        'uuid' => (string) Str::uuid(), 'created_by' => $seller->id, 'seller_id' => $seller->id,
        'customer_name' => 'Customer', 'customer_mobile' => '09120000000', 'customer_address' => 'Tehran',
        'province_id' => 1, 'shipping_id' => 0, 'shipping_price' => 0, 'discount_amount' => 0,
        'total_price' => 1000, 'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
    ]);

    $createInvoice = fn () => Invoice::query()->create([
        'uuid' => (string) Str::uuid(),
        'preinvoice_order_id' => $preinvoice->id,
        'customer_name' => 'Customer',
        'document_date' => '2026-08-15',
        'subtotal' => 1000,
        'total' => 1000,
        'status' => Invoice::STATUS_SHIPPED,
    ]);

    $invoice = $withoutCommissionEvents
        ? Invoice::withoutEvents($createInvoice)
        : $createInvoice();

    $createItem = fn () => InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 1000,
    ]);

    if ($withoutCommissionEvents) {
        InvoiceItem::withoutEvents($createItem);
    } else {
        $createItem();
    }

    return $invoice->fresh('items');
}

function reportRate(Product $product, User $actor): CommissionRateRevision
{
    return CommissionRateRevision::query()->create(array_merge([
        'target_type' => 'product', 'target_id' => $product->id, 'target_key' => "product:{$product->id}",
        'active_marker' => 1, 'percentage' => '2.0000', 'effective_from' => '2026-01-01', 'created_by' => $actor->id,
    ], CommissionTarget::foreignKeys('product', $product->id)));
}

it('marks only open and review periods dirty and refuses closed recalculation', function () {
    $open = reportPeriod();
    $review = reportPeriod('2026-09-01', '2026-10-01');
    $review->update(['status' => CommissionPeriod::STATUS_REVIEW]);
    $closed = reportPeriod('2026-10-01', '2026-11-01');
    $closed->update(['status' => CommissionPeriod::STATUS_CLOSED]);

    app(CommissionPeriodDirtyMarker::class)->markAllMutable();
    expect($open->fresh()->needs_recalculation)->toBeTrue()
        ->and($review->fresh()->needs_recalculation)->toBeTrue()
        ->and($closed->fresh()->needs_recalculation)->toBeFalse();

    expect(fn () => app(CommissionCalculationService::class)->recalculate($closed))->toThrow(ValidationException::class);
});

it('reports weighted seller summaries distinct invoices and paginated seller details', function () {
    [, $product] = reportCatalog();
    $seller = reportSeller('Weighted Seller');
    $period = reportPeriod();
    reportRate($product, $seller);
    $invoice = reportInvoice($seller, $product, null);
    $invoice->items()->create(['product_id' => $product->id, 'quantity' => 1, 'price' => 5_000_000, 'line_discount_amount' => 0]);
    app(CommissionCalculationService::class)->recalculate($period);

    $report = app(CommissionReportService::class);
    $summary = $report->periodSummary($period);
    $sellerRow = $report->sellerSummaries($period)->first();
    $details = $report->sellerDetails($period, $seller, 1);

    expect($summary['eligible_invoice_count'])->toBe(1)
        ->and($summary['eligible_item_count'])->toBe(2)
        ->and($sellerRow->invoice_count)->toBe(1)
        ->and((float) $sellerRow->effective_rate)->toBe(2.0)
        ->and((float) $summary['effective_rate'])->toBe(2.0)
        ->and($details->total())->toBe(1)
        ->and((int) $details->first()->items_count)->toBe(2);
});

it('scopes ordinary seller summaries and totals to the authenticated seller', function () {
    $period = reportPeriod();
    $seller = reportSeller('Own Seller');
    $other = reportSeller('Other Seller');
    [$category, $product] = reportCatalog();
    reportRate($product, $seller);
    reportInvoice($seller, $product);
    reportInvoice($other, $product);
    app(CommissionCalculationService::class)->recalculate($period);
    $reports = app(CommissionReportService::class);

    expect($reports->sellerSummariesFor($period, $seller)->pluck('seller_id')->all())->toBe([$seller->id])
        ->and($reports->periodSummary($period, $seller->id)['eligible_invoice_count'])->toBe(1)
        ->and($reports->periodSummary($period)['eligible_invoice_count'])->toBe(2);
});

it('audits missing sellers without creating ledger rows', function () {
    [, $product] = reportCatalog();
    $period = reportPeriod();
    $seller = reportSeller();
    $invoice = reportInvoice($seller, $product, null);
    $invoice->preinvoiceOrder()->update(['seller_id' => null, 'created_by' => null]);
    $invoice->update(['seller_id' => null]);

    app(CommissionCalculationService::class)->recalculate($period);

    expect(
        CommissionLedgerEntry::query()
            ->where('invoice_id', $invoice->id)
            ->where('active_marker', 1)
            ->count()
    )->toBe(0)
        ->and(
            CommissionLedgerEntry::query()
                ->where('invoice_id', $invoice->id)
                ->where('status', CommissionLedgerEntry::STATUS_SUPERSEDED)
                ->exists()
        )->toBeTrue()
        ->and($period->calculationWarnings()->where('code', 'missing_seller')->where('invoice_id', $invoice->id)->exists())->toBeTrue()
        ->and(app(CommissionReportService::class)->periodSummary($period)['missing_seller_invoice_count'])->toBe(1);
});

it('marks an open period dirty through invoice item and rate changes but never dirties closed periods', function () {
    [, $product] = reportCatalog();
    $seller = reportSeller();
    $open = reportPeriod();
    $closed = reportPeriod('2026-07-01', '2026-08-01');
    $closed->update(['status' => CommissionPeriod::STATUS_CLOSED]);
    $invoice = reportInvoice($seller, $product, null);
    $open->update(['needs_recalculation' => false]);

    $invoice->items->first()->update(['price' => 11_000_000]);

    $active = CommissionLedgerEntry::query()
        ->where('commission_period_id', $open->id)
        ->where('invoice_id', $invoice->id)
        ->where('active_marker', 1)
        ->firstOrFail();

    expect($open->fresh()->needs_recalculation)->toBeFalse()
        ->and($closed->fresh()->needs_recalculation)->toBeFalse()
        ->and((int) $active->gross_amount_snapshot)->toBe(11_000_000)
        ->and($active->missing_rate)->toBeTrue();

    // Global rate changes can affect many invoices, so they must still dirty
    // the mutable period even though one-invoice source changes are synced
    // incrementally.
    $open->update(['needs_recalculation' => false]);
    app(CommissionRateService::class)->setRate('product', $product->id, '2.0000', $seller);
    expect($open->fresh()->needs_recalculation)->toBeTrue()
        ->and($closed->fresh()->needs_recalculation)->toBeFalse();
});

it('resolves an archived campaign for invoices dated before its archive timestamp', function () {
    [$category, $product] = reportCatalog();
    $seller = reportSeller();
    $campaign = CommissionCampaign::query()->create([
        'name' => 'Historical', 'bonus_percentage' => '5.0000', 'start_at' => '2026-08-01', 'end_at' => '2026-09-01',
        'archived_at' => '2026-08-20', 'created_by' => $seller->id, 'updated_by' => $seller->id,
    ]);
    $campaign->targets()->create(array_merge([
        'target_type' => 'category', 'target_id' => $category->id, 'target_key' => "category:{$category->id}",
    ], CommissionTarget::foreignKeys('category', $category->id)));

    expect(app(CommissionCampaignResolver::class)->resolve($product, null, '2026-08-15'))->not->toBeNull()
        ->and(app(CommissionCampaignResolver::class)->resolve($product, null, '2026-08-21'))->toBeNull();
});

it('keeps seller summary query count constant for hundreds of ledger rows', function () {
    $seller = reportSeller();
    $period = reportPeriod();
    [, $product] = reportCatalog();
    // This is a report query-count test, not an incremental-sync test.
    // Keep fixture creation silent so the Phase 2 observer does not create
    // active ledger rows that this test intentionally inserts by hand.
    $invoice = reportInvoice($seller, $product, true);
    $items = [$invoice->items->first()];
    for ($index = 2; $index <= 300; $index++) {
        $items[] = InvoiceItem::withoutEvents(
            fn () => $invoice->items()->create([
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 1000,
                'line_discount_amount' => 0,
            ])
        );
    }
    $rows = [];
    foreach ($items as $index => $item) {
        $rows[] = [
            'commission_period_id' => $period->id, 'seller_id' => $seller->id,
            'invoice_id' => $invoice->id, 'invoice_item_id' => $item->id, 'product_id' => $product->id, 'invoice_number_snapshot' => $invoice->uuid,
            'invoice_date_snapshot' => '2026-08-15', 'product_name_snapshot' => 'Product', 'quantity_snapshot' => 1,
            'gross_amount_snapshot' => 1000, 'discount_amount_snapshot' => 0, 'net_amount_snapshot' => 1000,
            'base_rate_snapshot' => 2, 'campaign_rate_snapshot' => 0, 'effective_rate_snapshot' => 2,
            'base_commission_amount' => 20, 'campaign_commission_amount' => 0, 'total_commission_amount' => 20,
            'missing_rate' => false, 'calculation_version' => 1, 'calculation_fingerprint' => hash('sha256', (string) $item->id),
            'status' => CommissionLedgerEntry::STATUS_ACTIVE, 'active_marker' => 1, 'calculated_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ];
    }
    DB::table('commission_ledger_entries')->insert($rows);
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $summary = app(CommissionReportService::class)->sellerSummaries($period);

    expect($summary)->toHaveCount(1)->and($queries)->toBeLessThanOrEqual(2);
});
