<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>فرم تحویل اموال پرسنل - {{ $document->document_number }}</title>
  <style>
    @page { size: A4; margin: 14mm; }
    body { font-family: Tahoma, sans-serif; color:#111; }
    .sheet { border:2px solid #222; padding:14px; }
    .header { text-align:center; margin-bottom:12px; }
    .header h1 { margin:0; font-size:20px; }
    .header p { margin:4px 0 0; font-size:12px; color:#333; }
    .meta { width:100%; border-collapse: collapse; margin-bottom:10px; }
    .meta td { border:1px solid #444; padding:6px; font-size:12px; }
    .items { width:100%; border-collapse: collapse; margin-top:8px; }
    .items th, .items td { border:1px solid #444; padding:7px; font-size:12px; vertical-align: top; }
    .items th { background:#f0f0f0; }
    .signatures { margin-top:18px; display:grid; grid-template-columns: 1fr 1fr; gap:18px; }
    .sign-box { border:1px solid #333; min-height:120px; padding:8px; }
    .sign-title { font-weight:700; margin-bottom:8px; }
    .fingerprint { border:1px dashed #777; height:90px; margin-top:8px; display:flex; align-items:center; justify-content:center; color:#666; font-size:11px; }
    .notes { margin-top:12px; border:1px solid #333; min-height:72px; padding:8px; }
    .print-btn { margin:10px 0; }
    @media print { .print-btn { display:none; } }
  </style>
</head>
<body>
  <button class="print-btn" onclick="window.print()">چاپ فرم</button>

  <div class="sheet">
    <div class="header">
      <h1>فرم تحویل اموال پرسنل</h1>
      <p>این فرم جهت تحویل رسمی اموال به پرسنل تهیه شده است.</p>
    </div>

    <table class="meta">
      <tr>
        <td><strong>شماره سند:</strong> {{ $document->document_number }}</td>
        <td><strong>تاریخ ثبت:</strong> {{ optional($document->document_date)->format('Y-m-d') }}</td>
      </tr>
      <tr>
        <td><strong>نام پرسنل:</strong> {{ $document->trusteeDisplayName() }}</td>
        <td><strong>کد پرسنلی:</strong> {{ $document->personnel?->personnel_code ?: '—' }}</td>
      </tr>
      <tr>
        <td><strong>سمت:</strong> {{ $document->personnel?->position ?: '—' }}</td>
        <td><strong>واحد سازمانی:</strong> {{ $document->personnel?->department ?: '—' }}</td>
      </tr>
      <tr>
        <td colspan="2"><strong>تحویل‌دهنده / ثبت‌کننده:</strong> {{ $document->creator?->name ?: '—' }}</td>
      </tr>
    </table>

    <table class="items">
      <thead>
        <tr>
          <th style="width:40px">ردیف</th>
          <th>نام کالا</th>
          <th>مدل / تنوع / سریال</th>
          <th style="width:70px">تعداد</th>
          <th>توضیحات</th>
        </tr>
      </thead>
      <tbody>
        @foreach($document->items as $index => $item)
          <tr>
            <td>{{ $index + 1 }}</td>
            <td>{{ $item->item_name }}</td>
            <td>{{ $item->codes->pluck('asset_code')->join(' ، ') ?: '—' }}</td>
            <td>{{ $item->quantity }}</td>
            <td>{{ $item->description ?: '—' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>

    <div class="signatures">
      <div class="sign-box">
        <div class="sign-title">تحویل‌گیرنده (پرسنل)</div>
        <div>نام و نام خانوادگی: {{ $document->trusteeDisplayName() !== '—' ? $document->trusteeDisplayName() : '....................' }}</div>
        <div style="margin-top:10px;">امضا: ............................</div>
        <div style="margin-top:10px;">تاریخ: ............................</div>
        <div class="fingerprint">محل اثر انگشت پرسنل</div>
      </div>
      <div class="sign-box">
        <div class="sign-title">تحویل‌دهنده</div>
        <div>نام و نام خانوادگی: {{ $document->creator?->name ?: '....................' }}</div>
        <div style="margin-top:10px;">امضا: ............................</div>
        <div style="margin-top:10px;">تاریخ: ............................</div>
      </div>
    </div>

    <div class="notes">
      <strong>توضیحات:</strong>
      <div style="margin-top:6px;">{{ $document->description ?: '........................................................................................................................' }}</div>
    </div>
  </div>
</body>
</html>
