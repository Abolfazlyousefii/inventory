@include('product-exports.partials.clean-price-list', ['products' => $products])
@if(method_exists($products, 'links'))<div class="catalog-pagination">{{ $products->links() }}</div>@endif
