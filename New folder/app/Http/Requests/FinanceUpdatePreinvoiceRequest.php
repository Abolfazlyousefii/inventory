<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class FinanceUpdatePreinvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $items = collect((array) $this->input('items', []))->map(function ($row) {
            if (! is_array($row)) {
                return $row;
            }
            foreach (['price'] as $key) {
                if (array_key_exists($key, $row)) {
                    $row[$key] = $this->normalizeMoney($row[$key]);
                }
            }
            if (array_key_exists('quantity', $row)) {
                $row['quantity'] = $this->normalizeMoney($row['quantity']);
            }
            return $row;
        })->all();

        $productDiscounts = collect((array) $this->input('product_discounts', []))->map(function ($row) {
            if (! is_array($row)) {
                return $row;
            }
            if (array_key_exists('value', $row)) {
                $row['value'] = $this->normalizeMoney($row['value']);
            }
            return $row;
        })->all();

        $intent = $this->input('intent', $this->input('action', 'save'));
        $this->merge([
            'intent' => $intent,
            'action' => $intent,
            'items' => $items,
            'product_discounts' => $productDiscounts,
            'invoice_discount_value' => $this->normalizeMoney($this->input('invoice_discount_value', 0)),
        ]);
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['required', 'integer', 'min:0'],
            'product_discounts' => ['nullable', 'array'],
            'product_discounts.*.product_id' => ['required_with:product_discounts', 'integer'],
            'product_discounts.*.type' => ['required_with:product_discounts', 'in:amount,percent'],
            'product_discounts.*.value' => ['required_with:product_discounts', 'numeric', 'min:0'],
            'invoice_discount_type' => ['required', 'in:none,amount,percent'],
            'invoice_discount_value' => ['nullable', 'integer', 'min:0'],
            'edit_reason' => ['required', 'string', 'min:3', 'max:1000'],
            'intent' => ['nullable', 'in:save,save_and_finalize'],
            'action' => ['nullable', 'in:save,save_and_finalize'],
        ];
    }

    public function messages(): array
    {
        return [
            'edit_reason.required' => 'لطفاً دلیل ویرایش مالی را وارد کنید.',
            'edit_reason.string' => 'دلیل ویرایش مالی نامعتبر است.',
            'edit_reason.min' => 'دلیل ویرایش مالی باید حداقل ۳ کاراکتر باشد.',
            'edit_reason.max' => 'دلیل ویرایش مالی نمی‌تواند بیشتر از ۱۰۰۰ کاراکتر باشد.',
            'items.*.line_discount_amount.max' => 'تخفیف ردیف نمی‌تواند بیشتر از مبلغ ناخالص همان ردیف باشد.',
        ];
    }

    public function attributes(): array
    {
        return [
            'edit_reason' => 'دلیل ویرایش مالی',
            'invoice_discount_value' => 'مقدار تخفیف کلی',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('items', []) as $index => $row) {
                if (($this->input('intent') === 'save_and_finalize') && (int) ($row['price'] ?? 0) <= 0) {
                    $validator->errors()->add("items.{$index}.price", 'برای تأیید مالی، قیمت تمام اقلام باید بیشتر از صفر باشد.');
                }
            }
            if ($this->input('invoice_discount_type') === 'percent' && (int) $this->input('invoice_discount_value', 0) > 100) {
                $validator->errors()->add('invoice_discount_value', 'درصد تخفیف کلی نمی‌تواند بیشتر از ۱۰۰ باشد.');
            }

            foreach ((array) $this->input('product_discounts', []) as $index => $row) {
                if (($row['type'] ?? null) === 'percent' && (int) ($row['value'] ?? 0) > 100) {
                    $validator->errors()->add("product_discounts.{$index}.value", 'درصد تخفیف محصول نمی‌تواند بیشتر از ۱۰۰ باشد.');
                }
            }
        });
    }

    private function normalizeMoney(mixed $value): int
    {
        $value = strtr((string) ($value ?? ''), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $value = preg_replace('/[^0-9]/u', '', $value) ?: '0';
        return (int) $value;
    }
}
