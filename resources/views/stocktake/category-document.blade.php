@extends('layouts.app')
@section('content')
<link rel="stylesheet" href="{{ asset('css/stocktake-workspace.css') }}?v=20261030-3">
<style>
.category-document{max-width:1600px;width:100%;margin:auto;padding:0 18px 210px;direction:rtl}
.category-document .cd-selection{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) minmax(220px,.8fr);gap:18px;padding:22px;align-items:end}
.category-document .cd-warning{padding:16px 20px;border:1px solid var(--app-warning,#c98216);border-radius:13px;background:var(--app-warning-soft,#fff6e5);color:var(--app-text,#172b3a);font-size:.84rem;line-height:2;margin-bottom:18px}
.category-document .cd-warning strong{color:var(--app-warning,#c98216)}
.category-document .cd-badge{display:inline-flex;color:var(--st-accent);background:var(--st-accent-soft);border-radius:999px;padding:5px 11px;font-size:.73rem;font-weight:800;margin-top:8px}
.category-document .cd-table-wrap{border:1px solid var(--st-border);border-radius:16px;overflow:auto;background:var(--st-surface);margin-top:14px}
.category-document table{width:100%;min-width:850px;border-collapse:collapse;table-layout:fixed;color:var(--st-ink);font-size:.83rem}
.category-document th{font-size:.75rem;color:var(--st-muted);font-weight:800;background:var(--st-soft);padding:13px 12px;border-bottom:1px solid var(--st-border);text-align:right;line-height:1.8}
.category-document td{padding:13px 12px;border-bottom:1px solid var(--st-border);vertical-align:middle;overflow-wrap:anywhere}
.category-document tr:last-child td{border-bottom:0}
.category-document th:first-child{width:30%}.category-document th:nth-child(2){width:14%}.category-document th:nth-child(3){width:11%}.category-document th:nth-child(4){width:14%}.category-document th:nth-child(5){width:20%}.category-document th:nth-child(6){width:11%}
.category-document .cd-name strong,.category-document .cd-name span,.category-document .cd-name small{display:block;line-height:1.8}.category-document .cd-name strong{font-size:.86rem}.category-document .cd-name span{color:var(--st-muted)}.category-document .cd-name small{direction:ltr;text-align:right;font-size:.7rem;color:var(--st-muted)}
.category-document .cd-number{font-variant-numeric:tabular-nums;text-align:center}.category-document .cd-actual input{max-width:160px;margin:auto;text-align:center;min-height:44px;border:1px solid var(--st-border);border-radius:10px;background:var(--st-surface);color:var(--st-accent);font-size:1.05rem;font-weight:850}.category-document .cd-actual input:invalid{border-color:var(--app-danger,#c54b4b);color:var(--app-danger,#c54b4b)}
.category-document .cd-diff{font-weight:800;text-align:center;font-variant-numeric:tabular-nums}
.category-document .cd-toolbar{position:fixed;right:280px;left:24px;bottom:max(12px,env(safe-area-inset-bottom));z-index:1035;display:flex;align-items:center;justify-content:flex-end;flex-wrap:wrap;gap:10px;padding:14px 17px;border:1px solid var(--st-border);border-top:3px solid var(--st-accent);border-radius:15px;background:var(--st-surface);color:var(--st-ink);box-shadow:var(--app-shadow-md)}
.category-document .cd-toolbar-message{flex:1 1 100%;font-size:.76rem;line-height:1.9;color:var(--st-muted);max-height:5.7em;overflow:auto}.category-document .cd-toolbar-message strong{color:var(--st-ink);display:block;font-size:.8rem}.category-document .cd-toolbar-message .is-error{color:var(--app-danger,#c54b4b)}
.category-document .cd-toolbar button{min-height:43px;border-radius:10px;font-size:.81rem;font-weight:800}.category-document #cdSave{background:#14785c;border-color:#14785c;color:white}
.category-document [hidden]{display:none!important}
@media(max-width:1199px){.category-document .cd-toolbar{left:16px;right:16px}}
@media(max-width:800px){.category-document .cd-selection{grid-template-columns:repeat(2,minmax(0,1fr))}.category-document #cdShow{grid-column:1/-1}.category-document{padding-inline:10px}}
@media(max-width:600px){.category-document{padding-bottom:260px}.category-document .cd-selection{grid-template-columns:minmax(0,1fr);padding:18px}.category-document #cdShow{grid-column:auto}.category-document .cd-toolbar{display:grid;grid-template-columns:1fr 1fr;left:8px;right:8px;gap:7px;padding:10px}.category-document .cd-toolbar-message{grid-column:1/-1}.category-document #cdSave{grid-column:1/-1}.category-document .cd-table-wrap{overflow:visible;border:0;background:transparent}.category-document table,.category-document tbody{display:block;width:100%;min-width:0}.category-document thead{display:none}.category-document tbody tr{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;padding:13px;margin-bottom:13px;border:1px solid var(--st-border);border-radius:14px;background:var(--st-surface)}.category-document tbody td{display:flex;flex-direction:column;gap:5px;padding:10px;background:var(--st-soft);border:1px solid var(--st-border);border-radius:9px;text-align:right;min-width:0}.category-document tbody td::before{content:attr(data-label);font-size:.7rem;font-weight:700;color:var(--st-muted)}.category-document tbody td:first-child,.category-document tbody td:nth-child(4),.category-document tbody td:nth-child(5){grid-column:1/-1}.category-document .cd-actual input{max-width:none;width:100%}}
</style>
<div class="stocktake-workspace category-document" id="categoryCountApp" data-subcategories-url="{{ route('stock-count-documents.subcategories') }}">
    <header class="stocktake-page__header mb-3">
        <div class="stocktake-page__heading">
            <div class="stocktake-eyebrow">کنترل موجودی انبار مرکزی</div>
            <h4 class="mb-1">{{ !empty($categoryDocumentSeed['id']) ? 'ادامه شمارش سند دسته‌ای' : 'انبارگردانی دسته‌ای' }}</h4>
            <p class="stocktake-page__intro mb-0">همه کالاهای محدوده، یک سند انبارگردانی و یک ثبت نهایی</p>
            <span class="cd-badge">یک سند برای کل دسته و زیردسته‌های آن</span>
        </div>
        <a href="{{ !empty($categoryDocumentSeed['review_url']) ? $categoryDocumentSeed['review_url'] : route('stocktake.index') }}" class="btn btn-outline-secondary stocktake-page__back">{{ !empty($categoryDocumentSeed['id']) ? 'بازگشت به بررسی سند' : 'بازگشت به انبارگردانی' }}</a>
    </header>

    <div id="cdRecovery" class="alert alert-info" hidden>
        <p class="mb-2">شمارش ذخیره‌نشده یا درخواست قبلی در این مرورگر قابل بازیابی است. بازیابی به‌تنهایی چیزی را ذخیره یا نهایی نمی‌کند.</p>
        <button type="button" id="cdRestore" class="btn btn-sm btn-primary">بازیابی شمارش قبلی</button>
        <button type="button" id="cdDiscard" class="btn btn-sm btn-outline-secondary">ادامه با نسخه سرور</button>
    </div>

    <section class="stocktake-selector mb-3" aria-labelledby="cdScopeTitle">
        <header class="stocktake-selector__header">
            <div><h5 id="cdScopeTitle">محدودهٔ شمارش</h5><p>{{ !empty($categoryDocumentSeed['id']) ? 'محدوده این سند ثابت است؛ شمارش ذخیره‌شده از سرور دریافت شده است.' : 'دسته اصلی را انتخاب کنید؛ انتخاب زیردسته اختیاری است.' }}</p></div>
            @if(!empty($categoryDocumentSeed['id']))<span class="stocktake-selector__tag">سند {{ $categoryDocumentSeed['id'] }}</span>@endif
        </header>
        <div class="cd-selection">
            <label class="stocktake-field" for="cdCategory"><span>دسته‌بندی اصلی</span>
                <select id="cdCategory" class="form-select"><option value="">انتخاب دسته‌بندی</option>@foreach($rootCategories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select>
            </label>
            <label class="stocktake-field" for="cdSubcategory"><span>زیردسته <small>(اختیاری)</small></span><select id="cdSubcategory" class="form-select" disabled><option value="">همه زیردسته‌ها</option></select></label>
            <button type="button" id="cdShow" class="btn stocktake-category-button">نمایش کالاهای محدوده <span aria-hidden="true">←</span></button>
        </div>
    </section>

    <div class="cd-warning" role="note">
        @if(!empty($categoryDocumentSeed['id']))
        <strong>شمارش ذخیره‌شدهٔ تمام ردیف‌های این سند حفظ می‌شود.</strong>
        فقط ردیف‌های موردنظر را اصلاح کنید؛ تغییر جستجو یا صفحه، شمارش ردیف‌های دیگر را پاک نمی‌کند. مقدار خالی در این سند دسته‌ای صفر است.
        @else
        <strong>تمام کالاهای این محدوده از ابتدا با موجودی واقعی صفر محاسبه می‌شوند.</strong>
        فقط تعدادهای واقعی را اصلاح کنید. کالاهایی که هنوز نمایش داده نشده‌اند نیز در سند همین دسته با صفر ثبت می‌شوند؛ مقدار خالی هم صفر است.
        @endif
        ذخیره فقط یک پیش‌نویس می‌سازد و موجودی را تغییر نمی‌دهد. در صفحه بعد، ردیف‌های مثبت و خلاصه صفرها را بررسی می‌کنید؛ ثبت نهایی، صفرها را هم اعمال می‌کند.
    </div>

    <div class="sc-live-search"><label for="cdSearch" class="form-label fw-bold">جستجوی کالا یا تنوع در همین محدوده</label><input id="cdSearch" class="form-control" type="search" maxlength="80" autocomplete="off" placeholder="نام کالا، مدل، SKU یا کد تنوع..." disabled><div class="sc-subtitle">با تغییر جستجو، شمارش‌های واردشده حفظ می‌شوند.</div></div>
    <div class="cd-table-wrap"><table><thead><tr><th>کالا و تنوع</th><th>موجودی انبار مرکزی</th><th>رزرو فعال</th><th>صف جمع‌آوری</th><th>شمارش واقعی</th><th>اختلاف</th></tr></thead><tbody id="cdRows"></tbody></table></div>

    <div class="cd-toolbar" role="toolbar" aria-label="ذخیره سند انبارگردانی دسته‌ای">
        <div class="cd-toolbar-message"><strong id="cdSummary">ابتدا محدوده شمارش را انتخاب کنید.</strong><div id="cdStatus" role="status" aria-live="polite">مقادیر پیش‌فرض صفر هستند؛ ذخیره، صفحه بررسی یک سند را باز می‌کند.</div></div>
        <button type="button" id="cdMore" class="btn btn-outline-primary" hidden>دریافت ادامه کالاها</button>
        <button type="button" id="cdLoadAll" class="btn btn-outline-secondary" hidden>نمایش همه کالاها</button>
        <button type="button" id="cdSave" class="btn btn-success" disabled>ذخیره یک سند و بررسی شمارش</button>
    </div>
</div>
<script type="application/json" id="categoryDocumentSeed">@json($categoryDocumentSeed)</script>
<script src="{{ asset('js/stocktake-category-document.js') }}?v=20261030-2"></script>
@endsection
