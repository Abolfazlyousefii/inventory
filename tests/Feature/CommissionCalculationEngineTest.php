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
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Commissions\CommissionCalculationService;
use App\Services\Commissions\CommissionTarget;
use App\Services\SalesDocumentSellerReassignmentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function phaseTwoSeller(string $name = 'Seller'): User
{
    return User::factory()->create(['name' => $name, 'is_active' => true, 'can_access_erp' => true, 'is_seller' => true]);
}

function phaseTwoCatalog(): array
{
    $category = Category::query()->create(['name' => 'Commission Category']);
    $product = Product::query()->create(['name' => 'Commission Product', 'sku' => Str::uuid(), 'category_id' => $category->id, 'stock' => 1, 'reserved' => 0, 'price' => 10_000_000]);
    $variant = ProductVariant::query()->create(['product_id' => $product->id, 'variant_name' => 'Black', 'variant_code' => Str::uuid(), 'stock' => 1, 'reserved' => 0, 'sell_price' => 10_000_000]);

    return [$category, $product, $variant];
}

function phaseTwoPeriod(string $start = '2026-08-01 00:00:00', string $end = '2026-09-01 00:00:00'): CommissionPeriod
{
    return CommissionPeriod::query()->create(['label' => 'Test period', 'start_at' => $start, 'end_at' => $end, 'cycle_day_snapshot' => 10, 'status' => CommissionPeriod::STATUS_OPEN]);
}

function phaseTwoInvoice(User $seller, Product $product, ?ProductVariant $variant, array $invoice = [], array $item = []): Invoice
{
    $preinvoice = PreinvoiceOrder::query()->create([
        'uuid' => (string) Str::uuid(), 'created_by' => $seller->id, 'seller_id' => $seller->id,
        'customer_name' => 'Customer', 'customer_mobile' => '09120000000', 'customer_address' => 'Tehran',
        'province_id' => 1, 'shipping_id' => 0, 'shipping_price' => 0, 'discount_amount' => 0,
        'total_price' => 10_000_000, 'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
    ]);
    $model = Invoice::query()->create(array_merge([
        'uuid' => (string) Str::uuid(), 'preinvoice_order_id' => $preinvoice->id, 'seller_id' => null,
        'customer_name' => 'Customer', 'document_date' => '2026-08-15 12:00:00',
        'shipping_price' => 900_000, 'discount_amount' => 0, 'invoice_discount_amount' => 0,
        'product_discount_amount' => 0, 'discount_allocation_mode' => 'separate',
        'subtotal' => 10_000_000, 'total' => 10_900_000, 'status' => Invoice::STATUS_SHIPPED,
    ], $invoice));
    InvoiceItem::query()->create(array_merge([
        'invoice_id' => $model->id, 'product_id' => $product->id, 'variant_id' => $variant?->id,
        'quantity' => 1, 'price' => 10_000_000, 'line_discount_amount' => 0,
    ], $item));

    return $model->fresh('items');
}

function phaseTwoRate(string $type, int $id, string $rate, User $actor, string $from = '2026-01-01 00:00:00'): CommissionRateRevision
{
    return CommissionRateRevision::query()->create(array_merge([
        'target_type' => $type, 'target_id' => $id, 'target_key' => CommissionTarget::key($type, $id),
        'active_marker' => 1, 'percentage' => $rate, 'effective_from' => $from, 'created_by' => $actor->id,
    ], CommissionTarget::foreignKeys($type, $id)));
}

