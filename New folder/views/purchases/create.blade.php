@extends('layouts.app')

@section('content')
@php
    $isEdit = isset($purchase) && $purchase;
    $formAction = $isEdit ? route('purchases.update', $purchase) : route('purchases.store');
    $initialItems = old('items');

    if (!is_array($initialItems)) {
        $initialItems = $isEdit
            ? $purchase->items->map(fn ($it) => [
                'id' => $it->id,
                'product_id' => $it->product_id,
                'category_id' => $it->product?->category_id,
                'variant_id' => $it->product_variant_id,
                'name' => $it->product_name,
                'code' => $it->product_code,
                'variant_name' => $it->variant_name,
                'variant_code' => $it->variant?->variant_code,
                'quantity' => $it->quantity,
                'buy_price' => \App\Support\Currency::toRial($it->buy_price),
                'sell_price' => \App\Support\Currency::toRial($it->sell_price),
                'product_buy_price' => \App\Support\Currency::toRial($it->buy_price),
                'product_sell_price' => \App\Support\Currency::toRial($it->sell_price),
                'price_overridden' => true,
                'discount_type' => $it->discount_type,
                'discount_value' => $it->discount_type === 'amount' ? \App\Support\Currency::toRial($it->discount_value) : $it->discount_value,
            ])->values()->all()
            : [];
    }

    $productsPayload = $products->map(function ($p) {
        return [
            'id' => (int) $p->id,
            'name' => (string) $p->name,
            'code' => (string) ($p->code ?: $p->short_barcode),
            'short_barcode' => (string) ($p->short_barcode ?? ''),
            'sku' => (string) ($p->sku ?? ''),
            'category_id' => $p->category_id ? (int) $p->category_id : null,
            'variants' => $p->variants->map(function ($v) {
                return [
                    'id' => (int) $v->id,
                    'name' => (string) $v->variant_name,
                    'model_name' => (string) ($v->modelList?->model_name ?? ''),
                    'model_code' => (string) ($v->modelList?->code ?? ''),
                    'variety_name' => (string) ($v->variety_name ?? ''),
                    'code' => (string) ($v->variant_code ?? ''),
                    'buy_price' => \App\Support\Currency::toRial($v->buy_price ?? 0),
                    'sell_price' => \App\Support\Currency::toRial($v->sell_price ?? 0),
                    'stock' => (int) ($v->stock ?? 0),
                    'reserved' => (int) ($v->reserved ?? 0),
                    'barcode' => (string) ($v->barcode ?? ''),
                    'color_name' => (string) ($v->relationLoaded('color') ? ($v->color?->name ?? '') : ''),
                    'color_code' => (string) ($v->relationLoaded('color') ? ($v->color?->code ?? '') : ''),
                ];
            })->values()->all(),
        ];
    })->values()->all();

    $categoriesPayload = ($categories ?? collect())->map(fn ($category) => [
        'id' => (int) $category->id,
        'name' => (string) $category->name,
    ])->values()->all();
@endphp

