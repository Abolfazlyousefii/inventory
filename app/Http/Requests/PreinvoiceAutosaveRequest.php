<?php

namespace App\Http\Requests;

use App\Models\ProductVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PreinvoiceAutosaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('discount_breakdown'))) {
            $value = $this->input('discount_breakdown');
            $decoded = json_decode($value, true);
            // Invalid JSON must fail validation, never silently become an empty discount.
            $this->merge(['discount_breakdown' => trim($value) === '' ? null : ($decoded ?? $value)]);
        }
    }

    public function rules(): array
    {
        return [
            'draft_uuid' => ['nullable', 'string', 'max:100'],
            'base_version' => ['required_with:draft_uuid', 'nullable', 'string', 'size:64'],
            'reservation_token' => ['required', 'uuid'],
            'action' => ['sometimes', 'in:autosave,confirm_changes'],
            'confirmation_token' => ['nullable', 'string', 'size:64'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name' => ['present', 'nullable', 'string', 'max:255'],
            'customer_mobile' => ['present', 'nullable', 'string', 'max:20'],
            'customer_address' => ['nullable', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:2000'],
            'payment_terms_note' => ['nullable', 'string', 'max:2000'],
            'is_in_person' => ['nullable', 'boolean'],
            'province_id' => ['nullable', 'integer'],
            'city_id' => ['nullable', 'integer'],
            'shipping_id' => ['nullable', 'integer', 'exists:shipping_methods,id'],
            'shipping_price' => ['nullable', 'integer', 'min:0'],
            'discount_amount' => ['nullable', 'integer', 'min:0'],
            'invoice_discount_type' => ['nullable', 'in:amount,percent,none'],
            'invoice_discount_value' => ['nullable', 'integer', 'min:0'],
            'discount_breakdown' => ['nullable', 'array'],
            'discount_breakdown.order_discount_type' => ['sometimes', 'in:amount,percent,none'],
            'discount_breakdown.order_discount_value' => ['sometimes', 'integer', 'min:0'],
            'discount_breakdown.groups' => ['sometimes', 'array'],
            'discount_breakdown.groups.*' => ['array'],
            'discount_breakdown.groups.*.product_id' => ['required', 'integer', 'distinct'],
            'discount_breakdown.groups.*.discount_type' => ['required', 'in:amount,percent'],
            'discount_breakdown.groups.*.discount_value' => ['required', 'integer', 'min:0'],
            'products' => ['present', 'array', 'list', 'max:2000'],
            'products.*' => ['array'],
            'products.*.id' => ['required', 'integer', 'exists:products,id'],
            'products.*.variety_id' => ['required', 'integer', 'distinct'],
            'products.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'products.*.price' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'products.*.line_discount_amount' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $products = $this->input('products');
            $variants = ProductVariant::query()
                ->whereIn('id', array_column($products, 'variety_id'))
                ->pluck('product_id', 'id');

            foreach ($products as $index => $row) {
                if ((int) $variants->get($row['variety_id']) !== (int) $row['id']) {
                    $validator->errors()->add("products.{$index}.variety_id", 'تنوع انتخابی برای این کالا معتبر نیست.');
                }
            }
        }];
    }
}
