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
        .col-discount-unit,.col-discount-total { width:18mm; text-align:left; direction:ltr; white-space:nowrap; }
        .col-total { width:26mm; white-space:nowrap; font-weight:700; }
        .warehouse-badge { display:block; width:fit-content; margin-top:4px; padding:1px 6px; border:1px solid #7d919d; border-radius:3px; font-size:9px; font-weight:700; color:#284b60; }
        .warehouse-section td { background:#e9eff3 !important; font-size:13px !important; font-weight:700; padding:8px !important; border-top:2px solid #244b60; }
        .warehouse-section small { font-size:10px; font-weight:400; }
        .warehouse-mode .items-table td.col-qty { font-size:19px; font-weight:900; }
        .warehouse-mode .col-qty { width:18mm; }
        .warehouse-mode .col-check { width:24mm; }
        .warehouse-mode .items-table td { min-height:12mm; padding-top:9px; padding-bottom:9px; }
        /* A4 warehouse invoice now contains sale prices as well as picking details. */
        .warehouse-mode.warehouse-priced .col-qty { width:11mm; }
        .warehouse-mode.warehouse-priced .items-table td.col-qty { font-size:15px; }
        .warehouse-mode.warehouse-priced .items-table td { padding:6px 5px; }
        .warehouse-mode.warehouse-priced .product-discount { background:#f8fafc; font-weight:700; }
        .warehouse-mode.warehouse-priced .warehouse-items-summary { margin-top:12px; }
        .summary-table td { padding:5px 8px; border-color:#bac6ce; font-size:11px; }
        .final-row td { background:#edf2f5; font-size:13px; border-top:2px solid #244b60; }
        @media screen and (max-width:760px) { .invoice-page,.no-print { width:100%; } .invoice-page { overflow-x:auto; } .items-table { min-width:680px; } }

        .signature-section { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:8px; page-break-inside:avoid; break-inside:avoid; }
        .signature-box { height:48px; border:1px solid #374151; border-radius:6px; padding:6px; font-weight:900; }
        /* Readable row-based invoice layout with restrained print contrast. */
        body { color:#303840; }
        .invoice-page { width:194mm; padding:5mm 4mm; }
        .no-print { width:194mm; }
        /* Compact invoice header: shared frame, no equal-height cards. */
        .invoice-header {
            display:block;
            border:1px solid #d7dfe5;
            border-radius:6px;
            background:#fff;
            padding:0;
            margin-bottom:10px;
            overflow:visible;
        }
        .header-top {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            padding:6px 10px;
            border-bottom:1px solid #e5ebef;
            background:#fafbfd;
        }
        .header-top .brand { min-width:0; gap:8px; }
        .header-top .brand img { width:38px; max-height:40px; flex-shrink:0; }
        .brand-name { color:#263746; font-size:15px; line-height:1.5; }
        .brand-phone { color:#667681; font-size:10px; line-height:1.4; }
        .doc-title { color:#263746; font-size:18px; line-height:1.4; text-align:left; margin:0; white-space:nowrap; }
        .header-details {
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            align-items:start;
        }
        .header-info { min-width:0; padding:6px 10px 7px; page-break-inside:avoid; break-inside:avoid; }
        .header-info + .header-info { border-right:1px solid #e5ebef; }
        .header-info .info-title {
            display:block; padding:0; margin:0 0 4px; border:0;
            color:#62727e; font-size:10px; font-weight:700; line-height:1.4;
        }
        .header-customer { margin:0; font-size:10.5px; line-height:1.5; }
        .header-customer .info-row {
            grid-template-columns:72px minmax(0,1fr);
            align-items:baseline; gap:5px; margin-bottom:2px;
        }
        .header-customer .info-row:last-child { margin-bottom:0; }
        .header-customer .info-row > :last-child { min-width:0; overflow-wrap:anywhere; }
        .header-customer .label { color:#63727d; font-weight:600; }
        .doc-meta {
            display:flex; flex-direction:column; gap:2px; margin:0;
            color:#394956; font-size:10.5px; line-height:1.5; text-align:right;
        }
        .doc-meta-row {
            display:grid; grid-template-columns:max-content minmax(0,1fr);
            align-items:baseline; gap:5px;
        }
        .meta-label { color:#63727d; font-weight:600; }
        .meta-value { min-width:0; overflow-wrap:anywhere; font-weight:700; }
        @media screen and (max-width:560px) {
            .header-details { grid-template-columns:1fr; }
            .header-info + .header-info { border-right:0; border-top:1px solid #e5ebef; }
        }
        .info-box,.company-box,.customer-payment-box,.customer-balance-box,.signature-box {
            border-color:#d7dde2; border-radius:5px;
        }
        .info-box { padding:7px 9px; }
        .info-title { border-bottom:1px solid #e1e6ea; }
        .customer-items { margin-top:8px; }
        .customer-items caption { text-align:left; font-size:10px; padding:4px 0 7px; color:#6b7680; }
        .print-pricing-formula { display:inline-block; margin-inline-start:9px; color:#73808a; font-size:10px; }
        .customer-items th,.customer-items td { border-color:#d9dfe4; }
        .customer-items > thead > tr > th {
            background:#f3f5f7; color:#3c4852; padding:7px 5px;
            border-top:1px solid #cbd3da; border-bottom:1px solid #cbd3da;
            font-size:11px; font-weight:700;
        }
        .customer-items .product-heading td {
            background:#f8f9fa; border-top:1px solid #cbd3da;
            border-bottom:1px solid #e0e5e9; padding:7px 8px;
        }
        .product-heading-content { display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .customer-items .product-heading strong { color:#303b44; font-size:12px; font-weight:700; }
        .product-summary { color:#566875; font-size:10px; font-weight:700; white-space:nowrap; }
        .customer-items .variant-row td {
            background:#fff; color:#303840; border-bottom:1px solid #e5e9ed;
            padding:7px 5px; font-size:11px; line-height:1.6;
        }
        .customer-items .variant-row:nth-child(even) td { background:#fcfdfe; }
        .col-index { width:7mm; text-align:center; }
        .col-desc { overflow-wrap:anywhere; }
        .col-code { width:16mm; text-align:center; direction:ltr; unicode-bidi:embed; font-size:10px !important; overflow-wrap:anywhere; }
        .col-qty { width:10mm; text-align:center; font-weight:700; font-variant-numeric:tabular-nums; }
        .col-price { width:21mm; text-align:left; direction:ltr; unicode-bidi:embed; white-space:nowrap; font-variant-numeric:tabular-nums; }
        .col-discount-unit { width:18mm; text-align:left; direction:ltr; unicode-bidi:embed; white-space:nowrap; color:#65717b !important; font-variant-numeric:tabular-nums; }
        .col-discount-total { width:18mm; text-align:left; direction:ltr; unicode-bidi:embed; white-space:nowrap; color:#53616e !important; font-variant-numeric:tabular-nums; }
        .col-total { width:28mm; text-align:left; direction:ltr; unicode-bidi:embed; white-space:nowrap; font-weight:700; font-variant-numeric:tabular-nums; }
        .col-check { width:12mm; text-align:center; }
        .approximate-discount { white-space:nowrap; }
        .customer-items .variant-row td.col-discount-unit,
        .customer-items .variant-row td.col-discount-total { font-size:10.5px; }
        .model-row-number { display:block; color:#77818a; font-size:10px; }
        .variant-name { overflow-wrap:anywhere; }
        .showroom-source {
            display:inline-block; color:#596975; background:#f8fafb; border:1px solid #d8e0e5;
            border-radius:3px; padding:0 4px; margin-right:4px; font-size:9px; font-weight:600;
            white-space:nowrap;
        }
        .bundle-detail { display:block; margin-top:3px; color:#74808a; font-size:10px; }
        .collection-checkbox { display:inline-block; width:11px; height:11px; border:1px solid #aeb9c1; border-radius:2px; vertical-align:middle; }
        .customer-items tfoot td { background:#f4f6f8; border-color:#d5dce2; font-size:11px; font-weight:700; padding:8px 5px; }
        .warehouse-mode .items-table td.col-qty { font-size:12px; font-weight:700; }
        .warehouse-mode .col-qty { width:10mm; }
        .warehouse-mode .col-check { width:12mm; }
        .summary-table td { border-color:#d9dfe4; padding:7px 8px; font-size:11px; }
        .final-row td { background:#f1f4f6; border-color:#cbd3da; border-top:1px solid #cbd3da; color:#263746; font-size:13px; }
        .signature-section { margin-top:14px; gap:18px; }
        .signature-box { border:0; border-top:1px solid #cbd3da; height:50px; padding-top:8px; font-size:10px; }
        .print-money { display:inline-flex; direction:rtl; align-items:baseline; gap:5px; white-space:nowrap; unicode-bidi:isolate; }
        .print-money bdi { direction:ltr; unicode-bidi:isolate; }
        .money-unit { font-size:0.85em; font-weight:400; }
        .customer-balance-box { background:#fff; }
        .customer-balance-status { border-color:#d7dde2; }
        .customer-payment-box,.company-box { font-size:10px; }
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
            .summary-section, .company-box, .customer-balance-box, .customer-payment-box, .signature-section, .invoice-header { page-break-inside:avoid; break-inside:avoid; }
        }
        /* Persian headings wrap independently from the left-aligned numeric cells. */
        .items-table thead th {
            white-space:normal; direction:rtl; unicode-bidi:normal;
            text-align:center; line-height:1.65; vertical-align:middle;
            overflow-wrap:normal; padding:7px 5px;
        }
        .customer-mode .items-table .col-discount-total { width:24mm; }
        .customer-mode .items-table thead .col-discount-total { font-size:10px; }
    </style>
</head>
<body class="{{ $printData['mode'] === 'customer' ? 'customer-mode' : 'warehouse-mode' }}{{ $printData['mode'] !== 'customer' && ($printData['documentType'] ?? null) === 'invoice' ? ' warehouse-priced' : '' }}">
@include('prints.partials.sales-invoice-template')
</body>
</html>
