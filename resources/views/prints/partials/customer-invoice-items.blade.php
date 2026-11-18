<table class="items-table customer-items">
    <caption>{{ $isWarehouseMode ? 'نسخه انبار — کنترل جمع‌آوری' : 'تمام مبلغ‌ها به ریال' }}</caption>
    <thead><tr><th class="col-index">ردیف</th><th>شرح کالا و مدل‌ها</th><th class="col-qty">تعداد</th>@unless($isWarehouseMode)<th class="col-price">قیمت واحد</th><th class="col-total">مبلغ خالص</th>@endunless</tr></thead>
    @forelse($printData['customerGroups'] as $group)
        <tbody class="customer-product-group">
            <tr class="product-heading">
                <td class="col-index">{{ $num($loop->iteration) }}</td>
                <td><strong>{{ $fa($group['name']) }}</strong></td>
                <td class="col-qty">{{ $num($group['quantity']) }}</td>
                @unless($isWarehouseMode)<td class="col-price">{{ $num($group['unitPrice']) }}</td>
                <td class="col-total">{{ $num($group['total']) }}</td>@endunless
            </tr>
            @foreach($group['items']->chunk(12) as $modelRows)
            @php
                $columns = $modelRows->values()->split(2)->map->values();
                $firstModelNumber = $modelRows->keys()->first() + 1;
            @endphp
            <tr class="product-models"><td></td><td colspan="{{ $isWarehouseMode ? 2 : 4 }}">
                <table class="model-schedule">
                    <colgroup><col class="model-index"><col><col class="model-qty"><col class="model-divider"><col class="model-index"><col><col class="model-qty"></colgroup>
                    <thead><tr><th>ردیف</th><th>مدل / رنگ</th><th class="model-qty">تعداد</th><th class="model-divider" aria-hidden="true"></th><th>ردیف</th><th>مدل / رنگ</th><th class="model-qty">تعداد</th></tr></thead>
                    <tbody>
                    @for($modelRow = 0; $modelRow < $columns->first()->count(); $modelRow++)
                        <tr>
                            @for($column = 0; $column < 2; $column++)
                                @if($column === 1)<td class="model-divider" aria-hidden="true"></td>@endif
                                @php($item = $columns->get($column, collect())->get($modelRow))
                                @if($item)
                                    <td class="model-index"><span class="model-row-number">{{ $num($firstModelNumber + ($column === 1 ? $columns->first()->count() : 0) + $modelRow) }}</span>@if($isWarehouseMode)<span class="collection-checkbox" aria-label="کنترل جمع‌آوری"></span>@endif</td>
                                    <td class="model-label">{{ $fa($item['variantDescription']) }}@if($item['sourceWarehouseId']) <strong class="showroom-source">شوروم</strong>@endif
                                        @if($isWarehouseMode && $item['inventoryCode'] !== '—')<small class="collection-code">{{ $fa($item['inventoryCode']) }}</small>@endif
                                        @foreach($item['bundle_contents'] as $part)
                                            <small class="bundle-detail">{{ $fa($part['name']) }}@if($part['variant'] && $part['variant'] !== $part['name']) — {{ $fa($part['variant']) }}@endif × {{ $num($part['quantity']) }} در هر بسته</small>
                                        @endforeach
                                    </td>
                                    <td class="model-qty"><strong>{{ $num($item['quantity']) }}</strong></td>
                                @else
                                    <td></td><td></td><td></td>
                                @endif
                            @endfor
                        </tr>
                    @endfor
                    </tbody>
                </table>
                @if(!$isWarehouseMode && $loop->last && $group['discount'] > 0)<div class="product-discount">تخفیف این کالا: <strong>{{ $money($group['discount']) }}</strong></div>@endif
            </td></tr>
            @endforeach
        </tbody>
    @empty
        <tbody><tr><td colspan="{{ $isWarehouseMode ? 3 : 5 }}">آیتمی ثبت نشده است.</td></tr></tbody>
    @endforelse
    <tfoot><tr><td></td><td>{{ $isWarehouseMode ? 'جمع تعداد اقلام' : 'جمع کالاها پس از تخفیف کالا' }}</td><td class="col-qty">{{ $num($totalQuantity) }}</td>@unless($isWarehouseMode)<td></td><td class="col-total">{{ $num($totalLineAmount) }}</td>@endunless</tr></tfoot>
</table>
