<?php

use App\Models\Category;
use App\Models\CommissionDocument;
use App\Models\CommissionPeriod;
use App\Models\CommissionRateRevision;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\User;
use App\Services\Commissions\CommissionCalculationService;
use App\Services\Commissions\CommissionDocumentService;
use App\Services\Commissions\CommissionTarget;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function phaseThreeSeller(string $name = 'Seller'): User
{
    return User::factory()->create(['name' => $name, 'is_active' => true, 'can_access_erp' => true, 'is_seller' => true]);
}

function phaseThreePeriod(string $start = '2026-08-01', string $end = '2026-09-01'): CommissionPeriod
{
    return CommissionPeriod::query()->create(['label' => "$start - $end", 'start_at' => $start, 'end_at' => $end, 'cycle_day_snapshot' => 10, 'status' => 'open']);
}

function phaseThreeCatalog(User $actor, string $rate = '2.0000'): Product
{
    $category = Category::query()->create(['name' => 'P3 Category '.Str::random(6)]);
    $product = Product::query()->create(['name' => 'P3 Product', 'sku' => Str::uuid(), 'category_id' => $category->id, 'stock' => 10, 'reserved' => 0, 'price' => 10_000_000]);
    CommissionRateRevision::query()->create(array_merge(['target_type' => 'product', 'target_id' => $product->id,
        'target_key' => CommissionTarget::key('product', $product->id), 'active_marker' => 1, 'percentage' => $rate,
        'effective_from' => '2026-01-01', 'created_by' => $actor->id], CommissionTarget::foreignKeys('product', $product->id)));

    return $product;
}

function phaseThreeInvoice(User $seller, Product $product, string $date = '2026-08-15', array $overrides = []): Invoice
{
    $preinvoice = PreinvoiceOrder::query()->create(['uuid' => Str::uuid(), 'created_by' => $seller->id, 'seller_id' => $seller->id,
        'customer_name' => 'مشتری تست', 'customer_mobile' => '09120000000', 'customer_address' => 'تهران', 'province_id' => 1,
        'shipping_id' => 0, 'shipping_price' => 0, 'discount_amount' => 0, 'total_price' => 10_000_000,
        'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE]);
    $invoice = Invoice::query()->create(array_merge(['uuid' => 'INV-'.Str::random(8), 'preinvoice_order_id' => $preinvoice->id,
        'seller_id' => null, 'customer_name' => 'مشتری تست', 'document_date' => $date, 'shipping_price' => 0,
        'discount_amount' => 0, 'invoice_discount_amount' => 0, 'product_discount_amount' => 0,
        'discount_allocation_mode' => 'separate', 'subtotal' => 10_000_000, 'total' => 10_000_000,
        'status' => Invoice::STATUS_SHIPPED], $overrides));
    InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'product_id' => $product->id, 'quantity' => 1,
        'price' => 10_000_000, 'line_discount_amount' => 0]);

    return $invoice->fresh('items');
}

