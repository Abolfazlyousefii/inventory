@extends('layouts.app')
@section('title','ثبت حواله شوروم')
@push('styles')<link rel="stylesheet" href="{{ asset('css/showroom-editor.css') }}?v={{ filemtime(public_path('css/showroom-editor.css')) }}">@endpush
@section('content')
@php
$editorConfig=['catalogUrl'=>route('vouchers.showroom.catalog'),'selected'=>$selectedRows??[],'errors'=>$errors->getMessages(),'maxRows'=>100,'categories'=>$categories->map(fn($c)=>['id'=>$c->id,'name'=>$c->name,'parent_id'=>$c->parent_id])->values()];
$safeOld=fn($key,$default='')=>is_scalar(old($key,$default)) ? (string)old($key,$default) : '';
@endphp
<div class="sr-editor" id="sr-editor" dir="rtl">
    <header class="sr-header"><div><span class="sr-eyebrow">حواله‌های انبار / شوروم</span><h1>ثبت حواله شوروم</h1><p>دسته و کالا را انتخاب کنید؛ تنوع‌ها و زیرتنوع‌های دلخواه را با هم به حواله اضافه کنید.</p></div><a class="btn btn-outline-secondary" href="{{ route('vouchers.section.index','showroom') }}">سوابق حواله‌ها</a></header>
    @if($errors->any())<div class="alert alert-danger" role="alert"><strong>حواله ثبت نشد؛ انتخاب‌ها حفظ شده‌اند.</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div class="sr-notice" id="sr-notice" role="status" aria-live="polite" hidden></div>
    <form method="POST" action="{{ route('vouchers.section.store','showroom') }}" id="showroom-form">
        @csrf
        <input type="hidden" name="request_token" value="{{ $safeOld('request_token',(string)\Illuminate\Support\Str::uuid()) }}">
        <input type="hidden" name="from_warehouse_id" value="{{ $centralWarehouse->id }}"><input type="hidden" name="to_warehouse_id" value="{{ $showroomWarehouse->id }}">
        <div class="sr-route"><span>مبدأ <strong>{{ $centralWarehouse->name }}</strong></span><span aria-hidden="true">←</span><span>مقصد <strong>{{ $showroomWarehouse->name }}</strong></span><small>از موجودی آزاد مرکزی کم و به موجودی شوروم اضافه می‌شود.</small></div>
        <div class="sr-workspace">
            <section class="sr-panel sr-catalog" aria-labelledby="sr-catalog-title">
                <div class="sr-section-heading"><h2 id="sr-catalog-title">۱. انتخاب کالا و مدل‌ها</h2><span class="sr-hint">تا ۱۰۰ ردیف در هر حواله</span></div>
                <nav class="sr-steps" aria-label="مراحل انتخاب"><span>۱ دسته‌بندی</span><span>۲ کالا</span><span>۳ تنوع و زیرتنوع</span></nav>
                <div class="sr-category-fields"><div><label for="sr-category">دسته‌بندی</label><select id="sr-category" class="form-select"><option value="">همه دسته‌ها</option></select></div><div><label for="sr-subcategory">زیردسته‌بندی</label><select id="sr-subcategory" class="form-select" disabled><option value="">همه زیردسته‌ها</option></select></div></div>
                <label for="sr-search" id="sr-search-label">جستجوی کالا</label><input id="sr-search" class="form-control" type="search" maxlength="100" autocomplete="off" placeholder="نام یا کد کالا">
                <label class="sr-check sr-stock-filter"><input id="sr-available-only" type="checkbox" checked> فقط موجودهای مرکزی</label>
                <div id="sr-product-list" class="sr-product-list"></div>
                <div id="sr-variant-pane" hidden>
                    <div class="sr-product-heading"><strong id="sr-product-name"></strong><button type="button" id="sr-back-products" class="btn btn-outline-secondary btn-sm">تغییر کالا</button></div>
                    <div class="sr-catalog-actions"><label class="sr-check"><input id="sr-check-visible" type="checkbox"> انتخاب جمعی موارد نمایش‌داده‌شده</label><button id="sr-add-selected" class="btn btn-primary" type="button" disabled>افزودن انتخاب‌ها</button></div>
                    <div id="sr-results" class="sr-variant-groups"></div>
                </div>
                <div class="sr-state" id="sr-search-state" role="status" aria-live="polite">در حال دریافت مدل‌ها…</div><div class="sr-load-actions"><button id="sr-retry" class="btn btn-outline-secondary" type="button" hidden>تلاش مجدد</button><button id="sr-more" class="btn btn-outline-secondary" type="button" hidden>نمایش مدل‌های بیشتر</button></div>
            </section>
            <section class="sr-panel sr-selected" aria-labelledby="sr-selected-title">
                <div class="sr-section-heading"><h2 id="sr-selected-title">۲. اقلام حواله</h2><button id="sr-refresh" class="btn btn-outline-secondary btn-sm" type="button" disabled>به‌روزرسانی موجودی</button></div>
                <label class="visually-hidden" for="sr-selected-search">جستجو در اقلام انتخاب‌شده</label><input id="sr-selected-search" class="form-control" type="search" placeholder="جستجو در اقلام انتخاب‌شده" autocomplete="off">
                <div class="sr-bulk"><label for="sr-bulk-quantity">تعداد یکسان برای همه ردیف‌ها</label><input id="sr-bulk-quantity" class="form-control" type="text" inputmode="numeric" placeholder="مثلاً ۱"><button id="sr-apply-quantity" class="btn btn-outline-primary" type="button">اعمال تعداد</button></div>
                <div class="sr-table-wrap sr-selected-wrap"><table class="sr-table sr-selected-table"><thead><tr><th>ردیف</th><th>کالا / مدل و رنگ</th><th>آزاد مرکزی</th><th>شوروم</th><th>تعداد انتقال</th><th><span class="visually-hidden">حذف</span></th></tr></thead><tbody id="sr-rows"></tbody></table></div>
                <p class="sr-empty" id="sr-empty">مدل‌های موردنظر را از بخش انتخاب کالا اضافه کنید.</p><p class="sr-hint sr-keyboard">با Enter به تعداد بعدی بروید؛ Tab برای حرکت بین کنترل‌هاست. انتخاب دوبارهٔ یک مدل، ردیف جدید نمی‌سازد.</p>
            </section>
        </div>
        <details class="sr-meta"><summary>اطلاعات حواله <span class="sr-hint">تاریخ، شماره و توضیحات</span></summary><div class="sr-meta-grid"><div><label for="sr-date">تاریخ شمسی</label><input id="sr-date" name="transferred_at_jalali" class="form-control" data-jdp data-jdp-only-date required value="{{ $safeOld('transferred_at_jalali',\App\Support\JalaliDate::date(now(),'')) }}" autocomplete="off"></div><div><label for="sr-reference">شماره حواله <small>اختیاری</small></label><input id="sr-reference" name="reference" class="form-control" maxlength="100" value="{{ $safeOld('reference') }}"></div><div><label for="sr-note">توضیحات <small>اختیاری</small></label><input id="sr-note" name="note" class="form-control" maxlength="255" value="{{ $safeOld('note') }}"></div></div></details>
        <footer class="sr-save"><div><strong id="sr-summary" aria-live="polite">هنوز مدلی انتخاب نشده است</strong><small id="sr-validation">موجودی هنگام ثبت دوباره کنترل می‌شود.</small></div><button class="btn btn-primary" type="submit" id="sr-submit" disabled>ثبت انتقال به شوروم</button></footer>
    </form>
</div>
<script type="application/json" id="sr-editor-config">@json($editorConfig)</script>
@endsection
@push('scripts')<script src="{{ asset('js/showroom-editor.js') }}?v={{ filemtime(public_path('js/showroom-editor.js')) }}" defer></script>@endpush
