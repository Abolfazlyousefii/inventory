<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $printData['title'] }} {{ $printData['number'] }}</title>
    <style>
        @page { size: A4 portrait; margin: 8mm; }
        * { box-sizing: border-box; }
        html, body { margin:0; padding:0; direction:rtl; }
        body { font-family: Tahoma, Arial, sans-serif; color:#111827; background:#eef2f7; font-size:10.5px; line-height:1.45; }
        .no-print { width:190mm; margin:12px auto; display:flex; justify-content:space-between; gap:8px; }
        .btn { border:1px solid #111827; background:#fff; color:#111827; border-radius:8px; padding:7px 12px; text-decoration:none; cursor:pointer; }
        .btn-primary { background:#111827; color:#fff; }
        .invoice-page { width:190mm; margin:0 auto 12px; background:#fff; padding:6mm; border:1px solid #d1d5db; }
        .invoice-header { display:grid; grid-template-columns:1fr auto; gap:12px; align-items:start; border-bottom:2px solid #111827; padding-bottom:6px; margin-bottom:6px; }
        .brand { display:flex; align-items:center; gap:10px; }
        .brand img { width:62px; max-height:62px; object-fit:contain; }
        .brand-name { font-size:15px; font-weight:900; }
        .doc-title { margin:0 0 4px; font-size:19px; font-weight:900; text-align:left; }
        .doc-meta { text-align:left; color:#374151; }
        .info-grid { display:grid; grid-template-columns:1.35fr 1fr; gap:6px; margin-bottom:7px; }
        .info-box, .company-box { border:1px solid #374151; border-radius:6px; padding:5px; page-break-inside:avoid; break-inside:avoid; }
        .info-title { font-weight:900; padding-bottom:3px; margin-bottom:3px; border-bottom:1px dashed #9ca3af; }
        .info-row { display:grid; grid-template-columns:78px 1fr; gap:4px; margin-bottom:1px; }
        .label { color:#374151; font-weight:800; }
        table { width:100%; border-collapse:collapse; table-layout:fixed; }
        .items-table th, .items-table td { border:1px solid #374151; padding:3px 4px; vertical-align:middle; line-height:1.35; font-size:10px; }
        .items-table th { background:#f3f4f6; text-align:center; font-weight:900; font-size:10px; }
        thead { display:table-header-group; }
        tfoot { display:table-footer-group; }
        .items-total-row td { background:#f9fafb; font-weight:900; }
        tr { page-break-inside:avoid; break-inside:avoid; }
        .col-index { width:7mm; text-align:center; }
        .col-desc { width:auto; }
        .col-code { width:24mm; text-align:center; direction:ltr; unicode-bidi:embed; }
        .col-map { width:42mm; text-align:center; direction:ltr; unicode-bidi:embed; font-size:10px; }
        .col-qty { width:12mm; text-align:center; }
        .col-check { width:26mm; text-align:center; }
        .col-price { width:24mm; text-align:left; direction:ltr; unicode-bidi:embed; }
        .col-total { width:27mm; text-align:left; direction:ltr; unicode-bidi:embed; }
        body.customer-mode .warehouse-only { display:none; }
        .summary-section { display:grid; grid-template-columns:1fr 68mm; gap:8px; margin-top:7px; page-break-inside:avoid; break-inside:avoid; }
        .summary-table td { border:1px solid #374151; padding:3px 6px; font-size:10px; }
        .summary-table td:last-child { text-align:left; direction:ltr; }
        .final-row td { background:#f3f4f6; font-weight:900; }
        .company-box { margin-top:7px; }
        .signature-section { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:8px; page-break-inside:avoid; break-inside:avoid; }
        .signature-box { height:48px; border:1px solid #374151; border-radius:6px; padding:6px; font-weight:900; }
        @media print {
            body { margin:0; direction:rtl; background:#fff !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .no-print { display:none !important; }
            .invoice-page { width:auto; margin:0; padding:0; border:0; }
            a[href]:after { content:"" !important; }
            table { page-break-inside:auto; }
            tr { page-break-inside:avoid; page-break-after:auto; }
            thead { display:table-header-group; }
            tfoot { display:table-footer-group; }
            .summary-section, .company-box, .signature-section { page-break-inside:avoid; break-inside:avoid; }
        }
    </style>
</head>
<body class="{{ $printData['mode'] === 'customer' ? 'customer-mode' : 'warehouse-mode' }}">
@include('prints.partials.sales-invoice-template')
</body>
</html>
