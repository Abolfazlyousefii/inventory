@extends('layouts.app')

@php
    $isEdit = isset($voucher) && $voucher?->exists;
    $rootCategoriesJson = $categories->map(fn($c) => [
        'id' => (int) $c->id,
        'name' => (string) $c->name,
    ])->values();

    $oldItems = old('items');
    if (is_array($oldItems)) {
        $productIds = collect($oldItems)->pluck('product_id')->filter()->map(fn($id) => (int) $id)->unique()->values();
        $variantIds = collect($oldItems)->pluck('variant_id')->filter()->map(fn($id) => (int) $id)->unique()->values();
        $productsMap = \App\Models\Product::query()->whereIn('id', $productIds)->get(['id', 'name', 'code', 'sku', 'category_id'])->keyBy('id');
        $variantsMap = \App\Models\ProductVariant::query()->whereIn('id', $variantIds)->get(['id', 'product_id', 'variant_name', 'variant_code', 'variety_code'])->keyBy('id');

        $initialItems = collect($oldItems)->values()->map(function ($item) use ($productsMap, $variantsMap) {
            $product = $productsMap->get((int) ($item['product_id'] ?? 0));
            $variant = $variantsMap->get((int) ($item['variant_id'] ?? 0));
            $category = $product?->category;
            $rootCategoryId = null;
            if ($category) {
                $rootCategoryId = $category->parent_id ?: $category->id;
            }

            return [
                'root_category_id' => $rootCategoryId,
                'category_id' => $item['category_id'] ?? ($product?->category_id ?? ''),
                'product_id' => $item['product_id'] ?? '',
                'product_label' => $product ? trim($product->name . (($product->code ?: $product->sku) ? ' - ' . ($product->code ?: $product->sku) : '')) : '',
                'variant_id' => $item['variant_id'] ?? '',
                'variant_label' => $variant ? trim(collect([$variant->variant_name, $variant->variety_code ? ('طرح ' . $variant->variety_code) : null, $variant->variant_code ? '['.$variant->variant_code.']' : null])->filter()->implode(' / ')) : '',
                'quantity' => $item['quantity'] ?? '',
                'personnel_asset_code' => $item['personnel_asset_code'] ?? '',
            ];
        });
    } elseif ($isEdit) {
        $initialItems = $voucher->items->map(function ($item) {
            $product = $item->product;
            $category = $product?->category;
            $rootCategoryId = $category?->parent_id ?: ($category?->id ?? null);
            $productLabel = $product ? trim($product->name . (($product->code ?: $product->sku) ? ' - ' . ($product->code ?: $product->sku) : '')) : '';
            $variant = $item->variant;
            $variantLabel = $variant ? trim(collect([
                $variant->variant_name,
                $variant->modelList?->model_name,
                $variant->variety_code ? ('طرح ' . $variant->variety_code) : null,
                $variant->variant_code ? '['.$variant->variant_code.']' : null,
            ])->filter()->unique()->implode(' / ')) : '';

            return [
                'root_category_id' => $rootCategoryId,
                'category_id' => $product?->category_id ?? '',
                'product_id' => $item->product_id,
                'product_label' => $productLabel,
                'variant_id' => $item->product_variant_id,
                'variant_label' => $variantLabel,
                'quantity' => $item->quantity,
                'personnel_asset_code' => $item->personnel_asset_code,
            ];
        })->values();
    } else {
        $initialItems = collect();
    }
@endphp

