@forelse($colors as $color)
<span class="catalog-color">@if($color['hex'])<i class="catalog-color__dot" style="background:{{ $color['hex'] }}"></i>@endif{{ $color['name'] }}</span>
@empty
<span class="catalog-color catalog-color--empty">—</span>
@endforelse
