<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceFinancialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->stringValue($this->input('edit_reason'));

        if ($reason === '') {
            foreach (['reason', 'change_reason', 'finance_note', 'correction_reason'] as $alias) {
                $candidate = $this->stringValue($this->input($alias));
                if ($candidate !== '') {
                    $reason = $candidate;
                    break;
                }
            }
        }

        $items = collect($this->input('items', []))->map(function ($row) {
            if (! is_array($row)) {
                return $row;
            }
            foreach (['quantity', 'price', 'line_discount_amount'] as $field) {
                $row[$field] = $this->normalizeIntegerInput($row[$field] ?? 0);
            }
            return $row;
        })->values()->all();

        $this->merge([
            'edit_reason' => $reason,
            'items' => $items,
            'discount_amount' => $this->normalizeIntegerInput($this->input('discount_amount', 0)),
            'shipping_price' => $this->normalizeIntegerInput($this->input('shipping_price', 0)),
        ]);
    }

    public function rules(): array
    {
        return [
            'discount_amount' => 'nullable|integer|min:0',
            'shipping_price' => 'nullable|integer|min:0',
            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|exists:invoice_items,id',
            'items.*.product_id' => 'required_without:items.*.id|nullable|exists:products,id',
            'items.*.variant_id' => 'required_without:items.*.id|nullable|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:0',
            'items.*.price' => 'required|integer|min:0',
            'items.*.line_discount_amount' => 'nullable|integer|min:0',
            'edit_reason' => 'required|string|max:2000',
        ];
    }

    public function messages(): array
    {
        return [
            'edit_reason.required' => 'لطفاً دلیل ویرایش مالی را وارد کنید.',
        ];
    }

    private function normalizeIntegerInput(mixed $value): int
    {
        $normalized = strtr((string) $value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            ',' => '', '٬' => '', ' ' => '', "\u{00A0}" => '',
        ]);

        return (int) preg_replace('/[^0-9]/', '', $normalized);
    }

    private function stringValue(mixed $value): string
    {
        return trim(is_scalar($value) ? (string) $value : '');
    }
}