it('calculates each invoice item from canonical discounts without shipping and snapshots additive rates', function () {
    [$category, $product, $variant] = phaseTwoCatalog();
    $seller = phaseTwoSeller();
    $period = phaseTwoPeriod();
    $rate = phaseTwoRate('product', $product->id, '3.0000', $seller);
    $campaign = CommissionCampaign::query()->create([
        'name' => 'Summer bonus', 'bonus_percentage' => '5.0000', 'start_at' => '2026-08-10', 'end_at' => '2026-08-20',
        'created_by' => $seller->id, 'updated_by' => $seller->id,
    ]);
    $campaign->targets()->create(array_merge([
        'target_type' => 'category', 'target_id' => $category->id, 'target_key' => "category:{$category->id}",
    ], CommissionTarget::foreignKeys('category', $category->id)));
    $invoice = phaseTwoInvoice($seller, $product, $variant, [
        'discount_amount' => 2_000_000, 'invoice_discount_amount' => 1_000_000,
        'product_discount_amount' => 1_000_000, 'subtotal' => 10_000_000, 'total' => 8_900_000,
    ], ['line_discount_amount' => 1_000_000]);

    app(CommissionCalculationService::class)->recalculate($period);
    $entry = CommissionLedgerEntry::query()->where('active_marker', 1)->firstOrFail();

    expect($entry->seller_id)->toBe($seller->id)
        ->and($entry->gross_amount_snapshot)->toBe(10_000_000)
        ->and($entry->discount_amount_snapshot)->toBe(2_000_000)
        ->and($entry->net_amount_snapshot)->toBe(8_000_000)
        ->and($entry->base_rate_snapshot)->toBe('3.0000')
        ->and($entry->campaign_rate_snapshot)->toBe('5.0000')
        ->and($entry->effective_rate_snapshot)->toBe('8.0000')
        ->and($entry->base_commission_amount)->toBe(240_000)
        ->and($entry->campaign_commission_amount)->toBe(400_000)
        ->and($entry->total_commission_amount)->toBe(640_000)
        ->and($entry->rate_rule_id)->toBe($rate->id)
        ->and($entry->campaign_id)->toBe($campaign->id)
        ->and($entry->invoice_number_snapshot)->toBe($invoice->uuid);
});

it('uses historical invoice date and deterministic integer rounding while distinguishing missing and explicit zero', function () {
    [, $product, $variant] = phaseTwoCatalog();
    $seller = phaseTwoSeller();
    $period = phaseTwoPeriod();
    phaseTwoRate('product', $product->id, '2.7500', $seller, '2026-01-01');
    $invoice = phaseTwoInvoice($seller, $product, $variant, [], ['price' => 12_345_678]);

    app(CommissionCalculationService::class)->recalculate($period);
    expect(CommissionLedgerEntry::query()->where('invoice_id', $invoice->id)->firstOrFail()->total_commission_amount)->toBe(339_506);

    $missingCategory = Category::query()->create(['name' => 'Missing Category']);
    $missingProduct = Product::query()->create(['name' => 'Missing', 'sku' => Str::uuid(), 'category_id' => $missingCategory->id, 'stock' => 1, 'reserved' => 0, 'price' => 10_000_000]);
    $missingInvoice = phaseTwoInvoice($seller, $missingProduct, null);
    $zeroCategory = Category::query()->create(['name' => 'Zero Category']);
    $zeroProduct = Product::query()->create(['name' => 'Zero', 'sku' => Str::uuid(), 'category_id' => $zeroCategory->id, 'stock' => 1, 'reserved' => 0, 'price' => 10_000_000]);
    phaseTwoRate('product', $zeroProduct->id, '0.0000', $seller);
    $zeroInvoice = phaseTwoInvoice($seller, $zeroProduct, null);
    app(CommissionCalculationService::class)->recalculate($period);

    expect(CommissionLedgerEntry::query()->where('invoice_id', $missingInvoice->id)->firstOrFail()->missing_rate)->toBeTrue()
        ->and(CommissionLedgerEntry::query()->where('invoice_id', $zeroInvoice->id)->firstOrFail()->missing_rate)->toBeFalse();
});

it('recalculates idempotently and supersedes changed or cancelled invoice entries', function () {
    [, $product, $variant] = phaseTwoCatalog();
    $seller = phaseTwoSeller();
    $period = phaseTwoPeriod();
    phaseTwoRate('product', $product->id, '2.0000', $seller);
    $invoice = phaseTwoInvoice($seller, $product, $variant);
    $service = app(CommissionCalculationService::class);

    $service->recalculate($period);
    $service->recalculate($period);
    expect(CommissionLedgerEntry::query()->count())->toBe(1);

    $invoice->items->first()->update(['price' => 20_000_000]);
    $service->recalculate($period);
    expect(CommissionLedgerEntry::query()->count())->toBe(2)
        ->and(CommissionLedgerEntry::query()->where('status', CommissionLedgerEntry::STATUS_ACTIVE)->count())->toBe(1)
        ->and(CommissionLedgerEntry::query()->where('status', CommissionLedgerEntry::STATUS_SUPERSEDED)->count())->toBe(1);

    $invoice->update(['status' => Invoice::STATUS_NOT_SHIPPED]);
    $service->recalculate($period);
    expect(CommissionLedgerEntry::query()->where('status', CommissionLedgerEntry::STATUS_ACTIVE)->count())->toBe(0);
});

