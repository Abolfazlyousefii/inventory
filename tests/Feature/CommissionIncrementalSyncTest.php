<?php

use App\Models\Category;
use App\Models\CommissionCalculationWarning;
use App\Models\CommissionInvoiceSyncOutbox;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPeriod;
use App\Models\CommissionRateRevision;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\User;
use App\Services\Commissions\CommissionInvoiceSyncService;
use App\Services\Commissions\CommissionTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    // This suite must observe real DB commits because the commission outbox is
    // dispatched via DB::afterCommit(). Do not wrap the test in RefreshDatabase
    // / DatabaseTransactions.
    //
    // The legacy project contains multiple migrations whose down() methods are
    // not SQLite-safe (indexed columns are dropped before their indexes). Using
    // DatabaseMigrations therefore makes otherwise successful tests fail during
    // teardown. `migrate:fresh` uses only the migration up() path and gives every
    // test a clean SQLite :memory: schema while preserving real commits.
    $this->artisan('migrate:fresh', ['--force' => true])
        ->assertExitCode(0);
});

function incrementalSeller(string $name = 'Incremental Seller'): User
{
    return User::factory()->create(['name' => $name, 'is_active' => true, 'can_access_erp' => true, 'is_seller' => true]);
}

function incrementalCatalog(): array
{
    $suffix = (string) Str::uuid();
    $category = Category::query()->create(['name' => 'Incremental Category '.$suffix]);
    $product = Product::query()->create([
        'name' => 'Incremental Product '.$suffix, 'sku' => (string) Str::uuid(), 'category_id' => $category->id,
        'stock' => 10, 'reserved' => 0, 'price' => 10_000_000,
    ]);
    return [$category, $product];
}

function incrementalPeriod(string $start = '2026-08-01 00:00:00', string $end = '2026-09-01 00:00:00'): CommissionPeriod
{
    return CommissionPeriod::query()->create([
        'label' => 'Incremental period', 'start_at' => $start, 'end_at' => $end,
        'cycle_day_snapshot' => 10, 'status' => CommissionPeriod::STATUS_OPEN, 'needs_recalculation' => false,
    ]);
}

function incrementalRate(Product $product, User $actor, string $rate = '2.0000'): CommissionRateRevision
{
    return CommissionRateRevision::query()->create(array_merge([
        'target_type' => 'product', 'target_id' => $product->id,
        'target_key' => CommissionTarget::key('product', $product->id),
        'active_marker' => 1, 'percentage' => $rate,
        'effective_from' => '2026-01-01 00:00:00', 'created_by' => $actor->id,
    ], CommissionTarget::foreignKeys('product', $product->id)));
}

function incrementalInvoice(User $seller, Product $product, string $date = '2026-08-15 12:00:00', int $price = 10_000_000): Invoice
{
    $preinvoice = PreinvoiceOrder::query()->create([
        'uuid' => (string) Str::uuid(), 'created_by' => $seller->id, 'seller_id' => $seller->id,
        'customer_name' => 'Incremental Customer', 'customer_mobile' => '09120000000',
        'customer_address' => 'Tehran', 'province_id' => 1, 'shipping_id' => 0, 'shipping_price' => 0,
        'discount_amount' => 0, 'total_price' => $price, 'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
    ]);

    $invoice = Invoice::query()->create([
        'uuid' => (string) Str::uuid(), 'preinvoice_order_id' => $preinvoice->id, 'seller_id' => null,
        'customer_name' => 'Incremental Customer', 'document_date' => $date,
        'shipping_price' => 0, 'discount_amount' => 0, 'invoice_discount_amount' => 0,
        'product_discount_amount' => 0, 'discount_allocation_mode' => 'separate',
        'subtotal' => $price, 'total' => $price, 'status' => Invoice::STATUS_SHIPPED,
    ]);

    InvoiceItem::query()->create([
        'invoice_id' => $invoice->id, 'product_id' => $product->id, 'variant_id' => null,
        'quantity' => 1, 'price' => $price, 'line_discount_amount' => 0,
    ]);

    return $invoice->fresh('items');
}

