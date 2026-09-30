<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        body, table, th, td { font-family: {{ $fontFamily }}, dejavusans, sans-serif; }
        body { direction: rtl; color: #17344c; font-size: 8pt; }
        .heading { width: 100%; border-bottom: 1px solid #c9dce5; padding-bottom: 7px; margin-bottom: 9px; }
        .heading td { border: 0; }
        .heading-title { color: #14354d; font-size: 13pt; font-weight: bold; }
        .heading-date { color: #6b8190; text-align: left; font-size: 8pt; }
        .intro { color: #658091; margin-bottom: 9px; }
        .card { width: 100%; border: 1px solid #d4e2e9; border-collapse: collapse; margin-bottom: 10px; }
        .card-head { background: #f1f8fa; border-bottom: 1px solid #d4e2e9; padding: 7px; }
        .card-head-table { width: 100%; border-collapse: collapse; }
        .photo-cell { width: 65px; vertical-align: middle; }
        .photo { width: 55px; height: 55px; object-fit: contain; border: 1px solid #d4e2e9; background: white; }
        .photo-empty { width: 55px; height: 55px; line-height: 55px; text-align: center; border: 1px solid #d4e2e9; color: #8ca0aa; background: white; font-size: 7pt; }
        .name { font-weight: bold; font-size: 10pt; color: #17344c; }
        .meta { color: #738a98; margin-top: 3px; font-size: 7pt; }
        .variants { width: 100%; border-collapse: collapse; }
        .variants th { background: #e6f1f5; color: #24516b; font-size: 7.3pt; padding: 5px 7px; text-align: right; }
        .variants td { border-top: 1px solid #e8eff2; padding: 5px 7px; vertical-align: top; }
        .variants tr:nth-child(even) td { background: #fbfdfe; }
        .model { width: 44%; }
        .color { width: 21%; }
        .stock { width: 14%; white-space: nowrap; }
        .price { width: 21%; white-space: nowrap; font-weight: bold; color: #08785f; }
        .empty { color: #7f919c; padding: 9px; }
        .footer { color: #8295a0; font-size: 7pt; border-top: 1px solid #d4e2e9; padding-top: 6px; }
    </style>
</head>
<body>
    <table class="heading"><tr><td class="heading-title">لیست کالاهای انتخاب‌شده</td><td class="heading-date">آریا گستر · {{ now()->format('Y/m/d') }}</td></tr></table>
    <div class="intro">{{ count($products) }} کالا · قیمت و موجودی بر اساس آخرین اطلاعات ثبت‌شده</div>

    @foreach($products as $product)
        <table class="card">
            <thead>
            <tr><th colspan="4" class="card-head">
                <table class="card-head-table"><tr>
                    <td class="photo-cell">
                        @if($product['image_data'])<img class="photo" src="{{ $product['image_data'] }}" alt="">
                        @else<div class="photo-empty">بدون عکس</div>@endif
                    </td>
                    <td><div class="name">{{ $product['name'] }}</div><div class="meta">{{ $product['category_name'] }} · {{ count($product['variants']) }} تنوع فعال</div></td>
                </tr></table>
            </th></tr>
            <tr class="variants"><th class="model">مدل / تنوع</th><th class="color">رنگ / طرح</th><th class="stock">موجودی</th><th class="price">قیمت هر تنوع</th></tr>
            </thead>
            <tbody class="variants">
            @forelse($product['variants'] as $variant)
                <tr>
                    <td class="model">{{ $variant['model'] }}</td>
                    <td class="color">{{ $variant['color'] }}</td>
                    <td class="stock">{{ $variant['stock'] > 0 ? number_format($variant['stock']) : 'ناموجود' }}</td>
                    <td class="price">{{ $variant['price_label'] }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">این کالا تنوع فعالی برای نمایش ندارد.</td></tr>
            @endforelse
            </tbody>
        </table>
    @endforeach
    <div class="footer">قیمت و موجودی ممکن است پس از تهیهٔ این فایل تغییر کنند.</div>
</body>
</html>
