<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>لیست فاکتورهای فروش</title>
    <link rel="stylesheet" href="{{ asset('css/Vazirmatn.css') }}">
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f2f5f7;color:#213b4b;font:12px Vazirmatn,Tahoma,sans-serif}.toolbar{max-width:1200px;margin:18px auto;text-align:left}button{font:inherit;cursor:pointer;padding:9px 18px;border:1px solid #d7e1e7;border-radius:7px;background:white}.page{max-width:1200px;margin:0 auto 24px;background:white;padding:28px}h1{font-size:19px;margin:0 0 10px}.meta{font-size:11px;color:#596e7b;line-height:2;margin:5px 0}.summary{font-size:12px;margin:14px 0}table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{padding:8px 6px;border-bottom:1px solid #dce4e9;text-align:right;overflow-wrap:anywhere;font-size:11px}th{background:#edf3f6}thead{display:table-header-group}tr{break-inside:avoid}.money{direction:ltr;white-space:nowrap;text-align:left;font-variant-numeric:tabular-nums}tfoot{font-weight:bold}tfoot td{border-top:2px solid #c6d5df}footer{margin-top:16px;color:#72808a;font-size:10px}@page{size:A4 landscape;margin:12mm}@media print{body{background:white}.toolbar{display:none}.page{margin:0;padding:0;max-width:none}th{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
    </style>
</head>
<body>
    <div class="toolbar"><button type="button" onclick="window.print()">چاپ / ذخیره PDF</button></div>
    <main class="page">
        <h1>لیست فاکتورهای فروش</h1>
        <p class="meta">{{ $filterDescription }}</p>
        <p class="meta">تهیه: {{ $printedAt }} · مبالغ به ریال</p>
        <p class="summary">{{ number_format($summary['invoice_count']) }} فاکتور · مبلغ کل: {{ number_format($summary['total_sales']) }} ریال</p>
        <table>
            <colgroup><col style="width:4%"><col style="width:9%"><col style="width:12%"><col style="width:18%"><col style="width:12%"><col style="width:12%"><col style="width:11%"><col style="width:11%"><col style="width:11%"></colgroup>
            <thead><tr><th>ردیف</th><th>شماره فاکتور</th><th>تاریخ ثبت</th><th>مشتری</th><th>فروشنده</th><th>وضعیت</th><th class="money">مبلغ فاکتور</th><th class="money">پرداخت‌شده</th><th class="money">مانده</th></tr></thead>
            <tbody>
                @forelse($invoices as $invoice)
                    @php($values = \App\Support\InvoiceListReportRow::values($invoice,$statusLabels,$loop->iteration))
                    <tr data-invoice-number="{{ $invoice->uuid }}">@foreach($values as $index=>$value)<td @class(['money'=>$index>=6])>{{ $index>=6 ? number_format($value) : $value }}</td>@endforeach</tr>
                @empty
                    <tr><td colspan="9">فاکتوری با این فیلترها پیدا نشد.</td></tr>
                @endforelse
            </tbody>
            <tfoot><tr><td colspan="6">جمع {{ number_format($summary['invoice_count']) }} فاکتور</td><td class="money">{{ number_format($summary['total_sales']) }}</td><td class="money">{{ number_format($summary['paid_amount']) }}</td><td class="money">{{ number_format($summary['remaining_amount']) }}</td></tr></tfoot>
        </table>
        <footer>تمام فاکتورهای مطابق فیلترهای انتخاب‌شده · {{ config('app.name') }}</footer>
    </main>
</body>
</html>
