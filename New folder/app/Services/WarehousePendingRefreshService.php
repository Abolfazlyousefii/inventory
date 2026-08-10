<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\PreinvoiceOrderItem;

class WarehousePendingRefreshService
{
    public function __construct(
        private readonly WarehouseReviewAuditService $auditService,
    ) {}

    public function refreshActiveWarehousePendingForDocument(PreinvoiceOrder|Invoice $document, string $documentType, ?int $editedBy = null): void
    {
        if ($documentType === 'invoice' && $document instanceof Invoice) {
            $this->refreshInvoicePending($document, $editedBy);
            return;
        }

        if ($documentType === 'preinvoice' && $document instanceof PreinvoiceOrder) {
            $this->refreshPreinvoicePending($document, $editedBy);
        }
    }

    private function refreshPreinvoicePending(PreinvoiceOrder $order, ?int $editedBy = null): void
    {
        $order->refresh()->load(['items.product', 'items.variant', 'creator', 'customer']);

        if ($order->status !== PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE) {
            return;
        }

        $this->auditService->refreshActivePendingSnapshot($order, $editedBy);
    }

    private function refreshInvoicePending(Invoice $invoice, ?int $editedBy = null): void
    {
        $invoice->refresh()->load(['items.product', 'items.variant', 'preinvoiceOrder.items']);
        $order = $invoice->preinvoiceOrder;

        if (! $order || $order->status !== PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE) {
            return;
        }

        $order->update([
            'customer_id' => $invoice->customer_id,
            'customer_name' => $invoice->customer_name,
            'customer_mobile' => $invoice->customer_mobile,
            'customer_address' => $invoice->customer_address,
            'province_id' => $invoice->province_id,
            'city_id' => $invoice->city_id,
            'shipping_id' => $invoice->shipping_id,
            'shipping_price' => (int) $invoice->shipping_price,
            'discount_amount' => (int) $invoice->discount_amount,
            'discount_breakdown' => $invoice->discount_breakdown,
            'invoice_discount_type' => $invoice->invoice_discount_type,
            'invoice_discount_value' => (int) $invoice->invoice_discount_value,
            'invoice_discount_amount' => (int) $invoice->invoice_discount_amount,
            'product_discount_amount' => (int) $invoice->product_discount_amount,
            'discount_allocation_mode' => $invoice->discount_allocation_mode,
            'total_price' => (int) $invoice->total,
            'warehouse_review_note' => null,
            'warehouse_reject_reason' => null,
            'warehouse_reviewed_by' => null,
            'warehouse_reviewed_at' => null,
            'items_updated_at' => now(),
            'items_updated_by' => $editedBy,
        ]);

        $requestedKeys = [];
        foreach ($invoice->items as $invoiceItem) {
            $key = (int) $invoiceItem->product_id . ':' . (int) $invoiceItem->variant_id;
            $requestedKeys[] = $key;

            PreinvoiceOrderItem::query()->updateOrCreate(
                [
                    'preinvoice_order_id' => $order->id,
                    'product_id' => (int) $invoiceItem->product_id,
                    'variant_id' => (int) $invoiceItem->variant_id,
                ],
                [
                    'quantity' => (int) $invoiceItem->quantity,
                    'price' => (int) $invoiceItem->price,
                ]
            );
        }

        $order->items()
            ->get()
            ->reject(fn (PreinvoiceOrderItem $item) => in_array((int) $item->product_id . ':' . (int) $item->variant_id, $requestedKeys, true))
            ->each->delete();

        $this->auditService->refreshActivePendingSnapshot($order->fresh(['items.product', 'items.variant', 'creator', 'customer']), $editedBy);
    }
}
