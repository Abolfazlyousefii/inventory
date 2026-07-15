<?php

namespace App\Support;

use Illuminate\Support\Collection;

class SalesDocumentTotals
{
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

        $subtotalBeforeDiscount = (int) $rows->sum(fn ($item) => self::lineSubtotal($item));
        $itemsDiscount = (int) $rows->sum(fn ($item) => self::lineDiscount($item));
        $invoiceDiscount = max((int) $documentDiscount, 0);
        $mode = (string) ($options['discount_allocation_mode'] ?? '');

        if ($mode === 'allocated_lines') {
            $totalDiscount = min($subtotalBeforeDiscount, $itemsDiscount);
            $invoiceDiscountForDisplay = $invoiceDiscount;
        } else {
            $totalDiscount = min($subtotalBeforeDiscount, $itemsDiscount + $invoiceDiscount);
            $invoiceDiscountForDisplay = $invoiceDiscount;
        }

        $subtotalAfterDiscount = max($subtotalBeforeDiscount - $totalDiscount, 0);
        $shipping = max((int) $shipping, 0);

        return [
            'subtotal_before_discount' => $subtotalBeforeDiscount,
            'items_discount' => $itemsDiscount,
            'invoice_discount' => $invoiceDiscountForDisplay,
            'total_discount' => $totalDiscount,
            'subtotal_after_discount' => $subtotalAfterDiscount,
            'total_tax' => 0,
            'shipping' => $shipping,
            'extra_costs' => $shipping,
            'grand_total' => $subtotalAfterDiscount + $shipping,
        ];
    }

    public static function lineSubtotal(object $item): int
    {
        return max((int) ($item->quantity ?? 0), 0) * max((int) ($item->price ?? 0), 0);
    }

    public static function lineDiscount(object $item): int
    {
        return min(max((int) ($item->line_discount_amount ?? 0), 0), self::lineSubtotal($item));
    }

    public static function lineTotal(object $item): int
    {
        return max(self::lineSubtotal($item) - self::lineDiscount($item), 0);
    }
}
