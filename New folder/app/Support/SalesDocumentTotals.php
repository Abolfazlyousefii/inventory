<?php

namespace App\Support;

use DomainException;
use Illuminate\Support\Collection;

class SalesDocumentTotals
{
    public const CALCULATION_VERSION = 2;

    /**
     * Calculate sales document totals from item rows.
     *
     * In this project `price` is the unit price and `line_discount_amount` is
     * the discount amount for the whole row, not for each unit. The document
     * `discount_amount` remains a separate invoice-level discount.
     */
    public static function calculate(iterable $items, int $documentDiscount = 0, int $shipping = 0, array $options = []): array
    {
        $rows = $items instanceof Collection ? $items : collect($items);

        $subtotalBeforeDiscount = self::sumAmounts($rows, fn ($item) => self::lineSubtotal($item));
        $itemsDiscount = self::sumAmounts($rows, fn ($item) => self::lineDiscount($item));
        $subtotalAfterProductDiscount = max($subtotalBeforeDiscount - $itemsDiscount, 0);
        $invoiceDiscount = min(max((int) $documentDiscount, 0), $subtotalAfterProductDiscount);
        $allocationMode = (string) ($options['discount_allocation_mode'] ?? 'separate');

        if ($allocationMode === 'allocated_lines') {
            $totalDiscount = min($subtotalBeforeDiscount, max($itemsDiscount, $invoiceDiscount));
        } else {
            $totalDiscount = min($subtotalBeforeDiscount, $itemsDiscount + $invoiceDiscount);
        }

        $subtotalAfterDiscount = max($subtotalBeforeDiscount - $totalDiscount, 0);
        $shipping = max((int) $shipping, 0);

        return [
            'subtotal_before_discount' => $subtotalBeforeDiscount,
            'items_discount' => $itemsDiscount,
            'invoice_discount' => $invoiceDiscount,
            'total_discount' => $totalDiscount,
            'subtotal_after_product_discount' => $subtotalAfterProductDiscount,
            'subtotal_after_discount' => $subtotalAfterDiscount,
            'total_tax' => 0,
            'shipping' => $shipping,
            'extra_costs' => $shipping,
            'grand_total' => $subtotalAfterDiscount + $shipping,
        ];
    }


    /**
     * Calculate totals directly from a persisted sales document.
     * For product_lines, only invoice_discount_amount is the document-level
     * discount; product discounts are already stored on item lines.
     */
    public static function fromDocument(object $document): array
    {
        $items = $document->items ?? collect();
        $mode = $document->discount_allocation_mode ?? null;
        $lineDiscount = (int) collect($items)->sum(fn ($item) => self::lineDiscount($item));

        if ($mode === 'product_lines') {
            $documentDiscount = (int) ($document->invoice_discount_amount ?? 0);
        } elseif ($mode === 'allocated_lines') {
            $documentDiscount = (int) ($document->discount_amount ?? 0);
        } else {
            $storedInvoice = $document->invoice_discount_amount ?? null;
            $documentDiscount = $storedInvoice !== null
                ? (int) $storedInvoice
                : max((int) ($document->discount_amount ?? 0) - $lineDiscount, 0);
        }

        return self::calculate($items, $documentDiscount, (int) ($document->shipping_price ?? 0), [
            'discount_allocation_mode' => $mode,
        ]);
    }

    public static function lineSubtotal(object $item): int
    {
        return self::safeMultiply(max((int) ($item->quantity ?? 0), 0), max((int) ($item->price ?? 0), 0));
    }

    public static function lineDiscount(object $item): int
    {
        return min(max((int) ($item->line_discount_amount ?? 0), 0), self::lineSubtotal($item));
    }

    public static function lineTotal(object $item): int
    {
        return max(self::lineSubtotal($item) - self::lineDiscount($item), 0);
    }

    public static function netUnitPrice(object $item): int
    {
        $quantity = max((int) ($item->quantity ?? 0), 0);
        if ($quantity === 0) return 0;
        $net = self::lineTotal($item);

        return intdiv($net, $quantity) + (($net % $quantity) >= intdiv($quantity, 2) + ($quantity % 2) ? 1 : 0);
    }

    /**
     * Preserve the historical per-unit commercial discount when quantity changes.
     * Integer round-half-up is used without converting money to float.
     */
    public static function proportionalLineDiscount(int $oldQuantity, int $oldDiscount, int $newQuantity, int $newUnitPrice): int
    {
        if ($oldQuantity <= 0) {
            throw new DomainException('Cannot preserve a line discount without a positive historical quantity.');
        }
        if ($oldDiscount < 0 || $newQuantity < 0 || $newUnitPrice < 0) {
            throw new DomainException('Sales line quantities, prices and discounts cannot be negative.');
        }

        $wholePerUnit = intdiv($oldDiscount, $oldQuantity);
        $remainder = $oldDiscount % $oldQuantity;
        $scaledRemainder = self::safeMultiply($remainder, $newQuantity);
        $roundedRemainder = intdiv($scaledRemainder, $oldQuantity);
        if (($scaledRemainder % $oldQuantity) >= intdiv($oldQuantity, 2) + ($oldQuantity % 2)) {
            $roundedRemainder++;
        }
        $discount = self::safeMultiply($wholePerUnit, $newQuantity) + $roundedRemainder;
        $gross = self::safeMultiply($newQuantity, $newUnitPrice);

        return min($discount, $gross);
    }

