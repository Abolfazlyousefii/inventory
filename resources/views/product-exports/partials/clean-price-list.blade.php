<div class="clean-price-list">
@if($products->count())
@foreach($products as $product)
<section class="price-list-product {{ count($product['groups']) <= 4 ? 'price-list-product--small' : 'price-list-product--large' }}">
<header class="price-list-product-header">@if($product['has_real_image'])<img src="{{ $product['image_path'] }}" alt="{{ $product['name'] }}" class="price-list-product-image">@endif<div class="price-list-product-info"><h3>{{ $product['name'] }}</h3><p>{{ $product['category_name'] }} | {{ $product['model_count'] }} مدل | {{ $product['color_count'] }} رنگ | {{ $product['variant_count'] }} تنوع</p></div><strong>{{ $product['price_summary'] }}</strong></header>
<div class="price-list-detail-head"><span>مدل‌های سازگار</span><span>رنگ‌های قابل سفارش</span><span>قیمت</span></div>
@foreach($product['groups'] as $group)<div class="price-list-detail-row"><div class="price-list-models" data-label="مدل‌ها">@foreach($group['models'] as $model)<span class="ltr">{{ $model }}</span>{{ $loop->last ? '' : '، ' }}@endforeach</div><div class="price-list-colors" data-label="رنگ‌ها">@include('product-exports.partials.color-list', ['colors'=>$group['colors'], 'columns'=>$group['color_columns']])</div><div class="price-list-price" data-label="قیمت">{{ $group['price_label'] }}</div></div>@endforeach
</section>
@endforeach
@else <div class="catalog-empty">محصولی برای فیلترهای انتخاب‌شده پیدا نشد.</div> @endif
</div>
