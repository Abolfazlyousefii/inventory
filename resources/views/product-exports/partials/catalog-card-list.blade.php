<div class="catalog-result-header"><h2>کارت‌های کاتالوگ مشتری</h2><span>{{ number_format($products->total()) }} محصول</span></div>
@if($products->isEmpty())
    <div class="catalog-empty">محصولی با این فیلترها پیدا نشد.</div>
@else
    <div class="catalog-card-grid">
        @foreach($products as $row)
            <article class="catalog-card {{ $row['is_wide'] ? 'catalog-card--wide catalog-card--large' : '' }}">
                <header class="catalog-card-head">
                    <img class="catalog-card-image" src="{{ $row['image_url'] }}" alt="{{ $row['name'] }}" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='grid'">
                    <span class="catalog-card-placeholder" style="display:none">▣</span>
                    <div><h3>{{ $row['name'] }}</h3><p>{{ $row['category_name'] }}</p><strong>{{ $row['price_summary'] }}</strong><small>{{ $row['model_count'] }} مدل | {{ $row['color_count'] }} رنگ</small></div>
                </header>
                <div class="catalog-card-body">
                    @foreach($row['catalog_groups'] as $group)
                        <section class="catalog-group"><b>{{ $group['price_label'] }}</b><p><span>مدل‌ها:</span> {{ implode('، ', $group['models']) }}</p><div><span>رنگ‌ها:</span> @forelse($group['colors'] as $color)<em class="color-badge">@if($color['hex'])<i style="background:{{ $color['hex'] }}"></i>@endif{{ $color['name'] }}</em>@empty<span class="muted">بدون رنگ</span>@endforelse</div></section>
                    @endforeach
                </div>
                <footer>{{ $row['model_count'] }} مدل، {{ $row['color_count'] }} رنگ، {{ $row['variant_count'] }} تنوع</footer>
            </article>
        @endforeach
    </div>
    <div class="catalog-pagination">{{ $products->links() }}</div>
@endif
