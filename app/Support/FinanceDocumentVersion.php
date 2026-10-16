<?php

namespace App\Support;

use App\Models\PreinvoiceOrder;
use Illuminate\Validation\ValidationException;

/** Compare the reviewed contents, never a mutable stock balance or a clock timestamp. */
final class FinanceDocumentVersion
{
    public static function token(PreinvoiceOrder $order): string
    {
        $order->loadMissing('items');
        $header = [];
        foreach (['id', 'uuid', 'status', 'customer_id', 'customer_name', 'customer_mobile', 'customer_address', 'created_by', 'shipping_id', 'shipping_price', 'description', 'payment_terms_note', 'invoice_discount_type', 'invoice_discount_value', 'invoice_discount_amount', 'product_discount_amount', 'discount_amount', 'discount_allocation_mode', 'total_price'] as $field) {
            $header[$field] = (string) ($order->getAttribute($field) ?? '');
        }
        $items = $order->items->sortBy('id')->map(function ($item) {
            $row = [];
            foreach (['id', 'product_id', 'variant_id', 'quantity', 'price', 'line_discount_amount', 'line_total', 'sort_order'] as $field) {
                $row[$field] = (int) $item->getAttribute($field);
            }

            return $row;
        })->values()->all();

        return hash_hmac('sha256', json_encode([$header, $order->discount_breakdown, $items], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public static function assertCurrent(PreinvoiceOrder $order, mixed $token): void
    {
        if (! is_string($token) || ! hash_equals(self::token($order), $token)) {
            throw ValidationException::withMessages([
                'finance_version' => 'اطلاعات این پیش‌فاکتور تغییر کرده یا نسخه صفحه معتبر نیست. صفحه را دوباره بارگذاری و مبالغ و تخفیف‌ها را بررسی کنید؛ تغییری ثبت نشد.',
            ]);
        }
    }
}
