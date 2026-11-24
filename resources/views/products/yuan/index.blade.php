@extends('layouts.app')
@section('title', 'مدیریت قیمت یوان')
@section('page-title', 'مدیریت قیمت یوان')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/yuan-dashboard.css') }}?v=2">
@endpush
@section('content')
<section class="yuan-dash" id="yuanDashboard"
         data-list-url="{{ route('products.yuan.data') }}"
         data-history-url="{{ route('products.yuan.history', ['product' => '__ID__']) }}"
         data-rate-url="{{ route('products.yuan.rate') }}"
         data-preview-url="{{ route('products.yuan.preview') }}"
         data-apply-url="{{ route('products.yuan.apply') }}"
         data-can-apply="{{ \App\Support\PermissionCatalog::userHasPermission(auth()->user(),'products.price_changes.apply') ? '1' : '0' }}">
    <header class="yuan-heading">
        <div><h2>مدیریت قیمت یوان</h2><p>مدیریت کالاهای یوانی، قیمت فروش و سود هر محصول</p></div>
        <div class="yuan-actions" aria-label="عملیات نرخ و قیمت‌گذاری">
            @if(\App\Support\PermissionCatalog::userHasPermission(auth()->user(),'products.price_changes.apply'))
                <button type="button" class="yuan-btn yuan-btn-soft" id="yuanFetch">↻ دریافت نرخ</button>
                <button type="button" class="yuan-btn" id="yuanPreview">◉ پیش‌نمایش تغییرات</button>
                <button type="button" class="yuan-btn yuan-btn-primary" id="yuanApply" disabled>✓ اعمال قیمت‌ها</button>
            @endif
        </div>
    </header>
    <div class="yuan-stats" aria-label="خلاصه وضعیت قیمت‌گذاری">
        <div class="yuan-stat"><span>نرخ فعلی یوان</span><strong class="yuan-blue" id="yuanRate">{{ $rate ? number_format(intdiv($rate['rate_rial'],10)).' تومان' : 'نامعتبر / ناموجود' }}</strong><small>{{ $rate && $rate['estimated'] ? 'نرخ تخمینی نوبیتکس' : ($rate && $rate['sample'] ? 'نرخ نمونه' : 'آخرین نرخ معتبر') }}</small></div>
        <div class="yuan-stat"><span>کالاهای یوانی</span><strong>{{ number_format($stats['total']) }}</strong><small>شامل غیرفعال‌ها</small></div>
        <div class="yuan-stat"><span>قیمت‌گذاری فعال</span><strong class="yuan-green">{{ number_format($stats['active']) }}</strong><small>با تغییر نرخ واجد شرایط بررسی می‌شوند</small></div>
        <div class="yuan-stat"><span>متوقف‌شده</span><strong class="yuan-orange">{{ number_format($stats['paused']) }}</strong><small>به‌روزرسانی خودکار ندارند</small></div>
    </div>
    <div class="yuan-grid">
        <section class="yuan-panel yuan-products" aria-labelledby="yuanListTitle">
            <header class="yuan-panel-head"><h3 id="yuanListTitle">فهرست کالاهای یوانی</h3><span id="yuanCount" class="yuan-muted">—</span></header>
            <div class="yuan-filters">
                <label class="yuan-search"><span class="visually-hidden">جستجوی کالا</span><input id="yuanSearch" type="search" placeholder="جستجوی نام کالا یا SKU…" autocomplete="off"></label>
                <label><span class="visually-hidden">وضعیت</span><select id="yuanStatus"><option value="all">همه وضعیت‌ها</option><option value="active">فعال</option><option value="paused">متوقف</option></select></label>
                <label><span class="visually-hidden">ترتیب</span><select id="yuanSort"><option value="latest">آخرین تغییر</option><option value="name">نام کالا</option><option value="price">قیمت فروش</option></select></label>
            </div>
            <div class="yuan-table-window" id="yuanWindow" tabindex="0" aria-label="فهرست کالاها؛ برای توقف حرکت نشانگر را روی جدول قرار دهید">
                <table class="yuan-table"><thead><tr><th>نام کالا</th><th>خرید (¥)</th><th>هزینه خرید (تومان)</th><th>قیمت فروش (تومان)</th><th>سود ناخالص</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody id="yuanRows"><tr><td colspan="7" class="yuan-empty">در حال دریافت اطلاعات…</td></tr></tbody></table>
            </div>
            <footer class="yuan-foot"><span id="yuanMotionLabel">مرور خودکار</span><div class="yuan-foot-actions"><button type="button" class="yuan-btn yuan-btn-small" id="yuanNext">ردیف بعد ←</button><button type="button" class="yuan-btn yuan-btn-small" id="yuanPause" aria-pressed="false">Ⅱ توقف مرور</button></div></footer>
        </section>
        <aside class="yuan-panel yuan-detail" aria-labelledby="yuanDetailTitle">
            <header class="yuan-panel-head"><h3 id="yuanDetailTitle">جزئیات کالا</h3><span id="yuanChartStatus" class="yuan-muted">یک کالا انتخاب کنید</span></header>
            <div class="yuan-periods" aria-label="بازه نمودار"><button type="button" data-days="7">۷ روز</button><button type="button" data-days="30" class="is-active">۳۰ روز</button><button type="button" data-days="90">۹۰ روز</button></div>
            <div class="yuan-legend"><span><i class="yuan-dot yuan-price-dot"></i> قیمت فروش محصول</span><span><i class="yuan-dot yuan-rate-dot"></i> نرخ یوان</span></div>
            <div class="yuan-chart-box"><svg id="yuanChart" viewBox="0 0 480 235" role="img" aria-label="نمودار قیمت فروش کالا و نرخ یوان با دو محور مستقل"></svg></div>
            <p class="yuan-muted yuan-chart-note">محور قیمت محصول و محور نرخ یوان مستقل‌اند؛ نمودار بر اساس سوابق ثبت‌شده است، نه قیمت‌های تخمینی.</p>
            <dl class="yuan-breakdown">
                <div><dt>خرید یوانی</dt><dd id="yuanDetailBuy">—</dd></div>
                <div><dt>نرخ اعمال‌شده</dt><dd id="yuanDetailRate">—</dd></div>
                <div><dt>هزینه خرید</dt><dd id="yuanDetailCost">—</dd></div>
                <div><dt>حاشیه احتیاط</dt><dd>۲٪</dd></div>
                <div><dt>سود هدف از مبلغ فروش</dt><dd>۳۰٪</dd></div>
                <div><dt>سود ناخالص بر اساس قیمت ثبت‌شده</dt><dd id="yuanDetailProfit">—</dd></div>
                <div class="yuan-sale"><dt>قیمت فروش فعلی</dt><dd id="yuanDetailSale">—</dd></div>
            </dl>
        </aside>
    </div>
    <dialog id="yuanDialog" class="yuan-dialog" aria-labelledby="yuanDialogTitle">
        <h3 id="yuanDialogTitle">پیش‌نمایش قیمت‌گذاری مجدد</h3>
        <p id="yuanDialogText" aria-live="polite"></p>
        <div class="yuan-dialog-list" id="yuanDialogList"></div>
        <div class="yuan-dialog-actions"><button type="button" class="yuan-btn" id="yuanCancel">بستن</button><button type="button" class="yuan-btn yuan-btn-primary" id="yuanConfirm">تأیید و اعمال</button></div>
    </dialog>
    <div id="yuanNotice" class="yuan-notice" role="status" aria-live="polite" hidden></div>
</section>
@endsection
@push('scripts')
<script src="{{ asset('js/yuan-dashboard.js') }}?v=2" defer></script>
@endpush
