<?php

namespace App\Services\Finance;

use App\Models\Invoice;
use App\Models\SellerReassignmentAudit;
use App\Models\SellerSalesDocument;
use App\Models\SellerSalesDocumentItem;
use App\Models\User;
use Illuminate\Support\Collection;

class SellerSalesDocumentReassignmentService
{
    /**
     * Release every stale ACTIVE legacy seller-sales-document claim that does
     * not belong to the invoice's current seller.
     *
     * Historical rows are never deleted. The method is intentionally safe to
     * call even when the requested seller is already the invoice seller; this
     * is what repairs legacy/direct-transfer mismatches such as an invoice that
     * already points to seller B while an old seller A commission document is
     * still holding active_invoice_id.
     *
     * @return int Number of released active claims.
     */
    public function reconcile(
        Invoice $invoice,
        User $newSeller,
        ?SellerReassignmentAudit $audit = null,
    ): int {
        $activeItems = SellerSalesDocumentItem::query()
            ->with('document')
            ->active()
            ->where('active_invoice_id', $invoice->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($activeItems->isEmpty()) {
            return 0;
        }

        $wrongOwnerItems = $activeItems
            ->filter(fn (SellerSalesDocumentItem $item): bool =>
                $item->document
                && (int) $item->document->seller_id !== (int) $newSeller->id
            )
            ->values();

        if ($wrongOwnerItems->isEmpty()) {
            return 0;
        }

        $affectedDocumentIds = $wrongOwnerItems
            ->pluck('seller_sales_document_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values();

        foreach ($wrongOwnerItems as $item) {
            $item->update([
                'status' => SellerSalesDocumentItem::STATUS_REASSIGNED,
                'active_invoice_id' => null,
                'reassigned_to_seller_id' => $newSeller->id,
                'reassigned_at' => $audit?->changed_at,
                'reassignment_audit_id' => $audit?->id,
            ]);
        }

        // Recalculate each affected historical document exactly once and lock
        // in deterministic ID order to reduce deadlock risk in bulk transfers.
        $documents = SellerSalesDocument::query()
            ->whereIn('id', $affectedDocumentIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($documents as $document) {
            $this->recalculate($document);
        }

        return $wrongOwnerItems->count();
    }

    public function recalculate(SellerSalesDocument $document): void
    {
        $document->update([
            'invoice_count' => $document->activeItems()->count(),
            'total_sales_amount' => (int) $document->activeItems()->sum('invoice_total_snapshot'),
        ]);
    }
}