it('calculates automatically only after the source transaction commits', function () {
    [, $product] = incrementalCatalog();
    $seller = incrementalSeller();
    incrementalPeriod();
    incrementalRate($product, $seller);
    $invoiceId = null;

    DB::transaction(function () use ($seller, $product, &$invoiceId): void {
        $invoice = incrementalInvoice($seller, $product);
        $invoiceId = $invoice->id;
        expect(CommissionLedgerEntry::query()->where('invoice_id', $invoice->id)->exists())->toBeFalse()
            ->and(CommissionInvoiceSyncOutbox::query()->where('invoice_id', $invoice->id)->exists())->toBeTrue();
    });

    $entry = CommissionLedgerEntry::query()->where('invoice_id', $invoiceId)->where('active_marker', 1)->firstOrFail();
    expect($entry->total_commission_amount)->toBe(200_000)
        ->and($entry->missing_rate)->toBeFalse()
        ->and(CommissionInvoiceSyncOutbox::query()->count())->toBe(0);
});

it('writes no commission when the source transaction rolls back', function () {
    [, $product] = incrementalCatalog();
    $seller = incrementalSeller();
    incrementalPeriod();
    incrementalRate($product, $seller);

    try {
        DB::transaction(function () use ($seller, $product): void {
            incrementalInvoice($seller, $product);
            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('force rollback');
    }

    expect(Invoice::query()->count())->toBe(0)
        ->and(InvoiceItem::query()->count())->toBe(0)
        ->and(CommissionLedgerEntry::query()->count())->toBe(0)
        ->and(CommissionInvoiceSyncOutbox::query()->count())->toBe(0);
});

it('updates only the changed invoice and preserves another invoice ledger untouched', function () {
    [, $product] = incrementalCatalog();
    $seller = incrementalSeller();
    incrementalPeriod();
    incrementalRate($product, $seller);
    $first = incrementalInvoice($seller, $product);
    $second = incrementalInvoice($seller, $product);

    $secondLedgerId = CommissionLedgerEntry::query()->where('invoice_id', $second->id)->where('active_marker', 1)->value('id');

    DB::transaction(fn () => $first->items()->firstOrFail()->update(['price' => 20_000_000]));

    expect(CommissionLedgerEntry::query()->where('invoice_id', $first->id)->where('status', CommissionLedgerEntry::STATUS_SUPERSEDED)->count())->toBe(1)
        ->and(CommissionLedgerEntry::query()->where('invoice_id', $first->id)->where('active_marker', 1)->firstOrFail()->total_commission_amount)->toBe(400_000)
        ->and(CommissionLedgerEntry::query()->where('invoice_id', $second->id)->where('active_marker', 1)->value('id'))->toBe($secondLedgerId);
});

it('is idempotent across repeated callbacks and retries', function () {
    [, $product] = incrementalCatalog();
    $seller = incrementalSeller();
    incrementalPeriod();
    incrementalRate($product, $seller);
    $invoice = incrementalInvoice($seller, $product);
    $service = app(CommissionInvoiceSyncService::class);

    $service->syncInvoice($invoice->id);
    $service->syncInvoice($invoice->id);
    $service->syncInvoice($invoice->id);

    expect(CommissionLedgerEntry::query()->where('invoice_id', $invoice->id)->count())->toBe(1)
        ->and(CommissionLedgerEntry::query()->where('invoice_id', $invoice->id)->where('active_marker', 1)->count())->toBe(1);
});

it('reconciles old and new mutable periods when the invoice date moves', function () {
    [, $product] = incrementalCatalog();
    $seller = incrementalSeller();
    $august = incrementalPeriod('2026-08-01 00:00:00', '2026-09-01 00:00:00');
    $september = incrementalPeriod('2026-09-01 00:00:00', '2026-10-01 00:00:00');
    incrementalRate($product, $seller);
    $invoice = incrementalInvoice($seller, $product, '2026-08-15 12:00:00');

    DB::transaction(fn () => $invoice->update(['document_date' => '2026-09-10 12:00:00']));

    expect(CommissionLedgerEntry::query()->where('commission_period_id', $august->id)->where('invoice_id', $invoice->id)->where('active_marker', 1)->count())->toBe(0)
        ->and(CommissionLedgerEntry::query()->where('commission_period_id', $september->id)->where('invoice_id', $invoice->id)->where('active_marker', 1)->count())->toBe(1);
});

it('supersedes active commission when an invoice is cancelled or an item is deleted', function () {
    [, $product] = incrementalCatalog();
    $seller = incrementalSeller();
    incrementalPeriod();
    incrementalRate($product, $seller);

    $cancelled = incrementalInvoice($seller, $product);
    DB::transaction(fn () => $cancelled->update(['status' => Invoice::STATUS_NOT_SHIPPED]));
    expect(CommissionLedgerEntry::query()->where('invoice_number_snapshot', $cancelled->uuid)->where('active_marker', 1)->count())->toBe(0);

    $itemDeleted = incrementalInvoice($seller, $product);
    DB::transaction(fn () => $itemDeleted->items()->firstOrFail()->delete());
    $historical = CommissionLedgerEntry::query()->where('invoice_number_snapshot', $itemDeleted->uuid)->firstOrFail();

    expect($historical->status)->toBe(CommissionLedgerEntry::STATUS_SUPERSEDED)
        ->and($historical->active_marker)->toBeNull()
        ->and($historical->invoice_item_id)->toBeNull()
        ->and($historical->product_name_snapshot)->toBe($product->name);
});

it('cleans active ledger after hard invoice deletion using its immutable snapshot', function () {
    [, $product] = incrementalCatalog();
    $seller = incrementalSeller();
    incrementalPeriod();
    incrementalRate($product, $seller);
    $invoice = incrementalInvoice($seller, $product);
    $number = $invoice->uuid;

    DB::transaction(fn () => $invoice->delete());
    $entry = CommissionLedgerEntry::query()->where('invoice_number_snapshot', $number)->latest('id')->firstOrFail();

    expect($entry->invoice_id)->toBeNull()
        ->and($entry->active_marker)->toBeNull()
        ->and($entry->status)->toBe(CommissionLedgerEntry::STATUS_SUPERSEDED);
});

it('never clears a pre-existing global dirty flag while still syncing this invoice', function () {
    [, $product] = incrementalCatalog();
    $seller = incrementalSeller();
    $period = incrementalPeriod();
    incrementalRate($product, $seller);
    $invoice = incrementalInvoice($seller, $product);
    $period->update(['needs_recalculation' => true]);

    DB::transaction(fn () => $invoice->items()->firstOrFail()->update(['price' => 15_000_000]));

    expect($period->fresh()->needs_recalculation)->toBeTrue()
        ->and(CommissionLedgerEntry::query()->where('invoice_id', $invoice->id)->where('active_marker', 1)->firstOrFail()->total_commission_amount)->toBe(300_000);
});

it('maintains invoice-scoped missing-rate warnings and keeps explicit zero distinct', function () {
    [, $missingProduct] = incrementalCatalog();
    $seller = incrementalSeller();
    $period = incrementalPeriod();
    $missing = incrementalInvoice($seller, $missingProduct);

    expect(CommissionCalculationWarning::query()->where('commission_period_id', $period->id)->where('invoice_id', $missing->id)->where('code', 'missing_rate')->count())->toBe(1)
        ->and(CommissionLedgerEntry::query()->where('invoice_id', $missing->id)->where('active_marker', 1)->firstOrFail()->missing_rate)->toBeTrue();

    [, $zeroProduct] = incrementalCatalog();
    incrementalRate($zeroProduct, $seller, '0.0000');
    $zero = incrementalInvoice($seller, $zeroProduct);

    expect(CommissionCalculationWarning::query()->where('invoice_id', $zero->id)->where('code', 'missing_rate')->exists())->toBeFalse()
        ->and(CommissionLedgerEntry::query()->where('invoice_id', $zero->id)->where('active_marker', 1)->firstOrFail()->missing_rate)->toBeFalse();
});

it('marks the period dirty if after-commit incremental synchronization fails', function () {
    [, $product] = incrementalCatalog();
    $seller = incrementalSeller();
    $period = incrementalPeriod();
    incrementalRate($product, $seller);
    $invoice = incrementalInvoice($seller, $product);
    $period->update(['needs_recalculation' => false]);

    $mock = Mockery::mock(CommissionInvoiceSyncService::class);
    $mock->shouldReceive('syncInvoice')->atLeast()->once()->andThrow(new RuntimeException('forced incremental failure'));
    app()->instance(CommissionInvoiceSyncService::class, $mock);

    DB::transaction(fn () => $invoice->items()->firstOrFail()->update(['price' => 12_000_000]));

    expect($period->fresh()->needs_recalculation)->toBeTrue()
        ->and(CommissionInvoiceSyncOutbox::query()->where('invoice_id', $invoice->id)->exists())->toBeTrue()
        ->and((int) CommissionInvoiceSyncOutbox::query()->where('invoice_id', $invoice->id)->max('attempts'))->toBeGreaterThanOrEqual(1);
});
