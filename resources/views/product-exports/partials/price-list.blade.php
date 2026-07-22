<div class="catalog-result-header"><h2>لیست قیمت تفصیلی</h2><span>{{ number_format($products->total()) }} محصول</span></div>
@if($products->isEmpty())
    <div class="catalog-empty">محصولی با این فیلترها پیدا نشد.</div>
@else
<div class="catalog-table-wrap"><table class="catalog-table price-list-table"><thead><tr><th>ردیف</th><th>تصویر</th><th>محصول</th><th>مدل</th><th>رنگ‌ها یا طرح‌ها</th><th>قیمت</th></tr></thead><tbody>
@php $rowNumber = $products->firstItem() ?? 1; @endphp
@foreach($products as $product)
    @foreach($product['price_list_rows'] as $row)
        <tr class="price-list-row">
            <td>{{ $rowNumber++ }}</td>
            @if($loop->first)<td rowspan="{{ max(1, count($product['price_list_rows'])) }}"><img class="catalog-thumb" src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}"></td><td rowspan="{{ max(1, count($product['price_list_rows'])) }}"><strong>{{ $product['name'] }}</strong><br><small>{{ $product['category_name'] }}</small></td>@endif
            <td>{{ $row['model'] }}</td><td>{{ $row['colors_label'] ?: 'بدون رنگ' }}</td><td><span class="catalog-price">{{ $row['price_label'] }}</span></td>
        </tr>
    @endforeach
@endforeach
</tbody></table></div><div class="catalog-pagination">{{ $products->links() }}</div>
@endif
