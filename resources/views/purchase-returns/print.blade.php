<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>برگشت از خرید {{ $document->document_number }}</title><link rel="stylesheet" href="{{ asset('css/purchase-returns.css') }}?v=1"></head><body class="pr-print">
@php
$fa = fn($value) => strtr((string)$value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
$money = fn($value) => $fa(number_format((int)$value)).' ریال';
@endphp

<button onclick="window.print()" class="pr-print-button">چاپ / ذخیره PDF</button><h1>آریا گستر · برگشت از خرید</h1>@if($document->trashed())<p>سند حذف‌شده · بدون اثر فعلی بر موجودی</p>@endif
<div class="pr-print-meta"><span>شماره: {{ $fa($document->document_number) }}</span><span>تاریخ: {{ $fa(\App\Support\JalaliDate::date($document->returned_at)) }}</span><span>تأمین‌کننده: {{ $document->supplier_name }}</span><span>خرید مرجع: {{ $fa($document->purchase_id) }}</span><span>انبار: مرکزی</span></div>
<table class="pr-table"><thead><tr><th>ردیف</th><th>کالا / تنوع</th><th>تعداد</th><th>قیمت خرید · ریال</th><th>جمع · ریال</th></tr></thead><tbody>@foreach($document->items as $item)<tr><td>{{ $fa($loop->iteration) }}</td><td>{{ $item->product_name }}<small>{{ $item->variant_name }}</small></td><td>{{ $fa($item->quantity) }}</td><td>{{ $fa(number_format($item->buy_price)) }}</td><td>{{ $fa(number_format($item->line_total)) }}</td></tr>@endforeach</tbody><tfoot><tr><td colspan="2">جمع</td><td>{{ $fa($document->total_quantity) }}</td><td colspan="2">{{ $money($document->total_amount) }}</td></tr></tfoot></table><p>{{ $document->note }}</p><footer>تحویل‌دهنده: ................................　 تحویل‌گیرنده: ................................</footer></body></html>
