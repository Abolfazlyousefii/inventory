<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><title>گزارش کلی برگشت از فروش</title>
<style>
    @page { size: A4 portrait; margin: 14mm; }
    body { direction: rtl; text-align: right; font-family: Tahoma, Arial, sans-serif; color:#111827; font-size: 12px; background:#fff; }
    .actions { margin-bottom: 12px; display:flex; gap:8px; }
    .btn { border:1px solid #d1d5db; padding:6px 10px; text-decoration:none; color:#111827; background:#f9fafb; border-radius:4px; }
    h1 { font-size: 22px; margin: 0 0 8px; }
    .generated { margin-bottom:14px; color:#374151; }
    table { width:100%; border-collapse: collapse; }
    thead { display: table-header-group; }
    th, td { border:1px solid #d1d5db; padding:7px 6px; vertical-align:middle; }
    th { background:#eef5fb; }
    td.amount { direction:ltr; text-align:left; }
    @media print { .actions { display:none; } }
</style>
</head>
<body>
<div class="actions"><button class="btn" onclick="window.print()">چاپ</button><a class="btn" href="{{ route('vouchers.return-from-sale.index', request()->query()) }}">بازگشت</a></div>
<h1>گزارش کلی برگشت از فروش</h1>
<div class="generated">تاریخ تولید: {{ $generatedAt }}</div>
<table><thead><tr><th>ردیف</th><th>شماره حواله یا سند</th><th>مشتری</th><th>تاریخ</th><th>نوع یا نام انبار</th><th>مبلغ کل</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td>{{ $loop->iteration }}</td><td>{{ $row['document_number'] }}</td><td>{{ $row['customer_name'] }}</td><td>{{ $row['returned_at_display'] }}</td><td>{{ $row['destination_warehouse_label'] }}</td><td class="amount">{{ number_format($row['total_amount']) }}</td></tr>@empty<tr><td colspan="6" style="text-align:center;color:#6b7280">موردی برای نمایش وجود ندارد.</td></tr>@endforelse
</tbody></table>
</body>
</html>
