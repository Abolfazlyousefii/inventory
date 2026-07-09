@extends('layouts.app')

@section('title', 'خروجی محصولات')

@section('content')
@php
    $filters = $filters ?? [];
    $activeFilterCount = collect([
        $filters['category_id'] ?? null,
        $filters['warehouse_id'] ?? null,
        ($filters['stock_status'] ?? 'all') !== 'all' ? $filters['stock_status'] : null,
        $filters['model_list_id'] ?? null,
        $filters['search'] ?? null,
    ])->filter(fn ($value) => filled($value))->count();
@endphp

<style>
    .product-export-page {
        --export-primary: #0f766e;
        --export-primary-dark: #115e59;
        --export-accent: #0284c7;
        --export-ink: #0f172a;
        --export-muted: #64748b;
        direction: rtl;
        width: 100%;
        max-width: 1600px;
        margin: 0 auto;
        padding: 1.5rem;
    }
    .export-hero {
        position: relative;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1.5rem;
        padding: 1.75rem;
        color: #fff;
        background: linear-gradient(125deg, var(--export-primary-dark), var(--export-primary) 52%, var(--export-accent));
        border-radius: 24px;
        box-shadow: 0 20px 50px rgba(15, 118, 110, .18);
    }
    .export-hero::after {
        content: "";
        position: absolute;
        inset: auto auto -90px -50px;
        width: 240px;
        height: 240px;
        border: 45px solid rgba(255, 255, 255, .08);
        border-radius: 50%;
        pointer-events: none;
    }
    .export-hero__content { position: relative; z-index: 1; min-width: 0; }
    .export-hero__eyebrow {
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        margin-bottom: .55rem;
        font-size: .78rem;
        font-weight: 800;
        color: rgba(255, 255, 255, .78);
    }
    .export-hero h1 { margin: 0 0 .4rem; font-size: clamp(1.45rem, 3vw, 2rem); font-weight: 950; }
    .export-hero p { max-width: 700px; margin: 0; color: rgba(255, 255, 255, .82); }
    .export-threshold {
        position: relative;
        z-index: 1;
        flex: 0 0 auto;
        display: flex;
        align-items: center;
        gap: .7rem;
        min-width: 190px;
        padding: .85rem 1rem;
        background: rgba(255, 255, 255, .14);
        border: 1px solid rgba(255, 255, 255, .24);
        border-radius: 16px;
        backdrop-filter: blur(10px);
    }
    .export-threshold__icon {
        display: grid;
        width: 40px;
        height: 40px;
        place-items: center;
        background: rgba(255, 255, 255, .16);
        border-radius: 12px;
        font-size: 1.2rem;
    }
    .export-threshold small { display: block; color: rgba(255, 255, 255, .7); }
    .export-threshold strong { display: block; margin-top: .1rem; font-size: .95rem; }
    .export-card {
        background: rgba(255, 255, 255, .96);
        border: 1px solid rgba(15, 23, 42, .08);
        border-radius: 20px;
        box-shadow: 0 12px 35px rgba(15, 23, 42, .06);
    }
    .export-filter-card { margin-top: 1.25rem; padding: 1.25rem; }
    .export-section-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.1rem;
    }
    .export-section-heading h2 { margin: 0; color: var(--export-ink); font-size: 1rem; font-weight: 900; }
    .export-section-heading p { margin: .2rem 0 0; color: var(--export-muted); font-size: .8rem; }
    .active-filter-badge {
        flex: 0 0 auto;
        padding: .42rem .7rem;
        color: var(--export-primary-dark);
        background: #ccfbf1;
        border-radius: 999px;
        font-size: .75rem;
        font-weight: 900;
    }
    .export-filter-card .form-label {
        margin-bottom: .45rem;
        color: #334155;
        font-size: .8rem;
        font-weight: 850;
    }
    .export-filter-card .form-control,
    .export-filter-card .form-select {
        min-height: 46px;
        border-color: #dbe4ea;
        border-radius: 12px;
    }
    .export-filter-card .form-control:focus,
    .export-filter-card .form-select:focus {
        border-color: rgba(15, 118, 110, .55);
        box-shadow: 0 0 0 4px rgba(15, 118, 110, .1);
    }
    .export-actions {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        padding-top: .25rem;
    }
    .export-actions__primary,
    .export-actions__secondary { display: flex; flex-wrap: wrap; gap: .65rem; }
    .btn-export {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: .5rem;
        min-height: 44px;
        padding-inline: 1.1rem;
        border-radius: 12px;
        font-weight: 900;
    }
    .btn-export svg { width: 18px; height: 18px; }
    .btn-export-pdf {
        color: #fff;
        background: #dc2626;
        border-color: #dc2626;
    }
    .btn-export-pdf:hover { color: #fff; background: #b91c1c; border-color: #b91c1c; }
    .export-result { position: relative; margin-top: 1.25rem; overflow: hidden; min-height: 180px; }
    .loading-mask {
        position: absolute;
        inset: 0;
        z-index: 20;
        display: none;
        align-items: center;
        justify-content: center;
        gap: .75rem;
        color: var(--export-primary-dark);
        background: rgba(255, 255, 255, .82);
        backdrop-filter: blur(3px);
    }
    .is-loading .loading-mask { display: flex; }
    .export-alert { margin: 1.25rem 0 0; border: 0; border-radius: 14px; }
    @media (max-width: 767.98px) {
        .product-export-page { padding: .75rem; }
        .export-hero { align-items: stretch; flex-direction: column; padding: 1.25rem; border-radius: 18px; }
        .export-threshold { width: 100%; }
        .export-filter-card { padding: 1rem; border-radius: 16px; }
        .export-section-heading { align-items: flex-start; }
        .export-actions { align-items: stretch; flex-direction: column; }
        .export-actions__primary,
        .export-actions__secondary,
        .btn-export { width: 100%; }
    }
</style>

<main class="product-export-page">
    <header class="export-hero">
        <div class="export-hero__content">
            <div class="export-hero__eyebrow"><span>●</span> گزارش‌گیری و مدیریت موجودی</div>
            <h1>خروجی محصولات</h1>
            <p>محصولات را بر اساس دسته‌بندی، انبار و وضعیت موجودی بررسی کنید و گزارش آماده چاپ PDF بگیرید.</p>
        </div>
        <div class="export-threshold" title="محصولات با موجودی بین ۱ تا این عدد، کم‌موجودی محسوب می‌شوند.">
            <span class="export-threshold__icon">⚠️</span>
            <span>
                <small>آستانه کم‌موجودی</small>
                <strong>{{ number_format(\App\Services\ProductExportService::LOW_STOCK_THRESHOLD) }} عدد</strong>
            </span>
        </div>
    </header>

    @if(session('error'))
        <div class="alert alert-danger export-alert" role="alert">{{ session('error') }}</div>
    @endif

    <section class="export-card export-filter-card" aria-labelledby="export-filter-title">
        <div class="export-section-heading">
            <div>
                <h2 id="export-filter-title">فیلتر و جستجوی محصولات</h2>
                <p>برای مشاهده نتیجه دقیق‌تر، یک یا چند فیلتر انتخاب کنید.</p>
            </div>
            @if($activeFilterCount)
                <span class="active-filter-badge">{{ $activeFilterCount }} فیلتر فعال</span>
            @endif
        </div>

        <form id="productExportForm" class="row g-3" method="GET" action="{{ route('admin.product-exports.index') }}">
            <div class="col-xl-3 col-md-6">
                <label for="export-category" class="form-label">دسته‌بندی محصول</label>
                <select id="export-category" name="category_id" class="form-select">
                    <option value="">همه دسته‌بندی‌ها</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected(($filters['category_id'] ?? '') == $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-xl-3 col-md-6">
                <label for="export-warehouse" class="form-label">انبار</label>
                <select id="export-warehouse" name="warehouse_id" class="form-select">
                    <option value="">همه انبارها</option>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected(($filters['warehouse_id'] ?? '') == $warehouse->id)>{{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-xl-3 col-md-6">
                <label for="export-stock-status" class="form-label">وضعیت موجودی</label>
                <select id="export-stock-status" name="stock_status" class="form-select">
                    <option value="all" @selected(($filters['stock_status'] ?? 'all') === 'all')>همه محصولات</option>
                    <option value="in_stock" @selected(($filters['stock_status'] ?? '') === 'in_stock')>فقط کالاهای موجود</option>
                    <option value="out_of_stock" @selected(($filters['stock_status'] ?? '') === 'out_of_stock')>فقط کالاهای ناموجود</option>
                    <option value="low_stock" @selected(($filters['stock_status'] ?? '') === 'low_stock')>کالاهای کم‌موجودی</option>
                </select>
            </div>
            <div class="col-xl-3 col-md-6">
                <label for="export-model-list" class="form-label">مدل لیست کالا</label>
                <select id="export-model-list" name="model_list_id" class="form-select">
                    <option value="">همه مدل‌ها</option>
                    @foreach($modelLists as $modelList)
                        <option value="{{ $modelList->id }}" @selected(($filters['model_list_id'] ?? '') == $modelList->id)>
                            {{ trim(($modelList->model_name ?? '') . (($modelList->code ?? '') !== '' ? ' - ' . $modelList->code : '')) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-xl-3 col-md-6">
                <label for="export-search" class="form-label">جستجو</label>
                <input id="export-search" type="search" name="search" class="form-control" value="{{ $filters['search'] ?? '' }}" placeholder="نام، کد، SKU یا بارکد" autocomplete="off">
            </div>
            <div class="col-12 export-actions">
                <div class="export-actions__primary">
                    <button type="submit" class="btn btn-primary btn-export">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m21 21-4.3-4.3m2.3-5.2a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                        نمایش محصولات
                    </button>
                    <a href="{{ route('admin.product-exports.index') }}" class="btn btn-outline-secondary btn-export">پاک کردن فیلترها</a>
                </div>
                <div class="export-actions__secondary">
                    <button type="button" data-format="pdf" class="btn btn-export btn-export-pdf">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 19h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        دریافت خروجی PDF
                    </button>
                </div>
            </div>
        </form>
    </section>

    <section id="productExportResult" class="export-card export-result" aria-live="polite" aria-busy="false">
        <div class="loading-mask" aria-hidden="true">
            <div class="spinner-border spinner-border-sm" role="status"></div>
            <strong>در حال دریافت اطلاعات...</strong>
        </div>
        @include('product-exports.partials.table', ['rows' => $rows])
    </section>
</main>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('productExportForm');
        const result = document.getElementById('productExportResult');
        const submitButton = form?.querySelector('[type="submit"]');
        const loadingMarkup = result?.querySelector('.loading-mask')?.outerHTML ?? '';
        const buildParams = () => new URLSearchParams(new FormData(form));

        if (!form || !result) return;

        const setLoading = (loading) => {
            result.classList.toggle('is-loading', loading);
            result.setAttribute('aria-busy', String(loading));
            if (submitButton) submitButton.disabled = loading;
        };

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            setLoading(true);

            try {
                const params = buildParams();
                const response = await fetch(`{{ route('admin.product-exports.data') }}?${params.toString()}`, {
                    headers: {
                        'Accept': 'text/html',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                if (!response.ok) throw new Error(`HTTP ${response.status}`);

                result.innerHTML = loadingMarkup + await response.text();
                window.history.replaceState({}, '', `${form.action}?${params.toString()}`);
                result.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } catch (error) {
                result.insertAdjacentHTML('afterbegin', '<div class="alert alert-danger m-3" role="alert">دریافت اطلاعات با خطا روبه‌رو شد. لطفاً دوباره تلاش کنید.</div>');
            } finally {
                setLoading(false);
            }
        });

        document.querySelectorAll('[data-format]').forEach((button) => {
            button.addEventListener('click', () => {
                const params = buildParams();
                params.set('format', button.dataset.format);
                window.location.assign(`{{ route('admin.product-exports.export') }}?${params.toString()}`);
            });
        });
    });
</script>
@endsection
