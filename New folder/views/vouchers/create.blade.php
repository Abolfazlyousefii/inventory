@extends('layouts.app')

@php
    $isEdit = isset($voucher) && $voucher;
    $formAction = $isEdit ? route('vouchers.update', $voucher) : route('vouchers.store');
    $initialItems = old('items', $isEdit
        ? $voucher->items->map(fn($it) => [
            'category_id' => $it->product?->category_id,
            'product_id' => $it->product_id,
            'variant_id' => $it->product_variant_id,
            'invoice_item_id' => $it->invoice_item_id,
            'quantity' => $it->quantity,
            'personnel_asset_code' => $it->personnel_asset_code,
        ])->values()->all()
        : []);

    $categoriesJson = $categories->map(fn($c) => ['id' => $c->id, 'name' => $c->name, 'parent_id' => $c->parent_id])->values();
    $productsJson = $products->map(fn($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'category_id' => $p->category_id])->values();
    $variantsJson = ($variants ?? collect())->map(fn($v) => [
        'id' => $v->id,
        'product_id' => $v->product_id,
        'name' => $v->variant_name,
        'code' => $v->variant_code,
        'variety_code' => $v->variety_code,
        'model_name' => $v->model_name,
        'is_active' => (bool) $v->is_active,
    ])->values();
    $invoiceOptions = ($invoices ?? collect())->map(fn($inv) => ['uuid' => $inv->uuid, 'label' => $inv->uuid . ' | ' . ($inv->customer_name ?: 'بدون نام')])->values();

    $voucherTypes = \App\Models\WarehouseTransfer::typeOptions();
    $returnReasonOptions = \App\Models\WarehouseTransfer::returnReasonOptions();
    $regularWarehouses = $warehouses->where('type', '!=', 'personnel')->values();
    $personnelWarehouses = $warehouses->where('type', 'personnel')->whereNotNull('parent_id')->values();
@endphp

@section('content')
<div class="purchase-page-wrap">
<div class="purchase-topbar d-flex justify-content-between align-items-center mb-3">
    <h4 class="page-title mb-0">{{ $isEdit ? 'ویرایش سند حواله' : 'ثبت سند حواله' }}</h4>
    <a class="btn btn-sm btn-outline-light" href="{{ $sectionSlug ? route('vouchers.section.index', $sectionSlug) : route('vouchers.index') }}">بازگشت</a>
</div>

