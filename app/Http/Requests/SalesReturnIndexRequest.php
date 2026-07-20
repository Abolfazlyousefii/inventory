<?php

namespace App\Http\Requests;

use App\Models\SalesReturnDocument;
use App\Models\SalesReturnDocumentItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalesReturnIndexRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['date_from', 'date_to'] as $field) {
            if ($this->has($field)) {
                $data[$field] = $this->normalizeJalaliDate($this->input($field));
            }
        }
        if ($data !== []) {
            $this->merge($data);
        }
    }

    private function normalizeJalaliDate(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return strtr((string) $value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '-' => '/', ' ' => '', "\t" => '', "\n" => '', "\r" => '',
        ]);
    }

    public function rules(): array
    {
        return [
            'document_number' => ['nullable','string','max:64'],
            'source_type' => ['nullable', Rule::in(['all', SalesReturnDocument::SOURCE_INTERNAL_INVOICE, SalesReturnDocument::SOURCE_SAZEH_HESAB])],
            'status' => ['nullable', Rule::in(['all', SalesReturnDocument::STATUS_DRAFT, SalesReturnDocument::STATUS_APPLIED, SalesReturnDocument::STATUS_CANCELLED])],
            'customer_id' => ['nullable','integer','exists:customers,id'],
            'invoice_number' => ['nullable','string','max:100'],
            'external_invoice_number' => ['nullable','string','max:100'],
            'reference_number' => ['nullable','string','max:100'],
            'destination_warehouse_id' => ['nullable','integer','exists:warehouses,id'],
            'item_condition' => ['nullable', Rule::in(['all', SalesReturnDocumentItem::CONDITION_HEALTHY, SalesReturnDocumentItem::CONDITION_DAMAGED])],
            'product_id' => ['nullable','integer','exists:products,id'],
            'product_variant_id' => ['nullable','integer','exists:product_variants,id'],
            'return_reason' => ['nullable','string','max:150'],
            'created_by' => ['nullable','integer','exists:users,id'],
            'applied_by' => ['nullable','integer','exists:users,id'],
            'date_from' => ['nullable','regex:/^\d{4}\/\d{2}\/\d{2}$/'],
            'date_to' => ['nullable','regex:/^\d{4}\/\d{2}\/\d{2}$/'],
            'min_amount' => ['nullable','integer','min:0'],
            'max_amount' => ['nullable','integer','min:0','gte:min_amount'],
            'sort' => ['nullable', Rule::in(['newest','oldest','amount_desc','amount_asc','customer'])],
            'per_page' => ['nullable','integer', Rule::in([30,50])],
        ];
    }

    public function messages(): array
    {
        return [
            'date_from.regex' => 'تاریخ را به‌شکل ۱۴۰۵/۰۴/۲۸ وارد کنید.',
            'date_to.regex' => 'تاریخ را به‌شکل ۱۴۰۵/۰۴/۲۸ وارد کنید.',
        ];
    }

    public function filters(): array
    {
        return collect($this->validated())->reject(fn ($v) => $v === null || $v === '')->all();
    }
}
