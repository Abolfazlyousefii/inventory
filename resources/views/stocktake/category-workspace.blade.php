@extends('layouts.app')
@section('content')
<style>
.stocktake-category{max-width:1500px;margin:auto;padding:4px 14px 70px;direction:rtl}
.stocktake-category .sc-panel{border:1px solid #e2e8f0;background:#fff;border-radius:15px;box-shadow:0 5px 16px rgba(20,45,62,.04);padding:18px;margin-bottom:14px}
.stocktake-category .sc-top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.stocktake-category .sc-controls{display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) auto;gap:12px;align-items:end}
.stocktake-category .sc-control{display:flex;flex-direction:column;gap:5px;font-size:.85rem;font-weight:700;color:#42566b}
.stocktake-category .sc-control :is(select,input){min-height:40px;width:100%;padding:7px 10px;border-radius:9px;border:1px solid #cedbe8;color:#18364a;background:#fff}
.stocktake-category .sc-subtitle{font-size:.82rem;color:#64748b;line-height:2;margin-top:7px}
.stocktake-category .sc-warning{border:1px solid #f1d6a2;background:#fffbeb;color:#805419;padding:12px 15px;border-radius:11px;font-size:.83rem;line-height:2;margin-bottom:15px}
.stocktake-category .sc-status{border-radius:10px;border:1px solid #cbdde8;background:#f0f9fc;color:#265573;padding:12px 14px;margin-bottom:12px;line-height:1.9}
.stocktake-category .sc-status.is-error{border-color:#fecaca;background:#fff4f4;color:#a12323}
.stocktake-category .sc-product{border:1px solid #dce6ee;border-radius:13px;background:#fff;margin-bottom:13px;overflow:hidden}
.stocktake-category .sc-product-head{background:#f6fafc;display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap;padding:12px 15px;border-bottom:1px solid #e1eaf0}
.stocktake-category .sc-product-head strong{display:block;font-size:.96rem;color:#173c53}
.stocktake-category .sc-product-meta{font-size:.73rem;color:#667b8c;margin-top:3px}
.stocktake-category .sc-table-wrap{overflow-x:auto}
.stocktake-category table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:.82rem}
.stocktake-category th{background:#f8fafc;color:#536879;font-weight:800;font-size:.75rem;padding:9px 10px;border-bottom:1px solid #e2e8f0;text-align:right}
.stocktake-category td{padding:9px 10px;border-bottom:1px solid #edf2f6;vertical-align:middle}
.stocktake-category tr:last-child td{border-bottom:0}
.stocktake-category th:nth-child(1){width:30%}
.stocktake-category th:nth-child(2){width:12%}
.stocktake-category th:nth-child(3){width:11%}
.stocktake-category th:nth-child(4){width:18%}
.stocktake-category th:nth-child(5){width:19%}
.stocktake-category th:nth-child(6){width:10%}
.stocktake-category .sc-flow-details{display:block;font-size:.68rem;line-height:1.8;color:#9a5609;margin-top:3px}
.stocktake-category .sc-diff-up{color:#166534;font-weight:800}
.stocktake-category .sc-diff-down{color:#991b1b;font-weight:800}
.stocktake-category .sc-number{text-align:center;font-variant-numeric:tabular-nums}
.stocktake-category .sc-variant{font-weight:750;color:#203c50}
.stocktake-category .sc-variant small{display:block;color:#8292a2;font-weight:400;direction:ltr;text-align:right}
.stocktake-category .sc-actual{display:block;width:100%;min-height:38px;border:1px solid #d0dce8;border-radius:8px;padding:6px 10px;text-align:center;font-weight:800;min-width:90px}
.stocktake-category .sc-actual:focus{outline:2px solid #84bdb9;border-color:#287c7c}
.stocktake-category .sc-badge{border-radius:999px;padding:3px 9px;background:#fff0d2;color:#87520c;font-weight:750}
.stocktake-category .sc-muted{color:#8292a2}
.stocktake-category .sc-feedback{font-size:.76rem;margin-top:5px;color:#61768c}
.stocktake-category .sc-feedback.is-error{color:#bd2929}
.stocktake-category .sc-success{font-size:.81rem;color:#0b805c;font-weight:700}
.stocktake-category .sc-footer{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:12px 15px;border-top:1px solid #e4eaf0}
.stocktake-category .sc-more{display:block;margin:18px auto}
@media(max-width:800px){.stocktake-category .sc-controls{grid-template-columns:1fr 1fr}.stocktake-category .sc-panel{padding:12px}.stocktake-category table{min-width:850px}}
@media(max-width:500px){.stocktake-category .sc-controls{grid-template-columns:1fr}.stocktake-category .sc-product-head,.stocktake-category .sc-footer{padding:11px}}
</style>
<div class="stocktake-category" id="stocktakeCategoryApp"
     data-rows-url="{{ route('stock-count-documents.category-variants') }}"
     data-subcategories-url="{{ route('stock-count-documents.subcategories') }}"
     data-bulk-store-url="{{ route('stock-count-documents.category-drafts.store') }}"
     data-store-url="{{ route('stock-count-documents.store') }}">
  <div class="sc-top mb-3">
    <div>
      <h4 class="mb-1">انبارگردانی دسته‌ای</h4>
      <div class="sc-subtitle">نمایش کالاها و تنوع‌های یک دسته یا زیردسته، بدون انتخاب جداگانه هر محصول</div>
    </div>
    <a class="btn btn-outline-secondary" href="{{ route('stocktake.index') }}">بازگشت به انبارگردانی</a>
  </div>

  <div class="sc-warning">
    <strong>مهم:</strong> هر محصول سند جداگانه انبارگردانی دارد. در این صفحه فقط پیش‌نویس همان محصول ذخیره می‌شود؛
    هیچ موجودی با نمایش یا ذخیره پیش‌نویس تغییر نمی‌کند. مقدارهای خالی نباید بدون شمارش در تأیید نهایی صفر شوند.
    قبل از اعمال نهایی هر سند، تمام تنوع‌های همان محصول را کنترل کنید.
  </div>

  <section class="sc-panel">
    <div class="sc-controls">
      <label class="sc-control">دسته‌بندی اصلی
        <select id="scCategory"><option value="">انتخاب دسته‌بندی</option>
          @foreach($rootCategories as $category)
          <option value="{{ $category->id }}">{{ $category->name }}</option>
          @endforeach
        </select>
      </label>
      <label class="sc-control">زیردسته‌بندی
        <select id="scSubcategory" disabled><option value="">همه زیردسته‌ها</option></select>
      </label>
      <label class="sc-control">تاریخ سند
        <input type="date" id="scDate" value="{{ now()->format('Y-m-d') }}" required>
      </label>
      <button type="button" id="scShow" class="btn btn-primary">نمایش محصولات دسته</button>
    </div>
    <p class="sc-subtitle mb-0">اگر زیردسته‌ای انتخاب نکنی، تمام کالاهای دسته اصلی و زیردسته‌های آن نمایش داده می‌شوند. اقلام در بسته‌های ۶۰تایی دریافت می‌شوند.</p>
  </section>

  <div id="scStatus" role="status" class="sc-status" aria-live="polite">برای مشاهده محصولات، دسته یا زیردسته را انتخاب کن.</div>
  <div id="scProducts"></div>
  <button type="button" id="scMore" class="btn btn-outline-primary sc-more" hidden>نمایش تنوع‌های بیشتر</button>
  <div class="sc-footer"><button type="button" id="scSaveAll" class="btn btn-success" disabled>ذخیره یکجای پیش‌نویس‌ها</button><span class="sc-subtitle">تمام کالاهای دریافت‌شده با یک دکمه ذخیره می‌شوند؛ مقادیر خالی شمارش‌نشده می‌مانند.</span></div>
  <section class="sc-panel mt-3"><h6>فهرست پیش‌نویس‌ها برای بررسی و ثبت نهایی</h6><ul id="scDraftList" class="mb-0" aria-live="polite"></ul></section>
  <p class="sc-subtitle">توجه: ارقام موجودی و رزرو در لحظه بارگذاری خوانده می‌شوند و تا پایان انبارگردانی Snapshot ثابت محسوب نمی‌شوند.</p>
</div>
<script>{!! file_get_contents(public_path('js/stocktake-category-workspace.js')) !!}</script>
@endsection
