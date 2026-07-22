@if(count($colors) === 0)
    بدون رنگ
@elseif(count($colors) <= 12)
    @foreach($colors as $color)@if($color['hex'])<span class="color-dot" style="background:{{ $color['hex'] }}"></span>@endif{{ $color['name'] }}{{ $loop->last ? '' : '، ' }}@endforeach
@else
    <table class="colors-table"><tr>@foreach($columns as $column)<td>@foreach($column as $color)<div>@if($color['hex'])<span class="color-dot" style="background:{{ $color['hex'] }}"></span>@endif{{ $color['name'] }}</div>@endforeach</td>@endforeach</tr></table>
@endif
