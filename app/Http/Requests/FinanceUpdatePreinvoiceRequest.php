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

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['required', 'integer', 'min:0'],
            'items.*.line_discount_amount' => ['nullable', 'integer', 'min:0'],
            'edit_reason' => ['required', 'string', 'min:3', 'max:1000'],
            'action' => ['nullable', 'in:save,save_and_finalize'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('items', []) as $index => $row) {
                $gross = max(0, (int) ($row['quantity'] ?? 0)) * max(0, (int) ($row['price'] ?? 0));
                $discount = max(0, (int) ($row['line_discount_amount'] ?? 0));
                if ($discount > $gross) {
                    $validator->errors()->add("items.{$index}.line_discount_amount", 'تخفیف ردیف نمی‌تواند بیشتر از مبلغ ناخالص همان ردیف باشد.');
                }
                if ($this->input('action') === 'save_and_finalize' && (int) ($row['price'] ?? 0) <= 0) {
                    $validator->errors()->add("items.{$index}.price", 'برای تأیید مالی، قیمت تمام اقلام باید بیشتر از صفر باشد.');
                }
            }
        });
    }
}
