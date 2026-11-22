<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>برگشت از خرید {{ $document->document_number }}</title><link rel="stylesheet" href="{{ asset('css/purchase-returns.css') }}?v={{ substr(hash_file('sha256', public_path('css/purchase-returns.css')), 0, 12) }}">
<style>
    /* The project does not ship licensed IranSans files. Use one when installed, otherwise its bundled Persian fallback. */
    @font-face {
        font-family: PurchaseReturnIranSans;
        src: local('IRANSans'), local('IRANSansWeb')
            @if (is_file(public_path('fonts/iransans/IRANSans.woff2')))
                , url("{{ asset('fonts/iransans/IRANSans.woff2') }}") format('woff2')
            @endif;
        font-weight: 400;
    }
    @font-face {
        font-family: PurchaseReturnVazirmatn;
        src: url("{{ asset('fonts/vazirmatn/Vazirmatn-Regular.woff2') }}") format('woff2');
    }
</style></head><body class="pr-print" data-pr-print-document>
@php
$fa = fn($value) => strtr((string)$value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
$money = fn($value) => $fa(number_format((int)$value)).' ریال';
@endphp

<h1>آریا گستر · برگشت از خرید</h1>@if($document->trashed())<p>سند ابطال‌شده · موجودی به انبار مبدأ بازگردانده شده و اثر مالی برگشت معکوس شده است</p>@endif
@if($document->trashed() && $document->delete_reason)<p>دلیل ابطال: {{ $document->delete_reason }}</p>@endif
<div class="pr-print-meta"><span>شماره: {{ $fa($document->document_number) }}</span><span>تاریخ: {{ $fa(\App\Support\JalaliDate::date($document->returned_at)) }}</span><span>تأمین‌کننده: {{ $document->supplier_name }}</span><span>خرید مرجع: {{ $fa($document->purchase_id) }}</span><span>انبار مبدأ: {{ $document->warehouse?->name ?: 'انبار #'.$document->warehouse_id }}</span></div>
<table class="pr-table"><thead><tr><th>ردیف</th><th>کالا / تنوع</th><th>تعداد</th><th>قیمت خرید · ریال</th><th>جمع · ریال</th></tr></thead><tbody>@foreach($document->items as $item)<tr><td>{{ $fa($loop->iteration) }}</td><td>{{ $item->product_name }}<small>{{ $item->variant_name }}</small></td><td>{{ $fa($item->quantity) }}</td><td>{{ $fa(number_format($item->buy_price)) }}</td><td>{{ $fa(number_format($item->line_total)) }}</td></tr>@endforeach</tbody><tfoot><tr><td colspan="2">جمع</td><td>{{ $fa($document->total_quantity) }}</td><td colspan="2">{{ $money($document->total_amount) }}</td></tr></tfoot></table><p>{{ $document->note }}</p><footer>تحویل‌دهنده: ................................　 تحویل‌گیرنده: ................................</footer></body></html>