@section('content')
<div class="container py-3" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">{{ $isEdit ? 'ویرایش حواله پرسنل' : 'ثبت حواله پرسنل' }}</h4>
        <a class="btn btn-outline-secondary" href="{{ route('vouchers.section.index', 'personnel') }}">بازگشت</a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ $isEdit ? route('vouchers.update', $voucher) : route('vouchers.section.store', 'personnel') }}" id="personnelVoucherForm" novalidate>
                @csrf
                @if($isEdit)
                    @method('PUT')
                @endif
                <input type="hidden" name="voucher_type" value="personnel_asset">

                <div class="alert alert-info small mb-3">
                    در این سند، انتقال موجودی به‌صورت سیستمی از
                    <strong>{{ $centralWarehouse?->name ?? 'انبار مرکزی' }}</strong>
                    به
                    <strong>{{ $personnelWarehouse?->name ?? 'انبار پرسنل' }}</strong>
                    انجام می‌شود و نیازی به انتخاب دستی انبار نیست.
                </div>

                <div class="row g-3">
                    <div class="col-lg-4">
                        <label class="form-label">تحویل‌گیرنده</label>
                        <input type="text" class="form-control form-control-sm mb-1 user-select-filter" data-target="receiverUserSelect" placeholder="جستجو بر اساس نام، تلفن، ایمیل یا کد پرسنلی">
                        <select id="receiverUserSelect" name="receiver_user_id" class="form-select" required>
                            <option value="">انتخاب پرسنل...</option>
                            @foreach($receiverUsers as $user)
                                <option value="{{ $user->id }}" data-search="{{ trim($user->name.' '.$user->phone.' '.$user->email.' '.$user->personnel_code) }}" @selected((string) old('receiver_user_id', $voucher->receiver_user_id ?? '') === (string) $user->id)>
                                    {{ $user->name }}{{ $user->phone ? ' - '.$user->phone : '' }}{{ $user->personnel_code ? ' | کد: '.$user->personnel_code : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">شماره حواله (اختیاری)</label>
                        <input name="reference" class="form-control" value="{{ old('reference', $voucher->reference ?? '') }}" placeholder="مثلاً TR-138">
                    </div>
                    <div class="col-lg-2">
                        <label class="form-label">تاریخ شمسی</label>
                        <input name="transferred_at_jalali" class="form-control js-jalali-date" data-jdp data-jdp-only-date inputmode="numeric" autocomplete="off" placeholder="۱۴۰۵/۰۶/۰۴" value="{{ old('transferred_at_jalali', $isEdit ? \App\Support\JalaliDate::date($voucher->transferred_at, '') : \App\Support\JalaliDate::date(now(), '')) }}">
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">توضیحات (اختیاری)</label>
                        <input name="note" class="form-control" value="{{ old('note', $voucher->note ?? '') }}">
                    </div>

                    <div class="col-12">
                        <div class="table-responsive">
                            <table class="table align-middle" id="itemsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th style="min-width:150px">دسته اصلی</th>
                                        <th style="min-width:170px">زیر دسته</th>
                                        <th style="min-width:280px">کالا</th>
                                        <th style="min-width:280px">تنوع/مدل</th>
                                        <th class="text-center" style="width:110px">موجودی مرکزی</th>
                                        <th style="width:140px">تعداد</th>
                                        <th style="width:150px">کد اموال ۴ رقمی</th>
                                        <th style="width:80px"></th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="addItemBtn">+ افزودن ردیف</button>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary">{{ $isEdit ? 'ذخیره تغییرات حواله' : 'ثبت حواله پرسنل' }}</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    #itemsTable .quantity-input {
        min-width: 105px;
        text-align: center;
        font-weight: 700;
    }
    #itemsTable .row-error {
        font-size: .78rem;
        margin-top: .25rem;
    }
    #itemsTable .stock-badge {
        min-width: 52px;
        display: inline-block;
    }
</style>

<script>
const rootCategories = @json($rootCategoriesJson);
const initialItems = @json($initialItems->values());
const editingVoucherId = @json($isEdit ? (int) $voucher->id : null);
const endpoints = {
    children: @json(route('vouchers.personnel.categories.children', ['category' => '__ID__'])),
    products: @json(route('vouchers.personnel.products.search')),
    variants: @json(route('vouchers.personnel.products.variants', ['product' => '__ID__'])),
};
const tbody = document.querySelector('#itemsTable tbody');
const addBtn = document.getElementById('addItemBtn');
const form = document.getElementById('personnelVoucherForm');

