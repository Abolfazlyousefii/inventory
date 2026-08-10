@extends('layouts.app')

@php
    $productsJson = collect($products ?? [])->map(fn($p) => [
        'id' => (int) $p->id,
        'name' => (string) $p->name,
        'code' => (string) ($p->code ?? $p->sku ?? ''),
        'category_id' => (int) ($p->category_id ?? 0),
        'stock' => (int) ($p->stock ?? 0),
    ])->values();

    $variantsJson = collect($variants ?? [])->map(fn($v) => [
        'id' => (int) $v->id,
        'product_id' => (int) $v->product_id,
        'name' => (string) ($v->variant_name ?? ''),
        'code' => (string) ($v->variant_code ?? ''),
        'stock' => (int) ($v->stock ?? 0),
        'reserved' => (int) ($v->reserved ?? 0),
    ])->values();

    $fromWarehousesCollection = collect($fromWarehouses ?? []);
    $scrapWarehouse = $scrapWarehouse ?? null;

    $oldItems = old('items', []);
    if (empty($oldItems)) {
        $oldItems = [[
            'product_id' => '',
            'variant_id' => '',
            'quantity' => 1,
        ]];
    }
@endphp

@section('content')
<style>
    :root{
        --brd:#e8edf3;
        --soft:#f8fafc;
        --soft2:#f3f6fb;
        --text:#0f172a;
        --muted:#64748b;
        --blue:#2563eb;
        --blue-soft:#eff6ff;
        --green-soft:#ecfdf5;
        --danger:#dc2626;
        --danger-soft:#fef2f2;
        --shadow:0 14px 34px rgba(15,23,42,.06);
    }

    .scrap-page-wrap{ padding: 8px 0 24px; }

    .hero-box{
        border:1px solid var(--brd);
        border-radius:24px;
        background:linear-gradient(135deg,#ffffff,#f8fbff 55%,#eef6ff);
        box-shadow:var(--shadow);
        overflow:hidden;
        margin-bottom:18px;
    }

    .hero-title{
        font-size:28px;
        font-weight:900;
        color:var(--text);
        margin-bottom:6px;
    }

    .hero-sub{
        color:var(--muted);
        font-size:14px;
        line-height:1.9;
        margin-bottom:0;
        max-width:760px;
    }

    .soft-chip{
        display:inline-flex;
        align-items:center;
        gap:8px;
        padding:8px 12px;
        border-radius:999px;
        border:1px solid var(--brd);
        background:#fff;
        font-size:12px;
        font-weight:700;
        color:var(--text);
    }

    .main-card{
        border:none;
        border-radius:22px;
        box-shadow:var(--shadow);
        overflow:hidden;
        background:#fff;
    }

    .section-head{
        padding:14px 18px;
        border-bottom:1px solid var(--brd);
        background:linear-gradient(0deg,#fff,var(--soft2));
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        flex-wrap:wrap;
    }

    .section-title{
        font-size:15px;
        font-weight:900;
        color:var(--text);
        margin:0;
    }

    .section-sub{
        color:var(--muted);
        font-size:12px;
        margin:4px 0 0;
    }

    .info-card{
        border:1px dashed #dbeafe;
        background:#f8fbff;
        border-radius:16px;
        padding:14px;
    }

    .mini-stat{
        border:1px solid var(--brd);
        border-radius:16px;
        background:#fff;
        padding:14px;
        height:100%;
    }

    .mini-stat .label{
        font-size:12px;
        color:var(--muted);
        margin-bottom:8px;
    }

    .mini-stat .value{
        font-size:24px;
        font-weight:900;
        color:var(--text);
        line-height:1.2;
    }

    .items-wrap{
        display:flex;
        flex-direction:column;
        gap:12px;
    }

    .item-card{
        border:1px solid var(--brd);
        border-radius:18px;
        background:#fff;
        padding:14px;
    }

    .item-head{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        flex-wrap:wrap;
        margin-bottom:12px;
    }

    .item-no{
        display:inline-flex;
        align-items:center;
        justify-content:center;
        min-width:36px;
        height:36px;
        border-radius:999px;
        background:var(--blue-soft);
        color:var(--blue);
        border:1px solid #dbeafe;
        font-weight:900;
        font-size:13px;
    }

    .search-box{
        border:1px solid var(--brd);
        border-radius:12px;
        background:#fff;
    }

    .stock-pill{
        display:inline-flex;
        align-items:center;
        gap:6px;
        padding:6px 10px;
        border-radius:999px;
        background:var(--green-soft);
        color:#166534;
        border:1px solid #bbf7d0;
        font-size:12px;
        font-weight:800;
    }

    .stock-pill.bad{
        background:var(--danger-soft);
        color:#991b1b;
        border-color:#fecaca;
    }

    .qty-box{
        display:flex;
        align-items:center;
        gap:8px;
    }

    .qty-btn{
        width:38px;
        height:38px;
        border:none;
        border-radius:12px;
        background:#f1f5f9;
        color:#0f172a;
        font-size:20px;
        font-weight:900;
        line-height:1;
        cursor:pointer;
        transition:.15s ease;
    }

    .qty-btn:hover{
        background:#e2e8f0;
    }

    .qty-btn:disabled{
        opacity:.5;
        cursor:not-allowed;
    }

    .qty-input{
        text-align:center;
        font-weight:800;
    }

    .sticky-actions{
        position:sticky;
        bottom:16px;
        z-index:5;
        background:rgba(255,255,255,.92);
        backdrop-filter:blur(6px);
        border:1px solid var(--brd);
        border-radius:16px;
        padding:12px;
    }

    .small-label{
        font-size:12px;
        color:var(--muted);
        font-weight:800;
        margin-bottom:6px;
    }

    .empty-note{
        border:1px dashed #dbeafe;
        border-radius:14px;
        padding:12px;
        color:var(--muted);
        background:#f8fbff;
        font-size:13px;
    }

    .code-mini{
        font-size:11px;
        color:var(--muted);
        margin-top:6px;
    }
</style>

<div class="container scrap-page-wrap">
    <div class="hero-box">
        <div class="p-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <div class="hero-title">ثبت حواله ضایعات</div>
                    <p class="hero-sub">
                        اول انبار مبدا را انتخاب کن. مقصد همیشه انبار ضایعات است.
                        بعد کالا را با جستجو پیدا کن، تنوع همان کالا را انتخاب کن، موجودی همان تنوع را ببین و تعداد قابل انتقال به ضایعات را ثبت کن.
                    </p>

                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <span class="soft-chip">مبدا انتخابی</span>
                        <span class="soft-chip">مقصد ثابت: {{ $scrapWarehouse?->name ?? 'انبار ضایعات' }}</span>
                        <span class="soft-chip">کنترل موجودی تنوع</span>
                    </div>
                </div>

                <div class="d-flex gap-2 flex-wrap">
                    <a class="btn btn-outline-secondary" href="{{ route('vouchers.section.index', 'scrap') }}">بازگشت</a>
                </div>
            </div>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <div class="fw-bold mb-2">لطفاً خطاهای زیر را بررسی کن:</div>
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card main-card">
        <div class="section-head">
            <div>
                <h6 class="section-title">فرم ثبت حواله ضایعات</h6>
                <div class="section-sub">کالا از انبار مبدا کم می‌شود و به انبار ضایعات منتقل می‌شود.</div>
            </div>
        </div>

        <div class="card-body p-3 p-lg-4">
            <form method="POST" action="{{ route('vouchers.section.store', 'scrap') }}" id="scrapForm">
                @csrf

                <input type="hidden" name="voucher_type" value="scrap">
                <input type="hidden" name="to_warehouse_id" value="{{ $scrapWarehouse?->id }}">

                <div class="row g-3 mb-4">
                    <div class="col-lg-4">
                        <label class="form-label">انبار مبدا</label>
                        <select name="from_warehouse_id" class="form-select" required>
                            <option value="">انتخاب انبار مبدا...</option>
                            @foreach($fromWarehousesCollection as $warehouse)
                                <option value="{{ $warehouse->id }}" @selected((int) old('from_warehouse_id', '') === (int) $warehouse->id)>
                                    {{ $warehouse->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-lg-4">
                        <label class="form-label">انبار مقصد</label>
                        <input class="form-control" value="{{ $scrapWarehouse?->name ?? 'انبار ضایعات' }}" readonly>
                    </div>

                    <div class="col-lg-4">
                        <label class="form-label">شماره حواله (اختیاری)</label>
                        <input name="reference" class="form-control" value="{{ old('reference') }}" placeholder="مثلاً SCR-1405-001">
                    </div>

                    <div class="col-12">
                        <label class="form-label">توضیحات (اختیاری)</label>
                        <input name="note" class="form-control" value="{{ old('note') }}" placeholder="مثلاً شکستگی / معیوب / آسیب‌دیده">
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="mini-stat">
                            <div class="label">تعداد ردیف‌ها</div>
                            <div class="value" id="rowsCount">0</div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="mini-stat">
                            <div class="label">جمع کل تعداد</div>
                            <div class="value" id="qtySum">0</div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="info-card h-100 d-flex align-items-center">
                            <div>
                                <div class="fw-bold mb-1">نکته</div>
                                <div class="text-muted small">
                                    اول کالا را پیدا کن، بعد تنوع را انتخاب کن. تعداد از موجودی همان تنوع بیشتر نمی‌شود.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <div class="fw-bold">کالاهای حواله ضایعات</div>
                        <div class="text-muted small">هر کالا را در یک ردیف ثبت کن.</div>
                    </div>

                    <button type="button" class="btn btn-outline-primary btn-sm" id="addItemBtn">+ افزودن ردیف</button>
                </div>

                <div id="itemsWrap" class="items-wrap"></div>

                <div class="mt-3 empty-note" id="emptyRowsNote" style="display:none;">
                    هنوز هیچ ردیفی اضافه نشده است.
                </div>

                <div class="sticky-actions mt-4">
                    <button class="btn btn-primary w-100" type="submit" id="submitBtn">
                        ثبت حواله ضایعات
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const products = @json($productsJson);
const variants = @json($variantsJson);
const oldItems = @json($oldItems);

const itemsWrap = document.getElementById('itemsWrap');
const addBtn = document.getElementById('addItemBtn');
const rowsCountEl = document.getElementById('rowsCount');
const qtySumEl = document.getElementById('qtySum');
const emptyRowsNote = document.getElementById('emptyRowsNote');
const scrapForm = document.getElementById('scrapForm');

function productOptions(selected = '') {
    return '<option value="">انتخاب کالا...</option>' + products.map(p => {
        const isSelected = String(p.id) === String(selected || '');
        const code = p.code ? ' (' + p.code + ')' : '';
        return `<option value="${p.id}" ${isSelected ? 'selected' : ''}>${p.name}${code}</option>`;
    }).join('');
}

function variantsByProduct(productId) {
    return variants.filter(v => String(v.product_id) === String(productId || ''));
}

function productById(id) {
    return products.find(p => String(p.id) === String(id || '')) || null;
}

function buildVariantOptions(productId, selected = '') {
    if (!productId) {
        return '<option value="">ابتدا کالا را انتخاب کن...</option>';
    }

    const list = variantsByProduct(productId);

    if (!list.length) {
        return '<option value="">برای این کالا تنوعی تعریف نشده است</option>';
    }

    return '<option value="">انتخاب تنوع...</option>' + list.map(v => {
        const available = Math.max(0, Number(v.stock || 0));
        const isSelected = String(v.id) === String(selected || '') ? 'selected' : '';
        const title = v.name || v.code || ('تنوع #' + v.id);
        const code = v.code ? ' [' + v.code + ']' : '';
        return `<option value="${v.id}" ${isSelected}>${title}${code} | موجودی: ${available}</option>`;
    }).join('');
}

function rowTemplate(index, data = {}) {
    return `
        <div class="item-card" data-index="${index}">
            <div class="item-head">
                <div class="d-flex align-items-center gap-2">
                    <span class="item-no row-no">${index + 1}</span>
                    <div>
                        <div class="fw-bold">ردیف کالا</div>
                        <div class="text-muted small">انتخاب کالا، تنوع و تعداد</div>
                    </div>
                </div>

                <button type="button" class="btn btn-sm btn-outline-danger remove-btn">حذف ردیف</button>
            </div>

            <input type="hidden" class="category-hidden" name="items[${index}][category_id]" value="">

            <div class="row g-3 align-items-end">
                <div class="col-lg-5">
                    <label class="form-label small-label">کالا</label>
                    <input type="text" class="form-control search-box product-search mb-2" placeholder="جستجو با نام یا کد کالا...">
                    <select class="form-select product-select" name="items[${index}][product_id]" required>
                        ${productOptions(data.product_id || '')}
                    </select>
                    <div class="code-mini selected-product-code">—</div>
                </div>

                <div class="col-lg-4">
                    <label class="form-label small-label">مدل / طرح / تنوع</label>
                    <select class="form-select variant-select" name="items[${index}][variant_id]" required>
                        ${buildVariantOptions(data.product_id || '', data.variant_id || '')}
                    </select>
                    <div class="mt-2">
                        <span class="stock-pill stock-state">موجودی: 0</span>
                    </div>
                </div>

                <div class="col-lg-3">
                    <label class="form-label small-label">تعداد</label>
                    <div class="qty-box">
                        <button type="button" class="qty-btn qty-minus">−</button>
                        <input
                            type="number"
                            min="1"
                            step="1"
                            value="${Number(data.quantity || 1)}"
                            class="form-control qty-input"
                            name="items[${index}][quantity]"
                            required
                        >
                        <button type="button" class="qty-btn qty-plus">+</button>
                    </div>
                </div>
            </div>
        </div>
    `;
}

function refreshIndexes() {
    const rows = Array.from(itemsWrap.querySelectorAll('.item-card'));

    rows.forEach((row, index) => {
        row.dataset.index = index;
        row.querySelector('.row-no').textContent = String(index + 1);
        row.querySelector('.category-hidden').name = `items[${index}][category_id]`;
        row.querySelector('.product-select').name = `items[${index}][product_id]`;
        row.querySelector('.variant-select').name = `items[${index}][variant_id]`;
        row.querySelector('.qty-input').name = `items[${index}][quantity]`;
    });

    updateSummary();
}

function updateSummary() {
    const rows = Array.from(itemsWrap.querySelectorAll('.item-card'));
    rowsCountEl.textContent = String(rows.length);

    let qtySum = 0;
    rows.forEach(row => {
        qtySum += Number(row.querySelector('.qty-input')?.value || 0);
    });

    qtySumEl.textContent = String(qtySum);
    emptyRowsNote.style.display = rows.length ? 'none' : '';
}

function filterProducts(selectEl, term) {
    const q = String(term || '').trim().toLowerCase();

    Array.from(selectEl.options).forEach((opt, idx) => {
        if (idx === 0) return;
        opt.hidden = q !== '' && !opt.textContent.toLowerCase().includes(q);
    });
}

function syncRow(row) {
    const productSelect = row.querySelector('.product-select');
    const variantSelect = row.querySelector('.variant-select');
    const qtyInput = row.querySelector('.qty-input');
    const stockState = row.querySelector('.stock-state');
    const categoryHidden = row.querySelector('.category-hidden');
    const productCodeText = row.querySelector('.selected-product-code');
    const minusBtn = row.querySelector('.qty-minus');
    const plusBtn = row.querySelector('.qty-plus');

    const product = productById(productSelect.value || '');
    categoryHidden.value = product ? String(product.category_id || '') : '';
    productCodeText.textContent = product?.code || '—';

    let max = 0;

    if (variantSelect.value) {
        const variant = variants.find(v => String(v.id) === String(variantSelect.value || ''));
        max = Math.max(0, Number(variant?.stock || 0));
    }

    if (max <= 0) {
        stockState.textContent = variantSelect.value ? 'ناموجود' : 'موجودی: 0';
        stockState.classList.add('bad');
        qtyInput.value = '0';
        qtyInput.max = '0';
        qtyInput.min = '0';
        minusBtn.disabled = true;
        plusBtn.disabled = true;
    } else {
        stockState.textContent = 'موجودی: ' + max.toLocaleString('fa-IR');
        stockState.classList.remove('bad');
        qtyInput.max = String(max);
        qtyInput.min = '1';
        minusBtn.disabled = false;
        plusBtn.disabled = false;

        if (!qtyInput.value || Number(qtyInput.value) < 1) {
            qtyInput.value = '1';
        }
        if (Number(qtyInput.value) > max) {
            qtyInput.value = String(max);
        }
    }

    updateSummary();
}

function bindRow(row, initialData = {}) {
    const productSearch = row.querySelector('.product-search');
    const productSelect = row.querySelector('.product-select');
    const variantSelect = row.querySelector('.variant-select');
    const qtyInput = row.querySelector('.qty-input');
    const minusBtn = row.querySelector('.qty-minus');
    const plusBtn = row.querySelector('.qty-plus');
    const removeBtn = row.querySelector('.remove-btn');

    productSearch.addEventListener('input', function () {
        filterProducts(productSelect, productSearch.value);
    });

    productSelect.addEventListener('change', function () {
        variantSelect.innerHTML = buildVariantOptions(productSelect.value || '', '');
        syncRow(row);
    });

    variantSelect.addEventListener('change', function () {
        syncRow(row);
    });

    qtyInput.addEventListener('input', function () {
        let val = Number(qtyInput.value || 0);
        let max = Number(qtyInput.max || 0);

        if (!Number.isFinite(val)) val = 1;

        if (max > 0) {
            if (val < 1) val = 1;
            if (val > max) val = max;
        } else {
            val = 0;
        }

        qtyInput.value = String(val);
        updateSummary();
    });

    minusBtn.addEventListener('click', function () {
        let val = Number(qtyInput.value || 1);
        let max = Number(qtyInput.max || 0);

        if (max <= 0) {
            qtyInput.value = '0';
            return;
        }

        val = Math.max(1, val - 1);
        qtyInput.value = String(val);
        qtyInput.dispatchEvent(new Event('input'));
    });

    plusBtn.addEventListener('click', function () {
        let val = Number(qtyInput.value || 1);
        let max = Number(qtyInput.max || 0);

        if (max <= 0) {
            qtyInput.value = '0';
            return;
        }

        val = val + 1;
        if (val > max) val = max;

        qtyInput.value = String(val);
        qtyInput.dispatchEvent(new Event('input'));
    });

    removeBtn.addEventListener('click', function () {
        row.remove();
        refreshIndexes();
    });

    if (initialData.product_id) {
        productSelect.value = String(initialData.product_id);
        variantSelect.innerHTML = buildVariantOptions(initialData.product_id, initialData.variant_id || '');
        if (initialData.variant_id) {
            variantSelect.value = String(initialData.variant_id);
        }
    }

    syncRow(row);
}

function addRow(data = {}) {
    const index = itemsWrap.querySelectorAll('.item-card').length;
    itemsWrap.insertAdjacentHTML('beforeend', rowTemplate(index, data));
    const row = itemsWrap.querySelector('.item-card:last-child');
    bindRow(row, data);
    refreshIndexes();
}

addBtn.addEventListener('click', function () {
    addRow({});
});

document.addEventListener('DOMContentLoaded', function () {
    itemsWrap.innerHTML = '';

    if (oldItems.length) {
        oldItems.forEach(item => addRow(item));
    } else {
        addRow({});
    }
});

scrapForm.addEventListener('submit', function (e) {
    const rows = Array.from(itemsWrap.querySelectorAll('.item-card'));

    for (const row of rows) {
        const productSelect = row.querySelector('.product-select');
        const variantSelect = row.querySelector('.variant-select');
        const qtyInput = row.querySelector('.qty-input');

        if (!productSelect.value) {
            e.preventDefault();
            alert('همه ردیف‌ها باید کالا داشته باشند.');
            return;
        }

        if (!variantSelect.value) {
            e.preventDefault();
            alert('برای همه ردیف‌ها باید تنوع انتخاب شود.');
            return;
        }

        if (Number(qtyInput.value || 0) < 1) {
            e.preventDefault();
            alert('تعداد هر ردیف باید حداقل 1 باشد.');
            return;
        }
    }

    const btn = document.getElementById('submitBtn');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'در حال ثبت...';
    }
});
</script>
@endsection