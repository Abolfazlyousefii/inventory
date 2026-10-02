<div class="clean-price-list catalog-card-list">
@forelse($products as $product)
<article class="catalog-card">
    <header class="catalog-card__head">
        @if($product['has_real_image'])
            <div class="catalog-card__photo"><img src="{{ $product['image_path'] }}" alt="{{ $product['name'] }}" loading="lazy"></div>
        @endif
        <div class="catalog-card__intro">
            <span class="catalog-card__category">{{ $product['category_name'] }}</span>
            <h3>{{ $product['name'] }}</h3>
            <p>{{ $product['model_count'] }} مدل <span aria-hidden="true">·</span> {{ $product['color_count'] }} رنگ <span aria-hidden="true">·</span> {{ $product['variant_count'] }} تنوع</p>
        </div>
        <div class="catalog-card__price"><span>قیمت</span><strong>{{ $product['price_summary'] }}</strong></div>
    </header>
    <details class="catalog-card__details">
        <summary>مدل‌ها، رنگ‌ها و قیمت‌های هر گروه <span>{{ count($product['groups']) }} گروه</span></summary>
        <div class="catalog-card__groups">
            @foreach($product['groups'] as $group)
                <div class="catalog-card__group">
                    <div class="catalog-card__models"><small>مدل‌های سازگار</small>@foreach($group['models'] as $model)<span dir="ltr" class="model-token">{{ $model }}</span>{{ $loop->last ? '' : '، ' }}@endforeach</div>
                    <div class="catalog-card__colors"><small>رنگ‌ها</small>@include('product-exports.partials.catalog-colors', ['colors' => $group['colors']])</div>
                    <strong class="catalog-card__group-price">{{ $group['price_label'] }}</strong>
                </div>
            @endforeach
        </div>
    </details>
</article>
@empty
<div class="catalog-empty">محصولی برای فیلترهای انتخاب‌شده پیدا نشد.</div>
@endforelse
</div>
