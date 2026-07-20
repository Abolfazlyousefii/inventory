<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><title>گزارش برگشتی مشتریان</title>
<style>
@page{size:A4 portrait;margin:13mm}body{direction:rtl;text-align:right;font-family:Tahoma,Arial,sans-serif;color:#111827;font-size:12px;background:#fff}.actions{margin-bottom:12px;display:flex;gap:8px}.btn{border:1px solid #cbd5e1;padding:6px 11px;text-decoration:none;color:#111827;background:#f8fafc;border-radius:6px}.print-header{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #0b5fa8;padding-bottom:10px;margin-bottom:10px}.brand{display:flex;align-items:center;gap:10px}.brand img{width:48px;height:48px;object-fit:contain}.system-name{font-size:12px;color:#475569}h1{font-size:20px;margin:2px 0 0}.generated{color:#374151}.filters,.summary{display:flex;flex-wrap:wrap;gap:8px;margin:9px 0}.filters span,.summary span{border:1px solid #dbeafe;background:#f8fbff;border-radius:999px;padding:4px 9px}table{width:100%;border-collapse:collapse}thead{display:table-header-group}tr{page-break-inside:avoid}th,td{border:1px solid #d1d5db;padding:7px 6px;vertical-align:middle}th{background:#eef5fb;font-weight:600}td.amount{direction:ltr;text-align:left}@media print{.no-print{display:none}.print-header,.filters span,.summary span,th{-webkit-print-color-adjust:exact;print-color-adjust:exact}a[href]:after{content:""}}
</style>
</head>
<body>
@include('vouchers.return-from-sale.partials.print-header', ['title' => 'گزارش برگشتی مشتریان'])
<div class="summary"><span>تعداد کل اسناد: <strong>{{ number_format($documentsCount) }}</strong></span><span>مبلغ کل برگشتی: <strong>{{ number_format($totalAmount) }}</strong> ریال</span></div>
<table><thead><tr><th>ردیف</th><th>شماره سند یا حواله</th><th>مشتری</th><th>تاریخ</th><th>نوع یا نام انبار</th><th>مبلغ کل</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td>{{ $loop->iteration }}</td><td>{{ $row['document_number'] }}</td><td>{{ $row['customer_name'] }}</td><td>{{ $row['returned_at_display'] }}</td><td>{{ $row['destination_warehouse_label'] }}</td><td class="amount">{{ number_format($row['total_amount']) }}</td></tr>@empty<tr><td colspan="6" style="text-align:center;color:#6b7280">موردی برای نمایش وجود ندارد.</td></tr>@endforelse
</tbody></table>
<script>window.addEventListener('load',()=>{});</script>
</body>
</html>
