@if(($filters['output_mode'] ?? 'catalog') === 'price_list')
    @include('product-exports.partials.price-list', ['products' => $products])
@else
    @include('product-exports.partials.catalog-card-list', ['products' => $products])
@endif
