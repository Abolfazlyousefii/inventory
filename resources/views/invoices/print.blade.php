<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php($totals = \App\Support\SalesDocumentTotals::calculate($invoice->items, (int) $invoice->discount_amount, (int) $invoice->shipping_price, ['discount_allocation_mode' => $invoice->discount_allocation_mode]))
    <title>چاپ فاکتور {{ $invoice->uuid }}</title>
    <style>
        :root { --print-font: 11px; --print-border: #1f2937; --print-muted: #4b5563; --print-soft: #f8fafc; }
        @page { size: A4 portrait; margin: 10mm; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Tahoma, "IRANSans", Arial, sans-serif;
            background: #eef2f7;
            color: #111827;
            direction: rtl;
            font-size: var(--print-font);
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .print-actions { width: 190mm; margin: 12px auto; display:flex; justify-content:space-between; gap:8px; align-items:center; }
        .print-actions-left { display:flex; gap:6px; align-items:center; }
        .btn { border: 1px solid #111827; background: #fff; color:#111827; text-decoration:none; border-radius:8px; padding: 7px 12px; font-size: 11px; cursor:pointer; }
        .btn-primary { background:#111827; color:#fff; }
        .invoice-page { width:190mm; min-height:277mm; margin:0 auto 16px; background:#fff; padding:9mm; border:1px solid #d1d5db; border-radius:10px; }
        .invoice-header { display:grid; grid-template-columns: 1fr auto; gap:10px; align-items:start; padding-bottom:8px; margin-bottom:8px; border-bottom:2px solid var(--print-border); }
        .brand { display:flex; align-items:center; gap:8px; font-weight:900; }
        .brand img { width:34px; height:34px; object-fit:contain; }
        .brand-name { font-size:13px; }
        .doc-title { margin:0 0 6px; font-size:18px; font-weight:900; text-align:left; }
        .doc-meta { color:var(--print-muted); font-size:10px; text-align:left; line-height:1.7; }
        .info-grid { display:grid; grid-template-columns:1fr 1fr; gap:7px; margin-bottom:9px; }
        .info-box { border:1px solid var(--print-border); border-radius:7px; padding:7px; page-break-inside:avoid; }
        .info-box-title { font-weight:900; border-bottom:1px dashed #9ca3af; padding-bottom:4px; margin-bottom:5px; }
        .info-row { display:grid; grid-template-columns:92px 1fr; gap:5px; margin-bottom:3px; line-height:1.6; }
        .info-label { color:#374151; font-weight:800; }
        .invoice-table { width:100%; border-collapse:collapse; table-layout:fixed; margin-top:6px; }
        .invoice-table th,.invoice-table td { border:1px solid var(--print-border); padding:5px 5px; line-height:1.45; vertical-align:middle; }
        .invoice-table th { font-size:10px; font-weight:900; background:var(--print-soft); text-align:center; }
        .invoice-table td { font-size:10px; }
        .col-index { width:28px; text-align:center; }
        .col-code { width:72px; text-align:center; direction:ltr; }
        .col-model { width:112px; text-align:center; }
        .col-qty { width:46px; text-align:center; }
        .col-price,.col-total { width:82px; text-align:left; direction:ltr; }
        .item-name { font-weight:900; color:#111827; }
        .item-model { color:#374151; font-weight:800; }
        .item-code { font-family: Tahoma, Arial, sans-serif; font-weight:900; direction:ltr; unicode-bidi:embed; }
        .summary-wrap { display:flex; justify-content:flex-start; margin-top:9px; page-break-inside:avoid; }
        .summary-table { width:88mm; border-collapse:collapse; }
        .summary-table td { border:1px solid var(--print-border); padding:5px 7px; font-size:10.5px; }
        .summary-table .final-row td { background:#eef2f7; font-weight:900; font-size:11px; }
        .signature-section { display:grid; grid-template-columns:repeat(2,1fr); gap:8px; margin-top:12px; page-break-inside:avoid; }
        .signature-box { border:1px solid var(--print-border); border-radius:7px; height:58px; padding:7px; font-size:10px; }
        .signature-title { font-weight:900; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        @media print{
            body{ background:#fff !important; margin:0; }
            .no-print,.print-actions{ display:none !important; }
            .invoice-page{ width:auto; min-height:auto; margin:0; padding:0; border:0; border-radius:0; box-shadow:none; }
        }
        body.print-a5 .invoice-page, body.print-a5 .print-actions { width:132mm; }
        body.print-a5 .info-grid, body.print-a5 .invoice-header { grid-template-columns:1fr; }
        body.print-a5 .doc-title, body.print-a5 .doc-meta { text-align:right; }
        body.print-a5 .invoice-table th, body.print-a5 .invoice-table td { font-size:8.2px; padding:2px; }
        body.print-a5 .col-code { width:52px; }
        body.print-a5 .col-model { width:76px; }
        body.print-a5 .col-price, body.print-a5 .col-total { width:58px; }
        body.print-a5 .summary-table { width:100%; }
    </style>
</head>
<body class="print-a4">
@php
    use Morilog\Jalali\Jalalian;

    $fmtDate = function($value, string $format = 'Y/m/d H:i') {
        if (!$value) {
            return '—';
        }
        return Jalalian::fromDateTime($value)->format($format);
    };

    $money = fn($value) => \App\Support\Currency::formatRial($value);
    $productCode = function ($item): string {
        return $item->product?->code
            ?: ($item->variant?->variant_code
                ?: ($item->product?->sku ?: '—'));
    };
    $companyName = config('app.name', 'شرکت');
    $shippingName = $invoice->shippingMethod?->name ?? ($invoice->shipping_id ? ('روش ارسال #' . $invoice->shipping_id) : '—');
@endphp

<div class="print-actions no-print">
    <div class="print-actions-left">
        <a class="btn" href="{{ route('vouchers.sales.show', $invoice->uuid) }}">بازگشت</a>
        <button type="button" class="btn" onclick="document.body.classList.remove('print-a5');document.body.classList.add('print-a4');">A4</button>
        <button type="button" class="btn" onclick="document.body.classList.remove('print-a4');document.body.classList.add('print-a5');">A5</button>
    </div>
    <button type="button" class="btn btn-primary" onclick="window.print()">چاپ</button>
</div>

<main class="invoice-page">
    <header class="invoice-header">
        <div class="brand">
            <img src="{{ asset('logo.png') }}" alt="{{ $companyName }}">
            <div class="brand-name">{{ $companyName }}</div>
        </div>
        <div>
            <h1 class="doc-title">فاکتور فروش</h1>
            <div class="doc-meta">
                <div>شماره: <strong>{{ $invoice->uuid }}</strong></div>
                <div>تاریخ: {{ $fmtDate($invoice->display_document_date) }}</div>
            </div>
        </div>
    </header>

    <section class="info-grid">
        <div class="info-box">
            <div class="info-box-title">مشخصات مشتری</div>
            <div class="info-row"><div class="info-label">نام:</div><div><strong>{{ $invoice->customer_name ?: '—' }}</strong></div></div>
            <div class="info-row"><div class="info-label">موبایل:</div><div>{{ $invoice->customer_mobile ?: '—' }}</div></div>
            <div class="info-row"><div class="info-label">آدرس:</div><div>{{ $invoice->customer_address ?: '—' }}</div></div>
        </div>
        <div class="info-box">
            <div class="info-box-title">ارسال</div>
            <div class="info-row"><div class="info-label">روش ارسال:</div><div>{{ $shippingName }}</div></div>
            <div class="info-row"><div class="info-label">هزینه ارسال:</div><div>{{ $money($totals['shipping']) }}</div></div>
        </div>
    </section>

    <section>
        <table class="invoice-table">
            <thead>
            <tr>
                <th class="col-index">ردیف</th>
                <th>نام کالا</th>
                <th class="col-code">کد کالا</th>
                <th class="col-model">مدل / لیست</th>
                <th class="col-qty">تعداد</th>
                <th class="col-price">قیمت واحد</th>
                <th class="col-total">مبلغ کل</th>
            </tr>
            </thead>
            <tbody>
            @forelse($invoice->items as $item)
                @php
                    $lineTotal = \App\Support\SalesDocumentTotals::lineTotal($item);
                    $itemProductCode = $productCode($item);
                @endphp
                <tr>
                    <td class="col-index">{{ $loop->iteration }}</td>
                    <td><div class="item-name">{{ $item->product?->name ?? ('#' . $item->product_id) }}</div></td>
                    <td class="col-code"><span class="item-code">{{ $itemProductCode }}</span></td>
                    <td class="col-model"><span class="item-model">{{ $item->variant?->variant_name ?? '—' }}</span></td>
                    <td class="col-qty">{{ number_format((int) $item->quantity) }}</td>
                    <td class="col-price">{{ number_format((int) $item->price) }}</td>
                    <td class="col-total">{{ number_format($lineTotal) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="col-index">آیتمی برای این فاکتور ثبت نشده است.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>

    <section class="summary-wrap">
        <table class="summary-table">
            <tr><td>جمع کالاها</td><td style="text-align:left;direction:ltr">{{ $money($totals['subtotal_before_discount']) }}</td></tr>
            <tr><td>تخفیف</td><td style="text-align:left;direction:ltr">{{ $money($totals['total_discount']) }}</td></tr>
            <tr><td>هزینه ارسال</td><td style="text-align:left;direction:ltr">{{ $money($totals['shipping']) }}</td></tr>
            <tr class="final-row"><td>مبلغ نهایی</td><td style="text-align:left;direction:ltr">{{ $money($totals['grand_total']) }}</td></tr>
        </table>
    </section>

    <section class="signature-section">
        <div class="signature-box"><div class="signature-title">امضا و مهر فروشنده</div></div>
        <div class="signature-box"><div class="signature-title">امضای مشتری / تحویل‌گیرنده</div></div>
    </section>
</main>
</body>
</html>
