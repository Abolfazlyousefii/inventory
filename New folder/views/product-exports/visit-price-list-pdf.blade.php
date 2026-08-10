<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<style>
body,table,th,td{font-family:{{ $fontFamily }},dejavusans,sans-serif}
body{direction:rtl;color:#16354F;font-size:7.7pt;font-weight:400}
.visit-product-table{width:100%;table-layout:fixed;border-collapse:collapse;border:0.65px solid #CBD8E0;margin:0 0 2.2mm 0;page-break-inside:auto}
.visit-product-table thead{display:table-header-group}
.visit-product-table th,.visit-product-table td{border-left:0.5px solid #D7E2E8;border-bottom:0.45px solid #E2E9ED;padding:4px 5px;vertical-align:middle}
.visit-product-table th:last-child,.visit-product-table td:last-child{border-left:0}
.visit-product-header th{background:#EDF5F8;border-top:1px solid #173A53;padding:5px 7px;text-align:right}
.visit-product-image{width:28px;height:28px;object-fit:contain;vertical-align:middle;margin-left:5px}
.visit-product-title{font-size:9.3pt;font-weight:700;color:#183747}
.visit-product-meta{font-size:6.7pt;color:#71828D;margin-top:1px}
.visit-summary{font-size:7.8pt;font-weight:700;color:#2879A8;text-align:center!important;white-space:nowrap}
.visit-columns th{background:#173A53;color:#fff;font-size:7.3pt;font-weight:700;text-align:center;padding:4px 4px}
.visit-row td{background:#fff;line-height:1.45}
.visit-row:nth-child(even) td{background:#FAFCFD}
.visit-row-number{text-align:center;color:#71828D}
.visit-model{text-align:right;direction:rtl;unicode-bidi:plaintext}
.visit-color{text-align:right}
.visit-code{text-align:center;direction:ltr;unicode-bidi:embed;font-size:7pt}
.visit-stock{text-align:center;font-weight:700;white-space:nowrap}
.visit-stock-zero{font-weight:700;color:#6B7280}
.visit-price{text-align:center;font-weight:700;white-space:nowrap;font-size:7.7pt}
</style>
</head>
<body>
@foreach($products as $product)
<table class="visit-product-table">
<colgroup>
    <col style="width:5%"><col style="width:29%"><col style="width:17%"><col style="width:17%"><col style="width:10%"><col style="width:22%">
</colgroup>
<thead>
<tr class="visit-product-header">
    <th colspan="5">
        @if($product['has_real_image'])<img class="visit-product-image" src="{{ $product['image_path'] }}" alt="{{ $product['name'] }}">@endif
        <span class="visit-product-title">{{ $product['name'] }}</span>
        <div class="visit-product-meta">{{ $product['category_name'] }} | {{ $product['variant_count'] ?: 'بدون' }} تنوع | موجودی کل: {{ number_format($product['total_stock']) }}</div>
    </th>
    <th class="visit-summary">{{ $product['price_summary'] }}</th>
</tr>
<tr class="visit-columns"><th>ردیف</th><th>مدل / تنوع</th><th>رنگ / طرح</th><th>کد تنوع</th><th>موجودی</th><th>قیمت</th></tr>
</thead>
<tbody>
@foreach($product['variants'] as $variant)
<tr class="visit-row">
    <td class="visit-row-number">{{ $loop->iteration }}</td>
    <td class="visit-model">{{ $variant['model'] }}</td>
    <td class="visit-color">{{ $variant['color'] }}</td>
    <td class="visit-code">{{ $variant['code'] }}</td>
    <td class="visit-stock {{ $variant['stock'] <= 0 ? 'visit-stock-zero' : '' }}">{{ $variant['stock'] > 0 ? number_format($variant['stock']) : 'ناموجود' }}</td>
    <td class="visit-price">{{ $variant['price_label'] }}</td>
</tr>
@endforeach
</tbody>
</table>
@endforeach
</body>
</html>
