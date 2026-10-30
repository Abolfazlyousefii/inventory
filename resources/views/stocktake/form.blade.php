@extends('layouts.app')
@section('content')
@php
    $stockCountConflicts = collect(session('stock_count_conflicts', []));
    $stockCountConflictCount = $stockCountConflicts->count();
    $oldActualQuantities = old('actual_quantities', []);
@endphp
<style>
.stocktake-page{width:100%;max-width:1600px;margin:0 auto;padding:0 18px 96px;overflow-x:hidden}.stocktake-card{border:1px solid #e9edf3;border-radius:16px;box-shadow:0 8px 24px rgba(15,23,42,.04)}.stocktake-compact .form-label{font-size:.78rem;color:#64748b;margin-bottom:.35rem}.stocktake-tools{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}.stocktake-tools .variant-search{min-width:280px;flex:1 1 340px}.stocktake-table-wrap{max-height:60vh;overflow-y:auto;overflow-x:hidden;scrollbar-width:thin}.stocktake-table{table-layout:fixed;width:100%;margin:0}.stocktake-table th{position:sticky;top:0;z-index:2;background:#f8fafc;font-size:.8rem;color:#475569;white-space:nowrap}.stocktake-table td{vertical-align:middle;background:#fff}.stocktake-table tbody tr:hover td{background:#fcfcfd}.col-variant{width:auto}.col-central{width:140px}.col-reserved{width:110px}.col-flow{width:154px}.flow-badge{display:inline-flex;align-items:center;justify-content:center;min-width:42px;border-radius:999px;padding:.16rem .5rem;background:#f1f5f9;color:#64748b;font-size:.84rem;font-weight:800}.flow-badge.has-flow{background:#fff3d6;color:#9a5609}.flow-breakdown{margin-top:.28rem;font-size:.66rem;line-height:1.8;color:#68758a}.flow-breakdown b{color:#92500b}.col-actual{width:190px}.col-diff{width:100px}.variant-title{font-weight:700;color:#0f172a;line-height:1.45;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.variant-meta{font-size:.76rem;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.sales-badge{display:inline-flex;margin-top:.25rem;padding:.1rem .45rem;border-radius:999px;background:#f1f5f9;color:#475569;font-size:.7rem}.sales-badge.is-on{background:#ecfdf5;color:#047857}.central-qty{text-align:center;font-weight:800;font-size:1rem;color:#1e293b}.central-help{display:block;font-size:.68rem;color:#94a3b8}.reserved-badge{display:inline-flex;align-items:center;justify-content:center;min-width:40px;padding:.2rem .5rem;border-radius:999px;background:#f8fafc;color:#475569}.reserved-badge.has-reserve{background:#fef9c3;color:#92400e}.stock-count-stepper{display:grid;grid-template-columns:42px minmax(72px,1fr) 42px;border:1px solid #dbe3ef;border-radius:12px;overflow:hidden;background:#fff}.stock-count-stepper button{border:0;background:#f8fafc;color:#0f172a;font-weight:900;font-size:1.05rem;min-height:40px}.stock-count-stepper button:hover{background:#eef2f7}.stock-count-stepper input{border:0;border-inline:1px solid #dbe3ef;text-align:center;font-weight:800;min-width:0;outline:0}.stock-count-stepper.has-error{border-color:#ef4444;box-shadow:0 0 0 3px rgba(239,68,68,.08)}.has-stock-count-error td{background:#fff7f7!important}.has-stock-count-error{border-inline-start:4px solid #fca5a5}.stock-count-row-message{margin-top:.3rem;font-size:.74rem;color:#dc2626}.stock-count-row-message.is-warning{color:#b45309}.stock-count-refresh{display:inline-flex;margin-top:.35rem}.summary-pill.error-pill{cursor:pointer}.summary-pill.error-pill.has-errors{border-color:#fecaca;background:#fee2e2;color:#991b1b}.summary-pill.error-pill.no-errors{background:#f0fdf4;color:#166534}.server-conflict-note{display:block;margin-top:.25rem;color:#b91c1c;font-size:.72rem}.diff-badge{display:inline-flex;align-items:center;justify-content:center;min-width:58px;padding:.22rem .55rem;border-radius:999px;font-weight:800;font-size:.82rem;background:#eef2ff;color:#3730a3}.diff-badge.diff-empty{background:#f8fafc;color:#64748b}.diff-badge.diff-up{background:#dcfce7;color:#166534}.diff-badge.diff-down{background:#fee2e2;color:#991b1b}.diff-badge.diff-zero{background:#f1f5f9;color:#334155}.reserve-error-text{display:block;margin-top:.25rem;color:#dc2626;font-size:.72rem}.summary-line{display:flex;gap:.75rem;flex-wrap:wrap;align-items:center}.summary-pill{border:1px solid #e2e8f0;border-radius:999px;padding:.35rem .75rem;background:#fff;font-size:.86rem}.summary-pill b{font-weight:900}.summary-details{font-size:.78rem;color:#64748b;margin-top:.5rem}.stocktake-actions{position:sticky;bottom:0;z-index:5;background:rgba(255,255,255,.96);backdrop-filter:blur(8px);border:1px solid #e2e8f0;border-radius:16px;padding:.75rem;margin-top:1rem;display:flex;gap:.5rem;align-items:center;justify-content:space-between;flex-wrap:wrap}.stocktake-mobile-label{display:none}@media(max-width:768px){.stocktake-page{padding:0 12px 110px}.stocktake-table-wrap{max-height:none;overflow:visible}.stocktake-table,.stocktake-table thead,.stocktake-table tbody,.stocktake-table tr,.stocktake-table td{display:block;width:100%}.stocktake-table thead{display:none}.stocktake-table tr{border:1px solid #e2e8f0;border-radius:14px;margin-bottom:.75rem;padding:.75rem;background:#fff}.stocktake-table td{border:0!important;padding:.35rem 0;background:transparent!important}.stocktake-mobile-label{display:block;font-size:.72rem;color:#64748b;margin-bottom:.15rem}.stocktake-row-grid{display:grid;grid-template-columns:1fr 1fr;gap:.5rem}.stock-count-stepper{grid-template-columns:48px 1fr 48px}.stock-count-stepper button{min-height:44px}.col-central,.col-reserved,.col-flow,.col-actual,.col-diff{width:auto}.stocktake-actions{left:12px;right:12px}}
.stocktake-category .sc-bulk-toolbar{position:fixed;bottom:max(12px,env(safe-area-inset-bottom));left:24px;right:280px;z-index:1035;display:flex;align-items:center;justify-content:flex-end;gap:9px;flex-wrap:wrap;padding:13px 16px;border:1px solid var(--app-border,#bad5df);border-radius:15px;background:var(--app-surface,#fff);color:var(--app-text,#18364a);box-shadow:0 7px 32px rgba(18,52,72,.2);backdrop-filter:blur(10px)}
.stocktake-category .sc-bulk-toolbar[hidden],.stocktake-category[hidden] .sc-bulk-toolbar{display:none!important}
.stocktake-category .sc-toolbar-status{flex:1 1 100%;font-size:.78rem;line-height:1.8;color:var(--app-text,#34566d);max-height:3.6em;overflow:auto}
.stocktake-category .sc-bulk-toolbar button{min-height:41px;margin:0;white-space:normal;font-weight:700}
.stocktake-category .sc-review-summary{padding:12px;border-radius:10px;background:var(--app-surface,#f0f8fb);color:var(--app-text,#25485d);border:1px solid var(--app-border,#cbdde8);font-size:.83rem;line-height:2;margin-bottom:12px}
.stocktake-category{padding-bottom:190px!important}
@media(max-width:1199px){.stocktake-category .sc-bulk-toolbar{left:16px;right:16px}}
@media(max-width:600px){.stocktake-category .sc-bulk-toolbar{display:grid;grid-template-columns:1fr 1fr;gap:7px;padding:10px;left:8px;right:8px;bottom:max(8px,env(safe-area-inset-bottom))}.stocktake-category .sc-toolbar-status{grid-column:1/-1;font-size:.72rem}.stocktake-category .sc-bulk-toolbar button{font-size:.77rem;padding:7px}.stocktake-category{padding-bottom:240px!important}}
</style>
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

.stocktake-page .stocktake-category{max-width:none;padding:0;margin:0 0 16px}
/* Page header and counting form must remain separate full-width blocks. */
.stocktake-page > #stocktakeForm{display:block;width:100%;min-width:0;max-width:100%}
.stocktake-page__header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;min-width:0;width:100%;padding:4px 0}
.stocktake-page__heading{min-width:0;flex:1 1 290px}
.stocktake-page__heading h4{font-weight:800;font-size:1.16rem;line-height:1.6;color:#15344a;overflow-wrap:anywhere}
.stocktake-page__intro{font-size:.8rem;line-height:1.75;color:#64748b}
.stocktake-page__back{flex:0 0 auto;white-space:nowrap}
.stocktake-page .card,.stocktake-page .card-body,.stocktake-page .stocktake-category,.stocktake-category .sc-panel{min-width:0}
.stocktake-category .sc-product-head>div{min-width:0;flex:1 1 220px}
.stocktake-category .sc-product-head strong{overflow-wrap:anywhere;line-height:1.6}
.stocktake-category .sc-table-wrap{max-width:100%;overflow-x:auto;overscroll-behavior-x:contain;-webkit-overflow-scrolling:touch}
.stocktake-category table{min-width:700px}
.stocktake-category td,.stocktake-category th{overflow-wrap:anywhere}
.stocktake-category .sc-actual{min-width:0}
.stocktake-category .sc-footer>*{min-width:0}
@media(max-width:800px){
 .stocktake-page__header{align-items:flex-start}
 .stocktake-category table{min-width:780px}
 .stocktake-category .sc-product{margin-bottom:10px}
 .stocktake-category .sc-panel{padding:12px}
}
@media(max-width:500px){
 .stocktake-page__heading{flex-basis:100%}
 .stocktake-page__heading h4{font-size:1.05rem}
 .stocktake-page__back{width:100%;justify-content:center}
 .stocktake-category .sc-footer{align-items:stretch}
 .stocktake-category .sc-footer button{width:100%}
}

/* Inline category counting: stable full-width desktop and accessible mobile cards. */
.stocktake-page{width:100%;max-width:1600px;min-width:0;overflow:visible}
.stocktake-page__header{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:12px;width:100%;min-width:0}
.stocktake-page__heading{min-width:0;max-width:100%}
.stocktake-page__back{justify-self:end;max-width:100%;white-space:normal}
.stocktake-page #stocktakeForm{display:block;width:100%;min-width:0;max-width:100%;margin:0}
.stocktake-page #stocktakeCategoryApp{display:block;width:100%;min-width:0;max-width:none;margin:0 0 18px;padding:0}
.stocktake-page .sc-panel,.stocktake-page .sc-product{width:100%;min-width:0;max-width:100%}
.stocktake-page .sc-panel{padding:16px}
.stocktake-page .sc-product{box-shadow:0 4px 16px rgba(20,43,62,.045)}
.stocktake-page .sc-product-head{display:grid;grid-template-columns:minmax(0,1fr) max-content;align-items:center;gap:12px;padding:13px 16px}
.stocktake-page .sc-product-head>div{min-width:0;max-width:100%}
.stocktake-page .sc-product-head strong{line-height:1.7;overflow-wrap:anywhere}
.stocktake-page .sc-product-head>.sc-muted{display:inline-flex;align-items:center;justify-content:center;max-width:100%;padding:5px 10px;border-radius:999px;border:1px solid #d8e8f0;background:#eef7fa;white-space:nowrap;color:#315771;font-weight:700;font-size:.75rem}
.stocktake-page .sc-table-wrap{display:block;width:100%;max-width:100%;min-width:0;overflow-x:auto;overflow-y:hidden;overscroll-behavior-x:contain;-webkit-overflow-scrolling:touch}
.stocktake-page .sc-table-wrap table{width:100%;min-width:860px;max-width:none;table-layout:fixed;border-collapse:collapse}
.stocktake-page .sc-table-wrap td,.stocktake-page .sc-table-wrap th{padding:10px 11px;line-height:1.8;vertical-align:middle;overflow-wrap:anywhere}
.stocktake-page .sc-table-wrap th{background:#f4f8fb;font-size:.76rem;white-space:normal}
.stocktake-page .sc-table-wrap th:nth-child(1){width:29%}
.stocktake-page .sc-table-wrap th:nth-child(2){width:12%}
.stocktake-page .sc-table-wrap th:nth-child(3){width:10%}
.stocktake-page .sc-table-wrap th:nth-child(4){width:21%}
.stocktake-page .sc-table-wrap th:nth-child(5){width:18%}
.stocktake-page .sc-table-wrap th:nth-child(6){width:10%}
.stocktake-page .sc-variant{min-width:0;overflow-wrap:anywhere}
.stocktake-page .sc-variant small{max-width:100%;overflow-wrap:anywhere}
.stocktake-page .sc-actual{display:block;box-sizing:border-box;width:100%;min-width:0;max-width:185px;min-height:42px;margin-inline:auto}
.stocktake-page .sc-flow-details{line-height:1.9;overflow-wrap:anywhere}
.stocktake-page .sc-footer{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;padding:12px 16px}
.stocktake-page .sc-feedback{min-width:0;overflow-wrap:anywhere;line-height:1.9}
.stocktake-page .sc-footer .btn{justify-self:end;white-space:normal}
.stocktake-page .sc-status,.stocktake-page .sc-warning,.stocktake-page .sc-live-search{min-width:0;max-width:100%;overflow-wrap:anywhere}
@media(max-width:900px){.stocktake-page{padding-inline:12px}.stocktake-page .sc-table-wrap table{min-width:800px}}
@media(max-width:720px){
.stocktake-page{padding-inline:10px}
.stocktake-page__header{grid-template-columns:minmax(0,1fr);gap:10px;align-items:stretch}
.stocktake-page__heading{width:100%}
.stocktake-page__back{justify-self:stretch;width:100%;text-align:center}
.stocktake-page .sc-panel{padding:11px}
.stocktake-page .sc-product-head{grid-template-columns:minmax(0,1fr);gap:7px;padding:12px}
.stocktake-page .sc-product-head>.sc-muted{justify-self:start;white-space:normal}
.stocktake-page .sc-product .sc-table-wrap{overflow:visible;max-width:100%}
.stocktake-page .sc-product table,.stocktake-page .sc-product thead,.stocktake-page .sc-product tbody{display:block;width:100%;min-width:0;max-width:100%}
.stocktake-page .sc-product thead{display:none}
.stocktake-page .sc-product tbody tr{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;padding:12px;border-bottom:1px solid #e6edf3}
.stocktake-page .sc-product tbody tr:last-child{border-bottom:0}
.stocktake-page .sc-product tbody td{display:flex;flex-direction:column;gap:4px;justify-content:center;align-items:stretch;min-width:0;width:100%;padding:9px 11px;border:1px solid #e8eef5;border-radius:10px;background:#fbfdff;text-align:right}
.stocktake-page .sc-product tbody td::before{content:attr(data-label);display:block;color:#6b7f91;font-size:.73rem;font-weight:700;line-height:1.65}
.stocktake-page .sc-product tbody td:first-child,.stocktake-page .sc-product tbody td:nth-child(4),.stocktake-page .sc-product tbody td:nth-child(5),.stocktake-page .sc-product tbody td:nth-child(6){grid-column:1/-1}
.stocktake-page .sc-product tbody td.sc-number{text-align:right}
.stocktake-page .sc-product .sc-actual{max-width:none;min-height:44px;margin:0}
.stocktake-page .sc-footer{grid-template-columns:minmax(0,1fr);padding:12px}
.stocktake-page .sc-footer .btn{justify-self:stretch;width:100%;min-height:43px}
}
[hidden]{display:none!important}
</style>
<link rel="stylesheet" href="{{ asset('css/stocktake-workspace.css') }}?v=20261030-2">
<div class="stocktake-page stocktake-workspace" dir="rtl" id="stocktakeApp" data-document-id="{{ $document?->id }}" data-product-id="{{ $document?->product_id }}">
 <header class="stocktake-page__header mb-3">
   <div class="stocktake-page__heading">
     <div class="stocktake-eyebrow">کنترل موجودی انبار مرکزی</div>
     <h4 class="mb-1">{{ $mode === 'edit' ? 'ویرایش شمارش محصول' : 'انبارگردانی کالاها' }}</h4>
     <p class="stocktake-page__intro mb-0">انتخاب محدوده، ثبت شمارش، بررسی و تأیید نهایی</p>
     @if($document)<span class="stocktake-document-tag">سند {{ $document->document_number }}</span>@endif
   </div>
   <a href="{{ route('stocktake.index') }}" class="btn btn-outline-secondary stocktake-page__back">بازگشت به انبارگردانی</a>
 </header>
 @if($errors->stock_count_finalize->any())
  <div class="alert alert-danger" id="stockCountFinalizeAlert">
   <div class="fw-bold mb-1">ثبت نهایی سند انجام نشد</div>
   <div>موجودی یا رزرو برخی تنوع‌ها با شرایط ثبت نهایی سازگار نیست. ردیف‌های مشخص‌شده را بررسی کنید.</div>
   @if($stockCountConflictCount)
    <ul class="mb-0 mt-2 small">
     @foreach($stockCountConflicts->take(5) as $conflict)
      <li>{{ $conflict['variant_name'] ?? 'تنوع نامشخص' }} — @if(($conflict['reason_code'] ?? '') === 'actual_below_reserved') موجودی واقعی {{ $conflict['actual_quantity'] ?? 0 }}، رزرو فعال {{ $conflict['reserved_quantity'] ?? 0 }} @else {{ $conflict['reason'] ?? 'موجودی یا رزرو پس از شروع شمارش تغییر کرده است.' }} @endif</li>
     @endforeach
     @if($stockCountConflictCount > 5)<li>و {{ $stockCountConflictCount - 5 }} مورد دیگر</li>@endif
    </ul>
   @endif
  </div>
 @elseif($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
 <form id="stocktakeForm" method="post" action="{{ $mode==='edit' ? route('stock-count-documents.update',$document) : route('stock-count-documents.store') }}">@csrf @if($mode==='edit') @method('PUT') @endif
  <div id="actualHiddenInputs"></div>
  <input type="hidden" name="document_date" value="{{ $document?->document_date?->format('Y-m-d') ?? now('Asia/Tehran')->format('Y-m-d') }}">
  @if($mode==='edit')<input type="hidden" name="expected_review_token" value="{{ app(\App\Services\StockCountDocumentService::class)->reviewToken($document) }}">@endif
  <section class="stocktake-selector stocktake-card mb-4" aria-labelledby="stocktakeScopeTitle">
    <header class="stocktake-selector__header">
      <span class="stocktake-selector__icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 8 9 5 9-5M3 8v9l9 5 9-5V8M12 13v9"/></svg></span>
      <div><h5 id="stocktakeScopeTitle">محدودهٔ شمارش را انتخاب کنید</h5><p>یک دسته را کامل بشمارید، یا فقط یک محصول را انتخاب کنید.</p></div>
      <span class="stocktake-selector__tag">۱ · انتخاب محدوده</span>
    </header>
    <div class="stocktake-selector__body">
      <div class="stocktake-selector__primary">
        <div class="stocktake-selector__fields">
          <label class="stocktake-field" for="category"><span>دسته‌بندی اصلی</span><select id="category" class="form-select" {{ $mode==='edit'?'disabled':'' }}><option value="">انتخاب دسته‌بندی اصلی</option>@foreach($rootCategories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach</select></label>
          <label class="stocktake-field" for="subcategory"><span>زیردسته <small>(اختیاری)</small></span><select id="subcategory" class="form-select" disabled><option value="">همه زیردسته‌ها</option></select></label>
        </div>
        @if($mode==='create')<button type="button" id="scShow" class="btn stocktake-category-button"><span>نمایش کالاهای دسته در همین صفحه</span><span aria-hidden="true">←</span></button>@endif
        <p class="stocktake-selector__hint">بدون انتخاب زیردسته، تمام کالاهای دسته و زیردسته‌هایش نمایش داده می‌شوند.</p>
      </div>
      <div class="stocktake-selector__alternative" data-stocktake-single>
        <div class="stocktake-selector__alternative-title"><span></span>یا شمارش یک محصول</div>
        <label class="stocktake-field" for="product"><span>انتخاب محصول</span><select id="product" name="product_id" class="form-select" {{ $mode==='edit'?'readonly':'' }} required>@if($document?->product)<option value="{{ $document->product->id }}" selected>{{ $document->product->name }}</option>@else<option value="">انتخاب محصول</option>@endif</select></label>
        <label class="stocktake-search" for="productSearch"><span aria-hidden="true">⌕</span><input id="productSearch" class="form-control" placeholder="جستجوی نام، کد، SKU یا بارکد" aria-label="جستجوی محصول" {{ $mode==='edit'?'disabled':'' }}></label>
      </div>
    </div>
  </section>
  @if($mode==='create')
  <div id="scRecovery" class="alert alert-info d-flex gap-2 align-items-center flex-wrap" hidden>
    <span>یک شمارش قبلی در این مرورگر قابل بازیابی است؛ بازیابی به‌تنهایی هیچ سندی را ذخیره یا نهایی نمی‌کند.</span>
    <button type="button" id="scRestore" class="btn btn-sm btn-primary">بازیابی شمارش قبلی</button>
    <button type="button" id="scDiscardRecovery" class="btn btn-sm btn-outline-secondary">کنارگذاشتن نسخه مرورگر</button>
  </div>
  <section class="stocktake-category" id="stocktakeCategoryApp" data-embedded="1" hidden
    data-user-id="{{ auth()->id() }}"
    data-rows-url="{{ route('stock-count-documents.category-variants') }}"
    data-subcategories-url="{{ route('stock-count-documents.subcategories') }}"
    data-bulk-store-url="{{ route('stock-count-documents.category-drafts.store') }}"
    data-bulk-finalize-url="{{ route('stock-count-documents.category-finalize') }}"
    data-store-url="{{ route('stock-count-documents.store') }}">
    <div class="sc-panel"><div class="sc-top mb-2">
      <div><h5 class="mb-1">شمارش کالاهای دسته انتخابی</h5><p class="sc-subtitle mb-0">کالاهای دسته یا زیردسته انتخابی همراه تنوع‌ها در همین فرم نمایش داده می‌شوند.</p></div>
      <button type="button" id="scClose" class="btn btn-sm btn-outline-secondary">بازگشت به شمارش یک محصول</button>
    </div>
    <div class="sc-warning mb-2">شمارش همه کالاها را وارد و <strong>یکجا ذخیره</strong> کنید؛ پس از بررسی جمع‌بندی، با «ثبت نهایی همه» یکجا تأیید کنید. ذخیره پیش‌نویس موجودی مرکزی را تغییر نمی‌دهد. مقدار خالی یعنی شمارش‌نشده و مانع ثبت نهایی است.</div>
    <div class="sc-live-search mb-3"><label for="scLiveSearch" class="form-label fw-bold">جستجوی لایو کالا و تنوع در همین دسته</label><input id="scLiveSearch" class="form-control" type="search" maxlength="80" autocomplete="off" placeholder="نام کالا، مدل، SKU یا کد تنوع..." aria-describedby="scSearchHint"><div id="scSearchHint" class="small text-muted mt-1">جستجو بعد از مکث کوتاه انجام می‌شود؛ شمارش‌های واردشده با تغییر جستجو حفظ می‌شوند. برای موجودی صفر، عدد ۰ را صریحاً وارد کنید.</div></div>
     <div id="scStatus" class="sc-status" role="status" aria-live="polite">برای نمایش کالاها دکمه کنار زیردسته‌بندی را بزنید.</div>
    <div id="scProducts"></div>
    <div id="scBulkToolbar" class="sc-bulk-toolbar" role="toolbar" aria-label="عملیات دسته‌ای شمارش" hidden>
      <div id="scToolbarStatus" class="sc-toolbar-status" role="status" aria-live="polite">ابتدا همه کالاهای دسته را دریافت کنید؛ سپس شمارش‌ها را یکجا ذخیره کنید.</div>
      <button type="button" id="scMore" class="btn btn-outline-primary" hidden>دریافت ادامه کالاها</button>
      <button type="button" id="scSaveAll" class="btn btn-success" disabled>ذخیره یکجای پیش‌نویس‌ها</button>
      <button type="button" id="scReview" class="btn btn-outline-secondary" disabled>بررسی جمع‌بندی</button>
      <button type="button" id="scFinalizeAll" class="btn btn-primary" disabled>ثبت نهایی همه</button>
    </div>
    <div class="sc-panel mt-3" id="scReviewPanel"><h6>جمع‌بندی شمارش و بررسی پیش‌نویس‌ها</h6><div id="scReviewSummary" class="sc-review-summary" aria-live="polite">پس از ذخیره یکجا، جمع‌بندی اینجا نمایش داده می‌شود.</div><ul id="scDraftList" class="mb-0" aria-live="polite"></ul></div>
    <p class="sc-subtitle">موجودی مرکزی، رزرو و صف جمع‌آوری بر اساس اطلاعات فعلی سیستم است، نه Snapshot ثابت.</p>
    </div>
  </section>
  @endif
  <div data-stocktake-single class="alert alert-warning py-2">پیش‌نویس با شمارش ناقص قابل ذخیره است؛ ثبت نهایی فقط پس از تعیین تکلیف همه تنوع‌ها مجاز است. اگر موجودی یک تنوع صفر است، عدد <strong>۰</strong> را صریحاً وارد کنید. مقدار خالی به معنی «شمارش‌نشده» است.</div>
  <div class="alert alert-info py-2 mb-3" id="stocktakeFlowHelp">
    <strong>موجودی در صف جمع‌آوری</strong> کنار هر تنوع نمایش داده می‌شود: در انتظار جمع‌آوری، تحویل انبار و در حال جمع‌آوری.
    <span class="fw-bold">اطلاع‌رسانی فقط‌خواندنی:</span> این عدد به موجودی موردانتظار یا اختلاف انبارگردانی اضافه نمی‌شود؛ برای شمارش فیزیکی، وضعیت واقعی کالاها را بررسی کنید.
    <a class="ms-2" href="{{ route('warehouse.stock-flow.index') }}" target="_blank" rel="noopener">مشاهده گزارش کامل موجودی در جریان</a>
  </div>
  <div data-stocktake-single class="card stocktake-card mb-3"><div class="card-body stocktake-tools"><input id="variantSearch" class="form-control variant-search" placeholder="جستجو در تنوع‌ها"><button type="button" class="btn btn-sm btn-outline-primary" data-tool="fillExpected">پرکردن با موجودی فعلی</button><button type="button" class="btn btn-sm btn-outline-danger" data-tool="zeroAll">صفرکردن همه</button><button type="button" class="btn btn-sm btn-outline-secondary" data-tool="clearAll">پاک‌کردن مقدارها</button><div class="btn-group btn-group-sm" role="group"><button type="button" class="btn btn-outline-secondary active" data-filter="all">نمایش همه</button><button type="button" class="btn btn-outline-secondary" data-filter="diff">فقط اختلاف‌دارها</button><button type="button" class="btn btn-outline-secondary" data-filter="uncounted">فقط شمارش‌نشده‌ها</button></div></div></div>
  <div data-stocktake-single class="card stocktake-card mb-3"><div class="card-body p-0 stocktake-table-wrap" id="variantScroller"><table class="table stocktake-table align-middle"><thead><tr><th class="col-variant">تنوع کالا</th><th class="col-central text-center">موجودی انبار مرکزی</th><th class="col-reserved text-center">رزرو فعال</th><th class="col-flow text-center">موجودی در صف جمع‌آوری</th><th class="col-actual text-center">موجودی واقعی</th><th class="col-diff text-center">اختلاف</th></tr></thead><tbody id="variantRows"><tr><td colspan="6" class="text-center text-muted py-4">محصول را انتخاب کنید.</td></tr></tbody></table><div id="loadState" class="text-center p-2 text-muted small"></div></div></div>
  <div data-stocktake-single class="card stocktake-card mb-3"><div class="card-body"><div class="summary-line" id="summary"></div><div class="summary-details" id="summaryDetails"></div></div></div>
  <div data-stocktake-single class="card stocktake-card mb-3"><div class="card-body"><label class="form-label fw-bold">توضیح کلی انبارگردانی</label><textarea name="description" class="form-control" rows="3" placeholder="مثلاً شمارش مجدد موجودی پس از جابه‌جایی قفسه‌ها">{{ old('description',$document?->description) }}</textarea></div></div>
  <div data-stocktake-single class="stocktake-actions"><div><a href="{{ route('stocktake.index') }}" class="btn btn-outline-secondary">بازگشت</a><button class="btn btn-primary" type="submit">ذخیره پیش‌نویس</button></div><div class="d-flex align-items-center gap-3 flex-wrap"><span class="small text-muted">ثبت نهایی فقط با شمارش یا تعیین تکلیف تمام تنوع‌ها؛ خالی‌ها خودکار صفر نمی‌شوند.</span>@if($mode==='edit')<button class="btn btn-success" id="applyBtn" formaction="{{ route('stock-count-documents.finalize',$document) }}" formmethod="post" disabled onclick="event.preventDefault();showApplyModal();">ثبت نهایی و اعمال موجودی</button>@endif</div></div>
 </form>
</div>
<script>
const app=document.getElementById('stocktakeApp'), docId=app.dataset.documentId||'', serverConflicts=@json($stockCountConflicts->values()), oldActuals=@json($oldActualQuantities), serverConflictsByVariant=Object.fromEntries(serverConflicts.filter(c=>c.variant_id).map(c=>[String(c.variant_id),c])), state={rows:[],page:1,hasMore:false,loading:false,actual:{},filter:'all',submitting:false};
const els={rows:document.getElementById('variantRows'),search:document.getElementById('variantSearch'),summary:document.getElementById('summary'),details:document.getElementById('summaryDetails'),hidden:document.getElementById('actualHiddenInputs'),apply:document.getElementById('applyBtn')};
function esc(s){return (s??'').toString().replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}
function normalizeDigits(v){return (v??'').toString().replace(/[۰-۹]/g,d=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g,d=>'٠١٢٣٤٥٦٧٨٩'.indexOf(d));}
function sanitizeQuantity(v){v=normalizeDigits(v).trim(); if(v==='')return ''; if(!/^\d+$/.test(v))return null; return String(parseInt(v,10));}
function actualValue(id){return state.actual[id] === undefined ? '' : state.actual[id];}
function calc(r){let a=actualValue(r.variant_id), server=serverConflictsByVariant[String(r.variant_id)]||null; if(a==='')return {diff:null,newAvailable:null,error:false,emptyReserve:r.active_reserved>0,server}; a=parseInt(a,10); return {diff:a-r.expected_physical,newAvailable:a-r.active_reserved,error:a<r.active_reserved,emptyReserve:false,server};}
function diffHtml(r){const c=calc(r); if(c.diff===null)return '<span class="diff-badge diff-empty">—</span><span class="d-block small text-muted mt-1">شمارش‌نشده</span>'; let cls=(c.error||c.server)?'diff-down':(c.diff>0?'diff-up':(c.diff<0?'diff-down':'diff-zero')); let val=c.diff>0?'+'+c.diff:c.diff; return `<span class="diff-badge ${cls}">${val}</span>${c.error?'<span class="reserve-error-text">کمتر از رزرو</span>':''}`;}
function filteredRows(){let q=els.search.value.trim().toLowerCase(); return state.rows.filter(r=>!q||`${r.name} ${r.sku||''} ${r.barcode||''}`.toLowerCase().includes(q)).filter(r=>{let c=calc(r), a=actualValue(r.variant_id); if(state.filter==='diff')return c.diff!==null&&c.diff!==0; if(state.filter==='uncounted')return a===''; return true;});}
function rowMessage(r,c){if(c.server){let refresh=['stock_changed','reservation_changed','variant_set_changed','warehouse_stock_changed'].includes(c.server.reason_code);return `<div class="stock-count-row-message">${esc(c.server.reason||'این ردیف نیاز به بررسی دارد.')}${refresh?'<br><a class="btn btn-sm btn-outline-danger stock-count-refresh" href="'+location.pathname+'">به‌روزرسانی اطلاعات موجودی و رزرو</a>':''}</div>`} if(c.emptyReserve)return '<div class="stock-count-row-message is-warning">این تنوع هنوز شمارش نشده و رزرو فعال دارد؛ پیش از ثبت نهایی عدد واقعی را وارد کنید.</div>'; if(c.error)return '<div class="stock-count-row-message">موجودی واقعی نمی‌تواند کمتر از رزرو فعال باشد.</div>'; return '';}
function flowHtml(r){let pending=Number(r.pending_collection)||0,received=Number(r.warehouse_received)||0,collecting=Number(r.collecting)||0,total=Number(r.queue_total)||0; return `<span class="stocktake-mobile-label">موجودی در صف جمع‌آوری</span><span class="flow-badge ${total>0?'has-flow':''}">${total}</span>${total>0?`<div class="flow-breakdown">انتظار: <b>${pending}</b> · تحویل انبار: <b>${received}</b> · جمع‌آوری: <b>${collecting}</b></div>`:''}`;}
function render(){let rows=filteredRows(); els.rows.innerHTML=rows.map(r=>{let a=actualValue(r.variant_id), c=calc(r), reserve=r.active_reserved>0?`<span class="reserved-badge has-reserve">رزرو: ${r.active_reserved}</span>`:`<span class="reserved-badge">0</span>`, hasErr=c.error||c.server; return `<tr class="${hasErr?'has-stock-count-error':''}" data-variant-id="${r.variant_id}"><td class="col-variant"><div class="variant-title">${esc(r.name)}</div><div class="variant-meta">کد/SKU: ${esc(r.sku||r.barcode||'—')}</div><span class="sales-badge ${r.sales_enabled?'is-on':''}">${r.sales_enabled?'فعال برای فروش':'غیرفعال برای فروش'}</span></td><td class="col-central text-center"><span class="stocktake-mobile-label">موجودی مرکزی</span><div class="central-qty" title="موجودی آزاد انبار مرکزی">${r.system_available}</div><span class="central-help">آزاد</span></td><td class="col-reserved text-center"><span class="stocktake-mobile-label">رزرو فعال</span>${reserve}</td><td class="col-flow text-center">${flowHtml(r)}</td><td class="col-actual"><span class="stocktake-mobile-label">موجودی واقعی</span><div class="stock-count-stepper ${hasErr?'has-error':''}"><button type="button" data-stock-decrease aria-label="کاهش">−</button><input class="actual-input" type="text" inputmode="numeric" autocomplete="off" placeholder="0" data-id="${r.variant_id}" value="${esc(a)}"><button type="button" data-stock-increase aria-label="افزایش">+</button></div>${rowMessage(r,c)}</td><td class="col-diff text-center"><span class="stocktake-mobile-label">اختلاف</span>${diffHtml(r)}</td></tr>`}).join('') || '<tr><td colspan="6" class="text-center text-muted py-4">ردیفی یافت نشد.</td></tr>'; summary();}
function summary(){let s={total:state.rows.length,counted:0,empty:0,diff:0,inc:0,dec:0,err:0,expected:0,actual:0}; state.rows.forEach(r=>{let a=actualValue(r.variant_id); s.expected+=r.expected_physical; if(a===''){s.empty++;return} a=parseInt(a,10); s.counted++; s.actual+=a; let d=a-r.expected_physical; if(d!==0)s.diff++; if(d>0)s.inc+=d; if(d<0)s.dec+=Math.abs(d); if(a<r.active_reserved)s.err++;}); serverConflicts.forEach(c=>{if(c.variant_id && state.rows.some(r=>String(r.variant_id)===String(c.variant_id)) && !state.rows.some(r=>String(r.variant_id)===String(c.variant_id)&&calc(r).error))s.err++;}); state.rows.forEach(r=>{let a=actualValue(r.variant_id); if(a===''&&r.active_reserved>0)s.err++;}); els.summary.innerHTML=[['کل',s.total],['شمارش‌شده',s.counted],['خالی',s.empty],['دارای اختلاف',s.diff],['افزایش',s.inc],['کاهش',s.dec]].map(x=>`<span class="summary-pill">${x[0]}: <b>${x[1]}</b></span>`).join('')+`<span class="summary-pill error-pill ${s.err>0?'has-errors':'no-errors'}" data-scroll-error>خطاها: <b>${s.err}</b></span>`; els.details.textContent=`جزئیات بیشتر: جمع مورد انتظار ${s.expected} | جمع موجودی واقعی شمارش‌شده ${s.actual}`; if(els.apply)els.apply.disabled=!(s.empty===0&&s.err===0&&s.total>0&&!state.hasMore&&!state.loading&&!state.submitting);}
async function load(reset=false){let pid=document.getElementById('product').value;if(!pid)return; if(reset){state.rows=[];state.page=1;state.hasMore=false} if(state.loading)return; state.loading=true; document.getElementById('loadState').textContent='در حال دریافت...'; let u=`/stock-count-documents/products/${pid}/variants?page=${state.page}&limit=100${docId?'&document_id='+docId:''}`; let j=await (await fetch(u,{headers:{Accept:'application/json'}})).json(); j.data.forEach(r=>{state.rows.push(r); if(state.actual[r.variant_id]===undefined)state.actual[r.variant_id]=oldActuals[r.variant_id]!==undefined?String(oldActuals[r.variant_id]):(oldActuals[String(r.variant_id)]!==undefined?String(oldActuals[String(r.variant_id)]):(r.actual_physical===null?'':String(r.actual_physical??'')));}); state.hasMore=j.meta.has_more; state.page++; state.loading=false; document.getElementById('loadState').textContent=state.hasMore?'برای دریافت ادامه اسکرول کنید.':'همه تنوع‌های دریافت‌شده نمایش داده شدند.'; render();}
async function ensureAllLoaded(){while(state.hasMore&&!state.loading)await load(false);}
function setActual(id,val){state.actual[id]=val; let input=els.rows.querySelector(`.actual-input[data-id="${id}"]`); if(input)input.value=val; render();}
function buildHiddenInputs(){els.hidden.innerHTML=''; Object.entries(state.actual).forEach(([id,val])=>{let i=document.createElement('input'); i.type='hidden'; i.name=`actual_quantities[${id}]`; i.value=val; els.hidden.appendChild(i);});}
els.rows.addEventListener('click',e=>{let inc=e.target.closest('[data-stock-increase]'), dec=e.target.closest('[data-stock-decrease]'); if(!inc&&!dec)return; let input=e.target.closest('tr').querySelector('.actual-input'), id=input.dataset.id, cur=actualValue(id); let next=inc?(cur===''?1:parseInt(cur,10)+1):(cur===''?0:Math.max(0,parseInt(cur,10)-1)); setActual(id,String(next));});
els.rows.addEventListener('input',e=>{if(!e.target.matches('.actual-input'))return; let id=e.target.dataset.id, clean=sanitizeQuantity(e.target.value); if(clean===null){e.target.value=actualValue(id);return} state.actual[id]=clean; e.target.value=clean; render(); requestAnimationFrame(()=>{let next=els.rows.querySelector(`.actual-input[data-id="${id}"]`); if(next){next.focus(); next.setSelectionRange(next.value.length,next.value.length);}});});
els.rows.addEventListener('wheel',e=>{if(e.target.matches('.actual-input'))e.target.blur();},{passive:true});
document.addEventListener('focusin',e=>{if(e.target.matches('.actual-input'))e.target.select()});document.addEventListener('keydown',e=>{if(e.key==='Enter'&&e.target.matches('.actual-input')){e.preventDefault();let a=[...document.querySelectorAll('.actual-input')],i=a.indexOf(e.target);if(a[i+1])a[i+1].focus();}});
els.search.addEventListener('input',render);document.querySelectorAll('[data-filter]').forEach(b=>b.addEventListener('click',()=>{state.filter=b.dataset.filter;document.querySelectorAll('[data-filter]').forEach(x=>x.classList.toggle('active',x===b));render();}));
document.querySelectorAll('[data-tool]').forEach(b=>b.addEventListener('click',async()=>{await ensureAllLoaded(); if(b.dataset.tool==='zeroAll'&&!confirm('موجودی واقعی تمام تنوع‌های این محصول صفر شود؟'))return; if(b.dataset.tool==='clearAll'&&!confirm('مقدارهای واردشده پاک شوند؟'))return; state.rows.forEach(r=>{if(b.dataset.tool==='fillExpected')state.actual[r.variant_id]=String(r.expected_physical); if(b.dataset.tool==='zeroAll')state.actual[r.variant_id]='0'; if(b.dataset.tool==='clearAll')state.actual[r.variant_id]='';}); render();}));
document.getElementById('variantScroller').addEventListener('scroll',e=>{if(state.hasMore&&e.target.scrollTop+e.target.clientHeight>e.target.scrollHeight-80)load(false)});
document.getElementById('category').onchange=async e=>{document.getElementById('subcategory').innerHTML='<option value="">همه زیردسته‌ها</option>';document.getElementById('product').innerHTML='<option value="">انتخاب محصول</option>';state.rows=[];state.actual={};render(); if(!e.target.value)return; let j=await (await fetch(`/stock-count-documents/subcategories?category_id=${e.target.value}`)).json(); j.forEach(c=>document.getElementById('subcategory').insertAdjacentHTML('beforeend',`<option value="${c.id}">${esc(c.name)}</option>`)); document.getElementById('subcategory').disabled=false; searchProducts();};
document.getElementById('subcategory').onchange=()=>{document.getElementById('product').innerHTML='<option value="">انتخاب محصول</option>';state.rows=[];state.actual={};render();searchProducts()}; document.getElementById('productSearch').oninput=()=>setTimeout(searchProducts,250);
async function searchProducts(){let c=document.getElementById('category').value,sc=document.getElementById('subcategory').value,q=document.getElementById('productSearch').value; if(!c&&!q)return; let j=await (await fetch(`/stock-count-documents/products/search?category_id=${c}&subcategory_id=${sc}&q=${encodeURIComponent(q)}`)).json(); let p=document.getElementById('product'); p.innerHTML='<option value="">انتخاب محصول</option>'; j.forEach(x=>p.insertAdjacentHTML('beforeend',`<option value="${x.id}">${esc(x.name)} - ${esc(x.sku||x.code||'')}</option>`));}
document.getElementById('product').onchange=()=>{state.rows=[];state.actual={};load(true)};document.getElementById('stocktakeForm').addEventListener('submit',()=>{buildHiddenInputs();state.submitting=true;summary();});document.addEventListener('click',e=>{if(e.target.closest('[data-scroll-error]'))scrollToFirstError();});function scrollToFirstError(){let el=document.querySelector('.has-stock-count-error'); if(!el)return; el.scrollIntoView({behavior:'smooth',block:'center'}); setTimeout(()=>el.querySelector('.actual-input')?.focus(),350);}function showApplyModal(){summary(); if(els.apply?.disabled)return; if(confirm('تمام تنوع‌ها تعیین تکلیف شده‌اند. پس از ثبت نهایی، موجودی انبار مرکزی براساس این شمارش اصلاح می‌شود و سند دیگر قابل ویرایش نخواهد بود. تأیید نهایی و اعمال موجودی؟')){buildHiddenInputs();state.submitting=true;let f=document.getElementById('stocktakeForm'), apply=document.getElementById('applyBtn'); if(apply){apply.disabled=true;apply.innerHTML='در حال ثبت...';} if(apply&&apply.formAction)f.action=apply.formAction; f.querySelector('input[name=_method]').value='PATCH'; f.submit();}}
if(document.getElementById('product').value)load(true).then(()=>{if(serverConflicts.length)scrollToFirstError();}); else summary();
</script>
@if($mode==='create')<script>{!! file_get_contents(public_path('js/stocktake-category-workspace.js')) !!}</script>@endif
@endsection
