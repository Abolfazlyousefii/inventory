<?php

namespace App\Services\Finance;

use App\Models\Invoice;
use App\Models\SellerReassignmentAudit;
use App\Models\SellerSalesDocument;
use App\Models\SellerSalesDocumentItem;
use App\Models\User;

class SellerSalesDocumentReassignmentService
{
    public function reconcile(
        Invoice $invoice,
        User $newSeller,
        ?SellerReassignmentAudit $audit = null,
    ): ?SellerSalesDocumentItem {
        $item = SellerSalesDocumentItem::query()
            ->with('document')
            ->active()
            ->where('active_invoice_id', $invoice->id)
            ->lockForUpdate()
            ->first();

        if (! $item || (int) $item->document->seller_id === (int) $newSeller->id) {
            return null;
        }

        $item->update([
            'status' => SellerSalesDocumentItem::STATUS_REASSIGNED,
            'active_invoice_id' => null,
            'reassigned_to_seller_id' => $newSeller->id,
            'reassigned_at' => $audit?->changed_at,
            'reassignment_audit_id' => $audit?->id,
        ]);

        $this->recalculate($item->document);

        return $item->fresh();
    }

    public function recalculate(SellerSalesDocument $document): void
    {
        $document->update([
            'invoice_count' => $document->activeItems()->count(),
            'total_sales_amount' => (int) $document->activeItems()->sum('invoice_total_snapshot'),
        ]);
    }
}
