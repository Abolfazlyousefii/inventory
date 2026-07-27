<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\SalesReturnDocument;
use App\Models\WarehouseTransfer;
use App\Support\SalesDocumentTotals;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SalesReturnCalculationService
{
    public function allocateInvoiceDiscount(Invoice $invoice): array
    {
        $items = $this->invoiceItems($invoice);
        $gross = $items->mapWithKeys(fn ($item) => [
            $item->id => SalesDocumentTotals::lineSubtotal($item),
        ]);
        $grossTotal = (int) $gross->sum();
        $extra = $this->separateInvoiceDiscount($invoice, $items);

        if ($extra <= 0 || $grossTotal <= 0) {
            return $items->mapWithKeys(fn ($item) => [$item->id => 0])->all();
        }

        $allocations = [];
        $used = 0;
        foreach ($items as $item) {
            $value = intdiv($extra * (int) ($gross[$item->id] ?? 0), $grossTotal);
            $allocations[$item->id] = $value;
            $used += $value;
        }

        $remainder = $extra - $used;
        foreach ($items as $item) {
            if ($remainder <= 0) {
                break;
            }
            $allocations[$item->id]++;
            $remainder--;
        }

        return $allocations;
    }

    public function invoiceItemBreakdowns(Invoice $invoice): array
    {
        $items = $this->invoiceItems($invoice);
        $allocations = $this->allocateInvoiceDiscount($invoice);

        return $items->mapWithKeys(function ($item) use ($allocations) {
            $quantity = max((int) $item->quantity, 0);
            $historicalUnitPrice = max((int) $item->price, 0);
            $gross = $quantity * $historicalUnitPrice;
            $lineDiscount = min(max((int) ($item->line_discount_amount ?? 0), 0), $gross);
            $invoiceDiscount = min(
                max((int) ($allocations[$item->id] ?? 0), 0),
                max($gross - $lineDiscount, 0)
            );
            $net = max($gross - $lineDiscount - $invoiceDiscount, 0);

            return [$item->id => [
                'historical_unit_price' => $historicalUnitPrice,
                'gross_amount' => $gross,
                'line_discount_total' => $lineDiscount,
                'line_discount_unit' => $quantity > 0 ? intdiv($lineDiscount, $quantity) : 0,
                'allocated_invoice_discount_total' => $invoiceDiscount,
                'allocated_invoice_discount_unit' => $quantity > 0 ? intdiv($invoiceDiscount, $quantity) : 0,
                'net_refund_total' => $net,
                'net_refund_unit_price' => $quantity > 0 ? intdiv($net, $quantity) : 0,
            ]];
        })->all();
    }

    public function cumulativeRefundAmount(int $netAmount, int $soldQuantity, int $returnedQuantity): int
    {
        $netAmount = max($netAmount, 0);
        $soldQuantity = max($soldQuantity, 0);
        $returnedQuantity = max(min($returnedQuantity, $soldQuantity), 0);

        if ($soldQuantity === 0 || $returnedQuantity === 0) {
            return 0;
        }

        if ($returnedQuantity === $soldQuantity) {
            return $netAmount;
        }

        return intdiv($netAmount * $returnedQuantity, $soldQuantity);
    }

    public function previouslyReturnedQuantities(array $invoiceItemIds, ?int $excludeDocumentId = null): array
    {
        if (! $invoiceItemIds) {
            return [];
        }

        $new = DB::table('sales_return_document_items as i')
            ->join('sales_return_documents as d', 'd.id', '=', 'i.document_id')
            ->where('d.status', SalesReturnDocument::STATUS_APPLIED)
            ->when($excludeDocumentId, fn ($query) => $query->where('d.id', '<>', $excludeDocumentId))
            ->whereIn('i.invoice_item_id', $invoiceItemIds)
            ->groupBy('i.invoice_item_id')
            ->pluck(DB::raw('sum(i.return_quantity)'), 'i.invoice_item_id')
            ->all();
        $legacy = DB::table('warehouse_transfer_items as wi')
            ->join('warehouse_transfers as wt', 'wt.id', '=', 'wi.warehouse_transfer_id')
            ->where('wt.voucher_type', WarehouseTransfer::TYPE_CUSTOMER_RETURN)
            ->whereNotNull('wi.invoice_item_id')
            ->whereIn('wi.invoice_item_id', $invoiceItemIds)
            ->groupBy('wi.invoice_item_id')
            ->pluck(DB::raw('sum(wi.quantity)'), 'wi.invoice_item_id')
            ->all();

        $out = [];
        foreach ($invoiceItemIds as $id) {
            $out[$id] = (int) ($new[$id] ?? 0) + (int) ($legacy[$id] ?? 0);
        }

        return $out;
    }

    public function previouslyReturnedAmounts(array $invoiceItemIds, ?int $excludeDocumentId = null): array
    {
        if (! $invoiceItemIds) {
            return [];
        }

        return DB::table('sales_return_document_items as i')
            ->join('sales_return_documents as d', 'd.id', '=', 'i.document_id')
            ->where('d.status', SalesReturnDocument::STATUS_APPLIED)
            ->when($excludeDocumentId, fn ($query) => $query->where('d.id', '<>', $excludeDocumentId))
            ->whereIn('i.invoice_item_id', $invoiceItemIds)
            ->groupBy('i.invoice_item_id')
            ->pluck(DB::raw('sum(i.refund_amount)'), 'i.invoice_item_id')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    public function calculateInternalPreview(Invoice $invoice, array $rows, ?int $excludeDocumentId = null): array
    {
        $invoice->loadMissing('items.product', 'items.variant');
        $breakdowns = $this->invoiceItemBreakdowns($invoice);
        $ids = $invoice->items->pluck('id')->all();
        $previousQuantities = $this->previouslyReturnedQuantities($ids, $excludeDocumentId);
        $previousAmounts = $this->previouslyReturnedAmounts($ids, $excludeDocumentId);
        $byId = $invoice->items->keyBy('id');
        $result = [];

        foreach ($rows as $row) {
            $id = (int) ($row['invoice_item_id'] ?? 0);
            $item = $byId->get($id);
            $quantity = (int) ($row['return_quantity'] ?? 0);
            if (! $item || $quantity <= 0) {
                continue;
            }

            $sold = max((int) $item->quantity, 0);
            $returned = (int) ($previousQuantities[$id] ?? 0);
            $returnable = max($sold - $returned, 0);
            $quantity = min($quantity, $returnable);
            $breakdown = $breakdowns[$id];
            $net = (int) $breakdown['net_refund_total'];
            $previousEntitlement = $this->cumulativeRefundAmount($net, $sold, $returned);
            $previousAmount = min(max((int) ($previousAmounts[$id] ?? 0), $previousEntitlement), $net);
            $targetAmount = $this->cumulativeRefundAmount($net, $sold, $returned + $quantity);
            $amount = max(min($targetAmount - $previousAmount, $net - $previousAmount), 0);

            $result[] = [
                'invoice_item' => $item,
                'quantity' => $quantity,
                'previous_quantity' => $returned,
                'returnable_quantity' => $returnable,
                'allocated_discount' => (int) $breakdown['allocated_invoice_discount_total'],
                'refund_amount' => $amount,
                'refund_unit_price' => $quantity > 0 ? intdiv($amount, $quantity) : 0,
                'price_breakdown' => $breakdown,
            ];
        }

        return $result;
    }

    public function calculateSazehPreview(array $rows): array
    {
        return collect($rows)->map(function ($row) {
            $quantity = (int) ($row['return_quantity'] ?? 0);
            $unitPrice = (int) ($row['refund_unit_price'] ?? 0);

            return [
                'quantity' => $quantity,
                'refund_unit_price' => $unitPrice,
                'refund_amount' => $quantity * $unitPrice,
            ];
        })->all();
    }

    private function invoiceItems(Invoice $invoice): Collection
    {
        if ($invoice->relationLoaded('items')) {
            return $invoice->items->sortBy('id')->values();
        }

        return $invoice->items()->orderBy('id')->get();
    }

    private function separateInvoiceDiscount(Invoice $invoice, Collection $items): int
    {
        $mode = $invoice->discount_allocation_mode;
        $lineDiscount = (int) $items->sum(
            fn ($item) => SalesDocumentTotals::lineDiscount($item)
        );
        $available = max(
            (int) $items->sum(fn ($item) => SalesDocumentTotals::lineSubtotal($item)) - $lineDiscount,
            0
        );

        if ($mode === 'allocated_lines') {
            return 0;
        }

        if ($mode === 'product_lines') {
            return min(max((int) ($invoice->invoice_discount_amount ?? 0), 0), $available);
        }

        $storedInvoiceDiscount = (int) ($invoice->invoice_discount_amount ?? 0);
        $legacyFallback = max((int) ($invoice->discount_amount ?? 0) - $lineDiscount, 0);
        $discount = $storedInvoiceDiscount > 0 ? $storedInvoiceDiscount : $legacyFallback;

        return min(max($discount, 0), $available);
    }
}
