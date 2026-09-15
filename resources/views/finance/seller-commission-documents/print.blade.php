@php
    use App\Support\Currency;
    use App\Support\JalaliDate;

    $statusLabels = ['draft' => 'پیش‌نویس', 'confirmed' => 'تأیید‌شده', 'finalized' => 'نهایی‌شده'];
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>سند پورسانت {{ $document->document_number }}</title>
    <style>
        @page { size: A4; margin: 14mm 12mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Tahoma, Arial, sans-serif; font-size: 11px; color: #222; direction: rtl; line-height: 1.7; }
        .header { text-align: center; border-bottom: 2px solid #222; padding-bottom: 8px; margin-bottom: 12px; }
        .header h1 { font-size: 17px; }
        .header .sub { font-size: 11px; color: #666; }
        .info { display: flex; flex-wrap: wrap; border: 1px solid #ddd; border-radius: 4px; padding: 8px 10px; margin-bottom: 12px; }
        .info div { flex: 1 1 32%; padding: 2px 4px; }
        .info .lbl { font-size: 9px; color: #888; display: block; }
        .info .val { font-weight: bold; }
        .cards { display: flex; gap: 6px; margin-bottom: 12px; }
        .cards div { flex: 1; border: 1px solid #ddd; border-radius: 4px; padding: 6px; text-align: center; }
        .cards .lbl { font-size: 8px; color: #888; display: block; }
        .cards .val { font-size: 12px; font-weight: bold; }
        .net { border: 2px solid #1a7f37 !important; background: #f2fbf4; }
        h2 { font-size: 12px; margin: 10px 0 5px; border-right: 3px solid #222; padding-right: 7px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 10px; }
        th { background: #f2f2f2; border: 1px solid #ccc; padding: 4px 5px; text-align: right; }
        td { border: 1px solid #ddd; padding: 3px 5px; }
        tfoot td { background: #fafafa; font-weight: bold; }
        .red { color: #c0392b; } .green { color: #1a7f37; }
        .sig { display: flex; justify-content: space-around; margin-top: 34px; }
        .sig div { width: 28%; text-align: center; }
        .sig span { display: block; border-top: 1px solid #999; margin-top: 38px; padding-top: 4px; font-size: 10px; color: #555; }
        .foot { text-align: center; font-size: 8px; color: #aaa; margin-top: 16px; border-top: 1px solid #eee; padding-top: 5px; }
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
<button onclick="window.print()" class="no-print"
        style="position:fixed;top:8px;left:8px;padding:7px 15px;background:#0d6efd;color:#fff;border:0;border-radius:4px;cursor:pointer;font-family:Tahoma;">چاپ</button>

<div class="header">
    <h1>شرکت آریا گستر</h1>
    <div class="sub">سند پورسانت فروش</div>
</div>

<div class="info">
    <div><span class="lbl">شماره سند</span><span class="val">{{ $document->document_number }}</span></div>
    <div><span class="lbl">فروشنده</span><span class="val">{{ $document->seller?->name ?? '—' }}</span></div>
    <div><span class="lbl">وضعیت</span><span class="val">{{ $statusLabels[$document->status] ?? $document->status }}</span></div>
    <div><span class="lbl">دوره</span><span class="val">{{ JalaliDate::date($document->period_from) }} تا {{ JalaliDate::date($document->period_to) }}</span></div>
    <div><span class="lbl">تاریخ صدور</span><span class="val">{{ JalaliDate::date($document->created_at) }}</span></div>
    <div><span class="lbl">صادرکننده</span><span class="val">{{ $document->creator?->name ?? '—' }}</span></div>
</div>

<div class="cards">
    <div><span class="lbl">تعداد فاکتور</span><span class="val">{{ number_format($document->invoice_count) }}</span></div>
    <div><span class="lbl">جمع فروش</span><span class="val">{{ number_format($document->total_sales_amount) }}</span></div>
    <div><span class="lbl">پورسانت</span><span class="val">{{ number_format($document->total_commission_amount) }}</span></div>
    <div class="net"><span class="lbl">پورسانت خالص</span><span class="val green">{{ number_format($document->net_commission_amount) }}</span></div>
</div>

<h2>ریز فاکتورها</h2>
<table>
    <thead>
        <tr><th>#</th><th>شماره فاکتور</th><th>تاریخ</th><th>مشتری</th><th>کالا</th><th>مبلغ فروش</th><th>نرخ</th><th>پورسانت</th></tr>
    </thead>
    <tbody>
    @foreach($document->items as $index => $item)
        <tr>
            <td>{{ $index + 1 }}</td>
            <td>{{ $item->invoice_number_snapshot }}</td>
            <td>{{ JalaliDate::date($item->invoice_date_snapshot) }}</td>
            <td>{{ $item->customer_name_snapshot }}</td>
            <td>{{ $item->product_name_snapshot }}@if($item->variant_name_snapshot) ({{ $item->variant_name_snapshot }})@endif</td>
            <td>{{ number_format($item->invoice_total_snapshot) }}</td>
            <td>{{ $item->rate_snapshot }}٪</td>
            <td class="{{ $item->missing_rate ? 'red' : '' }}">
                {{ $item->missing_rate ? 'بدون نرخ' : number_format($item->commission_amount) }}
            </td>
        </tr>
    @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td colspan="5">جمع کل</td>
            <td>{{ number_format($document->total_sales_amount) }}</td>
            <td></td>
            <td>{{ number_format($document->total_commission_amount) }}</td>
        </tr>
    </tfoot>
</table>

@if($document->adjustments->isNotEmpty())
    <h2>تنظیمات دستی</h2>
    <table>
        <thead><tr><th>فاکتور</th><th>مبلغ</th><th>دلیل</th><th>ثبت‌کننده</th></tr></thead>
        <tbody>
        @foreach($document->adjustments as $adj)
            <tr>
                <td>{{ $adj->invoice?->uuid ?? '—' }}</td>
                <td class="{{ $adj->amount >= 0 ? 'green' : 'red' }}">{{ $adj->amount >= 0 ? '+' : '' }}{{ number_format($adj->amount) }}</td>
                <td>{{ $adj->reason }}</td>
                <td>{{ $adj->creator?->name ?? '—' }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot><tr><td>جمع تنظیمات</td><td>{{ number_format($document->total_adjustment_amount) }}</td><td colspan="2"></td></tr></tfoot>
    </table>
@endif

<div class="info">
    @if($document->bonus_amount)
        <div><span class="lbl">بونوس تشویقی</span><span class="val">{{ number_format($document->bonus_amount) }} ریال</span></div>
        @if($document->bonus_reason)
            <div><span class="lbl">دلیل بونوس</span><span class="val">{{ $document->bonus_reason }}</span></div>
        @endif
    @endif
    @if($document->cash_collected_amount)
        <div><span class="lbl">وجه نقد دریافتی</span><span class="val">{{ number_format($document->cash_collected_amount) }} ریال</span></div>
    @endif
</div>

<div class="info net">
    <div style="flex:1 1 100%; text-align:center;">
        <span class="lbl">پورسانت خالص قابل پرداخت</span>
        <span class="val green" style="font-size:16px;">{{ number_format($document->net_commission_amount) }} ریال</span>
    </div>
</div>

@if($document->notes)
    <div style="border:1px solid #ddd;border-radius:4px;padding:6px 9px;font-size:10px;margin-top:8px;">
        <strong>یادداشت:</strong> {{ $document->notes }}
    </div>
@endif

<div class="sig">
    <div><span>تنظیم‌کننده</span></div>
    <div><span>تأییدکننده</span></div>
    <div><span>مدیر مالی</span></div>
</div>

<div class="foot">
    صادرشده توسط سیستم مالی شرکت آریا گستر — تاریخ چاپ: {{ JalaliDate::dateTime(now()) }}
</div>
</body>
</html>
