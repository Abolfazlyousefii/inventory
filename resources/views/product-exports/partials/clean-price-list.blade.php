<div class="clean-price-list pe-table-wrap">
@if($products->count())
<table class="pe-price-table">
    <colgroup><col style="width: 46%"><col style="width: 38%"><col style="width: 16%"></colgroup>
    <thead><tr><th>مدل‌های سازگار</th><th>رنگ‌های قابل سفارش</th><th>قیمت</th></tr></thead>
    @foreach($products as $product)
        <tbody class="pe-product-group">
            <tr class="pe-product-header"><td colspan="2"><div class="pe-product-title-wrap">@if($product['has_real_image'])<span class="pe-product-image"><img src="{{ $product['image_path'] }}" alt="" loading="lazy"></span>@endif<div><h3>{{ $product['name'] }}</h3><p>{{ $product['category_name'] }} | {{ $product['model_count'] }} مدل | {{ $product['color_count'] }} رنگ | {{ $product['variant_count'] }} تنوع</p></div></div></td><td class="pe-product-summary-price">{{ $product['price_summary'] }}</td></tr>
            @foreach($product['groups'] as $group)
                <tr class="pe-product-detail"><td class="pe-models-cell" data-label="مدل‌های سازگار">@foreach($group['models'] as $model)<span class="pe-model-token" dir="ltr">{{ $model }}</span>{{ $loop->last ? '' : '، ' }}@endforeach</td><td class="pe-colors-cell" data-label="رنگ‌های قابل سفارش">@include('product-exports.partials.preview-color-list', ['colors' => $group['colors']])</td><td class="pe-price-cell {{ str_starts_with($group['price_label'], 'از ') ? 'pe-price-cell--range' : '' }}" data-label="قیمت">{{ $group['price_label'] }}</td></tr>
            @endforeach
        </tbody>
    @endforeach
</table>
@else <div class="pe-empty-state">محصولی برای فیلترهای انتخاب‌شده پیدا نشد.</div> @endif
</div>
