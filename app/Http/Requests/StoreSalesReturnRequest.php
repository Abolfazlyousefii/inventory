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
            'default_destination_warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('is_active', true)->whereIn('type', ['central', 'return']))],
            'return_reason' => ['required', Rule::in(array_keys(SalesReturnDocument::returnReasonLabels()))],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'description' => ['required_if:return_reason,other', 'nullable', 'string'],
            'action' => ['nullable', Rule::in(['draft', 'apply'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.invoice_item_id' => ['nullable', 'exists:invoice_items,id'],
            'items.*.item_source' => ['required', Rule::in([SalesReturnDocumentItem::SOURCE_INVOICE_ITEM, SalesReturnDocumentItem::SOURCE_EXISTING_PRODUCT, SalesReturnDocumentItem::SOURCE_NEW_PRODUCT])],
            'items.*.product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.return_quantity' => ['required', 'integer', 'min:1'],
            'items.*.item_condition' => ['nullable', Rule::in([SalesReturnDocumentItem::CONDITION_HEALTHY, SalesReturnDocumentItem::CONDITION_DAMAGED])],
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

            'items.*.new_product_payload.schema_version' => ['nullable', 'integer'],
            'items.*.new_product_payload.temporary_product_uuid' => ['nullable', 'uuid'],
            'items.*.new_product_payload.name' => ['nullable', 'string', 'max:255'],
            'items.*.new_product_payload.is_sellable' => ['nullable', 'boolean'],
            'items.*.new_product_payload.unit' => ['nullable', 'string', 'max:50'],
            'items.*.new_product_payload.use_models' => ['nullable', 'boolean'],
            'items.*.new_product_payload.model_brand_group' => ['nullable', 'string', 'max:120'],
            'items.*.new_product_payload.model_list_ids' => ['nullable', 'array'],
            'items.*.new_product_payload.model_list_ids.*' => ['integer', 'exists:model_lists,id'],
            'items.*.new_product_payload.use_designs' => ['nullable', 'boolean'],
            'items.*.new_product_payload.designs' => ['nullable', 'array'],
            'items.*.new_product_payload.designs.*.index' => ['required_with:items.*.new_product_payload.designs', 'integer', 'min:1', 'max:99'],
            'items.*.new_product_payload.designs.*.name' => ['required_with:items.*.new_product_payload.designs', 'string', 'max:120'],
            'items.*.new_product_payload.refund_unit_price_default' => ['nullable', 'integer', 'min:0'],
            'items.*.new_product_payload.selected_variants' => ['nullable', 'array', 'min:1'],
            'items.*.new_product_payload.selected_variants.*.temporary_variant_uuid' => ['nullable', 'uuid'],
            'items.*.new_product_payload.selected_variants.*.model_list_id' => ['nullable', 'exists:model_lists,id'],
            'items.*.new_product_payload.selected_variants.*.design_index' => ['nullable', 'integer', 'min:0', 'max:99'],
            'items.*.new_product_payload.selected_variants.*.display_name' => ['nullable', 'string', 'max:255'],
            'items.*.new_product_payload.selected_variants.*.preview_code' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $source = $this->input('source_type');
            $items = collect($this->input('items', []));
            foreach ($items as $idx => $row) {
                if (array_key_exists('destination_warehouse_id', (array) $row)) {
                    $validator->errors()->add("items.$idx.destination_warehouse_id", 'مقصد انبار فقط در سطح سند قابل انتخاب است.');
                }
            }

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
                        foreach ([($p['schema_version'] ?? null) == 2 ? 'name' : 'product_name','category_id','purchase_price','sell_price'] as $field) if (blank($p[$field] ?? null)) $validator->errors()->add("items.$idx.new_product_payload.$field", 'این فیلد الزامی است.');
                        if (($p['schema_version'] ?? null) == 2) {
                            if (!empty($p['use_models']) && empty($p['model_list_ids'])) $validator->errors()->add("items.$idx.new_product_payload.model_list_ids", 'حداقل یک مدل انتخاب کنید.');
                            if (!empty($p['use_models']) && filled($p['model_brand_group'] ?? null)) {
                                $badBrand = \App\Models\ModelList::whereIn('id', $p['model_list_ids'] ?? [])->where('brand', '<>', $p['model_brand_group'])->exists();
                                if ($badBrand) $validator->errors()->add("items.$idx.new_product_payload.model_brand_group", 'مدل‌ها متعلق به برند انتخاب‌شده نیستند.');
                            }
                            if (!empty($p['use_designs']) && empty($p['designs'])) $validator->errors()->add("items.$idx.new_product_payload.designs", 'طرح‌ها کامل نیستند.');
                            if (empty($p['selected_variants'])) $validator->errors()->add("items.$idx.new_product_payload.selected_variants", 'حداقل یک تنوع انتخاب کنید.');
                        } else {
                            if (blank($p['variant_name'] ?? null)) $validator->errors()->add("items.$idx.new_product_payload.variant_name", 'این فیلد الزامی است.');
                        }
                        foreach (['sku' => 'variant_code', 'barcode' => 'variant_code'] as $field => $column) if (filled($p[$field] ?? null) && ProductVariant::where($column, $p[$field])->exists()) $validator->errors()->add("items.$idx.new_product_payload.$field", 'کد یا بارکد تکراری است.');
                    }
                }
            }
        });
    }
}
