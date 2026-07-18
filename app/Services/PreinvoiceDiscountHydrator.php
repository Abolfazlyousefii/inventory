<?php

namespace App\Services;

use App\Models\PreinvoiceOrder;
use App\Support\SalesDocumentTotals;

class PreinvoiceDiscountHydrator
{
    public function hydrateForEditing(PreinvoiceOrder $order): array
    {
        $order->loadMissing('items.product', 'items.variant');
        $structured = is_array($order->discount_breakdown) ? $order->discount_breakdown : [];
        $itemsDiscount = (int) $order->items->sum(fn ($item) => SalesDocumentTotals::lineDiscount($item));
        $legacyTotalDiscount = max((int) ($order->discount_amount ?? 0), 0);
        $legacyInvoiceDiscount = max($legacyTotalDiscount - $itemsDiscount, 0);

        $productGroups = [];
        $structuredGroups = collect($structured['groups'] ?? [])->keyBy(fn ($group) => (int) ($group['product_id'] ?? 0));
        foreach ($order->items->groupBy('product_id') as $productId => $items) {
            $productId = (int) $productId;
            $gross = (int) $items->sum(fn ($item) => SalesDocumentTotals::lineSubtotal($item));
            $lineDiscount = (int) $items->sum(fn ($item) => SalesDocumentTotals::lineDiscount($item));
            $existing = (array) ($structuredGroups->get($productId) ?? []);
            $amount = (int) ($existing['discount_amount'] ?? $lineDiscount);
            $type = (string) ($existing['discount_type'] ?? ($amount > 0 ? 'amount' : 'amount'));
            $value = (int) ($existing['discount_value'] ?? $amount);

            $productGroups[$productId] = [
                'product_id' => $productId,
                'discount_type' => in_array($type, ['amount', 'percent'], true) ? $type : 'amount',
                'discount_value' => $value,
                'discount_amount' => $amount,
                'raw_subtotal' => $gross,
                'final_amount' => max($gross - $amount, 0),
                'legacy_hydrated' => empty($existing) && $lineDiscount > 0,
            ];
        }

        $invoiceType = $order->invoice_discount_type ?: null;
        $invoiceValue = (int) ($order->invoice_discount_value ?? 0);
        $invoiceAmount = (int) ($order->invoice_discount_amount ?? 0);
        if (! $invoiceType && $legacyInvoiceDiscount > 0) {
            $invoiceType = 'amount';
            $invoiceValue = $legacyInvoiceDiscount;
            $invoiceAmount = $legacyInvoiceDiscount;
        }

        return [
            'product_groups' => $productGroups,
            'invoice_discount' => [
                'type' => $invoiceType ?: 'none',
                'value' => $invoiceValue,
                'amount' => $invoiceAmount,
            ],
            'items_discount' => $itemsDiscount,
            'legacy_total_discount' => $legacyTotalDiscount,
            'legacy_invoice_discount' => $legacyInvoiceDiscount,
            'has_structured_breakdown' => ! empty($structured),
            'is_legacy_unstructured' => empty($structured) && $legacyTotalDiscount > 0,
            'legacy_message' => empty($structured) && $legacyTotalDiscount > 0
                ? 'این سند تخفیف قدیمی بدون جزئیات تفکیکی دارد؛ تفکیک محصول/کلی در دیتابیس موجود نیست و برای ویرایش فقط بازسازی نمایشی شده است.'
                : null,
        ];
    }
}
