@extends('layouts.app')

@section('content')
@php
    $rial = fn ($value) => number_format((int) $value).' ریال';
    $discountType = old('invoice_discount_type', $invoice->invoice_discount_type ?: 'amount');
    $discountValue = (int) old('invoice_discount_value', $invoice->invoice_discount_value ?? $invoice->invoice_discount_amount ?? 0);
    $originalItems = $invoice->items->keyBy('id');

    $variantLabel = function ($variant) {
        if (! $variant) {
            return 'تنوع';
        }
        $parts = collect([
            $variant->modelList?->model_name,
            $variant->variety_name,
            $variant->variant_name,
        ])->map(fn ($part) => trim((string) $part))->filter(fn ($part) => $part !== '' && $part !== '—')->unique()->values();

        return $parts->isNotEmpty() ? $parts->implode(' / ') : 'تنوع پیش‌فرض';
    };

    $bootItems = collect($formItems)->map(function ($item) use ($formVariants, $originalItems, $variantLabel) {
        $variant = $formVariants->get((int) ($item['variant_id'] ?? 0));
        $product = $variant?->product;
        $original = ! empty($item['id']) ? $originalItems->get((int) $item['id']) : null;

        return [
            'id' => ! empty($item['id']) ? (int) $item['id'] : null,
            'product_id' => (int) ($item['product_id'] ?? 0),
            'variant_id' => (int) ($item['variant_id'] ?? 0),
            'product_title' => $product?->name ?: $product?->title ?: 'کالا',
            'product_code' => $product?->code ?: $product?->sku ?: '',
            'label' => $variantLabel($variant),
            'barcode' => $variant?->variant_code ?: '',
            'quantity' => (int) ($item['quantity'] ?? 0),
            'price' => (int) ($item['price'] ?? 0),
            'line_discount_amount' => (int) ($item['line_discount_amount'] ?? 0),
            'ref_price' => (int) ($variant?->sell_price ?? 0),
            'original' => $original ? [
                'quantity' => (int) $original->quantity,
                'price' => (int) $original->price,
                'line_discount_amount' => (int) ($original->line_discount_amount ?? 0),
            ] : null,
        ];
    })->values();

    $backUrl = route('preinvoice.my.index', ['tab' => 'needs-correction']);
    $productsApiUrl = route('preinvoice.api.products', [], false);
    $productApiUrlTemplate = route('preinvoice.api.product', ['product' => '__ID__'], false);
@endphp