function esc(value) {
    return String(value ?? '').replace(/[&<>'"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[ch]));
}
function normalize(value) {
    return String(value || '').trim().toLowerCase();
}
function faNumber(value) {
    return Number(value || 0).toLocaleString('fa-IR');
}
function debounce(fn, delay = 350) {
    let timer;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), delay);
    };
}
async function fetchJson(url) {
    const response = await fetch(url, {headers: {'Accept': 'application/json'}});
    if (!response.ok) throw new Error('خطا در دریافت اطلاعات');
    return await response.json();
}
function rootOptions(selected = '') {
    return '<option value="">انتخاب دسته اصلی...</option>' + rootCategories.map(c => `<option value="${c.id}" ${String(selected) === String(c.id) ? 'selected' : ''}>${esc(c.name)}</option>`).join('');
}
function optionHtml(id, text, selected = false, extra = '') {
    return `<option value="${esc(id)}" ${selected ? 'selected' : ''} ${extra}>${esc(text)}</option>`;
}
function rowTemplate(index, item = {}) {
    return `<tr>
        <td><select class="form-select root-cat">${rootOptions(item.root_category_id || '')}</select></td>
        <td>
            <select class="form-select sub-cat"><option value="">ابتدا دسته اصلی...</option></select>
            <input type="hidden" name="items[${index}][category_id]" class="cat-hidden" value="${esc(item.category_id || '')}">
        </td>
        <td>
            <input type="text" class="form-control form-control-sm mb-1 product-search" placeholder="جستجو داخل دسته انتخاب‌شده">
            <select name="items[${index}][product_id]" class="form-select product-select" required>
                ${item.product_id ? optionHtml(item.product_id, item.product_label || ('کالا #' + item.product_id), true) : '<option value="">ابتدا زیر دسته...</option>'}
            </select>
        </td>
        <td>
            <select name="items[${index}][variant_id]" class="form-select variant-select" required>
                ${item.variant_id ? optionHtml(item.variant_id, item.variant_label || ('تنوع #' + item.variant_id), true) : '<option value="">ابتدا کالا...</option>'}
            </select>
        </td>
        <td class="text-center"><span class="badge text-bg-light stock-badge">—</span></td>
        <td>
            <input name="items[${index}][quantity]" type="number" min="1" step="1" class="form-control quantity-input" required value="${esc(item.quantity || '')}">
            <div class="text-danger row-error d-none"></div>
        </td>
        <td><input name="items[${index}][personnel_asset_code]" class="form-control asset-code-input" inputmode="numeric" pattern="\\d{4}" maxlength="4" required value="${esc(item.personnel_asset_code || '')}" placeholder="مثلاً 2039"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger remove-row">حذف</button></td>
    </tr>`;
}
function rowError(tr, message = '') {
    const error = tr.querySelector('.row-error');
    if (!error) return;
    error.textContent = message;
    error.classList.toggle('d-none', message === '');
}
async function loadChildren(tr, selected = '') {
    const rootId = tr.querySelector('.root-cat').value;
    const sub = tr.querySelector('.sub-cat');
    const hidden = tr.querySelector('.cat-hidden');
    hidden.value = '';
    sub.innerHTML = '<option value="">در حال دریافت...</option>';

    if (!rootId) {
        sub.innerHTML = '<option value="">ابتدا دسته اصلی...</option>';
        return;
    }

    const data = await fetchJson(endpoints.children.replace('__ID__', rootId));
    const rows = data.data || [];
    if (!rows.length) {
        sub.innerHTML = '<option value="">زیر دسته ندارد</option>';
        hidden.value = rootId;
        return;
    }

    sub.innerHTML = '<option value="">انتخاب زیر دسته...</option>' + rows.map(c => optionHtml(c.id, c.name, String(selected) === String(c.id))).join('');
    hidden.value = sub.value || '';
}
async function searchProducts(tr, selectedId = '', selectedText = '') {
    const catId = tr.querySelector('.cat-hidden').value;
    const product = tr.querySelector('.product-select');
    const term = tr.querySelector('.product-search').value;
    const variant = tr.querySelector('.variant-select');

    if (!catId) {
        product.innerHTML = '<option value="">ابتدا زیر دسته...</option>';
        variant.innerHTML = '<option value="">ابتدا کالا...</option>';
        return;
    }

    product.innerHTML = '<option value="">در حال جستجو...</option>';
    const url = new URL(endpoints.products, window.location.origin);
    url.searchParams.set('category_id', catId);
    if (term) url.searchParams.set('q', term);
    const data = await fetchJson(url.toString());
    const rows = data.results || [];
    let html = '<option value="">انتخاب کالا...</option>' + rows.map(p => optionHtml(p.id, p.text || p.name, String(selectedId) === String(p.id))).join('');
    if (selectedId && !rows.some(p => String(p.id) === String(selectedId))) {
        html += optionHtml(selectedId, selectedText || ('کالا #' + selectedId), true);
    }
    product.innerHTML = html;
}
async function loadVariants(tr, selectedId = '', selectedText = '') {
    const productId = tr.querySelector('.product-select').value;
    const variant = tr.querySelector('.variant-select');
    if (!productId) {
        variant.innerHTML = '<option value="">ابتدا کالا...</option>';
        syncVariant(tr);
        return;
    }

    variant.innerHTML = '<option value="">در حال دریافت تنوع‌ها...</option>';
    const url = new URL(endpoints.variants.replace('__ID__', productId), window.location.origin);
    if (editingVoucherId) url.searchParams.set('editing_voucher_id', editingVoucherId);
    const data = await fetchJson(url.toString());
    const rows = data.results || [];
    let html = '<option value="">انتخاب تنوع...</option>' + rows.map(v => {
        const extra = `data-stock="${v.central_stock}" data-max="${v.available_for_edit}" data-prev="${v.previous_quantity}"`;
        return optionHtml(v.id, `${v.text} — موجودی: ${faNumber(v.available_for_edit)}`, String(selectedId) === String(v.id), extra);
    }).join('');
    if (selectedId && !rows.some(v => String(v.id) === String(selectedId))) {
        html += optionHtml(selectedId, selectedText || ('تنوع #' + selectedId), true, 'data-stock="0" data-max="0" data-prev="0"');
    }
    variant.innerHTML = html;
    syncVariant(tr);
}
function syncVariant(tr) {
    const variant = tr.querySelector('.variant-select');
    const qty = tr.querySelector('.quantity-input');
    const badge = tr.querySelector('.stock-badge');
    const opt = variant.selectedOptions[0];
    const max = opt?.value ? Number(opt.dataset.max || 0) : 0;
    const stock = opt?.value ? Number(opt.dataset.stock || 0) : 0;
    const prev = opt?.value ? Number(opt.dataset.prev || 0) : 0;

    badge.textContent = opt?.value ? faNumber(max) : '—';
    badge.title = opt?.value ? `موجودی مرکزی: ${stock} | مقدار قبلی همین سند: ${prev}` : '';
    qty.max = opt?.value ? String(max) : '';
    qty.disabled = !!opt?.value && max <= 0;

    if (opt?.value && max <= 0) {
        qty.value = '';
        rowError(tr, 'موجودی قابل حواله برای این تنوع صفر است.');
    } else {
        rowError(tr, '');
        if (qty.value && Number(qty.value) > max) qty.value = max;
    }
}
function validateRow(tr) {
    const product = tr.querySelector('.product-select');
    const variant = tr.querySelector('.variant-select');
    const qty = tr.querySelector('.quantity-input');
    const code = tr.querySelector('.asset-code-input');
    rowError(tr, '');

    if (!product.value || !variant.value) {
        rowError(tr, 'کالا و تنوع را کامل انتخاب کنید.');
        return false;
    }
    const max = Number(qty.max || 0);
    const value = Number(qty.value || 0);
    if (!value || value < 1) {
        rowError(tr, 'تعداد باید حداقل ۱ باشد.');
        return false;
    }
    if (value > max) {
        rowError(tr, `تعداد از موجودی قابل حواله بیشتر است. حداکثر مجاز: ${faNumber(max)}`);
        return false;
    }
    if (!/^\d{4}$/.test(code.value || '')) {
        rowError(tr, 'کد اموال باید دقیقاً ۴ رقم باشد.');
        return false;
    }
    return true;
}
function bindRow(tr, item = {}) {
    const root = tr.querySelector('.root-cat');
    const sub = tr.querySelector('.sub-cat');
    const product = tr.querySelector('.product-select');
    const variant = tr.querySelector('.variant-select');
    const search = tr.querySelector('.product-search');
    const remove = tr.querySelector('.remove-row');
    const qty = tr.querySelector('.quantity-input');

    root.addEventListener('change', async () => {
        await loadChildren(tr);
        product.innerHTML = '<option value="">ابتدا زیر دسته...</option>';
        variant.innerHTML = '<option value="">ابتدا کالا...</option>';
        syncVariant(tr);
    });
    sub.addEventListener('change', async () => {
        tr.querySelector('.cat-hidden').value = sub.value || '';
        await searchProducts(tr);
        variant.innerHTML = '<option value="">ابتدا کالا...</option>';
        syncVariant(tr);
    });
    search.addEventListener('input', debounce(() => searchProducts(tr, product.value, product.selectedOptions[0]?.textContent || ''), 350));
    product.addEventListener('change', () => loadVariants(tr));
    variant.addEventListener('change', () => syncVariant(tr));
    qty.addEventListener('input', () => validateRow(tr));
    remove.addEventListener('click', () => { tr.remove(); reindexRows(); });
}
function reindexRows() {
    [...tbody.querySelectorAll('tr')].forEach((tr, index) => {
        tr.querySelector('.cat-hidden').name = `items[${index}][category_id]`;
        tr.querySelector('.product-select').name = `items[${index}][product_id]`;
        tr.querySelector('.variant-select').name = `items[${index}][variant_id]`;
        tr.querySelector('.quantity-input').name = `items[${index}][quantity]`;
        tr.querySelector('.asset-code-input').name = `items[${index}][personnel_asset_code]`;
    });
}
async function addRow(item = {}) {
    tbody.insertAdjacentHTML('beforeend', rowTemplate(tbody.querySelectorAll('tr').length, item));
    const tr = tbody.querySelector('tr:last-child');
    bindRow(tr, item);
    if (item.root_category_id) {
        await loadChildren(tr, item.category_id || '');
        tr.querySelector('.cat-hidden').value = item.category_id || tr.querySelector('.cat-hidden').value;
    }
    if (item.product_id) {
        await searchProducts(tr, item.product_id, item.product_label || '');
        tr.querySelector('.product-select').value = item.product_id;
        await loadVariants(tr, item.variant_id, item.variant_label || '');
        tr.querySelector('.variant-select').value = item.variant_id;
        syncVariant(tr);
    }
    reindexRows();
}

