<div class="catalog-result-header">
    <div>
        <h2>لیست محصولات</h2>
        <p>محصولات و تنوع‌های قابل چاپ مطابق فیلترها</p>
    </div>
    <span>{{ number_format($products->total()) }} محصول</span>
</div>

@if($products->isEmpty())
    <div class="catalog-empty">محصولی با این فیلترها پیدا نشد.</div>
@else
    <div class="catalog-table-wrap">
        <table class="table catalog-products-table">
            <thead><tr><th>ردیف</th><th>تصویر</th><th>نام محصول</th><th>قیمت</th></tr></thead>
            <tbody>
            @foreach($products as $row)
                <tr class="product-row catalog-product">
                    <td data-label="ردیف">{{ ($products->firstItem() ?? 1) + $loop->index }}</td>
                    <td data-label="تصویر"><img class="catalog-thumb" src="{{ $row['image_url'] }}" alt="{{ $row['name'] }}" loading="lazy"></td>
                    <td data-label="نام محصول"><strong>{{ $row['name'] }}</strong><small>{{ $row['category_name'] }}</small></td>
                    <td data-label="قیمت"><span class="catalog-price">{{ $row['price_label'] }}</span></td>
                </tr>
                <tr class="variants-row">
                    <td colspan="4">
                        <div class="catalog-variants">
                            @forelse($row['variants'] as $variant)
                                <div class="catalog-variant"><span>تنوع: {{ $variant['name'] }} <em>{{ $variant['model_list_name'] }}</em></span><strong>{{ $variant['price_label'] }}</strong></div>
                            @empty
                                <div class="catalog-variant catalog-variant--empty">بدون تنوع قابل نمایش</div>
                            @endforelse
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="catalog-pagination">{{ $products->links() }}</div>
@endif
