<?php

use App\Models\Category;
use App\Models\CommissionAdjustment;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPeriod;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Commissions\CommissionReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function sciaPeriod(string $label = 'Invoice audit period'): CommissionPeriod
{
    return CommissionPeriod::query()->create([
        'label' => $label, 'start_at' => '2026-08-01', 'end_at' => '2026-09-01',
        'cycle_day_snapshot' => 10, 'status' => CommissionPeriod::STATUS_OPEN,
    ]);
}

function sciaSeller(string $name): User
{
    return User::factory()->create(['name' => $name, 'is_seller' => true, 'is_active' => true, 'can_access_erp' => true]);
}

function sciaInvoice(User $seller, string $number, string $customer = 'Audit Customer'): Invoice
{
    return Invoice::query()->create([
        'uuid' => $number, 'seller_id' => $seller->id, 'customer_name' => $customer,
        'subtotal' => 10_000_000, 'total' => 10_000_000, 'discount_amount' => 0,
        'document_date' => '2026-08-15', 'status' => Invoice::STATUS_SHIPPED,
    ]);
}

function sciaLedger(CommissionPeriod $period, User $seller, Invoice $invoice, int $commission, array $overrides = []): CommissionLedgerEntry
{
    static $sequence = 0;
    $category = Category::query()->firstOrCreate(['name' => 'Guard']);
    $product = Product::query()->create([
        'category_id' => $category->id, 'name' => 'Audit Product '.++$sequence,
        'sku' => (string) Str::uuid(), 'stock' => 1, 'reserved' => 0, 'price' => 10_000_000,
    ]);
    $item = InvoiceItem::query()->create([
        'invoice_id' => $invoice->id, 'product_id' => $product->id,
        'quantity' => 1, 'price' => 10_000_000, 'line_discount_amount' => 0,
    ]);

    return CommissionLedgerEntry::query()->create(array_merge([
        'commission_period_id' => $period->id, 'seller_id' => $seller->id,
        'invoice_id' => $invoice->id, 'invoice_item_id' => $item->id, 'product_id' => $product->id,
        'invoice_number_snapshot' => $invoice->uuid, 'invoice_date_snapshot' => '2026-08-15',
        'product_name_snapshot' => $product->name, 'quantity_snapshot' => 1,
        'gross_amount_snapshot' => 10_000_000, 'discount_amount_snapshot' => 0, 'net_amount_snapshot' => 10_000_000,
        'base_rate_snapshot' => '2.0000', 'campaign_rate_snapshot' => '0.0000', 'effective_rate_snapshot' => '2.0000',
        'base_commission_amount' => $commission, 'campaign_commission_amount' => 0,
        'total_commission_amount' => $commission, 'rate_source_type' => 'category', 'rate_source_id' => $category->id,
        'missing_rate' => false, 'calculation_version' => 1,
        'calculation_fingerprint' => hash('sha256', $period->id.'-'.$seller->id.'-'.$item->id.'-'.$sequence),
        'status' => CommissionLedgerEntry::STATUS_ACTIVE, 'active_marker' => 1, 'calculated_at' => now(),
    ], $overrides));
}

it('groups seller commission rows by invoice and reconciles item and invoice totals', function () {
    $period = sciaPeriod();
    $seller = sciaSeller('Seller A');
    $first = sciaInvoice($seller, 'INV-001');
    $second = sciaInvoice($seller, 'INV-002');
    sciaLedger($period, $seller, $first, 100_000);
    sciaLedger($period, $seller, $first, 200_000, ['campaign_commission_amount' => 50_000, 'total_commission_amount' => 250_000]);
    sciaLedger($period, $seller, $first, 0, ['base_rate_snapshot' => 0, 'effective_rate_snapshot' => 0, 'missing_rate' => false]);
    sciaLedger($period, $seller, $second, 150_000);

    $rows = app(CommissionReportService::class)->sellerDetails($period, $seller);
    $invoiceOne = $rows->getCollection()->firstWhere('invoice_id', $first->id);

    expect($rows->total())->toBe(2)
        ->and((int) $invoiceOne->items_count)->toBe(3)
        ->and((int) $invoiceOne->total_commission_amount)->toBe(350_000)
        ->and((int) $rows->getCollection()->sum('total_commission_amount'))->toBe(500_000);
});

it('keeps missing rate items visible and treats explicit zero as a valid rate', function () {
    $period = sciaPeriod();
    $seller = sciaSeller('Missing Seller');
    $invoice = sciaInvoice($seller, 'INV-MISSING');
    sciaLedger($period, $seller, $invoice, 100_000);
    sciaLedger($period, $seller, $invoice, 0, ['base_rate_snapshot' => 0, 'effective_rate_snapshot' => 0, 'missing_rate' => true]);
    sciaLedger($period, $seller, $invoice, 0, ['base_rate_snapshot' => 0, 'effective_rate_snapshot' => 0, 'missing_rate' => false]);

    $row = app(CommissionReportService::class)->sellerDetails($period, $seller)->first();
    expect((int) $row->total_commission_amount)->toBe(100_000)
        ->and((int) $row->missing_rate_count)->toBe(1)
        ->and(app(CommissionReportService::class)->invoiceEntries($period, $seller, $invoice))->toHaveCount(3);
});

