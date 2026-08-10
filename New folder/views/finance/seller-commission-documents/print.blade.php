<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>چاپ سند {{ $document->document_number }}</title>
    <style>
        @font-face{font-family:Vazirmatn;src:url('{{ asset('fonts/Vazirmatn-Regular.woff2') }}') format('woff2')}*{box-sizing:border-box}body{font-family:Vazirmatn,Tahoma,sans-serif;direction:rtl;color:#111827;margin:0;background:#fff;font-size:11px}.page{width:100%;max-width:190mm;margin:0 auto;padding:9mm}.head{text-align:center;border-bottom:2px solid #111827;padding-bottom:8px;margin-bottom:12px}.head h1{font-size:17px;margin:0 0 5px}.head h2{font-size:14px;margin:0}.meta{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-bottom:10px}.meta div{border:1px solid #cbd5e1;padding:6px}.meta span{color:#64748b;display:block;font-size:9px;margin-bottom:2px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #94a3b8;padding:5px;text-align:right}th{background:#e2e8f0;font-weight:700}thead{display:table-header-group}tfoot{display:table-row-group}.amount{white-space:nowrap}.notes{border:1px solid #cbd5e1;padding:8px;margin-top:10px;min-height:30px}.signatures{display:grid;grid-template-columns:1fr 1fr;gap:50mm;margin-top:22mm;text-align:center}.signature{border-top:1px solid #475569;padding-top:6px}.print-actions{display:flex;justify-content:center;gap:8px;margin:12px}.print-actions button{padding:7px 18px;cursor:pointer}@page{size:A4 portrait;margin:8mm}@media print{.page{padding:0}.print-actions{display:none}body{print-color-adjust:exact;-webkit-print-color-adjust:exact}}
    </style>
</head>
<body>
<div class="print-actions"><button onclick="window.print()">چاپ</button><button onclick="window.close()">بستن</button></div>
<main class="page">
    <header class="head"><h1>شرکت آریا گستر</h1><h2>سند فروش فروشنده</h2></header>
    <section class="meta">
        <div><span>شماره سند</span><strong>{{ $document->document_number }}</strong></div>
        <div><span>نام فروشنده</span><strong>{{ $document->seller?->name ?: '—' }}</strong></div>
        <div><span>بازه گزارش</span><strong>{{ App\Support\JalaliDate::date($document->period_from) }} تا {{ App\Support\JalaliDate::date($document->period_to) }}</strong></div>
        <div><span>تعداد فاکتورها</span><strong>{{ number_format($document->invoice_count) }}</strong></div>
        <div><span>ثبت‌کننده سند</span><strong>{{ $document->creator?->name ?: '—' }}</strong></div>
        <div><span>تاریخ ثبت سند</span><strong>{{ App\Support\JalaliDate::dateTime($document->created_at) }}</strong></div>
    </section>
    <table>
        <thead><tr><th>ردیف</th><th>شماره فاکتور</th><th>تاریخ اولیه</th><th>مشتری</th><th>مبلغ نهایی</th></tr></thead>
        <tbody>@foreach($document->items as $item)<tr><td>{{ $loop->iteration }}</td><td>{{ $item->invoice_number_snapshot }}</td><td>{{ App\Support\JalaliDate::date($item->invoice_date_snapshot) }}</td><td>{{ $item->customer_name_snapshot }}</td><td class="amount">{{ App\Support\Currency::formatRial($item->invoice_total_snapshot) }}</td></tr>@endforeach</tbody>
        <tfoot><tr><th colspan="4">جمع کل فروش</th><th class="amount">{{ App\Support\Currency::formatRial($document->total_sales_amount) }}</th></tr></tfoot>
    </table>
    <div class="notes"><strong>توضیحات:</strong> {{ $document->notes ?: '—' }}</div>
    <div class="signatures"><div class="signature">امضای واحد مالی</div><div class="signature">امضای مدیریت</div></div>
</main>
</body>
</html>
