<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReceiveWarehouseInboundReceiptRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // A single scalar survives PHP max_input_vars even for very large receipts.
        // Keep legacy small forms compatible; the service still checks every item ID.
        if (! $this->exists('items_payload')) {
            return;
        }

        $payload = $this->input('items_payload');
        try {
            if (! is_string($payload) || strlen($payload) > 5242880) {
                throw new \JsonException('Invalid payload');
            }
            $items = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['items_payload' => 'اطلاعات اقلام رسید نامعتبر است؛ صفحه را تازه کنید و دوباره بررسی کنید.']);
        }

        $count = filter_var($this->input('items_payload_count'), FILTER_VALIDATE_INT);
        if (! is_array($items) || ! array_is_list($items) || $count === false || $count < 1 || $count > 5000 || count($items) !== $count) {
            throw ValidationException::withMessages(['items_payload' => 'اطلاعات اقلام رسید ناقص است؛ هیچ موجودی ثبت نشد. صفحه را تازه کنید و دوباره بررسی کنید.']);
        }

        $this->merge(['items' => $items]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'receipt_version' => ['required', 'string', 'size:64'],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'array'],
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
            'receipt_version.required' => 'صفحهٔ دریافت قدیمی است؛ صفحه را تازه کنید و دوباره بررسی کنید.',
            'items.required' => 'حداقل یک قلم برای دریافت لازم است.',
            'items.*.accepted_quantity.min' => 'تعداد دریافت‌شده نمی‌تواند منفی باشد.',
            'items.*.accepted_quantity.integer' => 'تعداد دریافت‌شده باید عدد صحیح معتبر باشد.',
            'items.*.received_warehouse_id.required' => 'انبار مقصد هر قلم الزامی است.',
        ];
    }
}
