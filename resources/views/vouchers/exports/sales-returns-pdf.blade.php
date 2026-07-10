<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        body{font-family:dejavusans,sans-serif;direction:rtl;text-align:right;font-size:11px;color:#111827}
        h1{font-size:18px;margin:0 0 8px}.meta{margin-bottom:12px;color:#475569}
        table{width:100%;border-collapse:collapse}thead{display:table-header-group}tfoot{display:table-footer-group}
        th,td{border:1px solid #cbd5e1;padding:6px;vertical-align:middle}th{background:#e2e8f0;font-weight:bold}.num{text-align:center;direction:ltr}.amount{direction:ltr;text-align:left;white-space:nowrap}
    </style>
</head>
<body>
<h1>گزارش کلی برگشت از فروش</h1>
<div class="meta">تاریخ تولید: {{ \App\Support\JalaliDate::dateTime($generatedAt) }}</div>
<table>
    <thead><tr><th>ردیف</th><th>شماره حواله یا سند</th><th>مشتری</th><th>تاریخ</th><th>نوع یا نام انبار</th><th>مبلغ کل</th></tr></thead>
    <tbody>
    @forelse($returns as $return)
        <tr>
            <td class="num">{{ $loop->iteration }}</td>
            <td>{{ \App\Exports\SalesReturnsExport::documentNumber($return) }}</td>
            <td>{{ \App\Exports\SalesReturnsExport::customerName($return) }}</td>
            <td class="num">{{ \App\Support\JalaliDate::dateTime($return->transferred_at) }}</td>
            <td>انبار مقصد: {{ \App\Exports\SalesReturnsExport::destinationWarehouseLabel($return) }}</td>
            <td class="amount">{{ number_format(\App\Exports\SalesReturnsExport::totalAmount($return)) }} ریال</td>
        </tr>
    @empty
        <tr><td colspan="6" style="text-align:center">موردی یافت نشد.</td></tr>
    @endforelse
    </tbody>
</table>
</body>
</html>
