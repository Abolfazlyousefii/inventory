<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><title>گزارش کالاهای برگشتی</title>
<style>
@page{size:A4 landscape;margin:9mm}body{direction:rtl;text-align:right;font-family:Tahoma,Arial,sans-serif;color:#111827;font-size:9.5px;background:#fff}.actions{margin-bottom:10px;display:flex;gap:8px}.btn{border:1px solid #cbd5e1;padding:6px 11px;text-decoration:none;color:#111827;background:#f8fafc;border-radius:6px}.print-header{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #0b5fa8;padding-bottom:8px;margin-bottom:8px}.brand{display:flex;align-items:center;gap:9px}.brand img{width:44px;height:44px;object-fit:contain}.system-name{font-size:11px;color:#475569}h1{font-size:18px;margin:1px 0 0}.generated{color:#374151}.filters,.summary{display:flex;flex-wrap:wrap;gap:6px;margin:7px 0}.filters span,.summary span{border:1px solid #dbeafe;background:#f8fbff;border-radius:999px;padding:3px 7px}table{width:100%;border-collapse:collapse;table-layout:fixed}thead{display:table-header-group}tfoot{display:table-row-group}tr{page-break-inside:avoid}th,td{border:1px solid #d1d5db;padding:5px 4px;vertical-align:middle;line-height:1.45}th{background:#eef5fb;font-weight:600}.ltr{direction:ltr;text-align:left}.warehouses{white-space:pre-line}.name{word-break:break-word}@media print{.no-print{display:none}.print-header,.filters span,.summary span,th,tfoot td{-webkit-print-color-adjust:exact;print-color-adjust:exact}a[href]:after{content:""}}
</style>
</head>
<body>
@include('vouchers.return-from-sale.partials.print-header', ['title' => 'گزارش کالاهای برگشتی'])
<div class="summary">
    <span>تعداد کالا/تنوع یکتا: <strong>{{ number_format($totals['unique_products']) }}</strong></span>
    <span>کل واحد برگشتی: <strong>{{ number_format($totals['total_quantity']) }}</strong></span>
    <span>سالم: <strong>{{ number_format($totals['healthy_quantity']) }}</strong></span>
    <span>معیوب: <strong>{{ number_format($totals['damaged_quantity']) }}</strong></span>
    <span>سند یکتا: <strong>{{ number_format($totals['documents_count']) }}</strong></span>
    <span>مشتری یکتا: <strong>{{ number_format($totals['customers_count']) }}</strong></span>
    <span>مبلغ کل: <strong>{{ number_format($totals['total_refund_amount']) }}</strong> ریال</span>
</div>
<table><thead><tr><th style="width:3%">ردیف</th><th style="width:12%">نام کالا</th><th style="width:10%">تنوع / مدل</th><th style="width:8%">کد کالا / SKU</th><th style="width:7%">تعداد کل برگشتی</th><th style="width:6%">تعداد سالم</th><th style="width:6%">تعداد معیوب</th><th style="width:6%">تعداد اسناد</th><th style="width:6%">تعداد مشتری</th><th style="width:8%">مبلغ کل برگشتی</th><th style="width:7%">میانگین وزنی مبلغ واحد</th><th style="width:13%">انبارهای مقصد</th><th style="width:8%">آخرین تاریخ برگشت</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td>{{ $loop->iteration }}</td><td class="name">{{ $row['product_name'] }}</td><td class="name">{{ $row['variant_name'] }}</td><td class="ltr">{{ $row['sku'] }}</td><td class="ltr">{{ number_format($row['total_quantity']) }}</td><td class="ltr">{{ number_format($row['healthy_quantity']) }}</td><td class="ltr">{{ number_format($row['damaged_quantity']) }}</td><td class="ltr">{{ number_format($row['documents_count']) }}</td><td class="ltr">{{ number_format($row['customers_count']) }}</td><td class="ltr">{{ number_format($row['total_refund_amount']) }}</td><td class="ltr">{{ number_format($row['weighted_unit_price']) }}</td><td class="warehouses">{{ $row['warehouses_label'] ?: '—' }}</td><td class="ltr">{{ $row['last_return_at_display'] }}</td></tr>@empty<tr><td colspan="13" style="text-align:center;color:#6b7280">موردی برای نمایش وجود ندارد.</td></tr>@endforelse
</tbody><tfoot><tr><td colspan="4">جمع کل</td><td class="ltr">{{ number_format($totals['total_quantity']) }}</td><td class="ltr">{{ number_format($totals['healthy_quantity']) }}</td><td class="ltr">{{ number_format($totals['damaged_quantity']) }}</td><td colspan="2"></td><td class="ltr">{{ number_format($totals['total_refund_amount']) }}</td><td colspan="3"></td></tr></tfoot></table>
<script>window.addEventListener('load',()=>{});</script>
</body>
</html>
