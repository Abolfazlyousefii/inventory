@extends('layouts.app')

@section('content')
@php
    $brandGroups = $modelLists->pluck('brand')->filter()->unique()->sort()->values();

    $categoriesJs = $categories->map(function ($c) {
        return [
            'id'        => (int) $c->id,
            'name'      => (string) $c->name,
            'code'      => (string) ($c->code ?? ''),
            'parent_id' => $c->parent_id ? (int) $c->parent_id : null,
        ];
    })->values();

    $modelListsJs = $modelLists->map(function ($m) {
        return [
            'id'         => (int) $m->id,
            'brand'      => (string) ($m->brand ?? ''),
            'model_name' => (string) ($m->model_name ?? ''),
            'code'       => (string) ($m->code ?? ''),
        ];
    })->values();

    $oldCategoryId   = old('category_id');
    $oldModelIds     = array_map('intval', old('model_list_ids', []));
    $oldDesignNotes  = array_values(old('design_notes', []));
@endphp

<style>
    :root{
        --brd:#e8edf3;
        --muted:#6b7280;
        --soft:#f8fafc;
        --soft2:#f3f6fb;
        --blue:#2563eb;
        --blue-soft:#eff6ff;
        --ok:#16a34a;
        --danger:#dc2626;
    }

    .card-soft{
        border:1px solid var(--brd);
        border-radius:18px;
        background:#fff;
        box-shadow:0 10px 28px rgba(15,23,42,.04);
    }

    .section-head{
        padding:12px 14px;
        border-bottom:1px solid var(--brd);
        background:linear-gradient(0deg,#fff,var(--soft2));
        border-top-left-radius:18px;
        border-top-right-radius:18px;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:10px;
        flex-wrap:wrap;
    }

    .section-title{
        font-weight:800;
        font-size:14px;
    }

    .muted{
        color:var(--muted);
        font-size:12px;
    }

    .chip{
        display:inline-flex;
        align-items:center;
        gap:8px;
        padding:6px 10px;
        border-radius:999px;
        border:1px solid var(--brd);
        background:var(--soft);
        font-size:12px;
    }

    .mono{
        font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;
        letter-spacing:1px;
    }

    .preview-box{
        border:1px dashed #cfe0ff;
        background:#f8fbff;
        border-radius:16px;
        padding:14px;
    }

    .preview-code{
        font-size:18px;
        font-weight:900;
        letter-spacing:1px;
    }

    .helper-box{
        border:1px solid var(--brd);
        border-radius:16px;
        background:#fff;
        padding:14px;
        height:100%;
    }

    .tree-box{
        border:1px dashed #d7e5fb;
        border-radius:16px;
        background:#fbfdff;
        padding:12px;
    }

    .tree-level{
        border:1px solid var(--brd);
        border-radius:14px;
        background:#fff;
        padding:10px;
    }

    .tree-breadcrumb{
        display:flex;
        flex-wrap:wrap;
        gap:8px;
        margin-top:10px;
    }

    .tree-breadcrumb .crumb{
        display:inline-flex;
        align-items:center;
        gap:6px;
        padding:6px 10px;
        border-radius:999px;
        background:var(--blue-soft);
        border:1px solid #dbeafe;
        color:#1d4ed8;
        font-size:12px;
        font-weight:700;
    }

    .model-picker{
        position:relative;
    }

    .picker-button{
        width:100%;
        min-height:44px;
        border:1px solid var(--brd);
        border-radius:12px;
        background:#fff;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:10px;
        padding:10px 12px;
        cursor:pointer;
        text-align:right;
    }

    .picker-button:hover{
        background:var(--soft);
    }

    .picker-panel{
        position:absolute;
        inset-inline:0;
        top:calc(100% + 8px);
        z-index:30;
        border:1px solid var(--brd);
        border-radius:16px;
        background:#fff;
        box-shadow:0 16px 32px rgba(15,23,42,.10);
        padding:10px;
        display:none;
    }

    .picker-panel.open{
        display:block;
    }

    .picker-search{
        border:1px solid var(--brd);
        border-radius:12px;
        padding:9px 12px;
        width:100%;
        outline:none;
    }

    .picker-list{
        margin-top:10px;
        max-height:280px;
        overflow:auto;
        border:1px solid #eef2f7;
        border-radius:12px;
        padding:6px;
        background:#fcfdff;
    }

    .picker-actions{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:8px;
        margin-top:10px;
    }

    .inline-create{
        margin-top:10px;
        border:1px dashed #dbe6f3;
        border-radius:12px;
        background:#f8fbff;
        padding:10px;
    }

    .inline-create[hidden]{
        display:none !important;
    }

    .tiny-feedback{
        font-size:12px;
        margin-top:6px;
    }

    .tiny-feedback.error{
        color:var(--danger);
    }

    .tiny-feedback.success{
        color:var(--ok);
    }

    .picker-item{
        display:flex;
        align-items:flex-start;
        gap:10px;
        padding:9px 10px;
        border-radius:10px;
        cursor:pointer;
        margin-bottom:4px;
    }

    .picker-item:hover{
        background:#f8fafc;
    }

    .picker-item small{
        display:block;
        color:var(--muted);
        margin-top:2px;
    }

    .picker-tags{
        display:flex;
        flex-wrap:wrap;
        gap:8px;
        margin-top:10px;
    }

    .picker-tag{
        display:inline-flex;
        align-items:center;
        gap:6px;
        border:1px solid #dbeafe;
        background:#eff6ff;
        color:#1d4ed8;
        border-radius:999px;
        padding:5px 10px;
        font-size:12px;
        font-weight:700;
    }

    .design-note-item{
        border:1px dashed #dbe6f3;
        border-radius:12px;
        padding:10px;
        background:#fff;
    }

    .examples-grid{
        display:flex;
        flex-wrap:wrap;
        gap:8px;
    }

    .example-code{
        display:inline-flex;
        align-items:center;
        padding:6px 10px;
        border-radius:999px;
        border:1px solid var(--brd);
        background:#fff;
        font-size:12px;
        font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;
    }

    .sticky-actions{
        position:sticky;
        bottom:16px;
        z-index:5;
        background:rgba(255,255,255,.92);
        backdrop-filter:blur(6px);
        border:1px solid var(--brd);
        border-radius:16px;
        padding:12px;
    }

    .soft-label{
        font-size:12px;
        color:var(--muted);
        margin-bottom:6px;
        font-weight:700;
    }

    .mini-stat{
        border:1px solid var(--brd);
        border-radius:14px;
        background:#fff;
        padding:12px;
        text-align:center;
    }

    .mini-stat .num{
        font-size:28px;
        font-weight:900;
        line-height:1;
        margin-top:8px;
    }

    .divider-soft{
        border-top:1px dashed #e5edf7;
        margin:12px 0;
    }
</style>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">افزودن کالا</h5>
            <a class="btn btn-outline-secondary" href="{{ route('products.index') }}">بازگشت</a>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger">
                <div class="fw-bold mb-2">لطفاً خطاهای زیر را بررسی کن:</div>
                <ul class="mb-0 ps-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="alert alert-info">
            در این بخش فقط <b>تعریف کالا و تنوع‌ها</b> انجام می‌شود.
            <br>
            <b>موجودی، قیمت خرید و قیمت فروش</b> بعداً در بخش <b>خرید کالا</b> ثبت می‌شوند.
        </div>

        <form method="POST" action="{{ route('products.store') }}" id="productCreateForm" class="row g-3" enctype="multipart/form-data">
            @csrf

            {{-- hidden inputs برای model_list_ids - اینجا توسط JS مدیریت می‌شن --}}
            <div id="modelHiddenInputsContainer"></div>

            {{-- اطلاعات پایه --}}
            <div class="col-12">
                <div class="card-soft">
                    <div class="section-head">
                        <div>
                            <div class="section-title">اطلاعات پایه کالا</div>
                            <div class="muted">اول نام کالا را بنویس، بعد دسته‌بندی نهایی را از ساختار درختی انتخاب کن</div>
                        </div>

                        <div class="chip">
                            <span class="muted">پیش‌نمایش شماره کالا:</span>
                            <span class="mono" id="previewSeq4">{{ $previewSeq4 }}</span>
                        </div>
                    </div>

                    <div class="p-3">
                        <div class="row g-3">
                            <div class="col-lg-5">
                                <label class="form-label">نام کالا</label>
                                <input
                                    type="text"
                                    name="name"
                                    class="form-control"
                                    value="{{ old('name') }}"
                                    required
                                    maxlength="255"
                                    placeholder="مثلاً کیف دوشی چرمی"
                                >
                            </div>

                            <div class="col-lg-4">
                                <label class="form-label">عکس کالا</label>
                                <input
                                    type="file"
                                    name="image"
                                    class="form-control"
                                    accept="image/*"
                                >
                                <div class="muted mt-1">فرمت‌های تصویری مجاز؛ حداکثر حجم ۴ مگابایت.</div>
                            </div>

                            <div class="col-lg-3">
                                <label class="form-label d-block">وضعیت فروش</label>
                                <input type="hidden" name="is_sellable" value="0">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" id="isSellable" name="is_sellable" value="1" @checked(old('is_sellable', 1))>
                                    <label class="form-check-label" for="isSellable">این کالا قابل فروش باشد</label>
                                </div>
                            </div>

                            <div class="col-lg-3">
                                <div class="soft-label">پیش‌نمایش کد ۶ رقمی کالا</div>
                                <div class="preview-box h-100 d-flex align-items-center">
                                    <div class="preview-code mono w-100 text-center" id="productCodePreview">--{{ $previewSeq4 }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="divider-soft"></div>

                        <input type="hidden" name="category_id" id="categoryId" value="{{ old('category_id') }}">

                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">دسته‌بندی به‌صورت درختی</label>

                                <div class="tree-box">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                        <div class="muted">از همین‌جا می‌توانید دسته‌بندی جدید هم اضافه کنید.</div>
                                        <button type="button" class="btn btn-sm btn-outline-primary" id="openCategoryQuickAdd">+ افزودن دسته‌بندی جدید</button>
                                    </div>

                                    <div class="inline-create mb-2" id="categoryQuickAddBox" hidden>
                                        <div class="row g-2">
                                            <div class="col-md-8">
                                                <input type="text" id="categoryQuickName" class="form-control" maxlength="255" placeholder="نام دسته‌بندی جدید...">
                                            </div>
                                            <div class="col-md-4 d-flex gap-2">
                                                <button type="button" class="btn btn-primary btn-sm w-100" id="submitCategoryQuickAdd">ثبت</button>
                                                <button type="button" class="btn btn-outline-secondary btn-sm" id="cancelCategoryQuickAdd">×</button>
                                            </div>
                                        </div>
                                        <div class="tiny-feedback" id="categoryQuickFeedback"></div>
                                    </div>

                                    <div class="row g-2" id="categoryLevels"></div>

                                    <div class="tree-breadcrumb" id="categoryBreadcrumb"></div>

                                    <div class="mt-2 muted">
                                        دسته‌بندی نهایی انتخاب‌شده برای ثبت کالا استفاده می‌شود.
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="preview-box">
                                    <div class="muted mb-1">الگوی کد تنوع ۱۱ رقمی</div>
                                    <div class="preview-code mono" id="variantPatternPreview">CC{{ $previewSeq4 }}MMMDD</div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="preview-box">
                                    <div class="muted mb-1">دسته‌بندی انتخاب‌شده</div>
                                    <div class="fw-bold" id="categorySelectedTitle">هنوز انتخاب نشده</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ساختار تنوع‌ها --}}
            <div class="col-12">
                <div class="card-soft">
                    <div class="section-head">
                        <div>
                            <div class="section-title">ساختار تنوع‌ها</div>
                            <div class="muted">مشخص کن کالا مدل‌لیست دارد یا طرح‌بندی یا هر دو</div>
                        </div>
                    </div>

                    <div class="p-3">
                        <div class="row g-3">
                            {{-- مدل لیست --}}
                            <div class="col-lg-7">
                                <div class="helper-box">
                                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                        <div>
                                            <div class="form-check form-switch">
                                                <input type="hidden" name="use_models" value="0">
                                                <input class="form-check-input" type="checkbox" value="1" id="useModels" name="use_models" @checked(old('use_models'))>
                                                <label class="form-check-label fw-bold" for="useModels">این کالا مدل‌لیست دارد</label>
                                            </div>
                                            <div class="muted mt-2">
                                                مثال: یک کالا برای چند مدل گوشی یا چند مدل دستگاه تعریف می‌شود.
                                            </div>
                                        </div>

                                        <div class="chip">
                                            <span class="muted">انتخاب‌شده:</span>
                                            <span id="selectedModelsCount">0</span>
                                        </div>
                                    </div>

                                    <div id="modelsSection" class="mt-3" style="display:none;">
                                        <div class="row g-3">
                                            <div class="col-md-5">
                                                <label class="form-label">گروه برند</label>
                                                <select name="model_brand_group" id="modelBrandGroup" class="form-select">
                                                    <option value="">انتخاب برند...</option>
                                                    @foreach($brandGroups as $brand)
                                                        <option value="{{ $brand }}" @selected(old('model_brand_group') == $brand)>{{ $brand }}</option>
                                                    @endforeach
                                                </select>
                                                <div class="form-text">اول برند را انتخاب کن، بعد مدل‌های همان برند را تیک بزن.</div>
                                            </div>

                                            <div class="col-md-7">
                                                <label class="form-label">مدل‌لیست‌ها</label>

                                                <div class="model-picker" id="modelPicker">
                                                    <button type="button" class="picker-button" id="modelPickerButton">
                                                        <span id="modelPickerButtonText">مدل‌لیست‌ها را انتخاب کن...</span>
                                                        <span class="muted">▼</span>
                                                    </button>

                                                    <div class="picker-panel" id="modelPickerPanel">
                                                        <input type="text" class="picker-search" id="modelSearchInput" placeholder="جستجو در مدل‌ها...">
                                                        <div class="picker-actions">
                                                            <button type="button" class="btn btn-sm btn-outline-primary" id="openModelQuickAdd">+ افزودن مدل جدید</button>
                                                            <span class="muted">در همان لیست</span>
                                                        </div>
                                                        <div class="inline-create" id="modelQuickAddBox" hidden>
                                                            <div class="d-flex gap-2">
                                                                <input type="text" class="form-control form-control-sm" id="modelQuickName" maxlength="255" placeholder="نام مدل جدید...">
                                                                <button type="button" class="btn btn-primary btn-sm" id="submitModelQuickAdd">ثبت</button>
                                                                <button type="button" class="btn btn-outline-secondary btn-sm" id="cancelModelQuickAdd">×</button>
                                                            </div>
                                                            <div class="tiny-feedback" id="modelQuickFeedback"></div>
                                                        </div>
                                                        <div class="picker-list" id="modelPickerList"></div>
                                                    </div>
                                                </div>

                                                <div class="picker-tags" id="modelSelectedTags"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- طرح بندی --}}
                            <div class="col-lg-5">
                                <div class="helper-box">
                                    <div class="form-check form-switch">
                                        <input type="hidden" name="use_designs" value="0">
                                        <input class="form-check-input" type="checkbox" value="1" id="useDesigns" name="use_designs" @checked(old('use_designs'))>
                                        <label class="form-check-label fw-bold" for="useDesigns">این کالا طرح‌بندی دارد</label>
                                    </div>

                                    <div class="muted mt-2">
                                        مثال: طرح ۱، طرح ۲، طرح ۳ یا طرح‌های چاپی مختلف.
                                    </div>

                                    <div id="designsSection" class="mt-3" style="display:none;">
                                        <div class="mb-3">
                                            <label class="form-label">تعداد طرح</label>
                                            <input
                                                type="number"
                                                min="1"
                                                max="99"
                                                name="design_count"
                                                id="designCount"
                                                class="form-control"
                                                value="{{ old('design_count', 1) }}"
                                            >
                                            <div class="form-text">مثلاً اگر ۳ طرح دارد، عدد ۳ را وارد کن.</div>
                                        </div>

                                        <div>
                                            <label class="form-label">تعریف هر طرح</label>
                                            <div id="designNotesWrap"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="helper-box mt-3">
                            <div class="row g-3 align-items-start">
                                <div class="col-md-3">
                                    <div class="mini-stat">
                                        <div class="muted">تعداد تنوع‌هایی که ساخته می‌شود</div>
                                        <div class="num" id="variantsCountPreview">1</div>
                                    </div>
                                </div>

                                <div class="col-md-9">
                                    <div class="muted mb-2">نمونه کد تنوع‌های قابل ساخت</div>
                                    <div class="examples-grid" id="variantExamples"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ثبت --}}
            <div class="col-12">
                <div class="sticky-actions d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="muted">
                        بعد از ثبت کالا، موجودی و قیمت را در بخش <b>خرید کالا</b> وارد کن.
                    </div>

                    <div class="d-flex gap-2">
                        <a href="{{ route('products.index') }}" class="btn btn-outline-secondary">انصراف</a>
                        <button type="submit" class="btn btn-primary">ثبت کالا</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var categories = @json($categoriesJs);
    var modelLists = @json($modelListsJs);
    var previewSeq4 = @json($previewSeq4);
    var oldCategoryId = @json($oldCategoryId);
    var oldModelIds = @json($oldModelIds);
    var oldDesignNotes = @json($oldDesignNotes);
    var csrfToken = @json(csrf_token());

    var categoryIdEl = document.getElementById('categoryId');
    var useModelsEl = document.getElementById('useModels');
    var useDesignsEl = document.getElementById('useDesigns');

    var modelsSection = document.getElementById('modelsSection');
    var designsSection = document.getElementById('designsSection');

    var modelBrandGroupEl = document.getElementById('modelBrandGroup');
    var selectedModelsCountEl = document.getElementById('selectedModelsCount');

    var designCountEl = document.getElementById('designCount');
    var designNotesWrap = document.getElementById('designNotesWrap');

    var productCodePreviewEl = document.getElementById('productCodePreview');
    var variantPatternPreviewEl = document.getElementById('variantPatternPreview');
    var variantsCountPreviewEl = document.getElementById('variantsCountPreview');
    var variantExamplesEl = document.getElementById('variantExamples');

    var categoryLevelsEl = document.getElementById('categoryLevels');
    var categoryBreadcrumbEl = document.getElementById('categoryBreadcrumb');
    var categorySelectedTitleEl = document.getElementById('categorySelectedTitle');
    var openCategoryQuickAddEl = document.getElementById('openCategoryQuickAdd');
    var categoryQuickAddBoxEl = document.getElementById('categoryQuickAddBox');
    var categoryQuickNameEl = document.getElementById('categoryQuickName');
    var submitCategoryQuickAddEl = document.getElementById('submitCategoryQuickAdd');
    var cancelCategoryQuickAddEl = document.getElementById('cancelCategoryQuickAdd');
    var categoryQuickFeedbackEl = document.getElementById('categoryQuickFeedback');

    var modelPickerEl = document.getElementById('modelPicker');
    var modelPickerButton = document.getElementById('modelPickerButton');
    var modelPickerButtonText = document.getElementById('modelPickerButtonText');
    var modelPickerPanel = document.getElementById('modelPickerPanel');
    var modelPickerList = document.getElementById('modelPickerList');
    var modelSearchInput = document.getElementById('modelSearchInput');
    var modelSelectedTags = document.getElementById('modelSelectedTags');
    var openModelQuickAddEl = document.getElementById('openModelQuickAdd');
    var modelQuickAddBoxEl = document.getElementById('modelQuickAddBox');
    var modelQuickNameEl = document.getElementById('modelQuickName');
    var submitModelQuickAddEl = document.getElementById('submitModelQuickAdd');
    var cancelModelQuickAddEl = document.getElementById('cancelModelQuickAdd');
    var modelQuickFeedbackEl = document.getElementById('modelQuickFeedback');

    var modelHiddenInputsContainer = document.getElementById('modelHiddenInputsContainer');

    // مجموعه آیدی‌های انتخاب‌شده - منبع اصلی داده
    var selectedModelIds = new Set(oldModelIds.map(function (x) { return parseInt(x, 10); }));

    var categoryPathIds = [];

    // ─── ابزارهای کمکی ───────────────────────────────────────────

    function onlyDigits(s) {
        return String(s || '').replace(/\D+/g, '');
    }

    function padLeft(s, len, ch) {
        s = String(s || '');
        ch = ch || '0';
        while (s.length < len) s = ch + s;
        return s;
    }

    function setFeedback(el, message, type) {
        if (!el) return;
        el.className = 'tiny-feedback' + (type ? (' ' + type) : '');
        el.textContent = message || '';
    }

    function clearFeedback(el) {
        setFeedback(el, '', '');
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    async function postJson(url, payload) {
        var response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify(payload || {}),
        });

        var json = await response.json().catch(function () { return {}; });

        if (!response.ok) {
            var err = new Error(json.message || 'خطا در ثبت اطلاعات.');
            err.payload = json;
            throw err;
        }

        return json;
    }

    // ─── کمک‌کننده‌های کد ─────────────────────────────────────────

    function normalizeCategory2(code) {
        var d = onlyDigits(code).substring(0, 2);
        return d.length === 2 ? d : '--';
    }

    function normalizeModel3(code) {
        var d = onlyDigits(code).substring(0, 3);
        return padLeft(d, 3, '0');
    }

    // ─── مدیریت hidden input های model_list_ids ──────────────────
    // این تابع همیشه پس از هر تغییر در selectedModelIds صدا زده می‌شود
    // تا مقادیر واقعی در فرم وجود داشته باشند

    function syncHiddenModelInputs() {
        // پاک کردن همه hidden input های قبلی
        modelHiddenInputsContainer.innerHTML = '';

        // ساخت یک hidden input به ازای هر آیدی انتخاب‌شده
        selectedModelIds.forEach(function (id) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'model_list_ids[]';
            input.value = String(id);
            modelHiddenInputsContainer.appendChild(input);
        });
    }

    // ─── دسته‌بندی ────────────────────────────────────────────────

    function categoryById(id) {
        id = parseInt(id || '0', 10);
        return categories.find(function (c) {
            return parseInt(c.id, 10) === id;
        }) || null;
    }

    function childrenOf(parentId) {
        return categories
            .filter(function (c) {
                var pid = c.parent_id === null ? null : parseInt(c.parent_id, 10);
                var target = parentId === null ? null : parseInt(parentId, 10);
                return pid === target;
            })
            .sort(function (a, b) {
                return String(a.name).localeCompare(String(b.name), 'fa');
            });
    }

    function getCategoryPath(id) {
        var path = [];
        var current = categoryById(id);

        while (current) {
            path.unshift(parseInt(current.id, 10));
            current = current.parent_id ? categoryById(current.parent_id) : null;
        }

        return path;
    }

    function selectedCategory() {
        return categoryById(categoryIdEl.value);
    }

    function normalizePersianText(text) {
        return String(text || '').replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/‌/g, ' ').trim();
    }

    function isElectricCategorySelected() {
        return getCategoryPath(categoryIdEl.value).some(function (id) {
            var cat = categoryById(id);
            return cat && normalizePersianText(cat.name) === 'برقیجات';
        });
    }

    function ensureDefaultElectricDesignNotes() {
        if (!isElectricCategorySelected()) return;

        var required = ['مشکی', 'سفید'];
        var existing = currentDesignNotesValues();
        var normalizedExisting = existing.map(normalizePersianText);
        var changed = false;

        required.forEach(function (color) {
            if (normalizedExisting.indexOf(normalizePersianText(color)) === -1) {
                existing.push(color);
                normalizedExisting.push(normalizePersianText(color));
                changed = true;
            }
        });

        if (!useDesignsEl.checked) {
            useDesignsEl.checked = true;
            changed = true;
        }

        if (parseInt(designCountEl.value || '0', 10) < existing.length) {
            designCountEl.value = String(existing.length);
            changed = true;
        }

        if (changed) {
            oldDesignNotes = existing;
            syncSections();
        }
    }

    function currentCategoryParentIdForQuickAdd() {
        var selected = selectedCategory();
        return selected ? parseInt(selected.id, 10) : null;
    }

    function updateCategoryPreview() {
        var cat = selectedCategory();
        var cat2 = cat ? normalizeCategory2(cat.code) : '--';

        productCodePreviewEl.textContent = cat2 + previewSeq4;
        variantPatternPreviewEl.textContent = cat2 + previewSeq4 + 'MMMDD';
        categorySelectedTitleEl.textContent = cat ? cat.name : 'هنوز انتخاب نشده';
    }

    function renderBreadcrumb() {
        categoryBreadcrumbEl.innerHTML = '';

        if (!categoryPathIds.length) {
            var span = document.createElement('div');
            span.className = 'muted';
            span.textContent = 'هنوز مسیر دسته‌بندی انتخاب نشده است.';
            categoryBreadcrumbEl.appendChild(span);
            return;
        }

        categoryPathIds.forEach(function (id) {
            var cat = categoryById(id);
            if (!cat) return;

            var crumb = document.createElement('span');
            crumb.className = 'crumb';
            crumb.textContent = cat.name;
            categoryBreadcrumbEl.appendChild(crumb);
        });
    }

    function renderCategoryLevels(pathIds) {
        categoryLevelsEl.innerHTML = '';
        categoryPathIds = pathIds.slice();

        var parentId = null;
        var level = 0;
        var lastSelected = '';

        while (true) {
            var children = childrenOf(parentId);
            if (!children.length) break;

            var col = document.createElement('div');
            col.className = 'col-md-4';

            var wrapper = document.createElement('div');
            wrapper.className = 'tree-level';

            var label = document.createElement('div');
            label.className = 'soft-label';
            label.textContent = level === 0 ? 'دسته اصلی' : ('زیر دسته ' + level);

            var select = document.createElement('select');
            select.className = 'form-select';
            select.dataset.level = String(level);

            var emptyOpt = document.createElement('option');
            emptyOpt.value = '';
            emptyOpt.textContent = level === 0 ? 'انتخاب دسته اصلی...' : 'انتخاب زیر دسته...';
            select.appendChild(emptyOpt);

            children.forEach(function (cat) {
                var opt = document.createElement('option');
                opt.value = String(cat.id);
                opt.textContent = cat.name + (cat.code ? ' (' + cat.code + ')' : '');
                select.appendChild(opt);
            });

            if (pathIds[level]) {
                select.value = String(pathIds[level]);
                lastSelected = String(pathIds[level]);
            }

            select.addEventListener('change', function () {
                var currentLevel = parseInt(this.dataset.level, 10);
                var newPath = [];

                var selects = categoryLevelsEl.querySelectorAll('select');
                selects.forEach(function (sel) {
                    var lv = parseInt(sel.dataset.level, 10);
                    if (lv < currentLevel && sel.value) newPath.push(parseInt(sel.value, 10));
                });

                if (this.value) newPath.push(parseInt(this.value, 10));

                categoryIdEl.value = this.value || '';
                renderCategoryLevels(newPath);
                updateCategoryPreview();
                ensureDefaultElectricDesignNotes();
                renderVariantPreview();
            });

            wrapper.appendChild(label);
            wrapper.appendChild(select);
            col.appendChild(wrapper);
            categoryLevelsEl.appendChild(col);

            if (!pathIds[level]) break;

            parentId = parseInt(pathIds[level], 10);
            level++;
        }

        categoryIdEl.value = lastSelected || '';
        renderBreadcrumb();
        updateCategoryPreview();
    }

    // ─── مدل‌لیست ─────────────────────────────────────────────────

    function syncSections() {
        var modelsOn = useModelsEl.checked;
        var designsOn = useDesignsEl.checked;

        modelsSection.style.display = modelsOn ? '' : 'none';
        designsSection.style.display = designsOn ? '' : 'none';

        modelBrandGroupEl.disabled = !modelsOn;
        designCountEl.disabled = !designsOn;

        if (!modelsOn) {
            selectedModelIds = new Set();
            syncHiddenModelInputs();
            renderModelPicker();
        }

        if (!designsOn) {
            designNotesWrap.innerHTML = '';
        }

        updateSelectedModelsCount();
        renderDesignNotes();
        renderVariantPreview();
    }

    function filteredModels() {
        var brand = modelBrandGroupEl.value || '';
        var keyword = String(modelSearchInput.value || '').trim().toLowerCase();

        var list = modelLists.filter(function (m) {
            return brand ? String(m.brand || '') === brand : false;
        });

        if (keyword) {
            list = list.filter(function (m) {
                return String(m.model_name || '').toLowerCase().indexOf(keyword) !== -1 ||
                       String(m.code || '').toLowerCase().indexOf(keyword) !== -1;
            });
        }

        return list;
    }

    function selectedModelsData() {
        var ids = Array.from(selectedModelIds).map(function (x) { return parseInt(x, 10); });
        return modelLists.filter(function (m) {
            return ids.indexOf(parseInt(m.id, 10)) !== -1;
        });
    }

    function updateSelectedModelsCount() {
        selectedModelsCountEl.textContent = String(selectedModelIds.size);

        if (!selectedModelIds.size) {
            modelPickerButtonText.textContent = 'مدل‌لیست‌ها را انتخاب کن...';
        } else {
            modelPickerButtonText.textContent = selectedModelIds.size + ' مدل انتخاب شده';
        }
    }

    function renderSelectedModelTags() {
        modelSelectedTags.innerHTML = '';

        var items = selectedModelsData().sort(function (a, b) {
            return String(a.model_name).localeCompare(String(b.model_name), 'fa');
        });

        if (!items.length) return;

        items.forEach(function (m) {
            var tag = document.createElement('span');
            tag.className = 'picker-tag';
            tag.textContent = m.model_name + (m.code ? ' (' + m.code + ')' : '');
            modelSelectedTags.appendChild(tag);
        });
    }

    function renderModelPicker() {
        modelPickerList.innerHTML = '';

        var brand = modelBrandGroupEl.value || '';
        var items = filteredModels();

        if (!brand) {
            var empty = document.createElement('div');
            empty.className = 'muted p-2';
            empty.textContent = 'اول گروه برند را انتخاب کن.';
            modelPickerList.appendChild(empty);
            updateSelectedModelsCount();
            renderSelectedModelTags();
            renderVariantPreview();
            return;
        }

        if (!items.length) {
            var empty2 = document.createElement('div');
            empty2.className = 'muted p-2';
            empty2.textContent = 'مدلی برای این برند پیدا نشد.';
            modelPickerList.appendChild(empty2);
            updateSelectedModelsCount();
            renderSelectedModelTags();
            renderVariantPreview();
            return;
        }

        items.forEach(function (m) {
            var lbl = document.createElement('label');
            lbl.className = 'picker-item';

            var check = document.createElement('input');
            check.type = 'checkbox';
            // توجه: name را اینجا نمی‌گذاریم چون hidden inputها کار ارسال را انجام می‌دهند
            check.value = String(m.id);
            check.checked = selectedModelIds.has(parseInt(m.id, 10));

            check.addEventListener('change', function () {
                var id = parseInt(this.value, 10);

                if (this.checked) {
                    selectedModelIds.add(id);
                } else {
                    selectedModelIds.delete(id);
                }

                // همیشه پس از تغییر، hidden inputها را به‌روز کن
                syncHiddenModelInputs();
                updateSelectedModelsCount();
                renderSelectedModelTags();
                renderVariantPreview();
            });

            var textWrap = document.createElement('div');
            var title = document.createElement('div');
            title.textContent = m.model_name || '—';

            var small = document.createElement('small');
            small.textContent = 'کد مدل: ' + (m.code || '---');

            textWrap.appendChild(title);
            textWrap.appendChild(small);

            lbl.appendChild(check);
            lbl.appendChild(textWrap);
            modelPickerList.appendChild(lbl);
        });

        updateSelectedModelsCount();
        renderSelectedModelTags();
        renderVariantPreview();
    }

    // ─── طرح‌بندی ─────────────────────────────────────────────────

    function getDesignCount() {
        if (!useDesignsEl.checked) return 0;

        var count = parseInt(designCountEl.value || '0', 10);
        if (isNaN(count) || count < 1) count = 1;
        if (count > 99) count = 99;

        return count;
    }

    function currentDesignNotesValues() {
        return Array.from(designNotesWrap.querySelectorAll('input[name="design_notes[]"]')).map(function (i) {
            return i.value || '';
        });
    }

    function renderDesignNotes() {
        if (!useDesignsEl.checked) {
            designNotesWrap.innerHTML = '';
            return;
        }

        var count = getDesignCount();
        var existing = currentDesignNotesValues();

        designNotesWrap.innerHTML = '';

        for (var i = 0; i < count; i++) {
            var val = existing[i] !== undefined ? existing[i] : (oldDesignNotes[i] || '');

            var box = document.createElement('div');
            box.className = 'design-note-item mb-2';

            box.innerHTML =
                '<label class="form-label">طرح ' + (i + 1) + '</label>' +
                '<input type="text" name="design_notes[]" class="form-control" maxlength="120" placeholder="مثلاً مشکی / گلدار / مات / زرشکی" value="' + escapeHtml(val) + '">';

            designNotesWrap.appendChild(box);
        }
    }

    // ─── پیش‌نمایش تنوع‌ها ────────────────────────────────────────

    function calcVariantsCount() {
        var modelsOn = useModelsEl.checked;
        var designsOn = useDesignsEl.checked;
        var modelCount = selectedModelsData().length;
        var designCount = getDesignCount();

        if (!modelsOn && !designsOn) return 1;
        if (modelsOn && !designsOn) return modelCount;
        if (!modelsOn && designsOn) return designCount;
        return modelCount * designCount;
    }

    function addExampleCode(text) {
        var item = document.createElement('span');
        item.className = 'example-code';
        item.textContent = text;
        variantExamplesEl.appendChild(item);
    }

    function renderVariantPreview() {
        var cat = selectedCategory();
        var productCode6 = (cat ? normalizeCategory2(cat.code) : '--') + previewSeq4;
        var modelsOn = useModelsEl.checked;
        var designsOn = useDesignsEl.checked;
        var models = selectedModelsData();
        var designCount = getDesignCount();

        variantsCountPreviewEl.textContent = String(calcVariantsCount());
        variantExamplesEl.innerHTML = '';

        var examples = [];

        if (!modelsOn && !designsOn) {
            examples.push(productCode6 + '00000');
        }

        if (modelsOn && !designsOn) {
            models.forEach(function (m) {
                examples.push(productCode6 + normalizeModel3(m.code) + '00');
            });
        }

        if (!modelsOn && designsOn) {
            for (var i = 1; i <= designCount; i++) {
                examples.push(productCode6 + '000' + padLeft(i, 2, '0'));
            }
        }

        if (modelsOn && designsOn) {
            models.forEach(function (m) {
                for (var j = 1; j <= designCount; j++) {
                    examples.push(productCode6 + normalizeModel3(m.code) + padLeft(j, 2, '0'));
                }
            });
        }

        if (!examples.length) {
            addExampleCode('هنوز نمونه‌ای برای نمایش وجود ندارد');
            return;
        }

        examples.slice(0, 10).forEach(function (code) {
            addExampleCode(code);
        });

        if (examples.length > 10) {
            addExampleCode('+' + (examples.length - 10) + ' مورد دیگر');
        }
    }

    // ─── Quick Add دسته‌بندی ──────────────────────────────────────

    function toggleCategoryQuickAdd(open) {
        categoryQuickAddBoxEl.hidden = !open;
        if (open) {
            clearFeedback(categoryQuickFeedbackEl);
            setTimeout(function () { categoryQuickNameEl.focus(); }, 20);
        }
    }

    async function quickAddCategory() {
        var name = String(categoryQuickNameEl.value || '').trim();

        if (!name) {
            setFeedback(categoryQuickFeedbackEl, 'نام دسته‌بندی نمی‌تواند خالی باشد.', 'error');
            return;
        }

        submitCategoryQuickAddEl.disabled = true;
        setFeedback(categoryQuickFeedbackEl, 'در حال ثبت...', '');

        try {
            var parentId = currentCategoryParentIdForQuickAdd();
            var data = await postJson(@json(route('categories.quickStore')), {
                name: name,
                parent_id: parentId,
            });

            var exists = categories.some(function (c) { return parseInt(c.id, 10) === parseInt(data.id, 10); });
            if (!exists) {
                categories.push({
                    id: parseInt(data.id, 10),
                    name: String(data.name || ''),
                    code: String(data.code || ''),
                    parent_id: data.parent_id === null ? null : parseInt(data.parent_id, 10),
                });
            }

            categoryIdEl.value = String(data.id);
            categoryQuickNameEl.value = '';
            setFeedback(categoryQuickFeedbackEl, data.message || 'با موفقیت ثبت شد.', 'success');
            renderCategoryLevels(getCategoryPath(data.id));
            renderVariantPreview();
            setTimeout(function () { toggleCategoryQuickAdd(false); }, 500);
        } catch (error) {
            setFeedback(categoryQuickFeedbackEl, error.message || 'ثبت دسته‌بندی انجام نشد.', 'error');
        } finally {
            submitCategoryQuickAddEl.disabled = false;
        }
    }

    // ─── Quick Add مدل ────────────────────────────────────────────

    function toggleModelQuickAdd(open) {
        modelQuickAddBoxEl.hidden = !open;
        if (open) {
            clearFeedback(modelQuickFeedbackEl);
            setTimeout(function () { modelQuickNameEl.focus(); }, 20);
        }
    }

    async function quickAddModel() {
        var brand = String(modelBrandGroupEl.value || '').trim();
        var name = String(modelQuickNameEl.value || '').trim();

        if (!brand) {
            setFeedback(modelQuickFeedbackEl, 'ابتدا گروه برند را انتخاب کنید.', 'error');
            return;
        }
        if (!name) {
            setFeedback(modelQuickFeedbackEl, 'نام مدل نمی‌تواند خالی باشد.', 'error');
            return;
        }

        submitModelQuickAddEl.disabled = true;
        setFeedback(modelQuickFeedbackEl, 'در حال ثبت...', '');

        try {
            var data = await postJson(@json(route('model-lists.quick-store')), {
                brand: brand,
                model_name: name,
            });

            var idx = modelLists.findIndex(function (m) {
                return parseInt(m.id, 10) === parseInt(data.id, 10);
            });

            var row = {
                id: parseInt(data.id, 10),
                brand: String(data.brand || ''),
                model_name: String(data.model_name || ''),
                code: String(data.code || ''),
            };

            if (idx === -1) {
                modelLists.push(row);
            } else {
                modelLists[idx] = row;
            }

            selectedModelIds.add(parseInt(data.id, 10));
            syncHiddenModelInputs(); // به‌روزرسانی hidden inputها
            modelQuickNameEl.value = '';
            setFeedback(modelQuickFeedbackEl, data.message || 'مدل ثبت شد.', 'success');
            renderModelPicker();
            setTimeout(function () { toggleModelQuickAdd(false); }, 500);
        } catch (error) {
            setFeedback(modelQuickFeedbackEl, error.message || 'ثبت مدل انجام نشد.', 'error');
        } finally {
            submitModelQuickAddEl.disabled = false;
        }
    }

    // ─── رویدادها ─────────────────────────────────────────────────

    modelPickerButton.addEventListener('click', function () {
        if (modelBrandGroupEl.disabled) return;

        modelPickerPanel.classList.toggle('open');

        if (modelPickerPanel.classList.contains('open')) {
            setTimeout(function () { modelSearchInput.focus(); }, 50);
        }
    });

    document.addEventListener('click', function (e) {
        if (!modelPickerEl.contains(e.target)) {
            modelPickerPanel.classList.remove('open');
        }
    });

    modelSearchInput.addEventListener('input', renderModelPicker);

    openModelQuickAddEl.addEventListener('click', function () {
        if (modelBrandGroupEl.disabled) return;
        toggleModelQuickAdd(modelQuickAddBoxEl.hidden);
    });

    cancelModelQuickAddEl.addEventListener('click', function () {
        toggleModelQuickAdd(false);
    });

    submitModelQuickAddEl.addEventListener('click', quickAddModel);

    modelQuickNameEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            quickAddModel();
        }
    });

    openCategoryQuickAddEl.addEventListener('click', function () {
        toggleCategoryQuickAdd(categoryQuickAddBoxEl.hidden);
    });

    cancelCategoryQuickAddEl.addEventListener('click', function () {
        toggleCategoryQuickAdd(false);
    });

    submitCategoryQuickAddEl.addEventListener('click', quickAddCategory);

    categoryQuickNameEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            quickAddCategory();
        }
    });

    useModelsEl.addEventListener('change', syncSections);
    useDesignsEl.addEventListener('change', syncSections);

    modelBrandGroupEl.addEventListener('change', function () {
        // وقتی برند عوض می‌شه، انتخاب‌های قبلی پاک می‌شن
        selectedModelIds = new Set();
        syncHiddenModelInputs();
        modelSearchInput.value = '';
        toggleModelQuickAdd(false);
        clearFeedback(modelQuickFeedbackEl);
        renderModelPicker();
    });

    designCountEl.addEventListener('input', function () {
        renderDesignNotes();
        renderVariantPreview();
    });

    // ─── راه‌اندازی اولیه ─────────────────────────────────────────

    if (oldCategoryId) {
        renderCategoryLevels(getCategoryPath(oldCategoryId));
    } else {
        renderCategoryLevels([]);
    }

    updateCategoryPreview();
    renderModelPicker();
    renderDesignNotes();
    ensureDefaultElectricDesignNotes();
    syncSections();

    // اگر از old() مدل‌هایی برگشته، hidden inputها را از همان ابتدا بساز
    syncHiddenModelInputs();
});
</script>
@endsection