<div class="card purchase-form">
    <div class="card-body">
        <form method="POST" action="{{ $formAction }}">
            @csrf
            @if($isEdit) @method('PUT') @endif

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">نوع حواله</label>
                    @if($fixedVoucherType)
                        <input type="hidden" name="voucher_type" value="{{ $fixedVoucherType }}">
                    @endif
                    <select name="voucher_type" class="form-select" id="voucherType" required @disabled($fixedVoucherType)>
                        @foreach($voucherTypes as $typeKey => $typeLabel)
                            <option value="{{ $typeKey }}" @selected(old('voucher_type', $fixedVoucherType ?? $voucher->voucher_type ?? 'between_warehouses') === $typeKey)>{{ $typeLabel }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4" id="fromWarehouseWrap">
                    <label class="form-label">انبار مبدا</label>
                    <select name="from_warehouse_id" class="form-select" id="fromWarehouse" required>
                        <option value="">انتخاب کنید...</option>
                        @foreach($regularWarehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" data-type="{{ $warehouse->type }}" @selected(old('from_warehouse_id', $voucher->from_warehouse_id ?? null)==$warehouse->id)>{{ $warehouse->name }}</option>
                        @endforeach
                        @foreach($personnelWarehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" data-type="{{ $warehouse->type }}" @selected(old('from_warehouse_id', $voucher->from_warehouse_id ?? null)==$warehouse->id)>{{ $warehouse->name }}</option>
                        @endforeach
                    </select>
                    <div class="small text-muted mt-1" id="fromWarehouseHint"></div>
                </div>

                <div class="col-md-4" id="toWarehouseWrap">
                    <label class="form-label">انبار مقصد</label>
                    <select name="to_warehouse_id" class="form-select" id="toWarehouse" required>
                        <option value="">انتخاب کنید...</option>
                        @foreach($regularWarehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" data-type="{{ $warehouse->type }}" @selected(old('to_warehouse_id', $voucher->to_warehouse_id ?? null)==$warehouse->id)>{{ $warehouse->name }}</option>
                        @endforeach
                        @foreach($personnelWarehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" data-type="{{ $warehouse->type }}" @selected(old('to_warehouse_id', $voucher->to_warehouse_id ?? null)==$warehouse->id)>{{ $warehouse->name }}</option>
                        @endforeach
                    </select>
                    <div class="small text-muted mt-1" id="toWarehouseHint"></div>
                </div>

                <div class="col-md-4" id="invoiceWrap">
                    <label class="form-label">فاکتور مرجع (مرجوعی مشتری)</label>
                    <select name="related_invoice_uuid" class="form-select" id="relatedInvoiceSelect">
                        <option value="">انتخاب کنید...</option>
                        @foreach($invoiceOptions as $invoiceOption)
                            <option value="{{ $invoiceOption['uuid'] }}" @selected(old('related_invoice_uuid', $voucher->relatedInvoice?->uuid ?? null) === $invoiceOption['uuid'])>{{ $invoiceOption['label'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4" id="beneficiaryWrap">
                    <label class="form-label">نام تحویل‌گیرنده / ذی‌نفع</label>
                    <input name="beneficiary_name" class="form-control" value="{{ old('beneficiary_name', $voucher->beneficiary_name ?? null) }}" placeholder="برای پرسنل / شوروم">
                </div>

                <div class="col-md-4" id="returnReasonWrap">
                    <label class="form-label">علت برگشت از فروش</label>
                    <select name="return_reason" class="form-select" id="returnReasonSelect">
                        <option value="">انتخاب کنید...</option>
                        @foreach($returnReasonOptions as $reasonKey => $reasonTitle)
                            <option value="{{ $reasonKey }}" @selected(old('return_reason', $voucher->return_reason ?? null) === $reasonKey)>{{ $reasonTitle }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">شماره حواله (اختیاری)</label>
                    <input name="reference" class="form-control" value="{{ old('reference', $voucher->reference ?? null) }}" placeholder="مثلاً 123">
                </div>


                <div class="col-12">
                    <label class="form-label">توضیحات (اختیاری)</label>
                    <input name="note" class="form-control" value="{{ old('note', $voucher->note ?? null) }}">
                </div>

                <div class="col-12">
                    <div class="table-responsive">
                        <table class="table align-middle" id="itemsTable">
                            <thead>
                                <tr>
                                    <th>سردسته</th>
                                    <th>دسته‌بندی</th>
                                    <th>کالا</th>
                                    <th>نوع / طرح دقیق کالا</th>
                                    <th>تعداد</th>
                                    <th class="asset-col" style="display:none;">کد اموال ۴ رقمی</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="addItemBtn">+ افزودن ردیف</button>
                </div>

                <div class="col-12">
                    <button class="btn btn-primary">{{ $isEdit ? 'ذخیره تغییرات سند' : 'ثبت سند حواله' }}</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
const categories = @json($categoriesJson);
const products = @json($productsJson);
const variants = @json($variantsJson);
const initialItems = @json($initialItems);
const centralWarehouseId = {{ (int) $centralWarehouseId }};
const invoiceProductsApi = "{{ url('/vouchers/invoice') }}";

const tbody = document.querySelector('#itemsTable tbody');
const addBtn = document.getElementById('addItemBtn');
const fromWarehouse = document.getElementById('fromWarehouse');
const toWarehouse = document.getElementById('toWarehouse');
const voucherTypeSelect = document.getElementById('voucherType');
const relatedInvoiceSelect = document.getElementById('relatedInvoiceSelect');
const fromWarehouseWrap = document.getElementById('fromWarehouseWrap');
const toWarehouseWrap = document.getElementById('toWarehouseWrap');
const invoiceWrap = document.getElementById('invoiceWrap');
const beneficiaryWrap = document.getElementById('beneficiaryWrap');
const returnReasonWrap = document.getElementById('returnReasonWrap');
const returnReasonSelect = document.getElementById('returnReasonSelect');
const fromWarehouseHint = document.getElementById('fromWarehouseHint');
const toWarehouseHint = document.getElementById('toWarehouseHint');

let allowedReturnProducts = [];

function rootCategoryOptions(selectedId = '') {
    const roots = categories.filter((c) => !c.parent_id);
    return `<option value="">انتخاب...</option>${roots.map((c) => `<option value="${c.id}" ${String(selectedId) === String(c.id) ? 'selected' : ''}>${c.name}</option>`).join('')}`;
}
function childCategoryOptions(parentId, selectedId = '') {
    const children = categories.filter((c) => String(c.parent_id || '') === String(parentId || ''));
    return `<option value="">انتخاب...</option>${children.map((c) => `<option value="${c.id}" ${String(selectedId) === String(c.id) ? 'selected' : ''}>${c.name}</option>`).join('')}`;
}
function resolveParentCategoryId(categoryId) {
    const current = categories.find((c) => String(c.id) === String(categoryId || ''));
    return current ? (current.parent_id || current.id) : '';
}

function productOptions(categoryId, selectedProductId) {
    const isReturn = voucherTypeSelect.value === 'customer_return';
    const filtered = products.filter((p) => {
        if (String(p.category_id) !== String(categoryId || '')) return false;
        if (!isReturn) return true;
        return String(selectedProductId || '') === String(p.id)
            || allowedReturnProducts.some((x) => String(x.product_id) === String(p.id));
    });
    return [`<option value="">انتخاب...</option>`]
        .concat(filtered.map(p => `<option value="${p.id}" ${String(selectedProductId || '') === String(p.id) ? 'selected' : ''}>${p.name} (${p.sku ?? ''})</option>`))
        .join('');
}

function variantLabel(v) {
    const parts = [v.model_name, v.name, v.variety_code ? `طرح ${v.variety_code}` : '', v.code ? `کد ${v.code}` : ''].filter(Boolean);
    return parts.length ? parts.join(' - ') : `تنوع #${v.id}`;
}

function variantOptions(productId, selectedVariantId) {
    const isReturn = voucherTypeSelect.value === 'customer_return';
    const allowedVariantIds = allowedReturnProducts
        .filter((x) => String(x.product_id) === String(productId || ''))
        .map((x) => String(x.variant_id));
    const filtered = variants.filter((v) => {
        if (String(v.product_id) !== String(productId || '')) return false;
        if (String(selectedVariantId || '') === String(v.id)) return true;
        if (isReturn) return allowedVariantIds.includes(String(v.id));
        return v.is_active;
    });

    return [`<option value="">انتخاب نوع/طرح...</option>`]
        .concat(filtered.map(v => `<option value="${v.id}" ${String(selectedVariantId || '') === String(v.id) ? 'selected' : ''}>${variantLabel(v)}</option>`))
        .join('');
}

function rowTemplate(i, item = null) {
    const selectedCategoryId = item?.category_id || '';
    const selectedParentId = resolveParentCategoryId(selectedCategoryId);
    const selectedProductId = item?.product_id || '';
    const selectedVariantId = item?.variant_id || '';

    return `<tr>
        <td><select class="form-select root-category-select" data-row="${i}" required>${rootCategoryOptions(selectedParentId)}</select></td>
        <td><select name="items[${i}][category_id]" class="form-select category-select" data-row="${i}" required>${childCategoryOptions(selectedParentId, selectedCategoryId)}</select></td>
        <td><select name="items[${i}][product_id]" class="form-select product-select" required>${productOptions(selectedCategoryId, selectedProductId)}</select></td>
        <td>
            <input type="hidden" name="items[${i}][invoice_item_id]" value="${item?.invoice_item_id || ''}">
            <select name="items[${i}][variant_id]" class="form-select variant-select" required>${variantOptions(selectedProductId, selectedVariantId)}</select>
        </td>
        <td><input name="items[${i}][quantity]" type="number" min="1" class="form-control" value="${item?.quantity || 1}" required></td>
        <td class="asset-col" style="display:none;"><input name="items[${i}][personnel_asset_code]" class="form-control" pattern="\\d{4}" maxlength="4" value="${item?.personnel_asset_code || ''}"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()">حذف</button></td>
    </tr>`;
}

function bindCategoryEvents() {
    tbody.querySelectorAll('.root-category-select').forEach((select) => {
        if (select.dataset.bound === '1') return;
        select.dataset.bound = '1';
        select.addEventListener('change', (e) => {
            const tr = e.target.closest('tr');
            const categorySelect = tr.querySelector('.category-select');
            const productSelect = tr.querySelector('.product-select');
            categorySelect.innerHTML = childCategoryOptions(e.target.value, '');
            productSelect.innerHTML = productOptions('', null);
            tr.querySelector('.variant-select').innerHTML = variantOptions('', null);
        });
    });

    tbody.querySelectorAll('.category-select').forEach((select) => {
        if (select.dataset.bound === '1') return;
        select.dataset.bound = '1';
        select.addEventListener('change', (e) => {
            const tr = e.target.closest('tr');
            tr.querySelector('.product-select').innerHTML = productOptions(e.target.value, null);
            tr.querySelector('.variant-select').innerHTML = variantOptions('', null);
        });
    });

    tbody.querySelectorAll('.product-select').forEach((select) => {
        if (select.dataset.bound === '1') return;
        select.dataset.bound = '1';
        select.addEventListener('change', (e) => {
            const tr = e.target.closest('tr');
            tr.querySelector('.variant-select').innerHTML = variantOptions(e.target.value, null);
        });
    });
}

function addRow(item = null) {
    tbody.insertAdjacentHTML('beforeend', rowTemplate(tbody.querySelectorAll('tr').length, item));
    bindCategoryEvents();
    toggleAssetCode();
}

function toggleAssetCode() {
    const selected = toWarehouse.selectedOptions[0];
    const isPersonnelDestination = selected && selected.dataset.type === 'personnel';
    document.querySelectorAll('.asset-col').forEach(el => el.style.display = isPersonnelDestination ? '' : 'none');
}

function filterWarehouseOptions(mode) {
    [...fromWarehouse.options].forEach((opt) => {
        if (!opt.value) return;
        const isPersonnel = opt.dataset.type === 'personnel';
        opt.hidden = mode === 'non_personnel' ? isPersonnel : !isPersonnel;
    });
    [...toWarehouse.options].forEach((opt) => {
        if (!opt.value) return;
        const isPersonnel = opt.dataset.type === 'personnel';
        opt.hidden = mode === 'personnel_only' ? !isPersonnel : (mode === 'non_personnel' ? isPersonnel : false);
    });
}

function applyVoucherTypeRules() {
    const type = voucherTypeSelect.value;
    invoiceWrap.style.display = type === 'customer_return' ? '' : 'none';
    relatedInvoiceSelect.required = type === 'customer_return';
    beneficiaryWrap.style.display = (type === 'personnel_asset' || type === 'showroom') ? '' : 'none';
    returnReasonWrap.style.display = type === 'customer_return' ? '' : 'none';
    returnReasonSelect.required = type === 'customer_return';
    if (type !== 'customer_return') returnReasonSelect.value = '';

    if (type === 'personnel_asset') {
        filterWarehouseOptions('personnel_only');
        fromWarehouseHint.textContent = 'برای حواله پرسنل، مبدا از انبارهای عادی و مقصد فقط پرسنل است.';
        toWarehouseHint.textContent = 'فقط پرسنل تعریف‌شده قابل انتخاب است.';
        fromWarehouseWrap.style.display = '';
        toWarehouseWrap.style.display = '';
    } else if (type === 'between_warehouses' || type === 'showroom') {
        filterWarehouseOptions('non_personnel');
        fromWarehouseHint.textContent = 'در این حالت، فقط انبارهای عادی نمایش داده می‌شود.';
        toWarehouseHint.textContent = 'پرسنل در مقصد نمایش داده نمی‌شود.';
        fromWarehouseWrap.style.display = '';
        toWarehouseWrap.style.display = '';
    } else if (type === 'scrap') {
        filterWarehouseOptions('non_personnel');
        fromWarehouse.value = String(centralWarehouseId);
        fromWarehouse.readOnly = true;
        fromWarehouseHint.textContent = 'حواله ضایعات فقط از انبار مرکزی ثبت می‌شود.';
        toWarehouseWrap.style.display = 'none';
        toWarehouse.value = String(centralWarehouseId);
    } else if (type === 'customer_return') {
        filterWarehouseOptions('non_personnel');
        fromWarehouse.value = String(centralWarehouseId);
        fromWarehouseHint.textContent = 'مرجوعی مشتری به انبار مقصد شما برمی‌گردد.';
        fromWarehouseWrap.style.display = 'none';
        toWarehouseWrap.style.display = '';
    } else {
        fromWarehouseWrap.style.display = '';
        toWarehouseWrap.style.display = '';
        filterWarehouseOptions('non_personnel');
    }

    rerenderProductSelects();
}

function rerenderProductSelects() {
    tbody.querySelectorAll('tr').forEach((tr) => {
        const categorySelect = tr.querySelector('.category-select');
        const productSelect = tr.querySelector('.product-select');
        const selected = productSelect.value;
        const variantSelect = tr.querySelector('.variant-select');
        const selectedVariant = variantSelect.value;
        productSelect.innerHTML = productOptions(categorySelect.value, selected);
        variantSelect.innerHTML = variantOptions(productSelect.value, selectedVariant);
    });
}

async function loadInvoiceProducts() {
    allowedReturnProducts = [];
    if (!relatedInvoiceSelect.value) {
        rerenderProductSelects();
        return;
    }

    const res = await fetch(`${invoiceProductsApi}/${relatedInvoiceSelect.value}/products`);
    if (!res.ok) return;
    const payload = await res.json();
    allowedReturnProducts = payload.products || [];

    if (voucherTypeSelect.value === 'customer_return' && tbody.querySelectorAll('tr').length === 0) {
        addRow();
    }

    rerenderProductSelects();
}

addBtn.addEventListener('click', () => addRow());
toWarehouse.addEventListener('change', toggleAssetCode);
voucherTypeSelect.addEventListener('change', applyVoucherTypeRules);
relatedInvoiceSelect.addEventListener('change', loadInvoiceProducts);

if (initialItems.length) initialItems.forEach(item => addRow(item)); else addRow();
applyVoucherTypeRules();
loadInvoiceProducts();
</script>
</div>
@endsection
