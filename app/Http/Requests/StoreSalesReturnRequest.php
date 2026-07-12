<?php
namespace App\Http\Requests;

use App\Models\{Invoice,InvoiceItem,ProductVariant,SalesReturnDocument,SalesReturnDocumentItem};
use App\Services\SalesReturnCalculationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalesReturnRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'source_type' => ['required', Rule::in([SalesReturnDocument::SOURCE_INTERNAL_INVOICE, SalesReturnDocument::SOURCE_SAZEH_HESAB])],
            'customer_id' => ['required', 'exists:customers,id'],
            'invoice_id' => ['required_if:source_type,'.SalesReturnDocument::SOURCE_INTERNAL_INVOICE, 'nullable', 'exists:invoices,id'],
            'external_invoice_number' => ['required_if:source_type,'.SalesReturnDocument::SOURCE_SAZEH_HESAB, 'nullable', 'string', 'max:100'],
            'external_invoice_date' => ['required_if:source_type,'.SalesReturnDocument::SOURCE_SAZEH_HESAB, 'nullable', 'date'],
            'default_destination_warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'return_reason' => ['required', Rule::in(array_keys(SalesReturnDocument::returnReasonLabels()))],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'description' => ['required_if:return_reason,other', 'nullable', 'string'],
            'action' => ['nullable', Rule::in(['draft', 'apply'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.invoice_item_id' => ['nullable', 'exists:invoice_items,id'],
            'items.*.item_source' => ['required', Rule::in([SalesReturnDocumentItem::SOURCE_INVOICE_ITEM, SalesReturnDocumentItem::SOURCE_EXISTING_PRODUCT, SalesReturnDocumentItem::SOURCE_NEW_PRODUCT])],
            'items.*.product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.return_quantity' => ['required', 'integer', 'min:1'],
            'items.*.item_condition' => ['required', Rule::in([SalesReturnDocumentItem::CONDITION_HEALTHY, SalesReturnDocumentItem::CONDITION_DAMAGED])],
            'items.*.destination_warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'items.*.refund_unit_price' => ['nullable', 'integer', 'min:0'],
            'items.*.purchase_price' => ['nullable', 'integer', 'min:0'],
            'items.*.sell_price' => ['nullable', 'integer', 'min:0'],
            'items.*.new_product_payload' => ['nullable', 'array'],
            'items.*.new_product_payload.product_name' => ['nullable', 'string', 'max:255'],
            'items.*.new_product_payload.category_id' => ['nullable', 'exists:categories,id'],
            'items.*.new_product_payload.model_list_id' => ['nullable', 'exists:model_lists,id'],
            'items.*.new_product_payload.variant_name' => ['nullable', 'string', 'max:255'],
            'items.*.new_product_payload.variety_name' => ['nullable', 'string', 'max:120'],
            'items.*.new_product_payload.variety_code' => ['nullable', 'digits:4'],
            'items.*.new_product_payload.sku' => ['nullable', 'string', 'max:150'],
            'items.*.new_product_payload.barcode' => ['nullable', 'string', 'max:150'],
            'items.*.new_product_payload.purchase_price' => ['nullable', 'integer', 'min:0'],
            'items.*.new_product_payload.sell_price' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $source = $this->input('source_type');
            $items = collect($this->input('items', []));

            if ($source === SalesReturnDocument::SOURCE_INTERNAL_INVOICE) {
                $invoice = Invoice::find((int) $this->input('invoice_id'));
                if (! $invoice) return;
                if ((int) $invoice->customer_id !== (int) $this->input('customer_id')) $validator->errors()->add('invoice_id', 'فاکتور متعلق به مشتری انتخاب‌شده نیست.');
                $canOverride = $this->user()?->can('sales_returns.override_invoice_status') ?? false;
                if (! $canOverride && $invoice->status !== Invoice::STATUS_SHIPPED) $validator->errors()->add('invoice_id', 'فقط فاکتور ارسال‌شده مجاز است.');
                if (in_array($invoice->status, [Invoice::STATUS_NOT_SHIPPED, 'draft', 'cancelled', 'canceled'], true)) $validator->errors()->add('invoice_id', 'فاکتور لغوشده یا پیش‌نویس قابل برگشت نیست.');
                $ids = [];
                foreach ($items as $idx => $row) {
                    $id = (int) ($row['invoice_item_id'] ?? 0);
                    if ($id <= 0) { $validator->errors()->add("items.$idx.invoice_item_id", 'آیتم فاکتور الزامی است.'); continue; }
                    if (isset($ids[$id])) $validator->errors()->add("items.$idx.invoice_item_id", 'آیتم فاکتور تکراری است.');
                    $ids[$id] = true;
                    if (! InvoiceItem::where('invoice_id', $invoice->id)->whereKey($id)->exists()) $validator->errors()->add("items.$idx.invoice_item_id", 'آیتم متعلق به این فاکتور نیست.');
                }
                $preview = app(SalesReturnCalculationService::class)->calculateInternalPreview($invoice, $items->all());
                $byId = collect($preview)->keyBy(fn ($row) => (int) $row['invoice_item']->id);
                foreach ($items as $idx => $row) {
                    $id = (int) ($row['invoice_item_id'] ?? 0); $qty = (int) ($row['return_quantity'] ?? 0);
                    $p = $byId->get($id);
                    if ($p && $qty > (int) $p['returnable_quantity']) $validator->errors()->add("items.$idx.return_quantity", 'تعداد برگشتی بیشتر از قابل برگشت است.');
                }
            }

            if ($source === SalesReturnDocument::SOURCE_SAZEH_HESAB) {
                foreach ($items as $idx => $row) {
                    $src = $row['item_source'] ?? null;
                    if ($src === SalesReturnDocumentItem::SOURCE_INVOICE_ITEM) $validator->errors()->add("items.$idx.item_source", 'در سازه‌حساب آیتم فاکتور داخلی مجاز نیست.');
                    if ((int) ($row['refund_unit_price'] ?? 0) < 1) $validator->errors()->add("items.$idx.refund_unit_price", 'قیمت برگشتی مشتری الزامی است.');
                    if ($src === SalesReturnDocumentItem::SOURCE_EXISTING_PRODUCT && empty($row['product_variant_id'])) $validator->errors()->add("items.$idx.product_variant_id", 'تنوع کالای موجود الزامی است.');
                    if ($src === SalesReturnDocumentItem::SOURCE_NEW_PRODUCT) {
                        if (! ($this->user()?->can('sales_returns.create_product') ?? false)) $validator->errors()->add("items.$idx.new_product_payload", 'مجوز تعریف کالای جدید را ندارید.');
                        $p = $row['new_product_payload'] ?? [];
                        foreach (['product_name','category_id','variant_name','purchase_price','sell_price'] as $field) if (blank($p[$field] ?? null)) $validator->errors()->add("items.$idx.new_product_payload.$field", 'این فیلد الزامی است.');
                        foreach (['sku' => 'variant_code', 'barcode' => 'variant_code'] as $field => $column) if (filled($p[$field] ?? null) && ProductVariant::where($column, $p[$field])->exists()) $validator->errors()->add("items.$idx.new_product_payload.$field", 'کد یا بارکد تکراری است.');
                    }
                }
            }
        });
    }
}
