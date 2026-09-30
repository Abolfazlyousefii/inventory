@extends('layouts.app')
@section('title', 'خروجی محصولات')
@section('content')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/product-export-builder.css') }}?v=3">
@endpush
<div class="product-builder" id="productBuilder"
     data-products-url="{{ route('admin.product-exports.builder.products') }}"
     data-print-url="{{ route('admin.product-exports.builder.print') }}"
     data-preview-url="{{ route('admin.product-exports.builder.preview') }}">
    <div class="builder-heading">
        <div><p class="eyebrow">ابزار فروشنده</p><h1>لیست محصولات مشتری را بساز</h1><p>کالا و مدل‌های موردنیاز را انتخاب کن، پیش‌نمایش را ببین و PDF بگیر.</p></div>
        <span class="help-pill">● اطلاعات زندهٔ محصولات</span>
    </div>
    <div class="builder-workspace">
        <main>
            <section class="builder-panel search-panel" aria-label="جست‌وجو و فیلتر">
                <div class="search-line"><div class="search-wrap"><span class="search-icon">⌕</span><input id="builderQuery" type="search" placeholder="نام کالا، کد یا مدل گوشی را بنویسید" autocomplete="off"></div><button class="filter-toggle" id="builderFilterToggle" type="button" aria-expanded="true">فیلترها ☰</button></div>
                <div class="builder-filters" id="builderFilters">
                    <label>دسته<select id="builderCategory"><option value="">همه دسته‌ها</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></label>
                    <label>برند / مدل<select id="builderBrand"><option value="">همه مدل‌ها</option>@foreach($brands as $brand)<option value="{{ $brand }}">{{ $brand }}</option>@endforeach</select></label>
                    <label>مرتب‌سازی<select id="builderSort"><option value="relevant">تازه‌ترین</option><option value="name">نام کالا</option><option value="low">قیمت کمتر</option><option value="high">قیمت بیشتر</option></select></label>
                    <label class="builder-check"><input id="builderInStock" type="checkbox"> فقط کالاهای موجود</label>
                </div>
            </section>
            <div class="result-head"><strong>کالاها <span id="builderResultCount"></span></strong><span>روی مدل‌ها بزنید تا فقط همان‌ها انتخاب شوند</span></div>
            <div class="builder-cards" id="builderCards" aria-live="polite"></div>
            <button class="load-more" id="builderLoadMore" type="button" hidden>نمایش کالاهای بیشتر</button>
        </main>
        <aside class="builder-basket" id="builderBasket"><div class="basket-head"><h2>لیست آمادهٔ خروجی</h2><span class="basket-counter" id="builderBasketCount">۰ کالا</span></div><div class="basket-body"><div class="basket-items" id="builderBasketItems"></div><div class="output-options"><strong>در PDF نمایش بده</strong><label><input type="checkbox" id="builderShowPrice" checked> قیمت حدودی</label><label><input type="checkbox" id="builderShowStock" checked> وضعیت موجودی</label><label><input type="checkbox" id="builderShowCode"> کد داخلی کالا</label></div><button class="builder-primary" id="builderPreview" type="button">پیش‌نمایش PDF</button><button class="builder-secondary" id="builderCopyAll" type="button">کپی مدل‌های انتخاب‌شده</button><p class="basket-hint">انتخاب‌ها فقط برای تهیهٔ همین خروجی هستند و ذخیره نمی‌شوند.</p></div></aside>
    </div>
    <div class="mobile-basket"><button type="button" id="builderMobileBasket">مشاهده لیست خروجی · ۰ کالا</button></div>
    <div class="builder-toast" id="builderToast" role="status" aria-live="polite"></div>
    <div class="builder-dialog" id="builderDialog" hidden><div class="dialog-card"><div class="dialog-top"><strong>پیش‌نمایش چیزی که مشتری دریافت می‌کند</strong><button type="button" id="builderClosePreview" aria-label="بستن">×</button></div><div class="customer-sheet" id="builderSheet">در حال آماده‌سازی پیش‌نمایش...</div><div class="dialog-actions"><button class="builder-primary" type="button" id="builderPrint">چاپ / ذخیره PDF</button><button class="builder-secondary" type="button" id="builderCopySummary">کپی متن لیست</button></div></div></div>
</div>
@push('scripts')
<script src="{{ asset('js/product-export-builder.js') }}?v=5" defer></script>
@endpush
@endsection
