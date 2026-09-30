<!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>محصولات پیشنهادی آریا گستر</title><link rel="stylesheet" href="{{ asset('css/Vazirmatn.css') }}"><link rel="stylesheet" href="{{ asset('css/product-export-builder.css') }}?v=3"></head>
<body class="builder-print-page"><div class="print-toolbar"><strong>لیست محصولات آمادهٔ ارسال</strong><button type="button" onclick="window.print()">چاپ / ذخیره PDF</button></div>
<main class="customer-sheet">@include('product-exports.partials.builder-sheet')</main>
<script>window.addEventListener('load', () => window.setTimeout(() => window.print(), 300));</script>
</body></html>