    public static function canonicalBreakdown(object $document, array $totals): array
    {
        $items = collect($document->items ?? []);

        return [
            'calculation_version' => self::CALCULATION_VERSION,
            'allocation_mode' => (string) ($document->discount_allocation_mode ?? 'separate'),
            'subtotal' => (int) $totals['subtotal_before_discount'],
            'product_discount_amount' => (int) $totals['items_discount'],
            'subtotal_after_product_discount' => (int) $totals['subtotal_after_product_discount'],
            'invoice_discount_type' => (string) ($document->invoice_discount_type ?? 'amount'),
            'invoice_discount_value' => (int) ($document->invoice_discount_value ?? 0),
            'invoice_discount_amount' => (int) $totals['invoice_discount'],
            'total_discount_amount' => (int) $totals['total_discount'],
            'shipping' => (int) $totals['shipping'],
            'grand_total' => (int) $totals['grand_total'],
            'lines' => $items->map(fn ($item) => [
                'item_id' => isset($item->id) ? (int) $item->id : null,
                'product_id' => isset($item->product_id) ? (int) $item->product_id : null,
                'variant_id' => isset($item->variant_id) ? (int) $item->variant_id : null,
                'quantity' => max((int) ($item->quantity ?? 0), 0),
                'unit_price' => max((int) ($item->price ?? 0), 0),
                'gross_amount' => self::lineSubtotal($item),
                'product_discount_amount' => self::lineDiscount($item),
                'net_amount' => self::lineTotal($item),
            ])->values()->all(),
        ];
    }

    public static function hasCanonicalMismatch(object $document): bool
    {
        $totals = self::fromDocument($document);
        $storedTotal = isset($document->total_price) ? (int) $document->total_price : (int) ($document->total ?? 0);

        return (int) ($document->subtotal ?? $totals['subtotal_before_discount']) !== (int) $totals['subtotal_before_discount']
            || (int) ($document->product_discount_amount ?? 0) !== (int) $totals['items_discount']
            || (int) ($document->discount_amount ?? 0) !== (int) $totals['total_discount']
            || $storedTotal !== (int) $totals['grand_total']
            || (int) data_get($document->discount_breakdown, 'grand_total', -1) !== (int) $totals['grand_total'];
    }

    public static function integrityIssues(object $document): array
    {
        $items = collect($document->items ?? []);
        $totals = self::fromDocument($document);
        $issues = [];

        foreach ($items as $item) {
            if ((int) ($item->quantity ?? 0) < 0 || (int) ($item->price ?? 0) < 0) $issues[] = 'negative_line_input';
            if ((int) ($item->line_discount_amount ?? 0) < 0 || (int) ($item->line_discount_amount ?? 0) > self::lineSubtotal($item)) $issues[] = 'invalid_line_discount';
            if (isset($item->line_total) && (int) $item->line_total !== self::lineTotal($item)) $issues[] = 'line_total_mismatch';
        }

        if (isset($document->subtotal) && (int) $document->subtotal !== (int) $totals['subtotal_before_discount']) $issues[] = 'subtotal_mismatch';
        if ((int) ($document->product_discount_amount ?? 0) !== (int) $totals['items_discount']) $issues[] = 'product_discount_mismatch';
        if ((int) ($document->invoice_discount_amount ?? 0) !== (int) $totals['invoice_discount']) $issues[] = 'invoice_discount_mismatch';
        if ((int) ($document->discount_amount ?? 0) !== (int) $totals['total_discount']) $issues[] = 'total_discount_mismatch';
        $storedTotal = isset($document->total_price) ? (int) $document->total_price : (int) ($document->total ?? 0);
        if ($storedTotal !== (int) $totals['grand_total']) $issues[] = 'grand_total_mismatch';
        if ((int) data_get($document->discount_breakdown, 'grand_total', -1) !== (int) $totals['grand_total']) $issues[] = 'discount_breakdown_mismatch';

        return array_values(array_unique($issues));
    }

    private static function safeMultiply(int $left, int $right): int
    {
        if ($left !== 0 && $right > intdiv(PHP_INT_MAX, abs($left))) {
            throw new DomainException('Sales amount exceeds the supported integer range.');
        }

        return $left * $right;
    }

    private static function sumAmounts(iterable $items, callable $value): int
    {
        $sum = 0;
        foreach ($items as $item) {
            $amount = (int) $value($item);
            if ($amount > PHP_INT_MAX - $sum) throw new DomainException('Sales total exceeds the supported integer range.');
            $sum += $amount;
        }

        return $sum;
    }
}
