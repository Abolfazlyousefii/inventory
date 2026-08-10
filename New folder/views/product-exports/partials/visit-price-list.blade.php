<div class="visit-price-list">
@if($products->count())
@foreach($products as $product)
<section class="visit-product-card">
    <header class="visit-product-header">
        <div class="visit-product-main">
            @if($product['has_real_image'])<img src="{{ $product['image_path'] }}" alt="{{ $product['name'] }}" class="visit-product-image">@endif
            <div class="visit-product-info">
                <h3>{{ $product['name'] }}</h3>
                <p>{{ $product['category_name'] }} | {{ $product['variant_count'] ?: 'بدون' }} تنوع | موجودی: {{ number_format($product['total_stock']) }}</p>
            </div>
        </div>
        <strong class="visit-product-price-summary">{{ $product['price_summary'] }}</strong>
    </header>
    <div class="visit-table-head"><span>ردیف</span><span>مدل / تنوع</span><span>رنگ / طرح</span><span>کد تنوع</span><span>موجودی</span><span>قیمت</span></div>
    @foreach($product['variants'] as $variant)
    <div class="visit-table-row">
        <div data-label="ردیف">{{ $loop->iteration }}</div>
        <div data-label="مدل / تنوع" class="visit-model">{{ $variant['model'] }}</div>
        <div data-label="رنگ / طرح">{{ $variant['color'] }}</div>
        <div data-label="کد تنوع" class="visit-code" dir="ltr">{{ $variant['code'] }}</div>
        <div data-label="موجودی" class="visit-stock {{ $variant['stock'] <= 0 ? 'visit-stock--zero' : '' }}">{{ $variant['stock'] > 0 ? number_format($variant['stock']) : 'ناموجود' }}</div>
        <div data-label="قیمت" class="visit-price">{{ $variant['price_label'] }}</div>
    </div>
    @endforeach
</section>
@endforeach
@else
<div class="catalog-empty">محصول یا تنوع قابل فروشی برای فیلترهای انتخاب‌شده پیدا نشد.</div>
@endif
</div>
