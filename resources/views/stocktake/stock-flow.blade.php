<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>موجودی در جریان | انبارگردانی آریا گستر</title>
  <style>
    @font-face{font-family:Vazirmatn;src:url("{{ asset('fonts/vazirmatn/Vazirmatn-Regular.woff2') }}") format("woff2");font-weight:400;font-display:swap}
    @font-face{font-family:Vazirmatn;src:url("{{ asset('fonts/vazirmatn/Vazirmatn-Bold.woff2') }}") format("woff2");font-weight:700 900;font-display:swap}
    *,*::before,*::after{box-sizing:border-box}
    body{font-family:Vazirmatn,Tahoma,Arial,sans-serif;background:#f1f5f9;color:#19374b;margin:0;font-size:12px}
    button,input{font:inherit}
    .toolbar{background:white;padding:12px max(20px,calc((100vw - 1180px)/2));display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid #dbe5ec;flex-wrap:wrap}
    .title strong{font-size:15px;display:block}.title span{color:#6b7c8c;font-size:11px}
    .buttons{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    .btn{display:inline-flex;justify-content:center;align-items:center;padding:8px 13px;border:1px solid #cbdae4;border-radius:9px;background:white;color:#204866;text-decoration:none;cursor:pointer;font-weight:700}
    .btn.primary{background:#15674f;border-color:#15674f;color:#fff}
    .btn:disabled{opacity:.5;cursor:not-allowed}
    main{max-width:1180px;margin:18px auto;padding:20px 24px;background:#fff;border:1px solid #e0e8ee;border-radius:13px;box-shadow:0 5px 22px #2438490b}
    h1{font-size:19px;margin:0 0 5px}p{margin:5px 0 12px}.hint{font-size:11px;color:#607789;line-height:2}
    .warning{border:1px solid #f0dfb1;border-radius:10px;background:#fff9ec;padding:11px 13px;margin:12px 0;color:#785c28;line-height:2}
    .filters{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin:15px 0 18px}
    .filters label{display:flex;flex-direction:column;gap:5px;font-weight:700;flex:1;min-width:200px}
    .filters input[type=search]{padding:9px;border:1px solid #cedce6;border-radius:9px;min-height:39px}
    .filters .toggle{display:flex;flex-direction:row;flex:0 1 auto;align-items:center;min-width:150px}
    .message{border:1px solid #d8e8f0;background:#f3fafb;padding:10px 12px;margin-bottom:12px;border-radius:9px;color:#25506a;line-height:2}
    .message.error{background:#fff1f1;color:#9f2424;border-color:#f2c7c7}
    .table-scroll{overflow-x:auto}
    table{border-collapse:collapse;width:100%;table-layout:fixed;font-size:11px}
    th,td{border:1px solid #dbe5ec;padding:7px 6px;text-align:right;vertical-align:middle;overflow-wrap:anywhere}
    th{background:#e9f2f6;color:#244b65;font-size:10px}
    tbody tr:nth-child(even){background:#fafcfd}
    .item{font-weight:700}.small{font-size:10px;color:#718092}
    .num{text-align:center;direction:ltr;font-variant-numeric:tabular-nums}
    .queue{font-weight:700;color:#af6812}.positive{color:#16694f;font-weight:700}
    col.n{width:4%}col.name{width:23%}col.variant{width:21%}col.stock{width:9%}
    col.reserve{width:9%}col.stage{width:8%}col.total{width:10%}
    .footer{margin-top:12px;display:flex;gap:15px;justify-content:space-between;flex-wrap:wrap;font-size:10px;color:#687d8f}
    [hidden]{display:none!important}
    @media(max-width:750px){.toolbar{padding:10px}.toolbar .buttons{width:100%}main{margin:9px;padding:12px}.table-scroll table{min-width:900px}.filters label{min-width:100%}}
    @page{size:A4 landscape;margin:9mm}
    @media print{
      body{background:white;-webkit-print-color-adjust:exact;print-color-adjust:exact}
      .toolbar,.filters,.message,.no-print{display:none!important}
      main{max-width:none;margin:0;padding:0;border:0;box-shadow:none}
      h1{font-size:14px}
      .warning{padding:4px 8px;font-size:9px;margin:5px 0}
      .table-scroll{overflow:visible}
      table{font-size:8px}
      th,td{padding:4px}
      thead{display:table-header-group}
      tr{break-inside:avoid;page-break-inside:avoid}
      .footer{font-size:8px}
    }
  </style>
</head>
<body>
  <div class="toolbar no-print">
    <div class="title"><strong>انبارگردانی | موجودی در جریان</strong><span>آریا گستر · گزارش فقط‌خواندنی</span></div>
    <div class="buttons">
      <a class="btn" href="{{ route('stocktake.index') }}">بازگشت به انبارگردانی</a>
      @if(!$printMode)
        <a class="btn primary" href="{{ route('warehouse.stock-flow.index', ['q'=>$q, 'only_in_flow'=>$onlyInFlow?1:0,'print'=>1]) }}" target="_blank" rel="noopener">چاپ / ذخیره PDF</a>
      @else
        <button class="btn primary" id="stockFlowPrint" type="button" disabled>چاپ / ذخیره PDF</button>
      @endif
      <button class="btn" id="stockFlowCancel" type="button" hidden>توقف دریافت</button>
    </div>
  </div>
  <main id="stockFlowApp"
      data-rows-url="{{ route('warehouse.stock-flow.rows') }}"
      data-q="{{ $q }}"
      data-only-in-flow="{{ $onlyInFlow ? '1':'0' }}"
      data-print="{{ $printMode ? '1':'0' }}">
    <header>
      <h1>گزارش موجودی در جریان انبار مرکزی</h1>
      <p class="hint">ثبت گزارش: {{ $evaluatedAt }} | اعداد بر اساس وضعیت فعلی سیستم‌اند، نه یک Snapshot ثابت.</p>
    </header>
    <div class="warning">
      موجودی آزاد، رزرو رسمی پیش‌فاکتور و سه مرحله صف جمع‌آوری **اعداد جداگانه‌اند**. جمع ستون‌ها موجودی فیزیکی قطعی نیست؛ وضعیت برداشت واقعی، رزرو موقت و قطعات بسته‌های خودکار باید هنگام شمارش جدا کنترل شود. فاکتورهای آماده ارسال و ارسال‌شده در صف جمع‌آوری این گزارش لحاظ نمی‌شوند.
    </div>
    @unless($printMode)
    <form class="filters" method="get" action="{{ route('warehouse.stock-flow.index') }}">
      <label>جستجوی نام کالا یا تنوع <input name="q" type="search" maxlength="80" value="{{ $q }}" placeholder="نام کالا، مدل یا کد تنوع"></label>
      <label class="toggle"><input type="checkbox" name="only_in_flow" value="1" @checked($onlyInFlow)> فقط اقلام در جریان</label>
      <button class="btn primary" type="submit">نمایش گزارش</button>
    </form>
    @endunless
    <div id="stockFlowMessage" class="message" role="status" aria-live="polite">در حال دریافت اقلام موجودی و صف جمع‌آوری...</div>
    <div class="table-scroll">
      <table aria-label="موجودی در جریان به تفکیک تنوع">
        <colgroup>
          <col class="n"><col class="name"><col class="variant"><col class="stock"><col class="reserve">
          <col class="stage"><col class="stage"><col class="stage"><col class="total">
        </colgroup>
        <thead><tr>
          <th>ردیف</th><th>نام کالا</th><th>تنوع / مدل / رنگ</th><th>موجودی آزاد</th>
          <th>رزرو رسمی</th><th>صف جمع‌آوری</th><th>تحویل انبار</th>
          <th>در حال جمع‌آوری</th><th>جمع صف</th>
        </tr></thead>
        <tbody id="stockFlowRows"></tbody>
      </table>
    </div>
    <div class="no-print" style="text-align:center;margin-top:12px">
      <button class="btn" id="stockFlowMore" type="button" hidden>نمایش ۱۰۰ قلم بعدی</button>
    </div>
    <div class="footer"><span id="stockFlowLoaded">ردیف‌های دریافت‌شده: ۰</span><span>صرفاً گزارش؛ هیچ تغییر موجودی یا رزروی انجام نمی‌شود.</span></div>
  </main>
  <script>{!! file_get_contents(public_path('js/warehouse-stock-flow.js')) !!}</script>
</body>
</html>
