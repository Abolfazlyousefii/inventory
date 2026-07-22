<div class="clean-price-list">
@if($products->count())
@foreach($products as $product)
@php $isLarge = count($product['groups']) > 4 || collect($product['groups'])->contains(fn($group) => count($group['colors']) > 24 || count($group['models']) > 25); @endphp
<section class="product-price-table {{ $isLarge ? 'product-price-table--large' : 'product-price-table--small' }}">
<header class="product-header-row price-list-product-header"><div class="product-header-main">@if($product['has_real_image'])<img src="{{ $product['image_path'] }}" alt="{{ $product['name'] }}" class="price-list-product-image">@endif<div class="price-list-product-info"><h3>{{ $product['name'] }}</h3><p>{{ $product['category_name'] }} | {{ $product['model_count'] }} مدل | {{ $product['color_count'] }} رنگ | {{ $product['variant_count'] }} تنوع</p></div></div><strong class="product-summary-price">{{ $product['price_summary'] }}</strong></header>
<div class="column-header-row price-list-detail-head"><span>مدل‌های سازگار</span><span>رنگ‌های قابل سفارش</span><span>قیمت</span></div>
@foreach($product['groups'] as $group)<div class="price-list-detail-row"><div class="price-list-models" data-label="مدل‌های سازگار">@foreach($group['models'] as $model)<span dir="ltr" class="model-token">{{ $model }}</span>{{ $loop->last ? '' : '، ' }}@endforeach</div><div class="price-list-colors" data-label="رنگ‌های قابل سفارش">@include('product-exports.partials.color-list', ['colors'=>$group['colors']])</div><div class="price-list-price {{ str_starts_with($group['price_label'], 'از ') ? 'price-list-price--range' : '' }}" data-label="قیمت">{{ $group['price_label'] }}</div></div>@endforeach
</section>
@endforeach
@else <div class="catalog-empty">محصولی برای فیلترهای انتخاب‌شده پیدا نشد.</div> @endif
</div>