@include('invoices.partials.correction-editor-styles')
<style>
    .seller-correction { max-width: 960px; }
    .seller-correction .pill-warn { display: inline-flex; align-items: center; border-radius: 999px; padding: 4px 10px; font-size: .72rem; font-weight: 900; color: #b7791f; border: 1px solid rgba(241,171,39,.35); background: rgba(241,171,39,.12); }
    .seller-correction .reason-banner { border: 1px solid rgba(241,171,39,.35); background: linear-gradient(180deg, rgba(241,171,39,.16), rgba(241,171,39,.06)); border-radius: 15px; padding: 12px 14px; margin-bottom: 12px; box-shadow: var(--shadow-sm); }
    .seller-correction .reason-text { color: var(--brand-darker); font-weight: 800; white-space: pre-wrap; }
    .seller-correction .soft-card, .seller-correction .soft-card-lg { margin-bottom: 14px; }

    .seller-correction #correctionGroups .group-card.is-changed { border-color: rgba(241,171,39,.5); }
    .seller-correction #correctionGroups .group-card.has-error { border-color: rgba(209,77,77,.45); }
    .seller-correction .group-flags { display: inline-flex; gap: 4px; margin-inline-start: 6px; vertical-align: middle; }
    .seller-correction .badge-new { background: rgba(51,199,192,.12); color: var(--brand-dark); border-color: rgba(51,199,192,.3); }
    .seller-correction .badge-changed { background: rgba(241,171,39,.12); color: #b7791f; border-color: rgba(241,171,39,.35); }
    .seller-correction .badge-removed { background: var(--danger-soft); color: var(--danger); border-color: rgba(209,77,77,.2); }

    .seller-correction .items-head, .seller-correction .c-row { display: grid; grid-template-columns: minmax(0, 2.2fr) 84px 140px 130px 130px 76px; gap: 7px; align-items: start; }
    .seller-correction .items-head { font-size: .72rem; font-weight: 800; color: var(--muted); padding: 0 8px 4px; }
    .seller-correction .c-row { padding: 8px; border: 1px solid rgba(12,83,103,.08); border-radius: 10px; background: #fff; margin-top: 6px; }
    .seller-correction .c-row.is-changed { border-color: rgba(241,171,39,.45); background: #fffaf0; }
    .seller-correction .c-row.is-new { border-color: rgba(51,199,192,.4); background: #f4fbfa; }
    .seller-correction .c-row.is-removed { border-color: rgba(209,77,77,.25); background: #fdf5f5; }
    .seller-correction .c-row.is-removed .c-title, .seller-correction .c-row.is-removed .c-total { text-decoration: line-through; color: var(--muted); }
    .seller-correction .c-title { font-weight: 800; color: var(--brand-darker); font-size: .82rem; line-height: 1.6; }
    .seller-correction .c-row .form-control { font-size: .82rem; padding: 4px 7px; direction: ltr; text-align: left; }
    .seller-correction .c-row .form-control.is-diff { border-color: var(--accent); background: #fffdf5; }
    .seller-correction .c-was { font-size: .68rem; color: #b7791f; margin-top: 2px; }
    .seller-correction .c-total { font-weight: 900; color: var(--accent-dark); font-size: .82rem; padding-top: 5px; white-space: nowrap; }
    .seller-correction .c-msg { grid-column: 1 / -1; font-size: .72rem; border-radius: 8px; padding: 3px 8px; }
    .seller-correction .c-msg.is-error { color: var(--danger); background: var(--danger-soft); }
    .seller-correction .c-msg.is-warn { color: #b7791f; background: rgba(241,171,39,.12); }
    .seller-correction .cell-label { display: none; }

    .seller-correction .breakdown { background: #f8fafc; border: 1px solid rgba(12,83,103,.10); border-radius: 12px; padding: 10px 12px; font-size: .84rem; }
    .seller-correction .breakdown .b-row { display: flex; justify-content: space-between; gap: 8px; padding: 3px 0; }
    .seller-correction .breakdown .b-row.is-total { border-top: 1px dashed rgba(12,83,103,.18); margin-top: 4px; padding-top: 7px; font-weight: 900; color: var(--brand-darker); font-size: .95rem; }
    .seller-correction .diff-up { color: var(--danger); }
    .seller-correction .diff-down { color: var(--success); }

    .seller-correction .sticky-bar { position: sticky; bottom: 0; z-index: 20; margin-bottom: 16px; background: rgba(255,253,249,.97); backdrop-filter: blur(4px); border: 1px solid rgba(51,199,192,.3); border-radius: 16px; box-shadow: 0 -6px 22px rgba(8,61,80,.10); padding: 10px 14px; display: flex; justify-content: space-between; align-items: center; gap: 10px; }
    .seller-correction .sticky-bar .grand { font-size: 1.1rem; font-weight: 900; color: var(--brand-darker); }
    .seller-correction .submit-hint { font-size: .74rem; color: var(--danger); font-weight: 700; }

    #correctionPickerModal .variant-pill--current { background: #fff7e6; color: #b7791f; border-color: rgba(241,171,39,.35); }

    @media (max-width: 767.98px) {
        .seller-correction .items-head { display: none; }
        .seller-correction .c-row { grid-template-columns: 1fr 1fr; }
        .seller-correction .c-row .c-cell-title { grid-column: 1 / -1; }
        .seller-correction .c-row .c-cell-actions { grid-column: 1 / -1; display: flex; justify-content: flex-end; }
        .seller-correction .cell-label { display: block; font-size: .68rem; font-weight: 800; color: var(--muted); margin-bottom: 2px; }
        .seller-correction .sticky-bar { padding: 8px 10px; gap: 8px; }
        .seller-correction .sticky-bar .grand { font-size: .95rem; }
        .seller-correction .sticky-bar #stickyDiff, .seller-correction .sticky-bar #correctionCancel, .seller-correction .sticky-bar .sticky-label { display: none; }
        .seller-correction .sticky-bar #correctionSubmit { font-size: .78rem; padding: 8px 10px; white-space: normal; line-height: 1.5; max-width: 190px; }
    }
</style>

<div class="container page-shell py-3 seller-correction">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h1 class="page-title">اصلاح فاکتور {{ $invoice->uuid }}</h1>
            <div class="hint mt-1">فقط کالاها، قیمت‌ها و تخفیف‌ها قابل اصلاح است · پس از ثبت نهایی به صف تأیید مجدد مالی می‌رود.</div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="pill-warn">نیازمند اصلاح فروشنده</span>
            <a class="btn btn-sm btn-outline-secondary rounded-3" href="{{ $backUrl }}">بازگشت به اسناد من</a>
        </div>
    </div>

    @if($errors->any())
        <div class="alert alert-danger border-0 shadow-sm rounded-4 py-2">
            <div class="fw-bold mb-1">اصلاحات ثبت نشد:</div>
            <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="reason-banner">
        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
            <div>
                <div class="hint fw-bold">علت ارجاع از مالی</div>
                <div class="reason-text">{{ $invoice->collection_note ?: 'توضیحی ثبت نشده است.' }}</div>
            </div>
            <div class="text-end">
                <div class="hint">مشتری: <strong>{{ $invoice->customer_name ?: '—' }}</strong></div>
                <div class="hint">مبلغ فعلی فاکتور: <strong>{{ $rial($invoice->total) }}</strong></div>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('preinvoice.my.invoice-correction.submit', $invoice->uuid) }}" id="sellerCorrectionForm" autocomplete="off" novalidate>
        @csrf
        <input type="hidden" name="opened_fingerprint" value="{{ $openedFingerprint }}">
        <div id="correctionItemInputs"></div>

        <div class="soft-card-lg product-focus">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h2 class="section-title">کالاها</h2>
                    <div class="hint mt-1">کد ۴ رقمی محصول مادر را وارد کنید یا از «یافتن کالا» با دسته‌بندی جستجو کنید.</div>
                </div>
            </div>

            <div class="quick-area mb-3">
                <div class="row g-2 align-items-end">
                    <div class="col-lg-4 col-sm-5">
                        <label class="label-sm" for="motherCodeInput">کد محصول مادر</label>
                        <input type="text" id="motherCodeInput" class="code-input" maxlength="4" inputmode="numeric" placeholder="4450">
                    </div>
                    <div class="col-lg-2 col-sm-3">
                        <button type="button" id="findMotherBtn" class="find-btn w-100">مشاهده</button>
                    </div>
                    <div class="col-lg-6 col-sm-4">
                        <button type="button" id="openProductFinderBtn" class="btn btn-outline-primary finder-trigger w-100 d-flex align-items-center justify-content-center gap-2" data-bs-toggle="modal" data-bs-target="#productFinderModal">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg>
                            <span>یافتن کالا</span>
                        </button>
                    </div>
                </div>
                <div class="hint mt-2" id="motherCodeHint">موجودی کالاها هنگام ثبت نهایی دوباره بررسی می‌شود.</div>
            </div>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <h2 class="section-title">سبد فاکتور</h2>
                <div class="hint" id="correctionItemsCount"></div>
            </div>
            <div id="correctionGroups"></div>
        </div>

        <div class="soft-card compact-card">
            <h2 class="section-title mb-2">تخفیف کلی و توضیح برای مالی</h2>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="label-sm" for="correctionDiscountValueView">تخفیف کلی فاکتور</label>
                    <div class="discount-control">
                        <select id="correctionDiscountType" name="invoice_discount_type" class="form-select form-select-sm">
                            <option value="amount" @selected($discountType === 'amount')>ریال</option>
                            <option value="percent" @selected($discountType === 'percent')>درصد</option>
                        </select>
                        <input id="correctionDiscountValueView" type="text" inputmode="numeric" class="form-control form-control-sm" dir="ltr" value="{{ $discountValue }}">
                    </div>
                    <input id="correctionDiscountValue" name="invoice_discount_value" type="hidden" value="{{ $discountValue }}">
                    <div class="discount-line" id="correctionDiscountHint"></div>

                    <label class="label-sm mt-3" for="correctionNote">توضیح اصلاح یا ارسال بدون تغییر <span class="text-danger">*</span></label>
                    <textarea id="correctionNote" name="change_note" class="form-control form-control-sm" rows="3" maxlength="2000" placeholder="برای مالی بنویسید چه چیزی اصلاح شد، یا چرا بدون تغییر ارسال می‌کنید.">{{ old('change_note') }}</textarea>
                </div>
                <div class="col-md-6">
                    <div class="breakdown">
                        <div class="b-row"><span>جمع اقلام (قبل از تخفیف)</span><strong id="sumGross">—</strong></div>
                        <div class="b-row"><span>تخفیف ردیف‌ها</span><strong id="sumLineDiscount">—</strong></div>
                        <div class="b-row"><span>تخفیف کلی</span><strong id="sumInvoiceDiscount">—</strong></div>
                        <div class="b-row"><span>هزینه ارسال (بدون تغییر)</span><strong>{{ $rial($invoice->shipping_price) }}</strong></div>
                        <div class="b-row is-total"><span>جمع نهایی</span><span id="sumTotal">—</span></div>
                        <div class="b-row"><span>مبلغ قبلی فاکتور</span><span>{{ $rial($invoice->total) }}</span></div>
                        <div class="b-row"><span>تفاوت</span><strong id="sumDiff">—</strong></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="sticky-bar">
            <div>
                <div class="hint sticky-label">جمع نهایی برای بررسی مالی</div>
                <div class="d-flex align-items-baseline gap-2 flex-wrap">
                    <span class="grand" id="stickyTotal">—</span>
                    <span class="hint" id="stickyDiff"></span>
                </div>
                <div class="submit-hint" id="correctionSubmitHint"></div>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary rounded-3" href="{{ $backUrl }}" id="correctionCancel">انصراف</a>
                <button type="submit" class="btn btn-primary rounded-3 px-4 fw-bold" id="correctionSubmit">ثبت نهایی و ارسال برای تأیید مجدد مالی</button>
            </div>
        </div>
    </form>
</div>

@include('preinvoice.partials.product-finder-modal')

<div class="modal fade" id="correctionPickerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl variant-modal-dialog">
        <div class="modal-content variant-modal-content">
            <div class="modal-header picker-head variant-modal__header">
                <div>
                    <h5 class="modal-title fw-bold" id="pickerModalTitle">انتخاب تنوع</h5>
                    <div class="hint mt-1" id="pickerModalSubTitle">—</div>
                </div>
                <button type="button" class="btn-close m-0" data-bs-dismiss="modal" aria-label="بستن"></button>
            </div>
            <div class="variant-modal__search">
                <div class="picker-search-wrap">
                    <input type="text" id="pickerSearchInput" class="picker-search" placeholder="جستجو در تنوع‌ها...">
                    <button type="button" id="clearPickerSearchBtn" class="picker-search-clear" aria-label="پاک کردن جستجو">×</button>
                </div>
                <label class="stock-toggle">
                    <input type="checkbox" id="onlyInStockToggle">
                    <span>فقط موجودها</span>
                </label>
            </div>
            <div class="modal-body variant-modal__body">
                <div id="pickerLoading" class="empty-state d-none">در حال دریافت تنوع‌ها...</div>
                <div class="variant-list" id="pickerTableWrap"><div id="pickerRows"></div></div>
            </div>
            <div class="variant-modal__footer-extra">
                <div class="modal-discount-box">
                    <label class="label-sm">تخفیف محصول</label>
                    <div class="discount-control">
                        <select id="modalGroupDiscountType" class="form-select form-select-sm">
                            <option value="amount">ریال</option>
                            <option value="percent">درصد</option>
                        </select>
                        <input type="text" id="modalGroupDiscountValue" class="form-control form-control-sm" inputmode="numeric" dir="ltr" value="0">
                    </div>
                    <div class="discount-line">تخفیف: <strong id="modalGroupDiscountPreview">0 ریال</strong></div>
                    <div class="hint" style="font-size:.7rem">با تغییر این مقدار، تخفیف بین تنوع‌های این محصول به نسبت مبلغ تقسیم می‌شود.</div>
                </div>
                <div class="modal-summary-bar mt-2">
                    <div class="summary-stat"><div class="s-label">ردیف انتخاب‌شده</div><div class="s-val" id="modalSelectedRows">0</div></div>
                    <div class="summary-stat"><div class="s-label">جمع تعداد</div><div class="s-val" id="modalTotalQty">0</div></div>
                    <div class="summary-stat"><div class="s-label">مبلغ قبل تخفیف</div><div class="s-val" id="modalRawAmount">0 ریال</div></div>
                    <div class="summary-stat"><div class="s-label">جمع نهایی</div><div class="s-val" id="modalTotalAmount" style="color:var(--accent-dark)">0 ریال</div></div>
                </div>
            </div>
            <div class="modal-footer variant-modal__footer" style="background:linear-gradient(180deg,#f9f6ee,#f3eee5);border-top:1px solid rgba(12,83,103,.08);">
                <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">لغو</button>
                <button type="button" id="savePickerBtn" class="btn btn-primary rounded-3 fw-bold px-4">اعمال در فاکتور</button>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    'use strict';
    const API = {
        products: @json($productsApiUrl),
        product: id => @json($productApiUrlTemplate).replace('__ID__', encodeURIComponent(id)),
    };
    const BOOT_ITEMS = @json($bootItems);
    const ORIGINAL_TOTAL = {{ (int) $invoice->total }};
    const SHIPPING_PRICE = {{ (int) $invoice->shipping_price }};

    const $ = id => document.getElementById(id);
    const form = $('sellerCorrectionForm');
    const groupsBox = $('correctionGroups');
    const submitBtn = $('correctionSubmit');

    // ---------- helpers ----------
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const latin = v => String(v ?? '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const toInt = v => { const s = latin(v).replace(/[^\d]/g, ''); return s === '' ? 0 : parseInt(s, 10); };
    const fmt = n => Math.round(Number(n) || 0).toLocaleString('en-US');
    const money = n => fmt(n) + ' ریال';
    const clean = v => { const s = String(v ?? '').trim(); return s === '—' ? '' : s; };
    const calcDiscount = (base, type, value) => {
        base = Math.max(0, Number(base) || 0);
        value = Math.max(0, Number(value) || 0);
        return type === 'percent' ? Math.min(base, Math.floor(base * Math.min(value, 100) / 100)) : Math.min(base, Math.floor(value));
    };
    const formatInput = (input, raw) => {
        const formatted = input.value.trim() === '' ? '' : fmt(raw);
        if (input.value === formatted) return;
        const fromEnd = input.value.length - (input.selectionStart ?? input.value.length);
        input.value = formatted;
        const pos = Math.max(0, formatted.length - fromEnd);
        try { input.setSelectionRange(pos, pos); } catch (e) {}
    };

    // ---------- state ----------
    let uid = 0, dirty = false, submitting = false;
    const openGroups = new Set();
    const productInfo = new Map(); // product_id => {title, code}
    const items = BOOT_ITEMS.map(it => {
        productInfo.set(it.product_id, productInfo.get(it.product_id) || { title: it.product_title, code: it.product_code });
        const removed = !!it.id && Number(it.quantity) <= 0;
        return { ...it, key: ++uid, removed, restoreQty: removed ? (it.original?.quantity || 1) : it.quantity };
    });

    const groupIds = () => [...new Set(items.map(i => i.product_id))];
    const groupItems = pid => items.filter(i => i.product_id === pid);

    const rowState = it => {
        const gross = it.removed ? 0 : it.quantity * it.price;
        const diff = {};
        let changed = false;
        if (it.original && !it.removed) {
            ['quantity', 'price', 'line_discount_amount'].forEach(k => { diff[k] = it.original[k] !== it[k]; if (diff[k]) changed = true; });
        }
        const errors = [], warnings = [];
        if (!it.removed) {
            if (it.quantity < 1) errors.push(it.id ? 'تعداد صفر است؛ برای حذف از دکمهٔ «حذف» استفاده کنید.' : 'تعداد باید حداقل ۱ باشد.');
            if (it.price < 1) errors.push('قیمت واحد باید بیشتر از صفر باشد.');
            if (it.line_discount_amount > it.quantity * it.price) errors.push('تخفیف ردیف از مبلغ ردیف بیشتر است.');
            if (it.price > 0 && it.price % 1000 !== 0) warnings.push('قیمت واحد رُند نیست (' + fmt(it.price) + ')؛ احتمال اشتباه تایپی را بررسی کنید.');
            const ref = Number(it.ref_price) || 0;
            if (ref > 0 && it.price > 0 && Math.abs(it.price - ref) / ref > 0.3) warnings.push('قیمت واحد بیش از ۳۰٪ با قیمت فروش کالا (' + fmt(ref) + ' ریال) اختلاف دارد.');
        }
        return {
            gross, diff, changed, errors, warnings,
            lineDiscount: it.removed ? 0 : Math.min(it.line_discount_amount, gross),
            net: it.removed ? 0 : Math.max(gross - it.line_discount_amount, 0),
            active: !it.removed && it.quantity > 0,
        };
    };

    // ---------- basket rendering ----------
    const rowHtml = it => `
        <div class="c-row" data-key="${it.key}">
            <div class="c-cell-title">
                <div class="c-title">${esc(it.label)}</div>
                <div class="d-flex gap-1 flex-wrap mt-1">
                    ${it.barcode ? `<span class="badge-soft">${esc(it.barcode)}</span>` : ''}
                    ${!it.id ? '<span class="badge-soft badge-new">جدید</span>' : ''}
                    <span class="badge-soft badge-changed d-none" data-flag="changed">تغییر کرده</span>
                    <span class="badge-soft badge-removed d-none" data-flag="removed">حذف می‌شود</span>
                </div>
            </div>
            <div><span class="cell-label">تعداد</span><input type="text" inputmode="numeric" class="form-control" data-field="quantity" aria-label="تعداد"><div class="c-was d-none" data-was="quantity"></div></div>
            <div><span class="cell-label">قیمت واحد (ریال)</span><input type="text" inputmode="numeric" class="form-control" data-field="price" aria-label="قیمت واحد"><div class="c-was d-none" data-was="price"></div></div>
            <div><span class="cell-label">تخفیف ردیف (ریال)</span><input type="text" inputmode="numeric" class="form-control" data-field="line_discount_amount" aria-label="تخفیف ردیف"><div class="c-was d-none" data-was="line_discount_amount"></div></div>
            <div><span class="cell-label">مبلغ ردیف</span><div class="c-total" data-total>—</div></div>
            <div class="c-cell-actions">
                <button type="button" class="btn btn-sm btn-outline-danger rounded-3 w-100" data-action="remove-row">حذف</button>
                <button type="button" class="btn btn-sm btn-outline-success rounded-3 w-100 d-none" data-action="restore-row">بازگردانی</button>
            </div>
            <div class="c-msg is-error d-none" data-msg="error"></div>
            <div class="c-msg is-warn d-none" data-msg="warn"></div>
        </div>`;

    const renderBasket = () => {
        const pids = groupIds();
        if (!pids.length) {
            groupsBox.innerHTML = '<div class="empty-state">هیچ کالایی در فاکتور نیست. از بالا کالا اضافه کنید.</div>';
            refresh();
            return;
        }
        if (openGroups.size === 0 && pids.length <= 3) pids.forEach(pid => openGroups.add(pid));
        groupsBox.innerHTML = pids.map(pid => {
            const info = productInfo.get(pid) || {};
            return `
            <div class="group-card ${openGroups.has(pid) ? 'is-open' : ''}" data-group="${pid}">
                <button type="button" class="group-main" data-action="toggle-group" aria-expanded="${openGroups.has(pid)}">
                    <div class="group-title" title="${esc(info.title)}">${esc(info.title || 'کالا')}<span class="group-flags" data-group-flags></span></div>
                    <div class="group-amount" data-group-amount>—</div>
                    <div class="group-arrow">▼</div>
                </button>
                <div class="group-details">
                    <div class="d-flex flex-wrap gap-2 mb-2 align-items-center">
                        <span class="badge-soft">کد: ${esc(info.code || '—')}</span>
                        <span class="badge-soft" data-group-meta></span>
                        <span class="flex-grow-1"></span>
                        <button type="button" class="btn btn-sm btn-outline-primary rounded-3" data-action="edit-group">ویرایش تنوع‌ها و تعداد</button>
                        <button type="button" class="btn btn-sm btn-outline-danger rounded-3" data-action="remove-group">حذف محصول</button>
                    </div>
                    <div class="items-head"><div>تنوع</div><div>تعداد</div><div>قیمت واحد (ریال)</div><div>تخفیف ردیف (ریال)</div><div>مبلغ ردیف</div><div></div></div>
                    ${groupItems(pid).map(rowHtml).join('')}
                </div>
            </div>`;
        }).join('');
        items.forEach(it => {
            const row = groupsBox.querySelector(`[data-key="${it.key}"]`);
            ['quantity', 'price', 'line_discount_amount'].forEach(f => { row.querySelector(`[data-field="${f}"]`).value = fmt(it[f]); });
        });
        refresh();
    };

    const paintRow = it => {
        const row = groupsBox.querySelector(`[data-key="${it.key}"]`);
        if (!row) return null;
        const s = rowState(it);
        row.classList.toggle('is-removed', it.removed);
        row.classList.toggle('is-new', !it.id && !it.removed);
        row.classList.toggle('is-changed', s.changed);
        row.querySelector('[data-flag="changed"]').classList.toggle('d-none', !s.changed);
        row.querySelector('[data-flag="removed"]').classList.toggle('d-none', !it.removed);
        row.querySelector('[data-action="remove-row"]').classList.toggle('d-none', it.removed);
        row.querySelector('[data-action="restore-row"]').classList.toggle('d-none', !it.removed);
        ['quantity', 'price', 'line_discount_amount'].forEach(f => {
            const input = row.querySelector(`[data-field="${f}"]`);
            input.readOnly = it.removed;
            input.classList.toggle('is-diff', !!s.diff[f]);
            const was = row.querySelector(`[data-was="${f}"]`);
            was.classList.toggle('d-none', !s.diff[f]);
            if (s.diff[f]) was.textContent = 'قبلی: ' + fmt(it.original[f]);
        });
        row.querySelector('[data-total]').textContent = money(s.net);
        const err = row.querySelector('[data-msg="error"]');
        err.classList.toggle('d-none', !s.errors.length); err.textContent = s.errors.join(' ');
        const warn = row.querySelector('[data-msg="warn"]');
        warn.classList.toggle('d-none', !s.warnings.length); warn.textContent = s.warnings.join(' ');
        return s;
    };

    // ---------- totals ----------
    const discountType = $('correctionDiscountType');
    const discountRaw = $('correctionDiscountValue');
    const discountView = $('correctionDiscountValueView');
    const noteEl = $('correctionNote');

    function refresh() {
        let gross = 0, lineDiscount = 0, active = 0, removed = 0, hasError = false;
        const perGroup = new Map();
        items.forEach(it => {
            const s = paintRow(it) || rowState(it);
            gross += s.gross; lineDiscount += s.lineDiscount;
            if (s.active) active++;
            if (it.removed) removed++;
            if (s.errors.length) hasError = true;
            const g = perGroup.get(it.product_id) || { net: 0, qty: 0, rows: 0, changed: false, added: false, removed: false, error: false };
            g.net += s.net; g.qty += it.removed ? 0 : it.quantity; g.rows += it.removed ? 0 : 1;
            g.changed = g.changed || s.changed; g.added = g.added || (!it.id && !it.removed); g.removed = g.removed || it.removed;
            g.error = g.error || s.errors.length > 0;
            perGroup.set(it.product_id, g);
        });
        perGroup.forEach((g, pid) => {
            const card = groupsBox.querySelector(`[data-group="${pid}"]`);
            if (!card) return;
            card.querySelector('[data-group-amount]').textContent = money(g.net);
            card.querySelector('[data-group-meta]').textContent = 'ردیف: ' + fmt(g.rows) + ' · تعداد: ' + fmt(g.qty);
            card.classList.toggle('is-changed', g.changed || g.added || g.removed);
            card.classList.toggle('has-error', g.error);
            card.querySelector('[data-group-flags]').innerHTML =
                (g.error ? '<span class="badge-soft badge-removed">خطا</span>' : '') +
                (g.added ? '<span class="badge-soft badge-new">جدید</span>' : '') +
                (g.changed ? '<span class="badge-soft badge-changed">تغییر</span>' : '') +
                (g.removed ? '<span class="badge-soft badge-removed">حذف</span>' : '');
        });

        const base = Math.max(gross - lineDiscount, 0);
        const type = discountType.value;
        const value = toInt(discountRaw.value);
        const discountError = type === 'percent' && value > 100 ? 'درصد تخفیف باید بین ۰ تا ۱۰۰ باشد.' : '';
        const invoiceDiscount = calcDiscount(base, type, value);
        const total = Math.max(base - invoiceDiscount, 0) + SHIPPING_PRICE;
        const diff = total - ORIGINAL_TOTAL;
        const diffText = diff === 0 ? 'بدون تغییر مبلغ' : (diff > 0 ? '+' : '−') + money(Math.abs(diff));
        const diffClass = diff > 0 ? 'diff-up' : (diff < 0 ? 'diff-down' : '');

        $('sumGross').textContent = money(gross);
        $('sumLineDiscount').textContent = money(lineDiscount);
        $('sumInvoiceDiscount').textContent = money(invoiceDiscount);
        $('sumTotal').textContent = money(total);
        $('stickyTotal').textContent = money(total);
        $('sumDiff').textContent = diffText; $('sumDiff').className = diffClass;
        $('stickyDiff').textContent = diff === 0 ? '(بدون تغییر مبلغ)' : '(' + diffText + ' نسبت به قبل)';
        $('stickyDiff').className = 'hint ' + diffClass;
        $('correctionDiscountHint').textContent = discountError || ('مبلغ تخفیف کلی: ' + money(invoiceDiscount));
        $('correctionDiscountHint').classList.toggle('text-danger', !!discountError);
        $('correctionItemsCount').textContent = fmt(perGroup.size) + ' محصول · ' + fmt(active) + ' ردیف فعال' + (removed ? ' · ' + fmt(removed) + ' حذف‌شده' : '');

        let reason = '';
        if (active === 0) reason = 'فاکتور باید حداقل یک کالا داشته باشد.';
        else if (hasError) reason = 'خطای ردیف‌های کالا را برطرف کنید.';
        else if (discountError) reason = discountError;
        else if (noteEl.value.trim() === '') reason = 'توضیح اصلاح برای مالی الزامی است.';
        $('correctionSubmitHint').textContent = reason;
        submitBtn.disabled = submitting || reason !== '';
    }

    // ---------- basket events ----------
    groupsBox.addEventListener('input', e => {
        const input = e.target.closest('[data-field]');
        if (!input) return;
        const it = items.find(i => i.key === Number(input.closest('[data-key]').dataset.key));
        if (!it || it.removed) return;
        const value = toInt(input.value);
        it[input.dataset.field] = value;
        formatInput(input, value);
        dirty = true;
        refresh();
    });
    groupsBox.addEventListener('focusout', e => {
        const input = e.target.closest('[data-field]');
        if (input && input.value.trim() === '') input.value = '0';
    });

    const setRemoved = (it, removed) => {
        if (removed) {
            if (it.quantity > 0) it.restoreQty = it.quantity;
            it.quantity = 0;
            it.removed = true;
        } else {
            it.removed = false;
            it.quantity = Math.max(1, Number(it.restoreQty) || 1);
        }
    };

    const removeItem = it => {
        if (it.id) setRemoved(it, true);
        else items.splice(items.indexOf(it), 1);
    };

    groupsBox.addEventListener('click', e => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        const card = btn.closest('[data-group]');
        const pid = card ? Number(card.dataset.group) : null;
        const action = btn.dataset.action;
        if (action === 'toggle-group') {
            card.classList.toggle('is-open');
            card.classList.contains('is-open') ? openGroups.add(pid) : openGroups.delete(pid);
            btn.setAttribute('aria-expanded', card.classList.contains('is-open'));
            return;
        }
        if (action === 'edit-group') { openPicker(pid); return; }
        if (action === 'remove-group') {
            const title = productInfo.get(pid)?.title || 'این محصول';
            if (!confirm(`همه تنوع‌های «${title}» از فاکتور حذف شود؟`)) return;
            groupItems(pid).forEach(removeItem);
            dirty = true;
            renderBasket();
            return;
        }
        const it = items.find(i => i.key === Number(btn.closest('[data-key]')?.dataset.key));
        if (!it) return;
        if (action === 'remove-row') {
            removeItem(it);
            dirty = true;
            if (!it.id) { renderBasket(); return; }
        } else if (action === 'restore-row') {
            setRemoved(it, false);
            dirty = true;
        }
        const row = groupsBox.querySelector(`[data-key="${it.key}"]`);
        if (row) row.querySelector('[data-field="quantity"]').value = fmt(it.quantity);
        refresh();
    });

    // ---------- product picker (variants of one mother product) ----------
    const pickerEl = $('correctionPickerModal');
    const pickerModal = bootstrap.Modal.getOrCreateInstance(pickerEl);
    let switchingModals = false;
    const picker = { pid: null, product: null, variants: [], qty: new Map(), max: new Map(), discountTouched: false, loading: false };

    const cleanupBackdrops = () => requestAnimationFrame(() => {
        if (switchingModals || document.querySelector('.modal.show')) return;
        document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    });
    window.PreinvoiceProductModalLifecycle = {
        beginSwitch() { switchingModals = true; },
        endSwitch() { switchingModals = false; },
        scheduleCleanup: cleanupBackdrops,
    };

    const variantTitle = v => {
        const parts = [clean(v.model_list_name), clean(v.variety_name), clean(v.variant_name)].filter(Boolean);
        return [...new Set(parts)].join(' / ') || 'تنوع پیش‌فرض';
    };
    const originalQtyFor = (pid, vid) => items
        .filter(i => i.product_id === pid && i.variant_id === vid && i.original)
        .reduce((s, i) => s + i.original.quantity, 0);
    const currentQtyFor = (pid, vid) => items
        .filter(i => i.product_id === pid && i.variant_id === vid && !i.removed)
        .reduce((s, i) => s + i.quantity, 0);

    const pickerFiltered = () => {
        const q = latin($('pickerSearchInput').value).trim().toLowerCase();
        const onlyStock = $('onlyInStockToggle').checked;
        return picker.variants.filter(v => {
            const id = Number(v.id);
            if (onlyStock && (picker.max.get(id) || 0) <= 0 && !(picker.qty.get(id) > 0)) return false;
            if (!q) return true;
            return [variantTitle(v), v.barcode, v.variety_code].join(' ').toLowerCase().includes(q);
        });
    };

    const renderPickerRows = () => {
        const wrap = $('pickerRows');
        const rows = pickerFiltered();
        if (!rows.length) { wrap.innerHTML = '<div class="empty-state">تنوعی برای نمایش وجود ندارد.</div>'; return; }
        wrap.innerHTML = rows.map(v => {
            const id = Number(v.id);
            const qty = picker.qty.get(id) || 0;
            const max = picker.max.get(id) || 0;
            const free = Math.max(0, Number(v.free_stock ?? v.quantity ?? 0));
            const inInvoice = originalQtyFor(picker.pid, id);
            const disabled = max <= 0 && qty <= 0;
            return `
            <div class="variant-row ${qty > 0 ? 'row-selected' : ''} ${disabled ? 'row-empty-stock' : ''}" data-row-variant="${id}">
                <div class="variant-row__info">
                    <div class="variant-row__title">${esc(variantTitle(v))}</div>
                    <div class="variant-row__meta">
                        <span class="variant-pill variant-pill--stock">آزاد: ${free > 0 ? fmt(free) : 'ناموجود'}</span>
                        ${inInvoice > 0 ? `<span class="variant-pill variant-pill--current">در این فاکتور: ${fmt(inInvoice)}</span>` : ''}
                        <span class="variant-pill">سقف: ${fmt(max)}</span>
                        <span class="variant-pill">قیمت: ${money(v.price)}</span>
                        ${v.barcode ? `<span class="variant-pill variant-pill--muted">${esc(v.barcode)}</span>` : ''}
                    </div>
                </div>
                <div class="variant-row__qty qty-control">
                    <button type="button" class="qty-btn" data-step="-1" data-id="${id}" ${qty <= 0 ? 'disabled' : ''}>−</button>
                    <input type="tel" class="qty-input" data-id="${id}" value="${qty}" inputmode="numeric" autocomplete="off" ${disabled ? 'disabled' : ''}>
                    <button type="button" class="qty-btn" data-step="1" data-id="${id}" ${qty >= max ? 'disabled' : ''}>+</button>
                </div>
            </div>`;
        }).join('');
    };

    const pickerPriceFor = v => {
        const existing = items.find(i => i.product_id === picker.pid && i.variant_id === Number(v.id));
        return existing ? existing.price : Number(v.price) || 0;
    };

    const updatePickerSummary = () => {
        let rows = 0, qty = 0, raw = 0;
        picker.variants.forEach(v => {
            const q = picker.qty.get(Number(v.id)) || 0;
            if (q > 0) { rows++; qty += q; raw += q * pickerPriceFor(v); }
        });
        const type = $('modalGroupDiscountType').value;
        const value = toInt($('modalGroupDiscountValue').value);
        const discount = picker.discountTouched
            ? calcDiscount(raw, type, value)
            : groupItems(picker.pid).filter(i => !i.removed).reduce((s, i) => s + i.line_discount_amount, 0);
        $('modalSelectedRows').textContent = fmt(rows);
        $('modalTotalQty').textContent = fmt(qty);
        $('modalRawAmount').textContent = money(raw);
        $('modalTotalAmount').textContent = money(Math.max(0, raw - discount));
        $('modalGroupDiscountPreview').textContent = money(discount) + (picker.discountTouched ? '' : ' (فعلی)');
        $('savePickerBtn').disabled = picker.loading;
    };

    const setPickerQty = (id, value, rerender) => {
        const max = picker.max.get(id) || 0;
        let q = Math.max(0, toInt(value));
        if (q > max) q = max;
        picker.qty.set(id, q);
        if (rerender) renderPickerRows();
        else {
            const row = $('pickerRows').querySelector(`[data-row-variant="${id}"]`);
            if (row) {
                row.classList.toggle('row-selected', q > 0);
                row.querySelector('.qty-input').value = q;
                row.querySelector('[data-step="-1"]').disabled = q <= 0;
                row.querySelector('[data-step="1"]').disabled = q >= max;
            }
        }
        updatePickerSummary();
    };

    async function fetchJson(url) {
        const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (res.status === 404) throw new Error('این محصول در حال حاضر قابل فروش نیست یا تنوع فعالی ندارد. ردیف‌های موجود را مستقیماً در سبد ویرایش کنید.');
        if (!res.ok) throw new Error(res.status === 419 || res.status === 401 ? 'نشست شما منقضی شده است. صفحه را تازه کنید.' : 'دریافت اطلاعات کالا انجام نشد. دوباره تلاش کنید.');
        return res.json();
    }

    async function openPicker(pid, fallbackInfo = null) {
        pid = Number(pid);
        if (!pid) return;
        picker.pid = pid; picker.product = null; picker.variants = []; picker.qty = new Map(); picker.max = new Map();
        picker.discountTouched = false; picker.loading = true;
        const info = productInfo.get(pid) || fallbackInfo || {};
        $('pickerModalTitle').textContent = info.title || 'انتخاب تنوع';
        $('pickerModalSubTitle').textContent = 'در حال دریافت...';
        $('pickerSearchInput').value = '';
        $('onlyInStockToggle').checked = false;
        $('modalGroupDiscountType').value = 'amount';
        $('modalGroupDiscountValue').value = fmt(groupItems(pid).filter(i => !i.removed).reduce((s, i) => s + i.line_discount_amount, 0));
        $('pickerRows').innerHTML = '';
        $('pickerLoading').classList.remove('d-none');
        $('pickerTableWrap').classList.add('d-none');
        updatePickerSummary();
        pickerModal.show();
        try {
            const json = await fetchJson(API.product(pid) + '?include_unavailable=1&_=' + Date.now());
            const product = json?.data?.product;
            if (!product) throw new Error('اطلاعات محصول دریافت نشد.');
            if (picker.pid !== pid) return;
            picker.product = product;
            picker.variants = Array.isArray(product.varieties) ? product.varieties : [];
            if (!productInfo.has(pid)) productInfo.set(pid, { title: product.title || info.title || 'کالا', code: product.code || product.sku || '' });
            picker.variants.forEach(v => {
                const id = Number(v.id);
                const free = Math.max(0, Number(v.free_stock ?? v.quantity ?? 0));
                const current = currentQtyFor(pid, id);
                picker.qty.set(id, current);
                picker.max.set(id, Math.max(free + originalQtyFor(pid, id), current));
            });
            $('pickerModalTitle').textContent = product.title || info.title || 'انتخاب تنوع';
            $('pickerModalSubTitle').textContent = 'کد: ' + (product.code || product.sku || '—') + ' | ' + fmt(picker.variants.length) + ' تنوع';
            picker.loading = false;
            renderPickerRows();
            updatePickerSummary();
            $('pickerLoading').classList.add('d-none');
            $('pickerTableWrap').classList.remove('d-none');
            setTimeout(() => $('pickerSearchInput').focus(), 150);
        } catch (err) {
            picker.loading = false;
            pickerModal.hide();
            alert(err.message || 'خطا در باز کردن لیست تنوع‌ها.');
        }
    }

    function distributeGroupDiscount(pid, total) {
        const rows = groupItems(pid).filter(i => !i.removed && i.quantity > 0);
        const grossSum = rows.reduce((s, i) => s + i.quantity * i.price, 0);
        rows.forEach(i => { i.line_discount_amount = 0; });
        if (total <= 0 || grossSum <= 0) return;
        total = Math.min(total, grossSum);
        let allocated = 0;
        rows.forEach(i => {
            i.line_discount_amount = Math.floor(total * (i.quantity * i.price) / grossSum);
            allocated += i.line_discount_amount;
        });
        let remainder = total - allocated;
        [...rows].sort((a, b) => b.quantity * b.price - a.quantity * a.price).forEach(i => {
            if (remainder <= 0) return;
            const room = i.quantity * i.price - i.line_discount_amount;
            const add = Math.min(room, remainder);
            i.line_discount_amount += add;
            remainder -= add;
        });
    }

    function savePicker() {
        if (picker.loading || !picker.pid) return;
        const pid = picker.pid;
        picker.variants.forEach(v => {
            const vid = Number(v.id);
            const q = picker.qty.get(vid) || 0;
            const rows = items.filter(i => i.product_id === pid && i.variant_id === vid);
            if (q > 0) {
                if (rows.length) {
                    const [first, ...rest] = rows;
                    if (first.removed || first.quantity !== q) { first.removed = false; first.quantity = q; }
                    rest.forEach(removeItem);
                } else {
                    items.push({
                        key: ++uid, id: null, product_id: pid, variant_id: vid,
                        product_title: productInfo.get(pid)?.title || '', product_code: productInfo.get(pid)?.code || '',
                        label: variantTitle(v), barcode: v.barcode || '', quantity: q, price: Number(v.price) || 0,
                        line_discount_amount: 0, ref_price: Number(v.price) || 0, original: null, removed: false, restoreQty: q,
                    });
                }
            } else {
                rows.filter(i => !i.removed).forEach(removeItem);
            }
        });
        if (picker.discountTouched) {
            const type = $('modalGroupDiscountType').value;
            const raw = groupItems(pid).filter(i => !i.removed).reduce((s, i) => s + i.quantity * i.price, 0);
            distributeGroupDiscount(pid, calcDiscount(raw, type, toInt($('modalGroupDiscountValue').value)));
        }
        dirty = true;
        openGroups.add(pid);
        pickerModal.hide();
        renderBasket();
        groupsBox.querySelector(`[data-group="${pid}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    $('pickerRows').addEventListener('click', e => {
        const btn = e.target.closest('.qty-btn');
        if (!btn) return;
        const id = Number(btn.dataset.id);
        setPickerQty(id, (picker.qty.get(id) || 0) + Number(btn.dataset.step), true);
    });
    $('pickerRows').addEventListener('input', e => {
        if (e.target.classList.contains('qty-input')) setPickerQty(Number(e.target.dataset.id), e.target.value, false);
    });
    $('pickerSearchInput').addEventListener('input', renderPickerRows);
    $('clearPickerSearchBtn').addEventListener('click', () => { $('pickerSearchInput').value = ''; renderPickerRows(); $('pickerSearchInput').focus(); });
    $('onlyInStockToggle').addEventListener('change', renderPickerRows);
    $('modalGroupDiscountType').addEventListener('change', () => { picker.discountTouched = true; updatePickerSummary(); });
    $('modalGroupDiscountValue').addEventListener('input', e => {
        picker.discountTouched = true;
        formatInput(e.target, toInt(e.target.value));
        updatePickerSummary();
    });
    $('savePickerBtn').addEventListener('click', savePicker);
    pickerEl.addEventListener('shown.bs.modal', () => { switchingModals = false; });
    pickerEl.addEventListener('hidden.bs.modal', () => { switchingModals = false; picker.loading = false; cleanupBackdrops(); });

    // Product finder (category / subcategory search) hands the chosen mother product over here.
    document.addEventListener('preinvoice:product-selected', e => {
        const product = e.detail?.product || { id: e.detail?.productId };
        if (!product?.id) return;
        switchingModals = true;
        openPicker(product.id, { title: product.name || product.title || '', code: product.code || product.sku || '' });
    });

    // ---------- 4-digit mother code ----------
    const codeInput = $('motherCodeInput');
    const findBtn = $('findMotherBtn');
    let lastAutoCode = '';
    async function findByMotherCode(fromTyping) {
        const code = latin(codeInput.value).replace(/\D/g, '').slice(0, 4);
        codeInput.value = code;
        if (code.length !== 4) {
            if (!fromTyping) { $('motherCodeHint').textContent = 'کد محصول مادر باید ۴ رقم باشد.'; codeInput.focus(); }
            return;
        }
        findBtn.disabled = true;
        const oldText = findBtn.textContent;
        findBtn.textContent = '...';
        try {
            const json = await fetchJson(API.products + '?q=' + encodeURIComponent(code));
            const rows = json?.data?.products?.data || [];
            const match = rows.find(p => [p.short_barcode, p.code, p.sku, p.short_code].some(c => String(c ?? '').trim() === code)) || rows[0];
            if (!match) { $('motherCodeHint').textContent = 'محصول مادری با کد ' + code + ' پیدا نشد یا تنوع موجودی ندارد. برای کالاهای داخل فاکتور از «ویرایش تنوع‌ها» در سبد استفاده کنید.'; codeInput.select(); return; }
            $('motherCodeHint').textContent = 'موجودی کالاها هنگام ثبت نهایی دوباره بررسی می‌شود.';
            await openPicker(match.id, { title: match.title || match.name || '', code: match.code || match.sku || code });
            codeInput.value = '';
            lastAutoCode = '';
        } catch (err) {
            $('motherCodeHint').textContent = err.message || 'خطا در جستجو. دوباره تلاش کنید.';
        } finally {
            findBtn.disabled = false;
            findBtn.textContent = oldText;
        }
    }
    codeInput.addEventListener('input', () => {
        const code = latin(codeInput.value).replace(/\D/g, '').slice(0, 4);
        if (code.length === 4 && code !== lastAutoCode) { lastAutoCode = code; findByMotherCode(true); }
    });
    codeInput.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); lastAutoCode = ''; findByMotherCode(false); } });
    findBtn.addEventListener('click', () => { lastAutoCode = ''; findByMotherCode(false); });

    // ---------- invoice discount / note ----------
    discountView.addEventListener('input', () => {
        const v = toInt(discountView.value);
        discountRaw.value = String(v);
        formatInput(discountView, v);
        dirty = true;
        refresh();
    });
    discountView.addEventListener('blur', () => { if (discountView.value.trim() === '') { discountView.value = '0'; discountRaw.value = '0'; refresh(); } });
    discountView.value = fmt(toInt(discountRaw.value));
    discountType.addEventListener('change', () => { dirty = true; refresh(); });
    noteEl.addEventListener('input', () => { dirty = true; refresh(); });

    // ---------- submit ----------
    form.addEventListener('keydown', e => {
        if (e.key === 'Enter' && e.target instanceof HTMLInputElement) e.preventDefault();
    });
    form.addEventListener('submit', e => {
        refresh();
        if (submitBtn.disabled) { e.preventDefault(); return; }
        discountRaw.value = String(toInt(discountRaw.value));
        const box = $('correctionItemInputs');
        box.innerHTML = '';
        items.forEach((it, i) => {
            const fields = { product_id: it.product_id, variant_id: it.variant_id, quantity: it.removed ? 0 : it.quantity, price: it.removed && it.original ? it.original.price : it.price, line_discount_amount: it.removed ? 0 : it.line_discount_amount };
            if (it.id) fields.id = it.id;
            Object.entries(fields).forEach(([k, v]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = `items[${i}][${k}]`;
                input.value = String(v);
                box.appendChild(input);
            });
        });
        submitting = true;
        submitBtn.disabled = true;
        submitBtn.textContent = 'در حال ثبت...';
    });
    window.addEventListener('beforeunload', e => {
        if (dirty && !submitting) { e.preventDefault(); e.returnValue = ''; }
    });

    renderBasket();
})();
</script>
@endsection
