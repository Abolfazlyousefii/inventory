@extends('layouts.app')

@section('title', 'خروجی محصولات')

@section('content')
@php
    $filters = $filters ?? [];
    $selectedModelListIds = collect($filters['model_list_ids'] ?? [])->map(fn ($id) => (int) $id)->all();
    $stockStatusLabels = [
        'all' => 'همه محصولات',
        'in_stock' => 'فقط کالاهای موجود',
        'out_of_stock' => 'فقط کالاهای ناموجود',
        'low_stock' => 'کالاهای کم‌موجودی',
    ];
    $selectedCategoryName = filled($filters['category_id'] ?? null)
        ? optional(($categories ?? collect())->firstWhere('id', (int) $filters['category_id']))->name
        : null;
    $selectedWarehouseName = filled($filters['warehouse_id'] ?? null)
        ? optional(($warehouses ?? collect())->firstWhere('id', (int) $filters['warehouse_id']))->name
        : null;
    $selectedStockStatus = $filters['stock_status'] ?? 'all';
    $activeChips = collect([
        $selectedCategoryName ? 'دسته‌بندی: ' . $selectedCategoryName : null,
        $selectedWarehouseName ? 'انبار: ' . $selectedWarehouseName : null,
        $selectedStockStatus !== 'all' ? 'وضعیت: ' . ($stockStatusLabels[$selectedStockStatus] ?? $selectedStockStatus) : null,
        filled($filters['search'] ?? null) ? 'جستجو: ' . $filters['search'] : null,
        ! empty($selectedModelListIds) ? 'مدل‌لیست‌ها: ' . count($selectedModelListIds) . ' انتخاب' : null,
    ])->filter()->values();
    $activeFilterCount = $activeChips->count();
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
    .export-pdf-action { display: flex; flex-direction: column; gap: .25rem; }
    .export-pdf-hint { max-width: 230px; color: var(--export-muted); font-size: .72rem; line-height: 1.5; }
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
    .export-toolbar { margin-top: 1.25rem; padding: 1rem; }
    .export-toolbar__row { display: flex; align-items: center; justify-content: space-between; gap: .9rem; flex-wrap: wrap; }
    .export-toolbar__actions { display: flex; align-items: center; gap: .65rem; flex-wrap: wrap; }
    .export-filter-chips { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; min-width: 0; }
    .export-filter-chip { max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; padding: .42rem .7rem; border-radius: 999px; background: #eefdf8; color: #115e59; font-size: .78rem; font-weight: 850; }
    .export-filter-chip--empty { background: #f1f5f9; color: var(--export-muted); }
    .export-filter-modal .modal-dialog { max-width: min(980px, calc(100vw - 1rem)); }
    .export-filter-modal .modal-content { border: 0; border-radius: 20px; overflow: hidden; }
    .export-filter-modal .modal-header,
    .export-filter-modal .modal-footer { border-color: #e2e8f0; }
    .export-filter-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
    .export-filter-grid .form-label { margin-bottom: .45rem; color: #334155; font-size: .8rem; font-weight: 850; }
    .export-filter-grid .form-control,
    .export-filter-grid .form-select { min-height: 44px; border-color: #dbe4ea; border-radius: 12px; }
    .export-filter-grid__wide { grid-column: 1 / -1; min-width: 0; }
    .model-list-filter {
        max-height: 280px;
        overflow: auto;
        padding: .85rem;
        border: 1px solid #dbe4ea;
        border-radius: 14px;
        background: #f8fafc;
    }
    .model-list-filter__search { margin-bottom: .75rem; }
    .model-list-filter__hint { margin-bottom: .75rem; color: var(--export-muted); font-size: .78rem; }
    .model-list-filter__group + .model-list-filter__group { margin-top: .8rem; padding-top: .8rem; border-top: 1px dashed #cbd5e1; }
    .model-list-filter__brand { margin-bottom: .5rem; color: var(--export-ink); font-size: .8rem; font-weight: 950; }
    .model-list-filter__items { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: .45rem .65rem; }
    .model-list-filter__item {
        display: flex;
        align-items: center;
        gap: .45rem;
        min-width: 0;
        padding: .42rem .5rem;
        border: 1px solid rgba(15, 23, 42, .08);
        border-radius: 10px;
        background: #fff;
        font-size: .82rem;
    }
    .model-list-filter__item input { flex: 0 0 auto; }
    .model-list-filter__item span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .model-list-filter__empty { color: var(--export-muted); font-size: .85rem; }

    @media (max-width: 767.98px) {
        .product-export-page { padding: .75rem; }
        .export-hero { align-items: stretch; flex-direction: column; padding: 1.25rem; border-radius: 18px; }
        .export-threshold { width: 100%; }
        .export-filter-card { padding: 1rem; border-radius: 16px; }
        .export-section-heading { align-items: flex-start; }
        .export-toolbar__row,
        .export-toolbar__actions { align-items: stretch; flex-direction: column; }
        .export-toolbar__actions,
        .export-filter-chips { width: 100%; }
        .export-filter-chip,
        .export-pdf-action,
        .export-pdf-hint { max-width: 100%; width: 100%; }
        .export-filter-grid { grid-template-columns: 1fr; }
        .export-filter-modal .modal-dialog { margin: .5rem; max-width: calc(100vw - 1rem); }
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

    <section class="export-card export-toolbar" aria-labelledby="export-filter-summary-title">
        <div class="export-toolbar__row">
            <div class="export-filter-chips" id="export-filter-summary-title" aria-label="فیلترهای فعال">
                @forelse($activeChips as $chip)
                    <span class="export-filter-chip">{{ $chip }}</span>
                @empty
                    <span class="export-filter-chip export-filter-chip--empty">بدون فیلتر فعال</span>
                @endforelse
            </div>

            <div class="export-toolbar__actions">
                <button type="button" class="btn btn-primary btn-export" data-bs-toggle="modal" data-bs-target="#productExportFiltersModal">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    فیلترها
                    @if($activeFilterCount)
                        <span class="badge text-bg-light">{{ $activeFilterCount }}</span>
                    @endif
                </button>

                <div class="export-pdf-action">
                    <button type="button" data-format="pdf" class="btn btn-export btn-export-pdf">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 19h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        دریافت خروجی PDF
                    </button>
                    <small class="export-pdf-hint">برای PDF بهتر است خروجی را با دسته‌بندی یا مدل‌لیست محدود کنید.</small>
                </div>

                <a href="{{ route('admin.product-exports.index') }}" class="btn btn-outline-secondary btn-export">پاک کردن فیلترها</a>
            </div>
        </div>
    </section>

    <div class="modal fade export-filter-modal" id="productExportFiltersModal" tabindex="-1" aria-labelledby="productExportFiltersModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <form id="productExportForm" method="GET" action="{{ route('admin.product-exports.index') }}">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="productExportFiltersModalLabel">فیلتر و جستجوی محصولات</h5>
                            <small class="text-muted">فیلترها را تنظیم کنید؛ جدول و PDF از همین مقادیر استفاده می‌کنند.</small>
                        </div>
                        <button type="button" class="btn-close m-0" data-bs-dismiss="modal" aria-label="بستن"></button>
                    </div>

                    <div class="modal-body">
                        <div class="export-filter-grid">
                            <div>
                                <label for="export-category" class="form-label">دسته‌بندی محصول</label>
                                <select id="export-category" name="category_id" class="form-select">
                                    <option value="">همه دسته‌بندی‌ها</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}" @selected(($filters['category_id'] ?? '') == $category->id)>{{ $category->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label for="export-warehouse" class="form-label">انبار</label>
                                <select id="export-warehouse" name="warehouse_id" class="form-select">
                                    <option value="">همه انبارها</option>
                                    @foreach($warehouses as $warehouse)
                                        <option value="{{ $warehouse->id }}" @selected(($filters['warehouse_id'] ?? '') == $warehouse->id)>{{ $warehouse->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label for="export-stock-status" class="form-label">وضعیت موجودی</label>
                                <select id="export-stock-status" name="stock_status" class="form-select">
                                    @foreach($stockStatusLabels as $value => $label)
                                        <option value="{{ $value }}" @selected(($filters['stock_status'] ?? 'all') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label for="export-search" class="form-label">جستجو</label>
                                <input id="export-search" type="search" name="search" class="form-control" value="{{ $filters['search'] ?? '' }}" placeholder="نام، کد، SKU یا بارکد" autocomplete="off">
                            </div>

                            <div class="export-filter-grid__wide">
                                <label class="form-label d-flex align-items-center justify-content-between gap-2">
                                    <span>مدل‌لیست‌ها</span>
                                    <span class="text-muted small"><span id="selectedModelListCount">{{ count($selectedModelListIds) }}</span> انتخاب</span>
                                </label>
                                <div class="model-list-filter" id="modelListFilter">
                                    <input type="search" class="form-control form-control-sm model-list-filter__search" id="modelListSearch" placeholder="جستجوی مدل یا برند" autocomplete="off">
                                    <div class="model-list-filter__hint">
                                        ابتدا دسته‌بندی را انتخاب کنید، سپس مدل‌های موردنظر را از برندهای مختلف انتخاب کنید.
                                    </div>
                                    @forelse(($modelListsByBrand ?? collect()) as $brand => $modelLists)
                                        <div class="model-list-filter__group" data-model-list-group>
                                            <div class="model-list-filter__brand">{{ $brand ?: 'سایر' }}</div>
                                            <div class="model-list-filter__items">
                                                @foreach($modelLists as $modelList)
                                                    @php
                                                        $modelLabel = trim(($modelList->code ? $modelList->code . ' - ' : '') . $modelList->model_name);
                                                        $searchText = mb_strtolower(trim(($brand ?: 'سایر') . ' ' . $modelList->model_name . ' ' . ($modelList->code ?? '')));
                                                    @endphp
                                                    <label class="model-list-filter__item" data-model-list-item data-search="{{ $searchText }}">
                                                        <input type="checkbox" name="model_list_ids[]" value="{{ $modelList->id }}" @checked(in_array((int) $modelList->id, $selectedModelListIds, true))>
                                                        <span title="{{ $brand }} {{ $modelList->model_name }}">{{ $modelLabel }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        </div>
                                    @empty
                                        <div class="model-list-filter__empty">مدلی برای فیلترهای فعلی پیدا نشد.</div>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer justify-content-between">
                        <a href="{{ route('admin.product-exports.index') }}" class="btn btn-outline-secondary">پاک کردن</a>
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">بستن</button>
                            <button type="submit" class="btn btn-primary">اعمال فیلتر</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

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

        const modelListSearch = document.getElementById('modelListSearch');
        const selectedModelListCount = document.getElementById('selectedModelListCount');
        const updateSelectedModelListCount = () => {
            if (!selectedModelListCount || !form) return;
            selectedModelListCount.textContent = form.querySelectorAll('input[name="model_list_ids[]"]:checked').length;
        };

        form?.addEventListener('change', (event) => {
            if (event.target?.matches('input[name="model_list_ids[]"]')) {
                updateSelectedModelListCount();
            }
        });
        updateSelectedModelListCount();

        modelListSearch?.addEventListener('input', () => {
            const term = modelListSearch.value.trim().toLocaleLowerCase();

            document.querySelectorAll('[data-model-list-item]').forEach((item) => {
                const matches = !term || (item.dataset.search || '').toLocaleLowerCase().includes(term);
                item.style.display = matches ? '' : 'none';
            });

            document.querySelectorAll('[data-model-list-group]').forEach((group) => {
                const hasVisibleItem = Array.from(group.querySelectorAll('[data-model-list-item]'))
                    .some((item) => item.style.display !== 'none');
                group.style.display = hasVisibleItem ? '' : 'none';
            });
        });

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

                const modalElement = document.getElementById('productExportFiltersModal');
                const modalInstance = window.bootstrap?.Modal?.getInstance(modalElement);
                modalInstance?.hide();
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
