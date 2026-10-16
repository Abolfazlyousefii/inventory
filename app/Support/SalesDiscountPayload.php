<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SalesDiscountPayload
{
    public static function decode(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (! is_array($value)) {
            throw ValidationException::withMessages(['discount_breakdown' => 'جزئیات تخفیف ناقص یا نامعتبر است. سند را دوباره باز کنید؛ تخفیف‌ها تغییر نکردند.']);
        }
        Validator::make(['discount_breakdown' => $value], [
            'discount_breakdown.groups' => ['sometimes', 'array'],
            'discount_breakdown.groups.*' => ['array'],
            'discount_breakdown.groups.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'discount_breakdown.groups.*.discount_type' => ['required', 'in:amount,percent'],
            'discount_breakdown.groups.*.discount_value' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'discount_breakdown.order_discount_type' => ['sometimes', 'in:none,amount,percent'],
            'discount_breakdown.order_discount_value' => ['required_with:discount_breakdown.order_discount_type', 'numeric', 'min:0', 'decimal:0,2'],
        ])->validate();
        foreach ($value['groups'] ?? [] as $group) {
            DiscountMath::value($group['discount_type'], $group['discount_value']);
        }
        if (isset($value['order_discount_type'])) {
            DiscountMath::value($value['order_discount_type'], $value['order_discount_value']);
        }

        return $value;
    }
}
