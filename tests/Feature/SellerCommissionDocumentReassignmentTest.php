<?php

namespace Tests\Feature;

use App\Models\SellerSalesDocumentItem;
use App\Services\Finance\SellerCommissionDocumentService;
use App\Services\SalesDocumentSellerReassignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSellerCommissionDocuments;
use Tests\TestCase;

class SellerCommissionDocumentReassignmentTest extends TestCase
{
    use CreatesSellerCommissionDocuments, RefreshDatabase;

    public function test_reassignment_preserves_history_recalculates_old_document_and_releases_invoice(): void
    {
        $actor = $this->financeActor();
        $sellerA = $this->erpUser(['is_seller' => true]);
        $sellerB = $this->erpUser(['is_seller' => true]);
        $invoice = $this->makeInvoice($sellerA, 61_570_000, '2026-07-30 15:41:21', [
            'uuid' => '00693',
            'seller_id' => $sellerA->id,
        ]);
        $invoice->preinvoiceOrder->update(['seller_id' => $sellerA->id]);
        $oldDocument = $this->createCommissionDocument($sellerA, [$invoice], $actor);
        $oldItem = $oldDocument->items()->firstOrFail();

        app(SalesDocumentSellerReassignmentService::class)
            ->reassignInvoiceSeller($invoice, $sellerB, $actor, 'انتقال نمونه 00693', true, 'bulk');

        $oldItem->refresh();
        $this->assertSame($invoice->id, $oldItem->invoice_id);
        $this->assertSame(SellerSalesDocumentItem::STATUS_REASSIGNED, $oldItem->status);
        $this->assertNull($oldItem->active_invoice_id);
        $this->assertSame($sellerB->id, $oldItem->reassigned_to_seller_id);
        $this->assertSame(61_570_000, $oldItem->invoice_total_snapshot);
        $this->assertSame(0, $oldDocument->fresh()->invoice_count);
        $this->assertSame(0, $oldDocument->fresh()->total_sales_amount);

        $commissionService = app(SellerCommissionDocumentService::class);
        $this->assertTrue($commissionService->getAvailableInvoices(
            $sellerB->id,
            '2026-07-01',
            '2026-07-31',
        )->where('invoices.id', $invoice->id)->exists());

        $newDocument = $this->createCommissionDocument($sellerB, [$invoice], $actor);
        $newItem = $newDocument->items()->firstOrFail();
        $this->assertSame(SellerSalesDocumentItem::STATUS_ACTIVE, $newItem->status);
        $this->assertSame($invoice->id, $newItem->active_invoice_id);
        $this->assertSame(2, SellerSalesDocumentItem::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_bulk_and_multistage_reassignment_keep_each_historical_row(): void
    {
        $actor = $this->financeActor();
        $sellerA = $this->erpUser(['is_seller' => true]);
        $sellerB = $this->erpUser(['is_seller' => true]);
        $sellerC = $this->erpUser(['is_seller' => true]);
        $invoice = $this->makeInvoice($sellerA, 1000, '2026-07-10 12:00:00', ['seller_id' => $sellerA->id]);
        $invoice->preinvoiceOrder->update(['seller_id' => $sellerA->id]);
        $this->createCommissionDocument($sellerA, [$invoice], $actor);
        $service = app(SalesDocumentSellerReassignmentService::class);

        $service->reassignMany([$invoice->id], $sellerB, $actor, 'A to B', true, 'bulk');
        $this->createCommissionDocument($sellerB, [$invoice->fresh()], $actor);
        $service->reassignMany([$invoice->id], $sellerC, $actor, 'B to C', true, 'bulk');

        $this->assertSame(2, SellerSalesDocumentItem::query()->where('invoice_id', $invoice->id)->reassigned()->count());
        $this->assertFalse(SellerSalesDocumentItem::query()->where('invoice_id', $invoice->id)->active()->exists());
        $this->assertTrue(app(SellerCommissionDocumentService::class)->getAvailableInvoices(
            $sellerC->id,
            '2026-07-01',
            '2026-07-31',
        )->where('invoices.id', $invoice->id)->exists());
    }

    public function test_reassignment_without_document_and_same_current_document_seller_are_noops_for_items(): void
    {
        $actor = $this->financeActor();
        $sellerA = $this->erpUser(['is_seller' => true]);
        $sellerB = $this->erpUser(['is_seller' => true]);
        $withoutDocument = $this->makeInvoice($sellerA, 1000, '2026-07-10', ['seller_id' => $sellerA->id]);
        $service = app(SalesDocumentSellerReassignmentService::class);

        $service->reassignInvoiceSeller($withoutDocument, $sellerB, $actor, 'no document');
        $this->assertDatabaseCount('seller_sales_document_items', 0);

        $invoice = $this->makeInvoice($sellerB, 2000, '2026-07-11', ['seller_id' => $sellerB->id]);
        $invoice->preinvoiceOrder->update(['seller_id' => $sellerB->id]);
        $document = $this->createCommissionDocument($sellerB, [$invoice], $actor);
        $invoice->forceFill(['seller_id' => $sellerA->id])->save();
        $service->reassignInvoiceSeller($invoice, $sellerB, $actor, 'align current seller');

        $this->assertSame(SellerSalesDocumentItem::STATUS_ACTIVE, $document->items()->firstOrFail()->status);
    }
}
