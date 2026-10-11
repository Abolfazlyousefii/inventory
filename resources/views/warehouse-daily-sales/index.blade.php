@extends('layouts.app')
@section('title', 'گزارش فروش روز')
@section('content')
<div class="warehouse-daily" dir="rtl" data-daily-sales data-url="{{ route('warehouse.daily-sales.data') }}" data-detail-url="{{ route('warehouse.daily-sales.detail') }}" data-save-url="{{ route('warehouse.daily-sales.store') }}" data-export-url="{{ route('warehouse.daily-sales.export') }}">
    <header class="wd-header"><div><small>انبار مرکزی</small><h1>گزارش فروش روز</h1><p>گردش روز را ببینید؛ موجودی آزاد فعلی را با شمارش قفسه مقایسه کنید.</p></div></header>
    <section class="wd-panel wd-date"><div><b>کدام روز را بررسی می‌کنید؟</b><p>فروش بر اساس تاریخ ثبت فاکتور و گردش بر اساس زمان ثبت کارتکس است.</p></div>
        <form id="wd-date-form"><label for="wd-day">تاریخ روز<input id="wd-day" data-jdp autocomplete="off" placeholder="۱۴۰۵/۰۷/۱۵" maxlength="16" required></label><button class="wd-button wd-primary">نمایش روز</button></form>
    </section>
    <p class="wd-notice">مبنای شمارش، موجودی آزاد فعلی انبار مرکزی است؛ کالاهای فاکتورشده و جداشده را نشمارید. ثبت این صفحه فقط یادداشت است و موجودی یا رزرو را تغییر نمی‌دهد.</p>
    <details class="wd-explanation"><summary>تفاوت فروش، خروج و موجودی فعلی</summary><p>فروش روز الزاماً برابر خروج همان روز نیست؛ ممکن است کالا قبلاً رزرو شده باشد. ورودی و خروجی فقط گردش‌های ثبت‌شدهٔ کارتکس انبار مرکزی هستند؛ برخی رزروها و آزادسازی‌ها در کارتکس ثبت نشده‌اند. از تفاضل این دو ستون، موجودی پایان روز محاسبه نمی‌شود. اسناد قدیمی بدون انبار مشخص نیز در این جمع نیستند.</p></details>
    <p id="wd-message" role="status" aria-live="polite" hidden></p>
    <section class="wd-panel wd-empty" id="wd-initial"><h2>اول تاریخ را انتخاب کنید</h2><p>پس از انتخاب روز، کالاهای فروخته‌شده، دارای گردش و یادداشت‌های همان روز نمایش داده می‌شوند.</p></section>
    <section id="wd-report" hidden>
        <div class="wd-metrics"><div class="wd-panel"><span>ردیف‌های روز</span><b id="wd-total">۰</b></div><div class="wd-panel"><span>بررسی‌شده</span><b id="wd-reviewed">۰</b></div><div class="wd-panel"><span>دارای مغایرت</span><b id="wd-issues">۰</b></div></div>
        <div class="wd-toolbar"><input id="wd-search" type="search" aria-label="جست‌وجوی کالا" maxlength="100" placeholder="نام کالا، تنوع یا کد…"><div class="wd-tabs"><button class="wd-active" data-wd-filter="all" aria-pressed="true">همه</button><button data-wd-filter="pending" aria-pressed="false">بررسی‌نشده</button><button data-wd-filter="issues" aria-pressed="false">مغایرت‌ها</button></div></div>
        <div class="wd-caption"><span id="wd-day-label"></span><span id="wd-read-at"></span></div>
        <div id="wd-cards" class="wd-cards" aria-busy="false"></div>
        <div class="wd-paging"><button class="wd-button" id="wd-prev">قبلی</button><span id="wd-page-label"></span><button class="wd-button" id="wd-next">بعدی</button></div>
        <footer class="wd-panel wd-footer"><p>مغایرت‌ها یادداشت بررسی هستند و باید جداگانه در انبارگردانی رسیدگی شوند. فایل شامل تمام مغایرت‌های روز با جست‌وجوی فعلی است؛ چاپ شامل تمام صفحات فیلتر انتخابی است.</p><div><a class="wd-button" id="wd-download">دریافت لیست مغایرت‌ها</a><a class="wd-button wd-primary" id="wd-print" target="_blank" rel="noopener">چاپ لیست</a></div></footer>
    </section>
    <dialog id="wd-detail" aria-labelledby="wd-detail-title"><header><h2 id="wd-detail-title"></h2><button class="wd-button" id="wd-close" aria-label="بستن جزئیات">×</button></header><div id="wd-detail-body"></div><div class="wd-paging"><button class="wd-button" id="wd-detail-prev">قبلی</button><span id="wd-detail-page"></span><button class="wd-button" id="wd-detail-next">بعدی</button></div></dialog>
</div>
@endsection
@push('styles')
<link rel="stylesheet" href="{{ asset('css/warehouse-daily-sales.css') }}?v=20261007">
@endpush
@push('scripts')
<script src="{{ asset('js/warehouse-daily-sales.js') }}?v=20261007" defer></script>
@endpush
