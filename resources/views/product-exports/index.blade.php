@extends('layouts.app')
@section('title', 'لیست قیمت محصولات آریا گستر')
@section('content_class', 'app-content-wide')
@section('meta')
    @vite(['resources/css/app.css'])
@endsection
@push('scripts')
    @vite(['resources/js/app.js'])
@endpush
@section('content')
@php
    $selectedModels = collect($filters['model_list_ids'] ?? [])->map(fn($id)=>(int)$id)->values()->all();
    $totalProducts = method_exists($products, 'total') ? $products->total() : $products->count();
@endphp
<main class="pe-page" data-product-export-page data-children-url-template="{{ route('admin.product-exports.categories.children', ['category' => '__ID__']) }}" data-model-lists-url="{{ route('admin.product-exports.model-lists') }}" data-data-url="{{ route('admin.product-exports.data') }}" data-download-url="{{ route('admin.product-exports.download') }}" data-selected-models='@json($selectedModels)'>
    <header class="pe-page-header">
        <div class="pe-page-header__content">
            <div class="pe-page-header__icon" aria-hidden="true">☷</div>
            <div>
                <h1>لیست قیمت محصولات آریا گستر</h1>
                <p>محصولات را فیلتر کنید و فایل PDF نهایی را دریافت کنید.</p>
            </div>
        </div>
        <div class="pe-page-header__meta">{{ number_format($totalProducts) }} محصول</div>
    </header>

    <section class="pe-filter-panel">
        <form id="productExportForm" class="pe-filter-form" method="GET" action="{{ route('admin.product-exports.index') }}">
            <div class="pe-filter-grid">
                <div class="pe-field"><label for="root-category">دسته اصلی</label><select id="root-category" name="root_category_id"><option value="">همه دسته‌ها</option>@foreach($rootCategories as $category)<option value="{{ $category->id }}" @selected(($filters['root_category_id']??'')==$category->id)>{{ $category->name }}</option>@endforeach</select></div>
                <div class="pe-field"><label for="subcategory">زیردسته</label><select id="subcategory" name="subcategory_id"><option value="">همه زیردسته‌ها</option>@foreach($subcategories as $category)<option value="{{ $category->id }}" @selected(($filters['subcategory_id']??'')==$category->id)>{{ $category->name }}</option>@endforeach</select></div>
                <div class="pe-field"><label for="model-brand">نوع مدل</label><select id="model-brand" name="model_brand"><option value="">همه انواع مدل</option>@foreach($modelBrands as $brand)<option value="{{ $brand }}" @selected(($filters['model_brand']??'')===$brand)>{{ $brand }}</option>@endforeach</select></div>
                <div class="pe-field"><label for="stock-status">وضعیت موجودی</label><select id="stock-status" name="stock_status"><option value="all" @selected(($filters['stock_status']??'all')==='all')>همه</option><option value="in_stock" @selected(($filters['stock_status']??'')==='in_stock')>موجود</option><option value="out_of_stock" @selected(($filters['stock_status']??'')==='out_of_stock')>ناموجود</option></select></div>
                <label class="pe-switch-option"><input type="checkbox" name="include_without_price" value="1" @checked($filters['include_without_price'] ?? false)><span class="pe-switch"></span><span><strong>محصولات بدون قیمت</strong><small>محصولاتی که قیمت ثبت‌شده ندارند نیز نمایش داده شوند.</small></span></label>
            </div>

            <div class="pe-model-panel">
                <div class="pe-model-panel__header"><div><h3>مدل‌های انتخابی</h3><span>یک یا چند مدل را برای خروجی انتخاب کنید.</span></div><div class="pe-model-panel__counter" id="modelCount">۰ مدل انتخاب‌شده</div></div>
                <div class="pe-model-panel__toolbar"><input id="modelSearch" type="search" placeholder="جست‌وجوی مدل..."><button class="pe-btn pe-btn--secondary" type="button" id="selectVisibleModels">انتخاب همه</button><button class="pe-btn pe-btn--ghost pe-btn--danger" type="button" id="clearModels">پاک‌کردن</button></div>
                <div class="pe-model-panel__grid" id="modelGrid"><div class="pe-model-empty">ابتدا نوع مدل را انتخاب کنید.</div></div>
            </div>

            <div class="pe-filter-actions"><button class="pe-btn pe-btn--secondary pe-filter-submit" type="submit">اعمال فیلتر</button><button class="pe-btn pe-btn--primary" type="button" id="downloadProductsButton">دانلود لیست قیمت PDF</button><a class="pe-btn pe-btn--ghost pe-btn--danger" href="{{ route('admin.product-exports.index') }}">پاک‌کردن فیلترها</a></div>
        </form>
    </section>

    <section id="productExportResult" class="pe-results" aria-live="polite"><div class="pe-loading">در حال دریافت اطلاعات...</div>@include('product-exports.partials.product-list', ['products'=>$products])</section>
</main>
@endsection