document.querySelectorAll('.user-select-filter').forEach(input => input.addEventListener('input', () => {
    const select = document.getElementById(input.dataset.target);
    const term = normalize(input.value);
    [...select.options].forEach((option, index) => {
        if (index === 0) return;
        option.hidden = term !== '' && !normalize(option.dataset.search || option.textContent).includes(term);
    });
}));
addBtn.addEventListener('click', () => addRow());
form.addEventListener('submit', event => {
    let ok = true;
    const seen = new Set();
    [...tbody.querySelectorAll('tr')].forEach(tr => {
        const rowOk = validateRow(tr);
        const key = `${tr.querySelector('.product-select').value}:${tr.querySelector('.variant-select').value}`;
        if (seen.has(key)) {
            rowError(tr, 'این کالا/تنوع تکراری است و فقط یک‌بار می‌تواند ثبت شود.');
            ok = false;
        }
        seen.add(key);
        if (!rowOk) ok = false;
    });
    if (!ok) {
        event.preventDefault();
        tbody.querySelector('.row-error:not(.d-none)')?.scrollIntoView({behavior: 'smooth', block: 'center'});
    }
});

(async function bootRows() {
    if (initialItems.length) {
        for (const item of initialItems) await addRow(item);
    } else {
        await addRow();
    }
})();

if (window.jalaliDatepicker) {
    window.jalaliDatepicker.startWatch({
        selector: '.js-jalali-date',
        persianDigits: true,
        zIndex: 3000,
        time: false,
        hideAfterChange: true
    });
}
</script>
@endsection