it('isolates sellers periods and superseded history while detecting conflicting active owners', function () {
    $period = sciaPeriod();
    $otherPeriod = CommissionPeriod::query()->create([
        'label' => 'Other', 'start_at' => '2026-09-01', 'end_at' => '2026-10-01',
        'cycle_day_snapshot' => 10, 'status' => CommissionPeriod::STATUS_OPEN,
    ]);
    $sellerA = sciaSeller('Seller A');
    $sellerB = sciaSeller('Seller B');
    $invoice = sciaInvoice($sellerA, 'INV-OWNER');
    sciaLedger($period, $sellerA, $invoice, 100_000);
    sciaLedger($period, $sellerB, $invoice, 200_000);
    $historical = sciaLedger($otherPeriod, $sellerA, $invoice, 900_000);
    $historical->update(['status' => CommissionLedgerEntry::STATUS_SUPERSEDED, 'active_marker' => null]);

    $reports = app(CommissionReportService::class);
    expect($reports->sellerDetails($period, $sellerA)->total())->toBe(1)
        ->and($reports->sellerDetails($period, $sellerB)->total())->toBe(1)
        ->and($reports->sellerDetails($otherPeriod, $sellerA)->total())->toBe(0)
        ->and($reports->conflictingSellerInvoices($period)->keys()->map(fn ($id) => (int) $id)->all())->toBe([$invoice->id])
        ->and($historical->fresh()->status)->toBe(CommissionLedgerEntry::STATUS_SUPERSEDED);
});

it('paginates unique invoices without duplicates across pages', function () {
    $period = sciaPeriod();
    $seller = sciaSeller('Paged Seller');
    foreach (range(1, 75) as $number) {
        $invoice = sciaInvoice($seller, sprintf('PAGE-%03d', $number));
        sciaLedger($period, $seller, $invoice, $number);
        sciaLedger($period, $seller, $invoice, $number);
    }
    $reports = app(CommissionReportService::class);
    $pageOne = $reports->sellerDetails($period, $seller, 30);
    request()->query->set('invoices_page', 2);
    $pageTwo = $reports->sellerDetails($period, $seller, 30);
    request()->query->set('invoices_page', 3);
    $pageThree = $reports->sellerDetails($period, $seller, 30);

    $ids = collect([$pageOne, $pageTwo, $pageThree])->flatMap(fn ($page) => $page->pluck('invoice_id'));
    expect($pageOne)->toHaveCount(30)->and($pageTwo)->toHaveCount(30)->and($pageThree)->toHaveCount(15)
        ->and($ids->unique())->toHaveCount(75);
});

it('secures invoice detail against missing permission and seller URL tampering and renders snapshots', function () {
    $period = sciaPeriod();
    $sellerA = sciaSeller('Seller A');
    $sellerB = sciaSeller('Seller B');
    $invoiceA = sciaInvoice($sellerA, 'SECURE-A');
    $invoiceB = sciaInvoice($sellerB, 'SECURE-B');
    sciaLedger($period, $sellerA, $invoiceA, 200_000, ['product_name_snapshot' => 'Historical Guard', 'base_rate_snapshot' => '2.0000']);
    sciaLedger($period, $sellerB, $invoiceB, 300_000);

    $ordinary = User::factory()->create();
    $this->actingAs($ordinary)->get(route('commercial.commissions.sellers.invoices.show', [$period, $sellerA, $invoiceA]))->assertForbidden();

    $owner = User::factory()->create();
    $owner->assignRole(Role::findOrCreate('Owner', 'web'));
    $this->actingAs($owner)->get(route('commercial.commissions.sellers.invoices.show', [$period, $sellerA, $invoiceB]))->assertNotFound();
    $this->actingAs($owner)->get(route('commercial.commissions.sellers.invoices.show', [$period, $sellerA, $invoiceA]))
        ->assertOk()->assertSee('Historical Guard')->assertSee('2');
});

it('reconciles returns reassignment and approved manual adjustments without mutating ledger', function () {
    $period = sciaPeriod();
    $seller = sciaSeller('Adjusted Seller');
    $invoice = sciaInvoice($seller, 'INV-ADJUST');
    $entry = sciaLedger($period, $seller, $invoice, 500_000);
    DB::table('commission_correction_entries')->insert([
        ['event_type' => 'return_reversal', 'identity_key' => 'return-a', 'commission_period_id' => $period->id, 'seller_id' => $seller->id, 'invoice_id' => $invoice->id, 'net_amount' => -1_000_000, 'base_commission_amount' => -50_000, 'campaign_commission_amount' => 0, 'total_commission_amount' => -50_000, 'status' => 'assigned', 'created_at' => now(), 'updated_at' => now()],
        ['event_type' => 'seller_reassignment_correction', 'identity_key' => 'move-a', 'commission_period_id' => $period->id, 'seller_id' => $seller->id, 'invoice_id' => $invoice->id, 'net_amount' => 0, 'base_commission_amount' => -20_000, 'campaign_commission_amount' => 0, 'total_commission_amount' => -20_000, 'status' => 'assigned', 'created_at' => now(), 'updated_at' => now()],
    ]);
    CommissionAdjustment::query()->create([
        'seller_id' => $seller->id, 'commission_period_id' => $period->id, 'amount' => 10_000,
        'reason' => 'Audit adjustment', 'status' => CommissionAdjustment::STATUS_APPROVED,
    ]);

    $before = $entry->fresh()->toArray();
    $audit = app(CommissionReportService::class)->sellerAudit($period, $seller);
    expect($audit['invoice_commission_sum'])->toBe(500_000)
        ->and($audit['final_expected'])->toBe(440_000)
        ->and($audit['displayed_total'])->toBe(440_000)
        ->and($audit['difference'])->toBe(0)
        ->and($entry->fresh()->toArray())->toBe($before);

    $this->artisan('commissions:audit-seller', ['--period' => $period->id, '--seller' => $seller->id])->assertSuccessful();
});
