<div class="catalog-result-header"><h2>لیست محصولات</h2><span>{{ number_format($products->total()) }} محصول</span></div>
@if($products->isEmpty())
    <div class="catalog-empty">محصولی با این فیلترها پیدا نشد.</div>
@else
    <div class="catalog-table-wrap">
        <table class="catalog-table catalog-products-table">
            <thead><tr><th>ردیف</th><th>تصویر</th><th>نام محصول / تنوع</th><th>قیمت</th></tr></thead>
            @foreach($products as $row)
                <tbody class="catalog-product-group">
                    <tr class="catalog-product-row">
                        <td>{{ ($products->firstItem() ?? 1) + $loop->index }}</td>
                        <td><img class="catalog-thumb" src="{{ $row['image_url'] }}" alt="{{ $row['name'] }}" loading="lazy"></td>
                        <td><strong>{{ $row['name'] }}</strong></td>
                        <td><span class="catalog-price">{{ $row['price_label'] }}</span></td>
                    </tr>
                    @foreach($row['variants'] as $variant)
                        <tr class="catalog-variant-row">
                            <td></td><td></td><td><span class="variant-indent">↳ {{ $variant['name'] }}</span></td><td><span class="catalog-price">{{ $variant['price_label'] }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            @endforeach
        </table>
    </div>
    <div class="catalog-pagination">{{ $products->links() }}</div>
@endif
