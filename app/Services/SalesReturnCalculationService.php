<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SalesReturnDocument;
use App\Models\WarehouseTransfer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SalesReturnCalculationService
{
    public function calculateInternalInvoicePreview(Invoice $invoice, array $requestedItems): array
    {
        $invoice->loadMissing(['items.product', 'items.variant']);
        $requestedByItem = $this->keyRequestedItems($requestedItems);
        $allocations = $this->allocateInvoiceDiscount($invoice);
        $previous = $this->previouslyReturnedQuantities(array_keys($requestedByItem));
        $previousRefunds = $this->previouslyRefundedAmounts(array_keys($requestedByItem));

        $items = [];
        $total = 0;

        foreach ($requestedByItem as $invoiceItemId => $requested) {
            /** @var InvoiceItem|null $invoiceItem */
            $invoiceItem = $invoice->items->firstWhere('id', (int) $invoiceItemId);
            if (!$invoiceItem) {
                throw ValidationException::withMessages(["items" => 'ردیف انتخاب‌شده متعلق به این فاکتور نیست.']);
            }

            $qty = (int) ($requested['return_quantity'] ?? 0);
            $soldQty = (int) $invoiceItem->quantity;
            $previousQty = (int) ($previous[$invoiceItem->id] ?? 0);
            $returnable = max($soldQty - $previousQty, 0);

            if ($qty < 1 || $qty > $returnable) {
                throw ValidationException::withMessages([
                    $this->requestedItemField($requestedItems, $invoiceItem->id, 'return_quantity') => 'تعداد برگشتی از مقدار قابل برگشت بیشتر است.',
                ]);
            }

            $gross = $soldQty * (int) $invoiceItem->price;
            $lineDiscount = (int) ($invoiceItem->line_discount_amount ?? 0);
            $allocated = (int) ($allocations[$invoiceItem->id] ?? 0);
            $netLine = max($gross - $lineDiscount - $allocated, 0);
            $previousRefund = (int) ($previousRefunds[$invoiceItem->id] ?? 0);
            $isLastReturn = $qty === $returnable;
            $refund = $isLastReturn ? max($netLine - $previousRefund, 0) : (int) floor($netLine * $qty / max($soldQty, 1));
            $refundUnit = (int) floor($refund / max($qty, 1));

            $row = [
                'invoice_item_id' => (int) $invoiceItem->id,
                'product_id' => (int) $invoiceItem->product_id,
                'product_variant_id' => $invoiceItem->variant_id ? (int) $invoiceItem->variant_id : null,
                'product_name_snapshot' => $invoiceItem->product?->name,
                'variant_name_snapshot' => $invoiceItem->variant?->variant_name ?: $invoiceItem->variant?->variety_name,
                'sku_snapshot' => $invoiceItem->variant?->variant_code ?: $invoiceItem->product?->sku ?: $invoiceItem->product?->barcode,
                'sold_quantity_snapshot' => $soldQty,
                'previous_returned_quantity_snapshot' => $previousQty,
                'returnable_quantity' => $returnable,
                'return_quantity' => $qty,
                'unit_price_snapshot' => (int) $invoiceItem->price,
                'line_discount_snapshot' => $lineDiscount,
                'allocated_invoice_discount_snapshot' => $allocated,
                'refund_unit_price' => $refundUnit,
                'refund_amount' => $refund,
                'item_condition' => $requested['item_condition'] ?? 'healthy',
                'destination_warehouse_id' => $requested['destination_warehouse_id'] ?? null,
                'sort_order' => (int) ($requested['sort_order'] ?? count($items)),
            ];
            $items[] = $row;
            $total += $refund;
        }

        return ['items' => $items, 'refund_subtotal' => $total, 'refund_total' => $total];
    }

    public function calculateSazehPreview(array $requestedItems): array
    {
        $items = [];
        $total = 0;
        foreach (array_values($requestedItems) as $index => $item) {
            $qty = (int) ($item['return_quantity'] ?? 0);
            $unit = (int) ($item['refund_unit_price'] ?? 0);
            $amount = $qty * $unit;
            $payload = $item['new_product_payload'] ?? null;
            $row = [
                'invoice_item_id' => null,
                'product_id' => $item['product_id'] ?? null,
                'product_variant_id' => $item['product_variant_id'] ?? null,
                'product_name_snapshot' => $item['product_name_snapshot'] ?? data_get($payload, 'product_name'),
                'variant_name_snapshot' => $item['variant_name_snapshot'] ?? data_get($payload, 'variant_name'),
                'sku_snapshot' => $item['sku_snapshot'] ?? data_get($payload, 'sku') ?? data_get($payload, 'barcode'),
                'item_condition' => $item['item_condition'] ?? 'healthy',
                'destination_warehouse_id' => $item['destination_warehouse_id'] ?? null,
                'return_quantity' => $qty,
                'refund_unit_price' => $unit,
                'refund_amount' => $amount,
                'purchase_price' => data_get($payload, 'purchase_price'),
                'sell_price' => data_get($payload, 'sell_price'),
                'new_product_payload' => $payload,
                'sort_order' => (int) ($item['sort_order'] ?? $index),
            ];
            $items[] = $row;
            $total += $amount;
        }

        return ['items' => $items, 'refund_subtotal' => $total, 'refund_total' => $total];
    }

    public function allocateInvoiceDiscount(Invoice $invoice): array
    {
        $invoice->loadMissing('items');
        $grossById = [];
        $subtotal = 0;
        $lineDiscountSum = 0;
        foreach ($invoice->items as $item) {
            $gross = (int) $item->quantity * (int) $item->price;
            $grossById[(int) $item->id] = $gross;
            $subtotal += $gross;
            $lineDiscountSum += (int) ($item->line_discount_amount ?? 0);
        }

        $extra = max((int) ($invoice->discount_amount ?? 0) - $lineDiscountSum, 0);
        if ($extra <= 0 || $subtotal <= 0) {
            return array_fill_keys(array_keys($grossById), 0);
        }

        $allocated = [];
        $remainders = [];
        $used = 0;
        foreach ($grossById as $id => $gross) {
            $raw = $extra * $gross;
            $floor = intdiv($raw, $subtotal);
            $allocated[$id] = $floor;
            $remainders[$id] = $raw % $subtotal;
            $used += $floor;
        }

        arsort($remainders);
        $remaining = $extra - $used;
        foreach (array_keys($remainders) as $id) {
            if ($remaining <= 0) {
                break;
            }
            $allocated[$id]++;
            $remaining--;
        }

        ksort($allocated);
        return $allocated;
    }

    public function previouslyReturnedQuantities(array $invoiceItemIds): array
    {
        $ids = collect($invoiceItemIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $totals = [];
        if (Schema::hasTable('sales_return_document_items') && Schema::hasTable('sales_return_documents')) {
            $newRows = DB::table('sales_return_document_items as sri')
                ->join('sales_return_documents as sr', 'sr.id', '=', 'sri.document_id')
                ->where('sr.status', SalesReturnDocument::STATUS_APPLIED)
                ->whereIn('sri.invoice_item_id', $ids)
                ->groupBy('sri.invoice_item_id')
                ->pluck(DB::raw('sum(sri.return_quantity)'), 'sri.invoice_item_id');
            foreach ($newRows as $id => $qty) {
                $totals[(int) $id] = (int) $qty;
            }
        }

        if (Schema::hasTable('warehouse_transfer_items') && Schema::hasTable('warehouse_transfers')) {
            $legacyRows = DB::table('warehouse_transfer_items as wti')
                ->join('warehouse_transfers as wt', 'wt.id', '=', 'wti.warehouse_transfer_id')
                ->where('wt.voucher_type', WarehouseTransfer::TYPE_CUSTOMER_RETURN)
                ->whereIn('wti.invoice_item_id', $ids)
                ->whereNotNull('wti.invoice_item_id')
                ->groupBy('wti.invoice_item_id')
                ->pluck(DB::raw('sum(wti.quantity)'), 'wti.invoice_item_id');
            foreach ($legacyRows as $id => $qty) {
                $totals[(int) $id] = ($totals[(int) $id] ?? 0) + (int) $qty;
            }
        }

        return $totals;
    }

    public function previouslyRefundedAmounts(array $invoiceItemIds): array
    {
        $ids = collect($invoiceItemIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty() || !Schema::hasTable('sales_return_document_items') || !Schema::hasTable('sales_return_documents')) {
            return [];
        }

        return DB::table('sales_return_document_items as sri')
            ->join('sales_return_documents as sr', 'sr.id', '=', 'sri.document_id')
            ->where('sr.status', SalesReturnDocument::STATUS_APPLIED)
            ->whereIn('sri.invoice_item_id', $ids)
            ->groupBy('sri.invoice_item_id')
            ->pluck(DB::raw('sum(sri.refund_amount)'), 'sri.invoice_item_id')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    private function keyRequestedItems(array $items): array
    {
        $keyed = [];
        foreach ($items as $index => $item) {
            $id = (int) ($item['invoice_item_id'] ?? 0);
            if ($id <= 0) {
                throw ValidationException::withMessages(["items.{$index}.invoice_item_id" => 'ردیف فاکتور الزامی است.']);
            }
            if (isset($keyed[$id])) {
                throw ValidationException::withMessages(["items.{$index}.invoice_item_id" => 'هر ردیف فاکتور فقط یک بار می‌تواند در سند بیاید.']);
            }
            $keyed[$id] = $item + ['sort_order' => $index];
        }
        return $keyed;
    }

    private function requestedItemField(array $items, int $invoiceItemId, string $field): string
    {
        foreach ($items as $index => $item) {
            if ((int) ($item['invoice_item_id'] ?? 0) === $invoiceItemId) {
                return "items.{$index}.{$field}";
            }
        }
        return 'items';
    }
}
