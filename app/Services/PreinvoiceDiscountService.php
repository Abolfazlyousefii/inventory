<?php

namespace App\Services;

use App\Models\PreinvoiceOrder;
use App\Support\SalesDocumentTotals;
use Illuminate\Support\Arr;

class PreinvoiceDiscountService
{
    public function applyToOrder(PreinvoiceOrder $order, array $payload): array
    {
        $order->loadMissing('items.product', 'items.variant');
        $breakdown = $this->decodeBreakdown($payload['discount_breakdown'] ?? null);
        $invoiceType = (string) Arr::get($breakdown, 'order_discount_type', $payload['invoice_discount_type'] ?? 'amount');
        $invoiceType = in_array($invoiceType, ['amount', 'percent'], true) ? $invoiceType : 'amount';
        $invoiceValue = max((int) Arr::get($breakdown, 'order_discount_value', $payload['invoice_discount_value'] ?? 0), 0);
        $itemsDiscount = (int) $order->items->sum(fn ($item) => SalesDocumentTotals::lineDiscount($item));
        $gross = (int) $order->items->sum(fn ($item) => SalesDocumentTotals::lineSubtotal($item));
        $base = max($gross - $itemsDiscount, 0);
        $invoiceAmount = $invoiceType === 'percent' ? (int) floor($base * min($invoiceValue, 100) / 100) : min($invoiceValue, $base);
        $totals = SalesDocumentTotals::calculate($order->items, $invoiceAmount, (int) $order->shipping_price, ['discount_allocation_mode' => 'product_lines']);
        $groups = $this->groupsFromBreakdown($order, $breakdown);
        $contract = [
            'subtotal' => (int) $totals['subtotal_before_discount'],
            'product_discount_amount' => (int) $totals['items_discount'],
            'invoice_discount_type' => $invoiceType,
            'invoice_discount_value' => $invoiceValue,
            'invoice_discount_amount' => (int) $totals['invoice_discount'],
            'total_discount_amount' => (int) $totals['total_discount'],
            'subtotal_after_product_discount' => (int) $totals['subtotal_after_product_discount'],
            'grand_total' => (int) $totals['grand_total'],
            'shipping' => (int) $totals['shipping'],
            'groups' => $groups,
        ];
        $order->forceFill([
            'discount_amount' => (int) $totals['total_discount'],
            'discount_breakdown' => $contract,
            'invoice_discount_type' => $invoiceType,
            'invoice_discount_value' => $invoiceValue,
            'invoice_discount_amount' => (int) $totals['invoice_discount'],
            'product_discount_amount' => (int) $totals['items_discount'],
            'discount_allocation_mode' => 'product_lines',
            'total_price' => (int) $totals['grand_total'],
        ])->save();

        return $contract;
    }

    private function decodeBreakdown(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function groupsFromBreakdown(PreinvoiceOrder $order, array $breakdown): array
    {
        $incoming = collect($breakdown['groups'] ?? [])->keyBy(fn ($group) => (int) ($group['product_id'] ?? 0));
        return $order->items->groupBy('product_id')->map(function ($items, $productId) use ($incoming) {
            $gross = (int) $items->sum(fn ($item) => SalesDocumentTotals::lineSubtotal($item));
            $lineDiscount = (int) $items->sum(fn ($item) => SalesDocumentTotals::lineDiscount($item));
            $group = (array) ($incoming->get((int) $productId) ?? []);
            return [
                'product_id' => (int) $productId,
                'discount_type' => in_array(($group['discount_type'] ?? 'amount'), ['amount', 'percent'], true) ? $group['discount_type'] : 'amount',
                'discount_value' => (int) ($group['discount_value'] ?? $lineDiscount),
                'discount_amount' => $lineDiscount,
                'raw_subtotal' => $gross,
                'final_amount' => max($gross - $lineDiscount, 0),
            ];
        })->values()->all();
    }
}