function phaseThreeDocumentUser(string ...$permissions): User
{
    $role = Role::findOrCreate('P3-'.implode('-', $permissions).Str::random(4), 'web');
    $keys = array_merge(['page.commercial.commissions'], $permissions);
    $role->givePermissionTo(Permission::query()->whereIn('key', $keys)->get());
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('creates one seller period document and auto imports eligible invoices as active pending claims', function () {
    $seller = phaseThreeSeller();
    $actor = phaseThreeSeller('Actor');
    $period = phaseThreePeriod();
    $product = phaseThreeCatalog($actor);
    phaseThreeInvoice($seller, $product);
    phaseThreeInvoice($seller, $product);
    phaseThreeInvoice($seller, $product);
    app(CommissionCalculationService::class)->recalculate($period);
    $document = app(CommissionDocumentService::class)->create($seller, $period, $actor, 'note');

    expect($document->document_number)->toMatch('/^COM-\d{6}$/')->and($document->items)->toHaveCount(3)
        ->and($document->items->every(fn ($item) => $item->status === 'pending' && $item->active_invoice_id === $item->invoice_id))->toBeTrue();
    expect(fn () => app(CommissionDocumentService::class)->create($seller, $period, $actor))->toThrow(ValidationException::class);
});

it('enforces active claim uniqueness at the database and releases rejected or removed invoices', function () {
    $seller = phaseThreeSeller();
    $actor = phaseThreeSeller('Actor');
    $period = phaseThreePeriod();
    $product = phaseThreeCatalog($actor);
    $invoice = phaseThreeInvoice($seller, $product);
    app(CommissionCalculationService::class)->recalculate($period);
    $service = app(CommissionDocumentService::class);
    $first = $service->create($seller, $period, $actor);
    $item = $first->items()->firstOrFail();
    $otherPeriod = phaseThreePeriod('2026-09-01', '2026-10-01');
    $second = CommissionDocument::query()->create(['document_number' => 'COM-999999', 'seller_id' => $seller->id, 'commission_period_id' => $otherPeriod->id, 'status' => 'draft', 'created_by' => $actor->id]);
    $duplicate = $item->replicate();
    $duplicate->commission_document_id = $second->id;
    expect(fn () => $duplicate->save())->toThrow(QueryException::class);

    $service->reject($item, $actor, 'بررسی مالی');
    expect($item->fresh()->active_invoice_id)->toBeNull();
    $reactivated = $service->addInvoice($first, $invoice, $actor);
    expect($reactivated->id)->toBe($item->id)->and($reactivated->status)->toBe('pending');
    $service->remove($reactivated, $actor, 'انتقال به دوره بعد');
    expect($service->addInvoice($second, $invoice, $actor, 'از دوره قبل'))->status->toBe('pending');
});

it('requires an outside-period reason and uses the historical ledger snapshot', function () {
    $seller = phaseThreeSeller();
    $actor = phaseThreeSeller('Actor');
    $old = phaseThreePeriod('2026-07-01', '2026-08-01');
    $current = phaseThreePeriod();
    $product = phaseThreeCatalog($actor, '2.0000');
    $invoice = phaseThreeInvoice($seller, $product, '2026-07-15');
    app(CommissionCalculationService::class)->recalculate($old);
    CommissionRateRevision::query()->where('product_id', $product->id)->update(['percentage' => '4.0000']);
    $document = app(CommissionDocumentService::class)->create($seller, $current, $actor);
    expect(fn () => app(CommissionDocumentService::class)->addInvoice($document, $invoice, $actor))->toThrow(ValidationException::class);
    $item = app(CommissionDocumentService::class)->addInvoice($document, $invoice, $actor, 'در دوره قبل بررسی نشد');
    expect($item->is_outside_period)->toBeTrue()->and($item->total_commission_snapshot)->toBe(200_000);
});

it('blocks cancelled seller-mismatch and dirty-period invoice additions', function () {
    $seller = phaseThreeSeller();
    $other = phaseThreeSeller('Other');
    $actor = phaseThreeSeller('Actor');
    $period = phaseThreePeriod();
    $product = phaseThreeCatalog($actor);
    $good = phaseThreeInvoice($seller, $product);
    $mismatch = phaseThreeInvoice($other, $product);
    $cancelled = phaseThreeInvoice($seller, $product, '2026-08-16', ['status' => Invoice::STATUS_NOT_SHIPPED]);
    app(CommissionCalculationService::class)->recalculate($period);
    $document = app(CommissionDocumentService::class)->create($seller, $period, $actor);
    expect(fn () => app(CommissionDocumentService::class)->addInvoice($document, $mismatch, $actor))->toThrow(ValidationException::class)
        ->and(fn () => app(CommissionDocumentService::class)->addInvoice($document, $cancelled, $actor))->toThrow(ValidationException::class);
    $document->items()->where('invoice_id', $good->id)->update(['status' => 'removed', 'active_invoice_id' => null]);
    $period->update(['needs_recalculation' => true]);
    expect(fn () => app(CommissionDocumentService::class)->addInvoice($document, $good, $actor))->toThrow(ValidationException::class);
});

it('detects changed approved ledger and refreshes it to pending while preserving rejected history', function () {
    $seller = phaseThreeSeller();
    $actor = phaseThreeSeller('Actor');
    $period = phaseThreePeriod();
    $product = phaseThreeCatalog($actor);
    $approvedInvoice = phaseThreeInvoice($seller, $product);
    $rejectedInvoice = phaseThreeInvoice($seller, $product);
    $calculator = app(CommissionCalculationService::class);
    $calculator->recalculate($period);
    $service = app(CommissionDocumentService::class);
    $document = $service->create($seller, $period, $actor);
    $approved = $document->items()->where('invoice_id', $approvedInvoice->id)->firstOrFail();
    $service->approve($approved, $actor);
    $rejected = $document->items()->where('invoice_id', $rejectedInvoice->id)->firstOrFail();
    $service->reject($rejected, $actor, 'رد');
    $oldRejected = $rejected->total_commission_snapshot;
    $approvedInvoice->items->first()->update(['price' => 20_000_000]);
    $rejectedInvoice->items->first()->update(['price' => 20_000_000]);
    $calculator->recalculate($period);
    expect($document->fresh()->needs_recalculation)->toBeTrue()->and($approved->fresh()->is_stale)->toBeTrue()
        ->and($rejected->fresh()->total_commission_snapshot)->toBe($oldRejected);
    $service->refreshCalculations($document, $actor);
    $approved = $approved->fresh();
    expect($approved->status)->toBe('pending')->and($approved->total_commission_snapshot)->toBe(400_000)
        ->and($rejected->fresh()->total_commission_snapshot)->toBe($oldRejected);
});

it('calculates approved totals only and protects manager reviewer and print endpoints', function () {
    $seller = phaseThreeSeller();
    $actor = phaseThreeSeller('Actor');
    $period = phaseThreePeriod();
    $product = phaseThreeCatalog($actor);
    phaseThreeInvoice($seller, $product);
    phaseThreeInvoice($seller, $product);
    app(CommissionCalculationService::class)->recalculate($period);
    $document = app(CommissionDocumentService::class)->create($seller, $period, $actor);
    $items = $document->items()->get();
    app(CommissionDocumentService::class)->approve($items[0], $actor);
    app(CommissionDocumentService::class)->reject($items[1], $actor, 'رد');
    $totals = app(CommissionDocumentService::class)->totals($document);
    expect($totals['approved_total_commission'])->toBe(200_000)->and($totals['rejected_commission'])->toBe(200_000);

    $viewer = phaseThreeDocumentUser();
    $manager = phaseThreeDocumentUser('commissions.manage_documents');
    $reviewer = phaseThreeDocumentUser('commissions.review_documents');
    $printer = phaseThreeDocumentUser('commissions.print_documents');
    $this->get(route('commercial.commissions.documents.show', $document))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('commercial.commissions.documents.show', $document))->assertForbidden();
    $this->actingAs($viewer)->post(route('commercial.commissions.documents.refresh-candidates', $document))->assertForbidden();
    $this->actingAs($manager)->post(route('commercial.commissions.documents.refresh-candidates', $document))->assertRedirect();
    $this->actingAs($manager)->post(route('commercial.commissions.documents.items.approve', [$document, $items[1]]))->assertForbidden();
    $this->actingAs($reviewer)->post(route('commercial.commissions.documents.items.approve', [$document, $items[1]]))->assertRedirect();
    $this->actingAs($viewer)->get(route('commercial.commissions.documents.print', $document))->assertForbidden();
    $this->actingAs($printer)->get(route('commercial.commissions.documents.print', $document))->assertOk()->assertSee($document->document_number)->assertSee('پرداخت نهایی ثبت نشده است');
});