<style>
    .purchase-fast-page .purchase-main-card,
    .purchase-fast-page .purchase-quick-card,
    .purchase-fast-page .purchase-summary-card,
    .purchase-fast-page .purchase-product-card {
        border: 1px solid #e5e7eb;
        border-radius: 16px;
        box-shadow: 0 10px 24px rgba(15, 23, 42, .05);
    }

    .purchase-fast-page .section-title {
        font-weight: 800;
        color: #0f172a;
    }

    .purchase-fast-page .purchase-product-card {
        background: #fff;
        overflow: hidden;
    }

    .purchase-fast-page .product-card-head {
        background: linear-gradient(90deg, #f8fafc, #eef6ff);
        border-bottom: 1px solid #e5e7eb;
        padding: 12px 14px;
    }

    .purchase-fast-page .product-code-chip,
    .purchase-fast-page .product-stat-chip {
        border: 1px solid #dbeafe;
        background: #eff6ff;
        color: #1e40af;
        border-radius: 999px;
        padding: 4px 10px;
        font-size: 12px;
        font-weight: 700;
    }

    .purchase-fast-page .price-panel {
        background: #fbfdff;
        border: 1px dashed #cbd5e1;
        border-radius: 14px;
        padding: 10px;
    }

    .purchase-fast-page .variants-table th {
        white-space: nowrap;
        font-size: 12px;
        color: #475569;
    }

    .purchase-fast-page .variants-table td {
        vertical-align: middle;
    }

    .purchase-fast-page .variant-title {
        font-weight: 700;
        color: #1f2937;
    }

    .purchase-fast-page .mono {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    }

    .purchase-fast-page .summary-sticky {
        position: sticky;
        bottom: 0;
        z-index: 20;
        background: rgba(255, 255, 255, .96);
        backdrop-filter: blur(8px);
    }

    .purchase-fast-page .qty-input { max-width: 95px; }
    .purchase-fast-page .price-input { min-width: 130px; }
    .purchase-fast-page .purchase-variant-filter {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 10px;
    }
    .purchase-fast-page .purchase-variant-row.has-qty > td {
        background: #ecfdf5;
    }
    .purchase-fast-page .purchase-variant-empty {
        border: 1px dashed #cbd5e1;
        border-radius: 12px;
        background: #fff7ed;
        color: #9a3412;
        padding: 10px;
    }
    .purchase-fast-page .current-sale-price {
        border: 1px solid #dbeafe;
        background: #eff6ff;
        color: #1e40af;
        border-radius: 10px;
        padding: 6px 8px;
        font-size: 12px;
        font-weight: 800;
        white-space: nowrap;
    }
    .purchase-fast-page .current-sale-price.missing {
        border-color: #fed7aa;
        background: #fff7ed;
        color: #9a3412;
    }
</style>

<div class="purchase-page-wrap purchase-fast-page">
    <div class="purchase-topbar d-flex justify-content-between align-items-center mb-3">
        <h4 class="page-title mb-0">{{ $isEdit ? 'ویرایش سند خرید' : 'ثبت خرید جدید' }}</h4>
        <a class="btn btn-sm btn-outline-light" href="{{ route('purchases.index') }}">بازگشت</a>
    </div>

    <form method="POST" action="{{ $formAction }}" id="purchaseForm">
        @csrf
        @if($isEdit)
            @method('PUT')
        @endif
        <input type="hidden" name="submission_token" value="{{ old('submission_token', (string) \Illuminate\Support\Str::uuid()) }}">
        <input type="hidden" name="warehouse_id" value="{{ old('warehouse_id', $purchase->warehouse_id ?? '') }}">
        <input type="hidden" name="items_json" id="itemsPayload" value="">

        <div class="card purchase-main-card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <div class="section-title">اطلاعات اصلی خرید</div>
                        <div class="small text-muted">تأمین‌کننده یا مشتری، انبار مقصد و توضیحات سند را وارد کنید.</div>
                    </div>
                    <button class="btn btn-sm btn-primary" type="submit">{{ $isEdit ? 'ذخیره تغییرات سند خرید' : 'ثبت نهایی خرید' }}</button>
                </div>

                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">تامین‌کننده / مشتری</label>
                        <div class="d-flex gap-2">
                            <select class="form-select form-select-sm @error('supplier_id') is-invalid @enderror" name="supplier_id" id="purchaseSupplierSelect" data-placeholder="جستجوی نام یا موبایل تأمین‌کننده / مشتری..." required>
                                <option value="">انتخاب کنید...</option>
                                @foreach($suppliers as $supplier)
                                    <option value="{{ $supplier->purchase_option_value ?? $supplier->id }}" @selected(old('supplier_id', $purchase->supplier_id ?? null)==($supplier->purchase_option_value ?? $supplier->id))>
                                        {{ $supplier->purchase_option_label ?? $supplier->name }}@if($supplier->phone) | {{ $supplier->phone }}@endif
                                    </option>
                                @endforeach
                            </select>
                            <a href="{{ route('persons.index') }}" class="btn btn-sm btn-outline-dark">اشخاص</a>
                        </div>
                        @error('supplier_id')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">انبار مقصد</label>
                        <input class="form-control form-control-sm" value="انبار مرکزی" readonly>
                    </div>
                    <div class="col-md-{{ $isEdit ? '3' : '6' }}">
                        <label class="form-label">توضیحات خرید</label>
                        <input class="form-control form-control-sm" name="note" value="{{ old('note', $purchase->note ?? '') }}" placeholder="اختیاری">
                    </div>
                    @if($isEdit)
                        <div class="col-md-3">
                            <label class="form-label">تاریخ خرید</label>
                            <input type="datetime-local" class="form-control form-control-sm" name="purchased_at" value="{{ old('purchased_at', optional($purchase->purchased_at)->format('Y-m-d\TH:i')) }}">
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="card purchase-quick-card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <div class="section-title">افزودن سریع محصول به خرید</div>
                        <div class="small text-muted">محصول را با نام، کد، بارکد یا SKU جستجو کنید؛ دسته‌بندی فقط فیلتر اختیاری است.</div>
                    </div>
                </div>

                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">فیلتر دسته‌بندی</label>
                        <select class="form-select form-select-sm" id="quickCategoryFilter">
                            <option value="">همه دسته‌بندی‌ها</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">جستجوی محصول</label>
                        <input type="search" class="form-control form-control-sm" id="quickProductSearch" placeholder="نام کالا، کد، بارکد یا SKU...">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">انتخاب کالا</label>
                        <select class="form-select form-select-sm" id="quickProductSelect"></select>
                    </div>
                    <div class="col-md-1 d-grid">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addProductBtn">افزودن</button>
                    </div>
                </div>
                <div class="small text-muted mt-2" id="quickProductHelp">بعد از افزودن محصول، همه تنوع‌های آن به صورت خودکار در کارت محصول نمایش داده می‌شوند.</div>
            </div>
        </div>

        <div id="purchaseProducts" class="d-flex flex-column gap-3"></div>

        <div class="card purchase-summary-card summary-sticky mt-3">
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-md-2">
                        <div class="small text-muted">محصولات انتخاب‌شده</div>
                        <div class="fw-bold"><span id="summaryProductCount">0</span> محصول</div>
                    </div>
                    <div class="col-md-2">
                        <div class="small text-muted">تنوع‌های دارای تعداد</div>
                        <div class="fw-bold"><span id="summaryVariantCount">0</span> ردیف</div>
                    </div>
                    <div class="col-md-2">
                        <div class="small text-muted">جمع قبل تخفیف</div>
                        <div class="fw-bold"><span id="subtotalAmount">0</span> ریال</div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">نوع تخفیف کلی</label>
                        <select class="form-select form-select-sm" name="invoice_discount_type" id="invoiceDiscountType">
                            <option value="">بدون تخفیف</option>
                            <option value="amount" @selected(old('invoice_discount_type', $purchase->discount_type ?? '')==='amount')>مبلغی</option>
                            <option value="percent" @selected(old('invoice_discount_type', $purchase->discount_type ?? '')==='percent')>درصدی</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">مقدار تخفیف</label>
                        <input type="text" inputmode="numeric" class="form-control form-control-sm formatted-number" id="invoiceDiscountValue" name="invoice_discount_value" value="{{ old('invoice_discount_value', ($purchase->discount_type ?? null) === 'amount' ? \App\Support\Currency::toRial($purchase->discount_value ?? 0) : ($purchase->discount_value ?? 0)) }}">
                    </div>
                    <div class="col-md-2 text-end">
                        <div class="small text-muted">جمع تخفیف: <span id="totalDiscountAmount">0</span> ریال</div>
                        <div class="fw-bold fs-5" style="color: var(--ink);">نهایی: <span id="totalAmount">0</span> ریال</div>
                        <button class="btn btn-sm btn-primary mt-2" type="submit">{{ $isEdit ? 'ذخیره تغییرات' : 'ثبت نهایی خرید' }}</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    const products = @json($productsPayload);
    const categories = @json($categoriesPayload);
    const initialItems = @json($initialItems);
    const variantsUrlTemplate = @json(route('purchases.products.variants', ['product' => '__PRODUCT__']));

    const purchaseForm = document.getElementById('purchaseForm');
    const productCardsEl = document.getElementById('purchaseProducts');
    const payloadEl = document.getElementById('itemsPayload');
    const quickCategoryEl = document.getElementById('quickCategoryFilter');
    const quickSearchEl = document.getElementById('quickProductSearch');
    const quickProductEl = document.getElementById('quickProductSelect');
    const addProductBtn = document.getElementById('addProductBtn');
    const invoiceDiscountTypeEl = document.getElementById('invoiceDiscountType');
    const invoiceDiscountValueEl = document.getElementById('invoiceDiscountValue');
    const supplierSelectEl = document.getElementById('purchaseSupplierSelect');

    const summaryProductCountEl = document.getElementById('summaryProductCount');
    const summaryVariantCountEl = document.getElementById('summaryVariantCount');
    const subtotalEl = document.getElementById('subtotalAmount');
    const totalDiscountEl = document.getElementById('totalDiscountAmount');
    const totalEl = document.getElementById('totalAmount');

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function normalizeDigits(value) {
        return String(value ?? '')
            .replace(/[٠-٩]/g, d => String(d.charCodeAt(0) - 0x0660))
            .replace(/[۰-۹]/g, d => String(d.charCodeAt(0) - 0x06F0));
    }

    function normalizePurchaseSearchText(value) {
        return normalizeDigits(value)
            .replace(/[ي]/g, 'ی')
            .replace(/[ك]/g, 'ک')
            .replace(/[\u200c\s]+/g, ' ')
            .replace(/[\/\\_.,;:()\[\]{}\-–—]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();
    }

    function initPurchaseSupplierSearch() {
        if (!supplierSelectEl || !window.jQuery || !window.jQuery.fn?.select2) return;

        const $supplierSelect = window.jQuery(supplierSelectEl);
        const placeholder = supplierSelectEl.dataset.placeholder || 'جستجوی تأمین‌کننده / مشتری...';

        if ($supplierSelect.hasClass('select2-hidden-accessible')) {
            $supplierSelect.select2('destroy');
        }

        $supplierSelect.select2({
            width: '100%',
            dir: 'rtl',
            placeholder,
            allowClear: true,
            minimumInputLength: 1,
            language: {
                inputTooShort: () => 'برای نمایش نتیجه، نام یا موبایل را جستجو کنید.',
                noResults: () => 'نتیجه‌ای یافت نشد.',
                searching: () => 'در حال جستجو...'
            },
            matcher: function (params, data) {
                const term = normalizePurchaseSearchText(params.term || '');
                if (term === '') return null;
                const text = normalizePurchaseSearchText(data.text || '');
                const tokens = term.split(' ').filter(Boolean);
                return tokens.every(token => text.includes(token)) ? data : null;
            }
        });
    }

    function debounce(fn, delay = 200) {
        let timer = null;
        return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), delay);
        };
    }

    function parseNumericInput(value) {
        const normalized = normalizeDigits(value).replace(/[^\d]/g, '');
        return Number(normalized || 0);
    }

    function formatNumericInput(value) {
        if (String(value ?? '').trim() === '') return '';
        return parseNumericInput(value).toLocaleString('en-US');
    }

    function formatFa(value) {
        return Number(value || 0).toLocaleString('fa-IR');
    }

    function formatMoney(value) {
        return `${formatFa(value)} ریال`;
    }

    function currentSalePriceMessage(value) {
        const price = Number(value || 0);
        return price > 0 ? `قیمت فروش فعلی: ${formatMoney(price)}` : 'قیمت فروش فعلی موجود نیست';
    }

    function calculateDiscount(base, type, value) {
        const v = Number(value || 0);
        if (!type || v <= 0 || base <= 0) return 0;
        if (type === 'percent') return Math.floor(base * Math.min(v, 100) / 100);
        return Math.min(v, base);
    }

    function productById(productId) {
        return products.find((p) => String(p.id) === String(productId)) || null;
    }

    function categoryName(categoryId) {
        return categories.find((c) => String(c.id) === String(categoryId))?.name || 'بدون دسته';
    }

    function variantLabel(variant) {
        const parts = [];
        if (variant.model_name) parts.push(variant.model_name);
        if (variant.variety_name && variant.variety_name !== '—') parts.push(variant.variety_name);
        return parts.join(' / ') || variant.title || variant.name || 'تنوع عمومی';
    }

    function normalizeVariant(variant, productId) {
        return {
            ...variant,
            id: Number(variant.id || 0),
            product_id: Number(variant.product_id || productId || 0),
            name: variant.name || variant.variant_name || variant.title || '',
            code: variant.code || variant.variant_code || variant.sku || variant.barcode || '',
            stock: Number(variant.central_stock ?? variant.stock ?? 0),
            reserved: Number(variant.reserved || 0),
            barcode: variant.barcode || '',
            buy_price: Number(variant.buy_price || 0),
            sell_price: Number(variant.sell_price || variant.price || 0),
            color_name: variant.color_name || variant.color?.name || '',
            color_code: variant.color_code || variant.color?.code || '',
        };
    }

    function realVariantsForProduct(product, incomingVariants = null) {
        const rows = Array.isArray(incomingVariants) ? incomingVariants : (product.variants || []);
        const seen = new Set();

        return rows
            .map((variant) => normalizeVariant(variant, product.id))
            .filter((variant) => String(variant.product_id) === String(product.id) && variant.id > 0)
            .filter((variant) => {
                if (seen.has(variant.id)) return false;
                seen.add(variant.id);
                return true;
            });
    }

    async function fetchRealProductVariants(product) {
        const url = variantsUrlTemplate.replace('__PRODUCT__', encodeURIComponent(product.id));
        const response = await fetch(url, { headers: { 'Accept': 'application/json' } });

        if (!response.ok) {
            throw new Error('خطا در دریافت تنوع‌های واقعی محصول.');
        }

        const payload = await response.json();
        product.variants = realVariantsForProduct(product, payload.variants || []);
        return product.variants;
    }

    function filteredProducts() {
        const categoryId = quickCategoryEl.value || '';
        const term = normalizeDigits(quickSearchEl.value).trim().toLowerCase();

        return products.filter((product) => {
            if (categoryId && String(product.category_id) !== String(categoryId)) return false;
            if (!term) return true;

            const haystack = [product.name, product.code, product.short_barcode, product.sku]
                .concat((product.variants || []).flatMap((variant) => [variant.name, variant.code]))
                .map((v) => normalizeDigits(v).toLowerCase())
                .join(' ');

            return haystack.includes(term);
        });
    }

    function renderProductOptions() {
        const list = filteredProducts().slice(0, 200);
        if (!list.length) {
            quickProductEl.innerHTML = '<option value="">محصولی پیدا نشد</option>';
            return;
        }

        quickProductEl.innerHTML = '<option value="">انتخاب محصول...</option>' + list.map((product) => {
            const code = product.code || product.sku || '-';
            return `<option value="${product.id}">${escapeHtml(product.name)} (${escapeHtml(code)})</option>`;
        }).join('');
    }


    function variantSearchText(product, variant) {
        return [
            product.name,
            product.code,
            product.short_barcode,
            product.sku,
            variantLabel(variant),
            variant.name,
            variant.title,
            variant.model_name,
            variant.model_code,
            variant.variety_name,
            variant.variety_code,
            variant.design_title,
            variant.color_name,
            variant.color_code,
            variant.code,
            variant.variant_code,
            variant.sku,
            variant.barcode,
        ].filter(Boolean).join(' ');
    }

    function rowHasQty(row) {
        return parseNumericInput(row.querySelector('[data-qty]')?.value || 0) > 0;
    }

    function purchaseSearchTokens(value) {
        return normalizePurchaseSearchText(value).split(' ').filter(Boolean);
    }

    function prepareVariantRowSearch(row) {
        if (!row.dataset.normalizedSearch) {
            row.dataset.normalizedSearch = normalizePurchaseSearchText([
                row.dataset.search || '',
                row.textContent || '',
            ].join(' '));
        }

        return row.dataset.normalizedSearch;
    }

    function rowMatchesPurchaseSearch(row, tokens) {
        if (!tokens.length) return true;

        const haystack = prepareVariantRowSearch(row);
        return tokens.every((token) => haystack.includes(token));
    }

    function updateVariantFilter(card) {
        const searchEl = card.querySelector('[data-variant-search]');
        const onlyQtyEl = card.querySelector('[data-only-qty]');
        const rows = Array.from(card.querySelectorAll('[data-variant-row]'));
        const tokens = purchaseSearchTokens(searchEl?.value || '');
        const onlyQty = Boolean(onlyQtyEl?.checked);
        let visibleCount = 0;
        let qtyCount = 0;

        rows.forEach((row) => {
            const hasQty = rowHasQty(row);
            if (hasQty) qtyCount++;
            row.classList.toggle('has-qty', hasQty);

            const matchesSearch = rowMatchesPurchaseSearch(row, tokens);
            const matchesQty = !onlyQty || hasQty;
            const shouldShow = matchesSearch && matchesQty;
            row.classList.toggle('d-none', !shouldShow);
            if (shouldShow) visibleCount++;
        });

        const counterEl = card.querySelector('[data-variant-filter-counter]');
        if (counterEl) {
            counterEl.textContent = `${formatFa(rows.length)} تنوع | ${formatFa(visibleCount)} مورد نمایش داده شده | ${formatFa(qtyCount)} ردیف دارای تعداد`;
        }

        card.querySelector('[data-variant-empty]')?.classList.toggle('d-none', visibleCount > 0 || rows.length === 0);
    }

    function updateAllVariantFilters() {
        productCardsEl.querySelectorAll('[data-product-card]').forEach(updateVariantFilter);
    }

    function rowPriceOverridden(row) {
        return row.dataset.buyOverridden === '1' || row.dataset.sellOverridden === '1';
    }

    function updatePriceBadge(row) {
        const badge = row.querySelector('[data-price-badge]');
        if (badge) badge.classList.toggle('d-none', !rowPriceOverridden(row));
    }

    function cardDefaults(card) {
        return {
            buy: card.querySelector('[data-product-buy]')?.value || '',
            sell: card.querySelector('[data-product-sell]')?.value || '',
        };
    }

    function applyDefaultsToCard(card, force = false, includeActiveRows = false, fields = ['buy', 'sell']) {
        const defaults = cardDefaults(card);
        card.querySelectorAll('[data-variant-row]').forEach((row) => {
            const buyEl = row.querySelector('[data-buy]');
            const sellEl = row.querySelector('[data-sell]');
            const isActiveRow = parseNumericInput(row.querySelector('[data-qty]')?.value || 0) > 0;
            const shouldApplyToActiveRow = includeActiveRows && isActiveRow;

            if (fields.includes('buy') && (force || shouldApplyToActiveRow || row.dataset.buyOverridden !== '1')) {
                buyEl.value = defaults.buy === '' ? '' : formatNumericInput(defaults.buy);
                row.dataset.buyOverridden = (force || shouldApplyToActiveRow) ? '0' : (row.dataset.buyOverridden || '0');
            }

            if (fields.includes('sell') && (force || shouldApplyToActiveRow || row.dataset.sellOverridden !== '1')) {
                sellEl.value = defaults.sell === '' ? '' : formatNumericInput(defaults.sell);
                row.dataset.sellOverridden = (force || shouldApplyToActiveRow) ? '0' : (row.dataset.sellOverridden || '0');
            }

            updatePriceBadge(row);
        });
        recalc();
    }

    function productCardTemplate(product) {
        const variants = realVariantsForProduct(product);
        const variantsRows = variants.length ? variants.map((variant) => `
            <tr class="purchase-variant-row" data-variant-row data-variant-id="${variant.id}" data-buy-overridden="0" data-sell-overridden="0" data-search="${escapeHtml(variantSearchText(product, variant))}" data-normalized-search="${escapeHtml(normalizePurchaseSearchText(variantSearchText(product, variant)))}">
                <td>
                    <div class="variant-title">${escapeHtml(variantLabel(variant))}</div>
                    <div class="small text-muted">${escapeHtml(variant.name || '')}</div>
                </td>
                <td class="mono small">${escapeHtml(variant.code || '—')}</td>
                <td>${formatFa(variant.stock || 0)}</td>
                <td><input type="number" min="0" class="form-control form-control-sm qty-input" data-qty value=""></td>
                <td><input type="text" inputmode="numeric" class="form-control form-control-sm formatted-number price-input" data-buy value=""></td>
                <td><input type="text" inputmode="numeric" class="form-control form-control-sm formatted-number price-input" data-sell value=""></td>
                <td><span class="current-sale-price ${Number(variant.sell_price || 0) > 0 ? '' : 'missing'}" data-current-sale-price data-sale-price="${Number(variant.sell_price || 0)}">${escapeHtml(currentSalePriceMessage(variant.sell_price))}</span></td>
                <td><span class="badge text-bg-warning d-none" data-price-badge>قیمت اختصاصی</span></td>
                <td><button type="button" class="btn btn-sm btn-outline-secondary" data-clear-row>خالی</button></td>
            </tr>
        `).join('') : `
            <tr>
                <td colspan="9" class="text-center text-muted py-3">برای این محصول تنوعی ثبت نشده است؛ ابتدا برای محصول یک تنوع عمومی تعریف کنید.</td>
            </tr>
        `;

        return `
            <div class="purchase-product-card" data-product-card data-product-id="${product.id}">
                <div class="product-card-head d-flex justify-content-between align-items-start gap-2 flex-wrap">
                    <div>
                        <div class="h6 mb-1">${escapeHtml(product.name)}</div>
                        <div class="d-flex gap-2 flex-wrap">
                            <span class="product-code-chip mono">کد: ${escapeHtml(product.code || product.sku || '—')}</span>
                            <span class="product-stat-chip">${escapeHtml(categoryName(product.category_id))}</span>
                            <span class="product-stat-chip">${formatFa(variants.length)} تنوع</span>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-remove-product>حذف کل محصول</button>
                </div>

                <div class="p-3">
                    <div class="price-panel mb-3">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label small mb-1">قیمت خرید کلی محصول</label>
                                <input type="text" inputmode="numeric" class="form-control form-control-sm formatted-number" data-product-buy placeholder="مثلاً 200,000">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small mb-1">قیمت فروش کلی محصول</label>
                                <input type="text" inputmode="numeric" class="form-control form-control-sm formatted-number" data-product-sell placeholder="مثلاً 250,000">
                            </div>
                            <div class="col-md-3 d-grid">
                                <button type="button" class="btn btn-sm btn-outline-primary" data-apply-all>اعمال قیمت روی همه تنوع‌ها</button>
                            </div>
                            <div class="col-md-3 small text-muted">این قیمت به‌صورت پیش‌فرض روی همه تنوع‌های این محصول اعمال می‌شود.</div>
                        </div>
                    </div>

                    <div class="purchase-variant-filter mb-3" data-variant-filter>
                        <div class="row g-2 align-items-center">
                            <div class="col-md-6">
                                <input type="search" class="form-control form-control-sm" data-variant-search placeholder="جستجو در تنوع‌ها، مدل، طرح، رنگ، کد یا بارکد...">
                            </div>
                            <div class="col-md-3">
                                <label class="form-check mb-0 small">
                                    <input class="form-check-input" type="checkbox" data-only-qty>
                                    <span class="form-check-label">نمایش فقط ردیف‌های دارای تعداد</span>
                                </label>
                            </div>
                            <div class="col-md-3 text-md-end">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-clear-filter>پاک کردن فیلتر</button>
                            </div>
                        </div>
                        <div class="small text-muted mt-2" data-variant-filter-counter>${formatFa(variants.length)} تنوع | ${formatFa(variants.length)} مورد نمایش داده شده | ۰ ردیف دارای تعداد</div>
                    </div>

                    <div class="purchase-variant-empty d-none mb-2 small" data-variant-empty>موردی با این جستجو پیدا نشد.</div>

                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle variants-table mb-2">
                            <thead class="table-light">
                                <tr>
                                    <th>نام مدل / طرح / تنوع</th>
                                    <th>کد تنوع / بارکد</th>
                                    <th>موجودی فعلی</th>
                                    <th>تعداد خرید</th>
                                    <th>قیمت خرید</th>
                                    <th>قیمت فروش خرید</th>
                                    <th>قیمت فروش فعلی سیستم</th>
                                    <th>وضعیت قیمت</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody>${variantsRows}</tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-end gap-3 small text-muted">
                        <div>جمع تعداد این محصول: <strong data-card-qty>0</strong></div>
                        <div>جمع مبلغ خرید این محصول: <strong data-card-total>0</strong> ریال</div>
                    </div>
                </div>
            </div>
        `;
    }

    async function addProductCard(productId, initialRows = []) {
        const product = productById(productId);
        if (!product) return null;

        const existing = productCardsEl.querySelector(`[data-product-card][data-product-id="${product.id}"]`);
        if (existing) {
            existing.scrollIntoView({ behavior: 'smooth', block: 'center' });
            existing.classList.add('border-primary');
            setTimeout(() => existing.classList.remove('border-primary'), 1200);
            alert('این محصول قبلاً به لیست خرید اضافه شده است.');
            return existing;
        }

        if (!initialRows.length) {
            try {
                await fetchRealProductVariants(product);
            } catch (error) {
                alert(error.message || 'خطا در دریافت تنوع‌های محصول.');
                return null;
            }
        } else {
            product.variants = realVariantsForProduct(product);
        }

        productCardsEl.insertAdjacentHTML('beforeend', productCardTemplate(product));
        const card = productCardsEl.querySelector(`[data-product-card][data-product-id="${product.id}"]`);

        if (initialRows.length) {
            const first = initialRows[0];
            card.querySelector('[data-product-buy]').value = formatNumericInput(first.product_buy_price || first.buy_price || '');
            card.querySelector('[data-product-sell]').value = formatNumericInput(first.product_sell_price || first.sell_price || '');

            initialRows.forEach((item) => {
                const row = card.querySelector(`[data-variant-row][data-variant-id="${item.variant_id}"]`);
                if (!row) return;

                row.dataset.purchaseItemId = item.id || '';
                row.querySelector('[data-qty]').value = item.quantity || '';
                row.querySelector('[data-buy]').value = formatNumericInput(item.buy_price || '');
                row.querySelector('[data-sell]').value = formatNumericInput(item.sell_price || '');
                row.dataset.buyOverridden = item.price_overridden ? '1' : '0';
                row.dataset.sellOverridden = item.price_overridden ? '1' : '0';
                updatePriceBadge(row);
            });
        }

        updateVariantFilter(card);
        recalc();
        return card;
    }

    function recalc() {
        let subtotal = 0;
        let activeVariantCount = 0;

        productCardsEl.querySelectorAll('[data-product-card]').forEach((card) => {
            let cardQty = 0;
            let cardTotal = 0;

            card.querySelectorAll('[data-variant-row]').forEach((row) => {
                const qty = Number(row.querySelector('[data-qty]')?.value || 0);
                const buy = parseNumericInput(row.querySelector('[data-buy]')?.value || 0);
                const lineTotal = qty > 0 ? qty * buy : 0;

                if (qty > 0) {
                    activeVariantCount++;
                    cardQty += qty;
                    cardTotal += lineTotal;
                }
            });

            card.querySelector('[data-card-qty]').textContent = formatFa(cardQty);
            card.querySelector('[data-card-total]').textContent = formatFa(cardTotal);
            updateVariantFilter(card);
            subtotal += cardTotal;
        });

        const invoiceDiscount = calculateDiscount(
            subtotal,
            invoiceDiscountTypeEl.value,
            parseNumericInput(invoiceDiscountValueEl.value || 0)
        );
        const total = Math.max(0, subtotal - invoiceDiscount);

        summaryProductCountEl.textContent = formatFa(productCardsEl.querySelectorAll('[data-product-card]').length);
        summaryVariantCountEl.textContent = formatFa(activeVariantCount);
        subtotalEl.textContent = formatFa(subtotal);
        totalDiscountEl.textContent = formatFa(invoiceDiscount);
        totalEl.textContent = formatFa(total);
    }

    function formatInputElement(input) {
        const atEnd = input.selectionStart === input.value.length;
        input.value = formatNumericInput(input.value);
        if (atEnd) input.setSelectionRange(input.value.length, input.value.length);
    }


    function numericPayloadValue(value) {
        return String(value ?? '').trim() === '' ? '' : parseNumericInput(value);
    }

    function buildPayload() {
        const items = [];

        productCardsEl.querySelectorAll('[data-product-card]').forEach((card) => {
            const productId = card.dataset.productId;
            const product = productById(productId);
            const defaults = cardDefaults(card);

            card.querySelectorAll('[data-variant-row]').forEach((row) => {
                const qty = parseNumericInput(row.querySelector('[data-qty]')?.value || 0);
                const purchaseItemId = row.dataset.purchaseItemId || '';
                if (qty <= 0 && !purchaseItemId) return;

                items.push({
                    ...(purchaseItemId ? { id: purchaseItemId } : {}),
                    client_key: `product-${productId}-variant-${row.dataset.variantId}`,
                    product_id: productId,
                    variant_id: row.dataset.variantId,
                    quantity: qty,
                    buy_price: numericPayloadValue(row.querySelector('[data-buy]')?.value || ''),
                    sell_price: numericPayloadValue(row.querySelector('[data-sell]')?.value || ''),
                    product_buy_price: numericPayloadValue(defaults.buy || ''),
                    product_sell_price: numericPayloadValue(defaults.sell || ''),
                    name: product?.name || '',
                    code: product?.code || product?.sku || '',
                });
            });
        });

        payloadEl.value = JSON.stringify(items);

        return items.length;
    }

    quickCategoryEl.addEventListener('change', renderProductOptions);
    quickSearchEl.addEventListener('input', renderProductOptions);

    const debouncedVariantFilter = debounce((card) => updateVariantFilter(card), 200);

    addProductBtn.addEventListener('click', () => {
        if (!quickProductEl.value) return;
        addProductCard(quickProductEl.value, []);
    });

    productCardsEl.addEventListener('input', (event) => {
        const target = event.target;
        const card = target.closest('[data-product-card]');
        if (!card) return;

        if (target.classList.contains('formatted-number')) {
            formatInputElement(target);
        }

        if (target.matches('[data-variant-search]')) {
            if (normalizePurchaseSearchText(target.value) === '') {
                updateVariantFilter(card);
            } else {
                debouncedVariantFilter(card);
            }
            return;
        }

        if (target.matches('[data-only-qty]')) {
            updateVariantFilter(card);
            return;
        }

        if (target.matches('[data-product-buy], [data-product-sell]')) {
            applyDefaultsToCard(card, false, true, [target.matches('[data-product-buy]') ? 'buy' : 'sell']);
            return;
        }

        if (target.matches('[data-buy], [data-sell]')) {
            const row = target.closest('[data-variant-row]');
            if (row) {
                if (target.matches('[data-buy]')) row.dataset.buyOverridden = '1';
                if (target.matches('[data-sell]')) row.dataset.sellOverridden = '1';
                updatePriceBadge(row);
            }
        }

        recalc();
    });

    productCardsEl.addEventListener('search', (event) => {
        const target = event.target;
        const card = target.closest('[data-product-card]');
        if (card && target.matches('[data-variant-search]')) {
            updateVariantFilter(card);
        }
    });

    productCardsEl.addEventListener('change', (event) => {
        const target = event.target;
        const card = target.closest('[data-product-card]');
        if (card && target.matches('[data-only-qty]')) {
            updateVariantFilter(card);
        }
    });

    productCardsEl.addEventListener('click', (event) => {
        const card = event.target.closest('[data-product-card]');
        if (!card) return;

        if (event.target.matches('[data-clear-filter]')) {
            const searchEl = card.querySelector('[data-variant-search]');
            const onlyQtyEl = card.querySelector('[data-only-qty]');
            if (searchEl) searchEl.value = '';
            if (onlyQtyEl) onlyQtyEl.checked = false;
            updateVariantFilter(card);
            searchEl?.focus();
            return;
        }

        if (event.target.matches('[data-remove-product]')) {
            card.remove();
            recalc();
            return;
        }

        if (event.target.matches('[data-apply-all]')) {
            if (confirm('قیمت همه تنوع‌های این محصول با قیمت کلی جایگزین شود؟')) {
                applyDefaultsToCard(card, true);
            }
            return;
        }

        if (event.target.matches('[data-clear-row]')) {
            const row = event.target.closest('[data-variant-row]');
            if (!row) return;
            row.querySelector('[data-qty]').value = '';
            updateVariantFilter(card);
            recalc();
        }
    });

    productCardsEl.addEventListener('keydown', (event) => {
        const card = event.target.closest('[data-product-card]');
        if (!card || event.key !== 'Enter') return;

        if (event.target.matches('[data-variant-search]')) {
            event.preventDefault();
            updateVariantFilter(card);
            const visibleRows = Array.from(card.querySelectorAll('[data-variant-row]')).filter((row) => !row.classList.contains('d-none'));
            if (visibleRows.length === 1) {
                const qtyEl = visibleRows[0].querySelector('[data-qty]');
                qtyEl?.focus();
                qtyEl?.select();
            }
        }

        if (event.target.matches('[data-qty]')) {
            event.preventDefault();
            const searchEl = card.querySelector('[data-variant-search]');
            searchEl?.focus();
            searchEl?.select();
        }
    });

    invoiceDiscountTypeEl.addEventListener('change', recalc);
    invoiceDiscountValueEl.addEventListener('input', () => {
        formatInputElement(invoiceDiscountValueEl);
        recalc();
    });

    purchaseForm.addEventListener('submit', (event) => {
        if (purchaseForm.dataset.submitting === '1') {
            event.preventDefault();
            return;
        }

        const count = buildPayload();
        invoiceDiscountValueEl.value = String(invoiceDiscountValueEl.value || '').trim() === '' ? '' : parseNumericInput(invoiceDiscountValueEl.value);

        if (count === 0) {
            event.preventDefault();
            alert('حداقل یک تنوع را برای خرید انتخاب کنید.');
            return;
        }

        purchaseForm.dataset.submitting = '1';
        purchaseForm.querySelectorAll('button[type="submit"]').forEach((button) => {
            button.disabled = true;
            button.dataset.originalText = button.textContent;
            button.textContent = 'در حال ثبت...';
        });
    });

    async function hydrateInitialItems() {
        if (!Array.isArray(initialItems) || !initialItems.length) return;

        const groups = new Map();
        initialItems.forEach((item) => {
            if (!groups.has(String(item.product_id))) groups.set(String(item.product_id), []);
            groups.get(String(item.product_id)).push(item);
        });

        for (const [productId, items] of groups.entries()) {
            await addProductCard(productId, items);
        }
    }

    initPurchaseSupplierSearch();
    renderProductOptions();
    hydrateInitialItems();
    recalc();
    updateAllVariantFilters();

    const firstInvalidField = purchaseForm.querySelector('.is-invalid');
    if (firstInvalidField) {
        setTimeout(() => {
            firstInvalidField.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstInvalidField.focus({ preventScroll: true });
        }, 100);
    }
})();
</script>
@endsection
