<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>پیش‌نمایش چاپ موجودی کالاهای انتخاب‌شده | آریا گستر</title>
    <style>
        :root{color-scheme:light;font-family:Tahoma,"Segoe UI",Arial,sans-serif;color:#1c3445}
        *,*::before,*::after{box-sizing:border-box}
        html{background:#f1f5f9}
        body{margin:0;line-height:1.8;font-size:12px}
        button{font-family:inherit;cursor:pointer}
        button:disabled{cursor:not-allowed;opacity:.5}
        .toolbar{position:sticky;top:0;z-index:5;display:flex;align-items:center;justify-content:space-between;gap:14px;background:#fff;border-bottom:1px solid #dce7ef;padding:13px max(18px,calc((100vw - 1230px)/2))}
        .toolbar-title{display:flex;flex-direction:column;line-height:1.6}
        .toolbar-title strong{font-size:15px;color:#17445d}
        .toolbar-title span{font-size:11px;color:#64748b}
        .tools{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
        .btn{border:1px solid #cddbe5;border-radius:9px;background:#fff;color:#28536e;padding:8px 15px;font-size:12px;font-weight:700;text-decoration:none}
        .btn-primary{background:#167055;color:#fff;border-color:#167055}
        .btn:hover:not(:disabled){filter:brightness(.96)}
        .paper{background:white;border:1px solid #e1eaf0;box-shadow:0 10px 35px rgba(24,55,80,.07);border-radius:12px;margin:22px auto;padding:25px 29px;max-width:1230px}
        .header{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;border-bottom:2px solid #d6e9e7;padding-bottom:15px;margin-bottom:18px}
        .eyebrow{font-size:11px;color:#19816d;font-weight:800}
        h1{font-size:20px;line-height:1.6;color:#173d56;margin:4px 0}
        .sub{font-size:11px;color:#64748b}
        .print-meta{display:grid;gap:3px;text-align:left;white-space:nowrap;color:#617487;font-size:11px}
        .summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:16px 0 20px}
        .summary>div{border:1px solid #e1e9ef;background:#f8fbfc;border-radius:9px;padding:11px 14px}
        .summary small{display:block;color:#657989;font-size:10px}
        .summary strong{display:block;font-size:17px;color:#183e57;font-variant-numeric:tabular-nums}
        .status{padding:14px;border:1px solid #d1e7eb;border-radius:10px;background:#f1f9fa;margin-bottom:12px;color:#28566d}
        .status.error{color:#991b1b;background:#fff2f2;border-color:#f3c7c7}
        .progress{width:100%;height:10px;margin:9px 0 3px;accent-color:#187d62}
        .table-wrap{width:100%;overflow-x:auto}
        table{border-collapse:collapse;width:100%;table-layout:fixed;font-size:10px;color:#263e4f}
        col.col-no{width:4%}col.col-name{width:19%}col.col-code{width:12%}col.col-variant{width:19%}
        col.col-detail{width:15%}col.col-stock{width:7%}col.col-reserved{width:7%}col.col-price{width:12%}col.col-sell{width:5%}
        th,td{border:1px solid #dae4ea;padding:8px 6px;vertical-align:middle;text-align:right;overflow-wrap:anywhere}
        thead th{background:#eaf3f4;color:#244b5e;font-weight:800;white-space:normal}
        tbody tr:nth-child(even){background:#f8fafc}
        .product-name{font-weight:800;color:#183e57}
        .code{direction:ltr;unicode-bidi:plaintext;text-align:right;font-family:Tahoma,Arial,sans-serif}
        .numeric{text-align:center;direction:ltr;unicode-bidi:plaintext;font-variant-numeric:tabular-nums}
        .price{text-align:left;direction:ltr;unicode-bidi:plaintext;font-weight:700}
        .muted{color:#64748b}
        .footer-note{font-size:10px;color:#6b7c8c;margin-top:13px;display:flex;align-items:center;justify-content:space-between;gap:12px}
        [hidden]{display:none!important}
        @media(max-width:700px){
            .toolbar{padding:10px 12px;align-items:flex-start;flex-direction:column}
            .paper{margin:10px;padding:14px 11px}
            h1{font-size:16px}
            .summary{gap:5px}
            .summary>div{padding:8px}
            .summary strong{font-size:13px}
            table{min-width:970px}
        }
        @page{size:A4 landscape;margin:10mm}
        @media print{
            html,body{background:#fff!important;margin:0!important;font-size:10px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
            .toolbar,.status,.progress,.no-print{display:none!important}
            .paper{padding:0!important;margin:0!important;max-width:none;border:0;box-shadow:none;border-radius:0}
            .header{margin:0 0 10px;padding-bottom:10px}
            h1{font-size:15px}
            .summary{margin:8px 0 12px}
            .summary>div{padding:5px 8px}
            .summary strong{font-size:12px}
            .table-wrap{overflow:visible}
            table{width:100%;font-size:8px;table-layout:fixed}
            th,td{padding:4px 5px;line-height:1.5;border-color:#c8d2d9}
            thead{display:table-header-group}
            tbody tr{break-inside:avoid;page-break-inside:avoid}
            .footer-note{font-size:8px}
        }
    </style>
</head>
<body>
    <div class="toolbar no-print">
        <div class="toolbar-title"><strong>آریا گستر | پیش‌نمایش خروجی کالاها</strong><span>برای ذخیره فایل، از پنجره چاپ گزینه Save as PDF را انتخاب کنید.</span></div>
        <div class="tools">
            <button class="btn btn-primary" type="button" id="printNow" disabled>چاپ / ذخیره PDF</button>
            <button class="btn" type="button" id="cancelPrintLoad">توقف بارگیری</button>
            <button class="btn" type="button" id="closePrint">بستن صفحه</button>
        </div>
    </div>
    <main class="paper" id="stockPrintApp"
        data-product-ids='@json($productIds)'
        data-start-url="{{ route('products.selected-stock-export.start') }}"
        data-batch-url="{{ route('products.selected-stock-export.batch') }}"
        data-max-rows="{{ $maxPrintableRows }}"
        data-error="{{ $errorMessage ?? '' }}">
        <header class="header">
            <div>
                <div class="eyebrow">نرم‌افزار داخلی آریا گستر · گزارش کالا</div>
                <h1>ریز موجودی کالاها و تنوع‌های موجود</h1>
                <div class="sub">هر ردیف متعلق به یک تنوع فعال و موجود است؛ کالاهای ساده دارای موجودی نیز درج می‌شوند.</div>
            </div>
            <div class="print-meta">
                <span>تاریخ تهیه: {{ now()->format('Y/m/d H:i') }}</span>
                <span>تعداد کالاهای انتخاب‌شده: {{ count($productIds) }}</span>
                <span>واحد قیمت: ریال</span>
            </div>
        </header>
        <div class="summary">
            <div><small>کالاهای انتخابی</small><strong>{{ count($productIds) }}</strong></div>
            <div><small>ردیف‌های موجود</small><strong id="printTotalRows">—</strong></div>
            <div><small>ردیف‌های آماده چاپ</small><strong id="printLoadedRows">۰</strong></div>
        </div>
        <div class="status" id="printStatus" role="status" aria-live="polite">در حال دریافت اقلام و بررسی موجودی...</div>
        <progress class="progress" id="printProgress" max="100" value="0"></progress>
        <div class="table-wrap">
            <table aria-label="ریز موجودی تنوع‌های انتخاب‌شده">
                <colgroup>
                    <col class="col-no"><col class="col-name"><col class="col-code"><col class="col-variant">
                    <col class="col-detail"><col class="col-stock"><col class="col-reserved">
                    <col class="col-price"><col class="col-sell">
                </colgroup>
                <thead><tr>
                    <th>ردیف</th><th>نام کالا</th><th>کد کالا / SKU</th><th>نام و کد تنوع</th>
                    <th>مدل / رنگ / طرح</th><th>موجودی</th><th>رزرو</th><th>قیمت فروش (ریال)</th><th>فروش</th>
                </tr></thead>
                <tbody id="printTableBody"></tbody>
            </table>
        </div>
        <div class="footer-note">
            <span>گزارش بر اساس موجودی هنگام دریافت ردیف‌ها تهیه شده است.</span>
            <span>صرفاً جهت بررسی و چاپ؛ بدون تغییر موجودی یا قیمت کالاها</span>
        </div>
    </main>
    <script>{!! file_get_contents(public_path('js/product-stock-print.js')) !!}</script>
</body>
</html>
