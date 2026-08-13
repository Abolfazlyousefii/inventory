@if(($filters['output_mode'] ?? 'visit') === 'visit')
    @include('product-exports.partials.visit-price-list', ['products' => $products])
@else
    @include('product-exports.partials.clean-price-list', ['products' => $products])
@endif
@if(method_exists($products, 'links'))<div class="catalog-pagination">{{ $products->links() }}</div>@endif
