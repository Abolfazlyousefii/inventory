<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $meta['title'] }} - آریا گستر</title>
    <style>
        @font-face {
            font-family: "Vazirmatn";
            src: url("{{ asset('fonts/vazirmatn/Vazirmatn-Regular.woff2') }}") format("woff2");
            font-weight: 400;
            font-style: normal;
        }
        @font-face {
            font-family: "Vazirmatn";
            src: url("{{ asset('fonts/vazirmatn/Vazirmatn-Bold.woff2') }}") format("woff2");
            font-weight: 700;
            font-style: normal;
        }
        :root { color-scheme: light; --ink:#17354b; --muted:#667784; --line:#cddae2; --soft:#eef5f8; --brand:#173a53; --accent:#2879a8; }
        * { box-sizing: border-box; }
        html { direction: rtl; }
        body { margin: 0; background: #f3f6f8; color: var(--ink); font-family: "Vazirmatn", "Vazir", Tahoma, Arial, sans-serif; font-size: 10px; }
        .print-toolbar { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 16px; color: #fff; background: var(--brand); box-shadow: 0 2px 10px rgba(15, 35, 50, .2); }
        .print-toolbar__title { font-size: 14px; font-weight: 700; }
        .print-toolbar__actions { display: flex; gap: 8px; }
        .print-toolbar button, .print-toolbar a { display: inline-flex; align-items: center; justify-content: center; min-height: 34px; padding: 6px 14px; border: 1px solid rgba(255,255,255,.55); border-radius: 4px; color: #fff; background: transparent; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .print-toolbar .print-toolbar__primary { border-color: #fff; color: var(--brand); background: #fff; }
        .print-document { width: min(100%, 1120px); margin: 14px auto; padding: 12px; background: #fff; box-shadow: 0 2px 16px rgba(22,53,79,.08); }
        .document-header { margin-bottom: 10px; border-bottom: 2px solid var(--accent); }
        .document-heading { display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 10px; padding: 5px 0 8px; }
        .document-brand { font-size: 14px; font-weight: 700; }
        .document-heading h1 { margin: 0; font-size: 16px; text-align: center; }
        .document-date { color: var(--muted); text-align: left; }
        .document-meta { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); border-top: 1px solid var(--line); }
        .document-meta div { min-width: 0; padding: 5px 7px; border-left: 1px solid var(--line); border-bottom: 1px solid var(--line); }
        .document-meta div:nth-child(4n) { border-left: 0; }
        .document-meta span { display: block; color: var(--muted); font-size: 8px; }
        .document-meta strong { display: block; margin-top: 1px; font-size: 9px; overflow-wrap: anywhere; }
        .print-product { width: 100%; table-layout: fixed; margin: 0 0 8px; border-collapse: collapse; border: 1px solid var(--line); }
        .print-product thead { display: table-header-group; }
        .print-product tfoot { display: table-footer-group; }
        .print-product tr { break-inside: avoid; page-break-inside: avoid; }
        .print-product th, .print-product td { padding: 4px 6px; border-left: 1px solid #d8e2e8; border-bottom: 1px solid #e2e9ed; vertical-align: middle; }
        .print-product th:last-child, .print-product td:last-child { border-left: 0; }
        .product-title-row { break-after: avoid; page-break-after: avoid; }
        .product-title-row th { padding: 5px 7px; background: var(--soft); border-top: 1.5px solid var(--brand); text-align: right; }
        .product-heading { display: flex; align-items: center; gap: 7px; }
        .product-image { width: 34px; height: 34px; flex: 0 0 auto; object-fit: contain; }
        .product-name { display: block; font-size: 11px; font-weight: 700; }
        .product-meta { display: block; margin-top: 1px; color: var(--muted); font-size: 8px; font-weight: 400; }
        .product-summary { color: var(--accent); text-align: center !important; white-space: nowrap; }
        .column-heading th { padding: 4px 5px; color: #fff; background: var(--brand); font-size: 9px; text-align: center; }
        .data-row:nth-child(even) td { background: #fafcfd; }
        .row-number, .stock, .price, .code { text-align: center; }
        .model, .color { text-align: right; }
        .code { direction: ltr; unicode-bidi: isolate; font-size: 8px; }
        .stock, .price { font-weight: 700; white-space: nowrap; }
        .stock-zero { color: #6b7280; }
        .model-token { display: inline-block; direction: ltr; unicode-bidi: isolate; white-space: nowrap; }
        .color-dot { display: inline-block; width: 5px; height: 5px; margin-left: 3px; border: 1px solid #94a3b8; }
        .colors-grid { width: 100%; table-layout: fixed; border-collapse: collapse; font-size: 8px; }
        .colors-grid td { width: 33.33%; padding: 1px 3px !important; border: 0 !important; background: transparent !important; text-align: right; }
        .catalog-models { direction: rtl; unicode-bidi: plaintext; text-align: right; }
        .catalog-colors { text-align: right; }
        .catalog-price { text-align: center; font-weight: 700; white-space: nowrap; }
        .print-product--small { break-inside: avoid; page-break-inside: avoid; }
        .print-product--large { break-inside: auto; page-break-inside: auto; }
        .empty-result { padding: 30px; border: 1px solid var(--line); color: var(--muted); text-align: center; }
        .document-footer { margin-top: 10px; padding-top: 5px; border-top: 1px solid var(--line); color: var(--muted); font-size: 8px; text-align: center; }
        @page { size: A4 landscape; margin: 8mm; }
        @media print {
            body { background: #fff; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
            .print-toolbar { display: none !important; }
            .print-document { width: 100%; margin: 0; padding: 0; box-shadow: none; }
        }
        @media screen and (max-width: 760px) {
            .print-toolbar { align-items: flex-start; flex-direction: column; }
            .document-heading { grid-template-columns: 1fr; text-align: center; }
            .document-date { text-align: center; }
            .document-meta { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .document-meta div:nth-child(4n) { border-left: 1px solid var(--line); }
            .document-meta div:nth-child(2n) { border-left: 0; }
            .print-document { overflow-x: auto; }
            .print-product { min-width: 720px; }
        }
    </style>
</head>
<body class="print-output print-output--{{ $meta['output_mode'] }}">
    <nav class="print-toolbar" aria-label="ابزارهای چاپ">
        <div class="print-toolbar__title">لیست قیمت و موجودی آریا گستر</div>
        <div class="print-toolbar__actions">
            <button class="print-toolbar__primary" type="button" onclick="window.print()">چاپ / ذخیره PDF</button>
            <button type="button" onclick="return backToProductExports()">بازگشت</button>
        </div>
    </nav>

    <main class="print-document">
        <header class="document-header">
            <div class="document-heading">
                <div class="document-brand">آریا گستر</div>
                <h1>{{ $meta['title'] }}</h1>
                <div class="document-date">تاریخ بروزرسانی: {{ $meta['generated_at'] }}</div>
            </div>
            <div class="document-meta">
                <div><span>دسته اصلی</span><strong>{{ $meta['root_category'] }}</strong></div>
                <div><span>زیردسته</span><strong>{{ $meta['subcategory'] }}</strong></div>
                <div><span>نوع مدل</span><strong>{{ $meta['model_brand'] }}</strong></div>
                <div><span>مدل‌های انتخابی</span><strong>{{ $meta['model_lists'] }}</strong></div>
                <div><span>محصولات انتخابی</span><strong>{{ $meta['selected_products'] }}</strong></div>
                <div><span>وضعیت موجودی</span><strong>{{ $meta['stock_status'] }}</strong></div>
                <div><span>نوع خروجی</span><strong>{{ $meta['output_mode'] === 'visit' ? 'ویزیتوری' : 'کاتالوگی' }}</strong></div>
                <div><span>تعداد محصولات خروجی</span><strong>{{ number_format($meta['products_count']) }}</strong></div>
            </div>
        </header>

        @forelse($products as $product)
            @if($meta['output_mode'] === 'visit')
                <table class="print-product visit-print-table {{ count($product['variants']) <= 12 ? 'print-product--small' : 'print-product--large' }}">
                    <colgroup><col style="width:5%"><col style="width:29%"><col style="width:17%"><col style="width:17%"><col style="width:10%"><col style="width:22%"></colgroup>
                    <thead>
                        <tr class="product-title-row">
                            <th colspan="5">
                                <div class="product-heading">
                                    @if($product['has_real_image'])
                                        <img class="product-image" src="{{ route('products.image', ['product' => $product['id']]) }}" alt="{{ $product['name'] }}">
                                    @endif
                                    <span><span class="product-name">{{ $product['name'] }}</span><span class="product-meta">دسته: {{ $product['category_name'] }} | {{ $product['variant_count'] ?: 'بدون' }} تنوع | موجودی کل: {{ number_format($product['total_stock']) }}</span></span>
                                </div>
                            </th>
                            <th class="product-summary">{{ $product['price_summary'] }}</th>
                        </tr>
                        <tr class="column-heading"><th>ردیف</th><th>مدل / تنوع</th><th>رنگ / طرح</th><th>کد تنوع</th><th>موجودی</th><th>قیمت</th></tr>
                    </thead>
                    <tbody>
                        @foreach($product['variants'] as $variant)
                            <tr class="data-row visit-variant-row">
                                <td class="row-number">{{ $loop->iteration }}</td>
                                <td class="model">{{ $variant['model'] }}</td>
                                <td class="color">{{ $variant['color'] }}</td>
                                <td class="code">{{ $variant['code'] }}</td>
                                <td class="stock {{ $variant['stock'] <= 0 ? 'stock-zero' : '' }}">{{ $variant['stock'] > 0 ? number_format($variant['stock']) : 'ناموجود' }}</td>
                                <td class="price">{{ $variant['price_label'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                @php $isLarge = count($product['groups']) > 4 || collect($product['groups'])->contains(fn ($group) => count($group['colors']) > 24 || count($group['models']) > 25); @endphp
                <table class="print-product catalog-print-table {{ $isLarge ? 'print-product--large' : 'print-product--small' }}">
                    <colgroup><col style="width:46%"><col style="width:38%"><col style="width:16%"></colgroup>
                    <thead>
                        <tr class="product-title-row">
                            <th colspan="2">
                                <div class="product-heading">
                                    @if($product['has_real_image'])
                                        <img class="product-image" src="{{ route('products.image', ['product' => $product['id']]) }}" alt="{{ $product['name'] }}">
                                    @endif
                                    <span><span class="product-name">{{ $product['name'] }}</span><span class="product-meta">دسته: {{ $product['category_name'] }} | {{ $product['model_count'] }} مدل | {{ $product['color_count'] }} رنگ | {{ $product['variant_count'] }} تنوع</span></span>
                                </div>
                            </th>
                            <th class="product-summary">{{ $product['price_summary'] }}</th>
                        </tr>
                        <tr class="column-heading"><th>مدل‌های سازگار</th><th>رنگ‌های قابل سفارش</th><th>قیمت</th></tr>
                    </thead>
                    <tbody>
                        @foreach($product['groups'] as $group)
                            <tr class="data-row catalog-group-row">
                                <td class="catalog-models">@foreach($group['models'] as $model)<span class="model-token">{{ $model }}</span>{{ $loop->last ? '' : '، ' }}@endforeach</td>
                                <td class="catalog-colors">@include('product-exports.partials.color-list', ['colors' => $group['colors']])</td>
                                <td class="catalog-price">{{ $group['price_label'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @empty
            <div class="empty-result">محصولی مطابق فیلترهای انتخاب‌شده برای چاپ پیدا نشد.</div>
        @endforelse

        <footer class="document-footer">قیمت و موجودی براساس آخرین اطلاعات ثبت‌شده در سامانه است.</footer>
    </main>

    <script>
        function backToProductExports() {
            try {
                if (document.referrer && new URL(document.referrer).origin === window.location.origin) {
                    history.back();
                    return false;
                }
            } catch (error) {}

            window.location.href = @json(route('admin.product-exports.index'));
            return false;
        }
    </script>
</body>
</html>
