<?php

namespace App\Services;

use App\Models\PreinvoiceOrder;
use App\Support\SalesDocumentTotals;
use Illuminate\Support\Arr;
use App\Support\DiscountMath;

class PreinvoiceDiscountService
{
    public function __construct(private readonly ProductDiscountAllocator $allocator) {}

    public function applyToOrder(PreinvoiceOrder $order, array $payload): array
    {
        $order->loadMissing('items.product', 'items.variant');
        if (empty($payload['discount_breakdown']) && (
            (int) $order->discount_amount > 0 || (int) $order->product_discount_amount > 0 || (int) $order->invoice_discount_amount > 0 || (int) ($payload['discount_amount'] ?? 0) > 0 ||
            $order->items->contains(fn ($item) => (int) $item->line_discount_amount > 0)
        )) {
            throw \Illuminate\Validation\ValidationException::withMessages(['discount_breakdown' => 'جزئیات تخفیف ارسال نشده است. برای جلوگیری از حذف تخفیف، سند را دوباره باز کنید.']);
        }
        $breakdown = $this->decodeBreakdown($payload['discount_breakdown'] ?? null);
        if (((int) $order->product_discount_amount > 0 || $order->items->contains(fn ($item) => (int) $item->line_discount_amount > 0)) && ! array_key_exists('groups', $breakdown)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['discount_breakdown' => 'جزئیات تخفیف کالاها ناقص است؛ تخفیف‌های قبلی تغییر نکردند.']);
        }
        if ((int) $order->invoice_discount_amount > 0 && ! array_key_exists('order_discount_value', $breakdown) && ! array_key_exists('invoice_discount_value', $payload)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['discount_breakdown' => 'جزئیات تخفیف کلی ناقص است؛ تخفیف قبلی تغییر نکرد.']);
        }
        $invoiceType = (string) Arr::get($breakdown, 'order_discount_type', $payload['invoice_discount_type'] ?? 'amount');
        $invoiceType = in_array($invoiceType, ['amount', 'percent'], true) ? $invoiceType : 'amount';
        $invoiceValue = DiscountMath::value($invoiceType, Arr::get($breakdown, 'order_discount_value', $payload['invoice_discount_value'] ?? 0));
        $productDiscounts = $this->allocator->allocate($order->items, $breakdown['groups'] ?? []);
        foreach ($order->items as $item) {
            $lineDiscount = (int) ($productDiscounts['lines'][(int) $item->id] ?? 0);
            $item->forceFill([
                'line_discount_amount' => $lineDiscount,
                'line_total' => SalesDocumentTotals::lineTotal((object) [
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'line_discount_amount' => $lineDiscount,
                ]),
            ])->save();
        }
        $order->load('items.product', 'items.variant');
        $itemsDiscount = (int) $order->items->sum(fn ($item) => SalesDocumentTotals::lineDiscount($item));
        $gross = (int) $order->items->sum(fn ($item) => SalesDocumentTotals::lineSubtotal($item));
        $base = max($gross - $itemsDiscount, 0);
        $invoiceAmount = DiscountMath::amount($base, $invoiceType, $invoiceValue);
        $totals = SalesDocumentTotals::calculate($order->items, $invoiceAmount, (int) $order->shipping_price, ['discount_allocation_mode' => 'product_lines']);
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
            'groups' => $productDiscounts['groups'],
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


    public function assertIntegrityOrRepair(PreinvoiceOrder $order): void
    {
        $order->loadMissing('items.product', 'items.variant');
        $hasGroups = ! empty($order->discount_breakdown['groups'] ?? []);
        $lineDiscount = (int) $order->items->sum(fn ($item) => SalesDocumentTotals::lineDiscount($item));

        if (($order->discount_allocation_mode === 'product_lines' || $order->discount_allocation_mode === null) && $hasGroups && $lineDiscount === 0) {
            $this->applyToOrder($order, [
                'discount_breakdown' => $order->discount_breakdown,
                'invoice_discount_type' => $order->invoice_discount_type,
                'invoice_discount_value' => $order->invoice_discount_value ?? 0,
            ]);
            $order->refresh()->load('items.product', 'items.variant');
        }

        $totals = SalesDocumentTotals::fromDocument($order);
        $productDiscount = (int) $order->items->sum(fn ($item) => SalesDocumentTotals::lineDiscount($item));
        $invoiceDiscount = (int) ($totals['invoice_discount'] ?? 0);
        $storedProduct = (int) ($order->product_discount_amount ?? $productDiscount);
        $storedDiscount = (int) ($order->discount_amount ?? 0);
        $storedTotal = (int) ($order->total_price ?? 0);

        if ($productDiscount !== $storedProduct || $storedDiscount !== (int) $totals['total_discount'] || $storedTotal !== (int) $totals['grand_total'] || $storedDiscount !== $productDiscount + $invoiceDiscount) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'discount' => 'قرارداد تخفیف این پیش‌فاکتور با اقلام همخوان نیست. لطفاً سند را در ویرایش مالی ذخیره کنید یا Audit/Repair تخفیف را اجرا کنید.',
            ]);
        }
    }

    private function decodeBreakdown(mixed $value): array
    {
        return \App\Support\SalesDiscountPayload::decode($value);
    }

}
