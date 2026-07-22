@if(count($colors) === 0)
    -
@elseif(count($colors) <= 12)
    @foreach($colors as $color)@if($color['hex'])<span class="color-dot" style="background:{{ $color['hex'] }}"></span>@endif{{ $color['name'] }}{{ $loop->last ? '' : '، ' }}@endforeach
@else
    @php $rows = array_chunk($colors, 3); @endphp
    <table class="colors-grid {{ count($colors) > 30 ? 'colors-grid--dense' : '' }}">
        @foreach($rows as $row)
            <tr>
                @for($i = 0; $i < 3; $i++)
                    <td>@if(isset($row[$i]))@if($row[$i]['hex'])<span class="color-dot" style="background:{{ $row[$i]['hex'] }}"></span>@endif{{ $row[$i]['name'] }}@endif</td>
                @endfor
            </tr>
        @endforeach
    </table>
@endif
