@php
    $total = method_exists($products, 'total') ? $products->total() : $products->count();
    $first = method_exists($products, 'firstItem') ? ($products->firstItem() ?? 0) : ($products->count() ? 1 : 0);
    $last = method_exists($products, 'lastItem') ? ($products->lastItem() ?? 0) : $products->count();
@endphp
<div class="pe-results-header"><div><h2>پیش‌نمایش لیست قیمت</h2><p>{{ number_format($total) }} محصول براساس فیلترهای انتخاب‌شده</p></div><div class="pe-results-header__meta">نمایش {{ number_format($first) }} تا {{ number_format($last) }} از {{ number_format($total) }}</div></div>
@include('product-exports.partials.clean-price-list', ['products' => $products])
@if(method_exists($products, 'links'))<div class="pe-results-footer"><div>نمایش {{ number_format($first) }} تا {{ number_format($last) }} از {{ number_format($total) }}</div><div class="pe-pagination">{{ $products->links() }}</div></div>@endif
