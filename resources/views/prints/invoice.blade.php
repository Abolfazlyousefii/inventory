<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $printData['title'] }} {{ $printData['number'] }}</title>
    <style>
        @font-face { font-family:VazirmatnPrint; src:url("{{ asset('fonts/vazirmatn/Vazirmatn-Regular.woff2') }}") format('woff2'); font-weight:400; }
        @font-face { font-family:VazirmatnPrint; src:url("{{ asset('fonts/vazirmatn/Vazirmatn-Bold.woff2') }}") format('woff2'); font-weight:700 900; }
        @page { size: A4 portrait; margin: 8mm; }
        * { box-sizing: border-box; }
        html, body { margin:0; padding:0; direction:rtl; }
        body { font-family:VazirmatnPrint,Tahoma,Arial,sans-serif; color:#172b3a; background:#edf1f5; font-size:11.5px; line-height:1.65; }
        .no-print { width:190mm; margin:12px auto; display:flex; justify-content:space-between; gap:8px; }
        .btn { border:1px solid #111827; background:#fff; color:#111827; border-radius:8px; padding:7px 12px; text-decoration:none; cursor:pointer; }
        .btn-primary { background:#111827; color:#fff; }
        .invoice-page { width:190mm; margin:0 auto 16px; background:#fff; padding:5mm; border:1px solid #d1d5db; }
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
        .customer-balance-box, .customer-payment-box {
            margin-top:7px; padding:7px 9px; border:1px solid #374151; border-radius:6px;
            page-break-inside:avoid; break-inside:avoid;
        }
        .customer-balance-box { background:#f8fafc; }
        .customer-balance-details { display:flex; flex-wrap:wrap; align-items:center; gap:6px 12px; }
        .customer-balance-details > strong { font-size:14px; }
        .customer-balance-status { padding:2px 7px; border:1px solid #b9c7d6; border-radius:15px; font-weight:800; }
        .customer-balance-time { margin-right:auto; font-size:9px; color:#4b5563; }
        .customer-balance-hint { margin-top:4px; color:#4b5563; font-size:9px; }
        .customer-payment-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:6px 14px; }
        .customer-payment-grid > div { display:flex; align-items:baseline; gap:5px; min-width:0; flex-wrap:wrap; }
        .payment-label { color:#374151; font-weight:700; }
        .payment-number { display:inline-block; font-family:Tahoma,Arial,sans-serif; direction:ltr; unicode-bidi:isolate; overflow-wrap:anywhere; text-align:left; }

        .invoice-header { border-color:#244b60; padding-bottom:10px; margin-bottom:10px; }
        .brand img { width:44px; max-height:48px; }
        .doc-title { font-size:18px; color:#173d53; }
        .doc-meta { font-size:10px; }
        .info-box,.company-box { border-color:#c2ccd4; border-radius:4px; padding:8px; }
        .items-table th,.items-table td { border-color:#bac6ce; padding:6px 5px; font-size:11px; }
        .items-table th { background:#edf2f5; font-size:10px; color:#173d53; }
        .items-table tbody tr:nth-child(even) { background:#fafbfd; }
        .col-desc { overflow-wrap:anywhere; }
        .col-index { width:7mm; }
        .col-code { width:20mm; font-size:9px !important; overflow-wrap:anywhere; }
        .col-qty { width:11mm; font-weight:700; }
        .col-price { width:23mm; white-space:nowrap; }
        .col-discount { width:18mm; text-align:left; direction:ltr; white-space:nowrap; }
        .col-total { width:26mm; white-space:nowrap; font-weight:700; }
        .warehouse-badge { display:block; width:fit-content; margin-top:4px; padding:1px 6px; border:1px solid #7d919d; border-radius:3px; font-size:9px; font-weight:700; color:#284b60; }
        .warehouse-section td { background:#e9eff3 !important; font-size:13px !important; font-weight:700; padding:8px !important; border-top:2px solid #244b60; }
        .warehouse-section small { font-size:10px; font-weight:400; }
        .warehouse-mode .items-table td.col-qty { font-size:19px; font-weight:900; }
        .warehouse-mode .col-qty { width:18mm; }
        .warehouse-mode .col-check { width:24mm; }
        .warehouse-mode .items-table td { min-height:12mm; padding-top:9px; padding-bottom:9px; }
        .summary-table td { padding:5px 8px; border-color:#bac6ce; font-size:11px; }
        .final-row td { background:#edf2f5; font-size:13px; border-top:2px solid #244b60; }
        @media screen and (max-width:760px) { .invoice-page,.no-print { width:100%; } .invoice-page { overflow-x:auto; } .items-table { min-width:680px; } }

        .signature-section { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:8px; page-break-inside:avoid; break-inside:avoid; }
        .signature-box { height:48px; border:1px solid #374151; border-radius:6px; padding:6px; font-weight:900; }
        .customer-items caption { text-align:left; font-size:10px; padding:3px 0 6px; color:#526570; }
        .customer-items .product-heading td { background:#edf2f5; padding:8px 6px; border-top:2px solid #567180; }
        .customer-items .product-heading strong { font-size:12px; }
        .customer-items .product-models td { background:#fff; padding:6px; }
        .variant-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:0 12px; }
        .variant-detail { display:flex; flex-wrap:wrap; align-items:baseline; gap:4px 7px; padding:5px 0; border-bottom:1px solid #e5e9ed; break-inside:avoid; }
        .variant-name { flex:1; min-width:80px; overflow-wrap:anywhere; }
        .variant-count { white-space:nowrap; }
        .variant-source { font-size:9px; color:#526570; white-space:nowrap; }
        .showroom-source { color:#173d53; font-weight:700; border:1px solid #879daa; padding:0 4px; border-radius:3px; }
        .bundle-detail { flex-basis:100%; color:#526570; }
        .product-discount { padding-top:6px; text-align:left; font-size:10px; }
        .customer-items tfoot td { background:#f4f6f8; font-weight:700; }
        .collection-checkbox { display:inline-block; width:12px; height:12px; border:1px solid #506574; flex-shrink:0; align-self:center; }
        .collection-code { display:block; direction:ltr; text-align:right; font-size:8px; color:#526570; }
        .info-grid.single-info { grid-template-columns:1fr; }
        /* Restrained print layout; model quantities occupy fixed, aligned columns. */
        body { color:#202020; }
        .invoice-page { padding:6mm; }
        .invoice-header { border-color:#303030; }
        .doc-title { color:#202020; font-size:20px; }
        .doc-meta { line-height:1.8; }
        .info-box,.company-box,.customer-payment-box,.customer-balance-box,.signature-box { border-radius:0; border-color:#b0b0b0; }
        .info-title { border-bottom:1px solid #d0d0d0; }
        .info-box { padding:7px 9px; }
        .customer-items th,.customer-items td { border-color:#b5b5b5; }
        .customer-items > thead > tr > th { background:#e9ecee; color:#202020; padding:9px 6px; border-top:2px solid #30383d; border-bottom:1px solid #68727a; }
        .customer-items .product-heading td { background:#f3f4f5; border-top:1.5px solid #444; padding:9px 7px; }
        .customer-items .product-models > td { padding:0; }
        .customer-items .product-heading strong { font-size:12px; }
        .customer-items .model-schedule th,.customer-items .model-schedule td { border:0; border-bottom:1px solid #d7dcdf; border-left:1px solid #e1e5e8; padding:8px 9px; background:#fff; color:#303030; font-size:11px; line-height:1.8; }
        .customer-items .model-schedule th { background:#fafafa; color:#555; font-size:10px; font-weight:400; text-align:right; }
        .model-schedule .model-index { width:7%; text-align:center; color:#666; }
        .model-schedule .model-qty { width:11%; text-align:center; font-weight:700; font-variant-numeric:tabular-nums; }
        .model-label { overflow-wrap:anywhere; }
        .customer-items .model-schedule td.model-qty { font-size:12px; }
        .header-brand { min-width:0; }
        .header-customer { margin-top:9px; font-size:10px; }
        .header-customer .info-title { display:none; }
        .header-customer .info-row { grid-template-columns:65px 1fr; }
        .invoice-header { grid-template-columns:minmax(0,1fr) 55mm; gap:8mm; }
        .model-schedule .model-index { width:5%; }
        .model-schedule .model-qty { width:12%; }
        .model-schedule col.model-divider { width:9%; }
        .customer-items .model-schedule td.model-divider,.customer-items .model-schedule th.model-divider { padding:0; border-top:0; border-bottom:0; border-left:1px solid #aaa; border-right:1px solid #aaa; background:#fff; }
        .customer-items .model-schedule td.model-index { font-size:9px; color:#777; text-align:center; }
        .customer-items .model-schedule td.model-qty { background:#f2f2f2; color:#111; text-align:center; }
        .brand-name { font-size:16px; color:#20292f; }
        .invoice-header { padding-bottom:14px; margin-bottom:14px; border-bottom:2px solid #30383d; }
        .header-customer { margin-top:12px; line-height:1.9; }
        .header-customer .info-row { margin-bottom:3px; }
        .doc-meta { margin-top:7px; }
        .customer-items .product-models > td { padding-top:4px; padding-bottom:8px; }
        .customer-items .product-models > td:first-child { padding:0; }
        .signature-section { margin-top:14px; gap:18px; }
        .signature-box { border:0; border-top:1px solid #70777c; height:50px; padding-top:8px; font-size:10px; }
        .model-row-number { display:block; }
        .collection-checkbox { margin-top:3px; }
        .print-money { display:inline-flex; direction:rtl; align-items:baseline; gap:5px; white-space:nowrap; unicode-bidi:isolate; }
        .print-money bdi { direction:ltr; unicode-bidi:isolate; }
        .money-unit { font-size:0.85em; font-weight:400; }
        .model-schedule .showroom-source { border:0; border-radius:0; border-bottom:1px solid #333; color:#111; padding:0; margin-right:4px; font-size:9px; white-space:nowrap; }
        .bundle-detail { display:block; }
        .product-discount { padding:5px 8px; border-top:1px solid #b5b5b5; }
        .summary-table td { border-color:#b5b5b5; }
        .final-row td { border-color:#444; background:#eee; }
        .customer-balance-box { background:#fff; }
        .customer-balance-status { border:0; border-radius:0; padding:0; }
        .customer-payment-box,.company-box { font-size:10px; }
        .warehouse-mode .variant-count { font-size:14px; }
        .warehouse-mode .variant-detail { padding:7px 0; }
        @media screen and (max-width:760px) { .customer-items { min-width:0; } }
        @media print {
            body { margin:0; direction:rtl; background:#fff !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .no-print { display:none !important; }
            .invoice-page { width:auto; margin:0; padding:0; border:0; }
            a[href]:after { content:"" !important; }
            table { page-break-inside:auto; }
            tr { page-break-inside:avoid; page-break-after:auto; }
            thead { display:table-header-group; }
            tfoot { display:table-footer-group; }
            .summary-section, .company-box, .customer-balance-box, .customer-payment-box, .signature-section { page-break-inside:avoid; break-inside:avoid; }
        }
    </style>
</head>
<body class="{{ $printData['mode'] === 'customer' ? 'customer-mode' : 'warehouse-mode' }}">
@include('prints.partials.sales-invoice-template')
</body>
</html>
