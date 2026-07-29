@push('styles')
    <link rel="stylesheet" href="{{ asset('css/pages/preinvoice-product-finder.css') }}">
@endpush

<div class="modal fade product-finder" id="productFinderModal" tabindex="-1" aria-labelledby="productFinderTitle" aria-hidden="true"
     data-search-url="{{ route('preinvoice.api.product-finder', absolute: false) }}"
     data-categories-url="{{ route('preinvoice.api.product-finder.categories', absolute: false) }}">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header product-finder__header">
                <div>
                    <h2 class="modal-title h5 fw-bold" id="productFinderTitle">یافتن کالا</h2>
                    <p class="mb-0 mt-1 small text-secondary">جست‌وجو بر اساس نام، کد، مدل دستگاه، تنوع یا دسته‌بندی</p>
                </div>
                <button type="button" class="btn-close m-0" data-bs-dismiss="modal" aria-label="بستن"></button>
            </div>
            <div class="modal-body">
                <div class="product-finder__filters" role="search">
                    <div class="product-finder__query">
                        <label for="productFinderQuery" class="form-label">جست‌وجوی کالا</label>
                        <input id="productFinderQuery" type="search" class="form-control" autocomplete="off"
                               placeholder="نام کالا، کد، مدل گوشی یا نام تنوع را وارد کنید...">
                    </div>
                    <div>
                        <label for="productFinderCategory" class="form-label">دسته‌بندی</label>
                        <select id="productFinderCategory" class="form-select"><option value="">همه دسته‌ها</option></select>
                    </div>
                    <div>
                        <label for="productFinderSubcategory" class="form-label">زیردسته‌بندی</label>
                        <select id="productFinderSubcategory" class="form-select" disabled><option value="">همه زیردسته‌ها</option></select>
                    </div>
                    <label class="product-finder__stock form-check form-switch">
                        <input id="productFinderInStock" class="form-check-input" type="checkbox" role="switch" checked>
                        <span class="form-check-label">فقط کالاهای موجود</span>
                    </label>
                    <button type="button" id="productFinderReset" class="btn btn-outline-secondary">پاک‌کردن فیلترها</button>
                </div>

                <div id="productFinderStatus" class="product-finder__status" role="status" aria-live="polite">
                    برای شروع حداقل دو حرف وارد کنید یا یک دسته‌بندی انتخاب کنید.
                </div>
                <div id="productFinderResults" class="product-finder__results" aria-live="polite"></div>
                <nav id="productFinderPagination" class="product-finder__pagination" aria-label="صفحه‌بندی نتایج"></nav>
            </div>
        </div>
    </div>
</div>

@push('scripts')
    <script src="{{ asset('js/pages/preinvoice-product-finder.js') }}"></script>
@endpush
