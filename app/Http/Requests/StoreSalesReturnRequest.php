<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SalesReturnDocument;
use App\Services\SalesReturnCalculationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSalesReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('sales_returns.create');
    }

    public function rules(): array
    {
        $source = $this->input('source_type');
        $rules = [
            'source_type' => ['required', Rule::in([SalesReturnDocument::SOURCE_INTERNAL_INVOICE, SalesReturnDocument::SOURCE_SAZEH_HESAB])],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'return_reason' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.return_quantity' => ['required', 'integer', 'min:1'],
            'items.*.item_condition' => ['required', Rule::in(['healthy', 'damaged'])],
            'items.*.destination_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
        ];

        if ($source === SalesReturnDocument::SOURCE_INTERNAL_INVOICE) {
            $rules += [
                'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
                'items.*.invoice_item_id' => ['required', 'integer', 'exists:invoice_items,id'],
            ];
        }

        if ($source === SalesReturnDocument::SOURCE_SAZEH_HESAB) {
            $rules += [
                'external_invoice_number' => ['required', 'string', 'max:64'],
                'external_invoice_date' => ['required', 'date'],
                'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
                'items.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
                'items.*.refund_unit_price' => ['required', 'integer', 'min:1'],
                'items.*.new_product_payload' => ['nullable', 'array'],
                'items.*.new_product_payload.product_name' => ['nullable', 'string', 'max:255'],
                'items.*.new_product_payload.variant_name' => ['nullable', 'string', 'max:255'],
                'items.*.new_product_payload.category_id' => ['nullable', 'integer', 'exists:categories,id'],
                'items.*.new_product_payload.subcategory_id' => ['nullable', 'integer', 'exists:categories,id'],
                'items.*.new_product_payload.model_list_id' => ['nullable', 'integer', 'exists:model_lists,id'],
                'items.*.new_product_payload.sku' => ['nullable', 'string', 'max:150'],
                'items.*.new_product_payload.barcode' => ['nullable', 'string', 'max:150'],
                'items.*.new_product_payload.purchase_price' => ['nullable', 'integer', 'min:0'],
                'items.*.new_product_payload.sell_price' => ['nullable', 'integer', 'min:1'],
                'items.*.new_product_payload.sales_enabled' => ['nullable', 'boolean'],
            ];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->input('source_type') === SalesReturnDocument::SOURCE_INTERNAL_INVOICE) {
                $this->validateInternal($validator);
                return;
            }

            if ($this->input('source_type') === SalesReturnDocument::SOURCE_SAZEH_HESAB) {
                $this->validateSazeh($validator);
            }
        });
    }

    protected function validateInternal(Validator $validator): void
    {
        $invoice = Invoice::query()->with('items')->whereKey((int) $this->input('invoice_id'))->first();
        if (!$invoice) {
            return;
        }
        if ((int) $invoice->customer_id !== (int) $this->input('customer_id')) {
            $validator->errors()->add('invoice_id', 'فاکتور انتخاب‌شده متعلق به این مشتری نیست.');
        }
        if ($invoice->status !== Invoice::STATUS_SHIPPED && !$this->user()?->hasPermission('sales_returns.override_invoice_status')) {
            $validator->errors()->add('invoice_id', 'برای کاربر عادی فقط فاکتور ارسال‌شده قابل برگشت است.');
        }

        $seen = [];
        foreach ($this->input('items', []) as $index => $item) {
            $id = (int) ($item['invoice_item_id'] ?? 0);
            if (isset($seen[$id])) {
                $validator->errors()->add("items.{$index}.invoice_item_id", 'هر ردیف فاکتور فقط یک بار مجاز است.');
            }
            $seen[$id] = true;
            if ($id > 0 && !$invoice->items->contains('id', $id)) {
                $validator->errors()->add("items.{$index}.invoice_item_id", 'ردیف انتخاب‌شده متعلق به این فاکتور نیست.');
            }
        }

        if ($validator->errors()->isEmpty()) {
            try {
                app(SalesReturnCalculationService::class)->calculateInternalInvoicePreview($invoice, $this->input('items', []));
            } catch (\Illuminate\Validation\ValidationException $e) {
                foreach ($e->errors() as $key => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($key, $message);
                    }
                }
            }
        }
    }

    protected function validateSazeh(Validator $validator): void
    {
        foreach ($this->input('items', []) as $index => $item) {
            $hasExisting = !empty($item['product_id']) || !empty($item['product_variant_id']);
            $hasPayload = !empty($item['new_product_payload']);
            if (!$hasExisting && !$hasPayload) {
                $validator->errors()->add("items.{$index}.product_id", 'برای هر ردیف باید کالای موجود یا Payload کالای جدید انتخاب شود.');
            }
            if ($hasPayload && !$this->user()?->hasPermission('sales_returns.create_product')) {
                $validator->errors()->add("items.{$index}.new_product_payload", 'شما دسترسی تعریف کالای جدید در برگشت از فروش را ندارید.');
            }
            if ($hasPayload) {
                foreach ([
                    'product_name' => 'نام محصول جدید الزامی است.',
                    'variant_name' => 'نام تنوع جدید الزامی است.',
                    'category_id' => 'دسته‌بندی کالای جدید الزامی است.',
                    'purchase_price' => 'قیمت خرید کالای جدید الزامی است.',
                    'sell_price' => 'قیمت فروش کالای جدید الزامی است.',
                ] as $field => $message) {
                    if (!array_key_exists($field, $item['new_product_payload']) || $item['new_product_payload'][$field] === '' || $item['new_product_payload'][$field] === null) {
                        $validator->errors()->add("items.{$index}.new_product_payload.{$field}", $message);
                    }
                }
            }
            if ($hasExisting && $hasPayload) {
                $validator->errors()->add("items.{$index}.new_product_payload", 'کالا نمی‌تواند هم موجود و هم جدید باشد.');
            }
            if ($hasExisting && (array_key_exists('purchase_price', $item) || array_key_exists('sell_price', $item))) {
                $validator->errors()->add("items.{$index}.purchase_price", 'تغییر قیمت کالای موجود از سند برگشت مجاز نیست.');
            }
        }
    }
}
