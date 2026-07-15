<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <title>گزارش فاکتورهای فروش</title>
  <style>
    body{font-family:dejavusans, DejaVu Sans, Vazirmatn, sans-serif; direction:rtl; text-align:right; color:#111827; font-size:10px;}
    h1{font-size:18px; margin:0 0 8px; font-weight:700;}
    .meta{width:100%; margin-bottom:10px; border-collapse:collapse;}
    .meta td{border:1px solid #e5e7eb; padding:6px; vertical-align:top;}
    .meta .label{color:#64748b; font-weight:700; width:120px;}
    .chips{margin:4px 0 10px;}
    .chip{display:inline-block; border:1px solid #dbeafe; background:#eff6ff; color:#1d4ed8; border-radius:12px; padding:3px 8px; margin:0 0 4px 4px;}
    table.report{width:100%; border-collapse:collapse; table-layout:fixed;}
    table.report th{background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; padding:6px 4px; font-weight:700; font-size:9px;}
    table.report td{border:1px solid #e2e8f0; padding:5px 4px; vertical-align:middle; font-size:9px; word-wrap:break-word;}
    table.report tbody tr:nth-child(even){background:#fafafa;}
    .ltr{direction:ltr; unicode-bidi:embed; text-align:left;}
    .money{direction:ltr; text-align:left; white-space:nowrap; font-weight:700;}
    .status{white-space:nowrap;}
    tfoot th{background:#ecfdf5!important; color:#065f46!important;}
    .empty{text-align:center; padding:18px!important; color:#64748b;}
  </style>
</head>
<body>
  <h1>گزارش فاکتورهای فروش</h1>

  <table class="meta">
    <tr>
      <td class="label">تاریخ تولید گزارش</td>
      <td>{{ $generatedAt }}</td>
      <td class="label">تعداد نتایج</td>
      <td>{{ number_format($totals['count'] ?? 0) }}</td>
    </tr>
  </table>

  @if(!empty($filters))
    <div class="chips">
      @foreach($filters as $label => $value)
        <span class="chip">{{ $label }}: {{ $value }}</span>
      @endforeach
    </div>
  @endif

  <table class="report">
    <thead>
      <tr>
        <th style="width:9%">شماره</th>
        <th style="width:7%">تاریخ</th>
        <th style="width:12%">مشتری</th>
        <th style="width:6%">کد</th>
        <th style="width:8%">موبایل</th>
        <th style="width:9%">مبلغ</th>
        <th style="width:9%">پرداخت‌شده</th>
        <th style="width:9%">مانده</th>
        <th style="width:9%">وضعیت پرداخت</th>
        <th style="width:12%">وضعیت فاکتور</th>
        <th style="width:10%">ثبت‌کننده</th>
      </tr>
    </thead>
    <tbody>
      @forelse($rows as $row)
        <tr>
          <td class="ltr">{{ $row['number'] }}</td>
          <td class="ltr">{{ $row['date'] }}</td>
          <td>{{ $row['customer'] }}</td>
          <td>{{ $row['code'] }}</td>
          <td class="ltr">{{ $row['mobile'] }}</td>
          <td class="money">{{ number_format($row['total']) }}</td>
          <td class="money">{{ number_format($row['paid']) }}</td>
          <td class="money">{{ number_format($row['remaining']) }}</td>
          <td class="status">{{ $row['payment_status'] }}</td>
          <td class="status">{{ $row['invoice_status'] }}</td>
          <td>{{ $row['creator'] }}</td>
        </tr>
      @empty
        <tr><td colspan="11" class="empty">هیچ فاکتوری با فیلترهای انتخاب‌شده یافت نشد.</td></tr>
      @endforelse
    </tbody>
    <tfoot>
      <tr>
        <th colspan="5">جمع کل گزارش</th>
        <th class="money">{{ number_format($totals['total'] ?? 0) }}</th>
        <th class="money">{{ number_format($totals['paid'] ?? 0) }}</th>
        <th class="money">{{ number_format($totals['remaining'] ?? 0) }}</th>
        <th colspan="3"></th>
      </tr>
    </tfoot>
  </table>
</body>
</html>