it('includes the inclusive period start and excludes the exclusive end boundary', function () {
    [, $product, $variant] = phaseTwoCatalog();
    $seller = phaseTwoSeller();
    $period = phaseTwoPeriod();
    phaseTwoRate('product', $product->id, '2.0000', $seller);
    $included = phaseTwoInvoice($seller, $product, $variant, ['document_date' => $period->start_at]);
    $alsoIncluded = phaseTwoInvoice($seller, $product, $variant, ['document_date' => $period->end_at->copy()->subSecond()]);
    $excluded = phaseTwoInvoice($seller, $product, $variant, ['document_date' => $period->end_at]);

    app(CommissionCalculationService::class)->recalculate($period);

    expect(CommissionLedgerEntry::query()->where('invoice_id', $included->id)->exists())->toBeTrue()
        ->and(CommissionLedgerEntry::query()->where('invoice_id', $alsoIncluded->id)->exists())->toBeTrue()
        ->and(CommissionLedgerEntry::query()->where('invoice_id', $excluded->id)->exists())->toBeFalse();
});

it('reconciles the canonical effective seller after reassignment in an open period', function () {
    [, $product, $variant] = phaseTwoCatalog();
    $oldSeller = phaseTwoSeller('Old Seller');
    $newSeller = phaseTwoSeller('New Seller');
    $actor = phaseTwoSeller('Actor');
    $period = phaseTwoPeriod();
    phaseTwoRate('product', $product->id, '2.0000', $actor);
    $invoice = phaseTwoInvoice($oldSeller, $product, $variant);
    $service = app(CommissionCalculationService::class);
    $service->recalculate($period);

    app(SalesDocumentSellerReassignmentService::class)->reassignInvoiceSeller($invoice, $newSeller, $actor, 'Commission test');
    expect($period->fresh()->needs_recalculation)->toBeTrue();
    $service->recalculate($period);

    expect(CommissionLedgerEntry::query()->where('status', CommissionLedgerEntry::STATUS_ACTIVE)->firstOrFail()->seller_id)->toBe($newSeller->id);
});

it('enforces one active ledger row per period and invoice item at database level', function () {
    [, $product, $variant] = phaseTwoCatalog();
    $seller = phaseTwoSeller();
    $period = phaseTwoPeriod();
    phaseTwoRate('product', $product->id, '2.0000', $seller);
    phaseTwoInvoice($seller, $product, $variant);
    app(CommissionCalculationService::class)->recalculate($period);
    $entry = CommissionLedgerEntry::query()->firstOrFail();

    $duplicate = $entry->replicate();
    $duplicate->calculation_fingerprint = hash('sha256', 'duplicate');

    expect(fn () => $duplicate->save())->toThrow(QueryException::class);
});

it('preserves historical snapshots without locking a deleted source item', function () {
    [, $product, $variant] = phaseTwoCatalog();
    $seller = phaseTwoSeller();
    $period = phaseTwoPeriod();
    phaseTwoRate('product', $product->id, '2.0000', $seller);
    $invoice = phaseTwoInvoice($seller, $product, $variant);
    app(CommissionCalculationService::class)->recalculate($period);

    $invoice->items->first()->delete();
    app(CommissionCalculationService::class)->recalculate($period);

    $entry = CommissionLedgerEntry::query()->firstOrFail();
    expect($entry->invoice_item_id)->toBeNull()
        ->and($entry->product_name_snapshot)->toBe('Commission Product')
        ->and($entry->status)->toBe(CommissionLedgerEntry::STATUS_SUPERSEDED);
});
