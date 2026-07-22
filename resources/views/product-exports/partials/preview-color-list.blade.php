@if(count($colors) === 0)
    -
@elseif(count($colors) <= 12)
    @foreach($colors as $color)@if($color['hex'])<span class="color-dot" style="background:{{ $color['hex'] }}"></span>@endif{{ $color['name'] }}{{ $loop->last ? '' : '، ' }}@endforeach
@else
    @php $previewColors = array_slice($colors, 0, 12); @endphp
    <div class="pe-colors-preview" data-collapsed-label="نمایش همه {{ count($colors) }} رنگ" data-expanded-label="نمایش کمتر">
        <div class="pe-colors-grid pe-colors-grid--preview">@foreach(array_chunk($previewColors, 3) as $row)<div class="pe-colors-grid__row">@for($i=0;$i<3;$i++)<span>@if(isset($row[$i]))@if($row[$i]['hex'])<i class="color-dot" style="background:{{ $row[$i]['hex'] }}"></i>@endif{{ $row[$i]['name'] }}@endif</span>@endfor</div>@endforeach</div>
        <div class="pe-colors-grid pe-colors-grid--all" hidden>@foreach(array_chunk($colors, 3) as $row)<div class="pe-colors-grid__row">@for($i=0;$i<3;$i++)<span>@if(isset($row[$i]))@if($row[$i]['hex'])<i class="color-dot" style="background:{{ $row[$i]['hex'] }}"></i>@endif{{ $row[$i]['name'] }}@endif</span>@endfor</div>@endforeach</div>
        <button type="button" class="pe-colors-toggle">نمایش همه {{ count($colors) }} رنگ</button>
    </div>
@endif
