<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReceiveWarehouseInboundReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.accepted_quantity' => ['required', 'integer', 'min:0'],
            'items.*.received_warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where(fn ($q) => $q->where('is_active', true)->whereIn('type', ['central', 'return'])),
            ],
            'items.*.note' => ['nullable', 'string', 'max:1000'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'حداقل یک قلم برای دریافت لازم است.',
            'items.*.accepted_quantity.min' => 'تعداد دریافت‌شده نمی‌تواند منفی باشد.',
            'items.*.accepted_quantity.integer' => 'تعداد دریافت‌شده باید عدد صحیح معتبر باشد.',
            'items.*.received_warehouse_id.required' => 'انبار مقصد هر قلم الزامی است.',
        ];
    }
}
