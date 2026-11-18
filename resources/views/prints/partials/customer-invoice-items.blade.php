<table class="items-table customer-items">
    <caption>
        {{ $isWarehouseMode ? ($isWarehouseInvoice ? 'نسخه انبار — کنترل جمع‌آوری · مبالغ به ریال' : 'نسخه انبار — کنترل جمع‌آوری') : 'تمام مبالغ به ریال' }}
        @if($showProductPrices)<span class="print-pricing-formula">مبلغ نهایی ردیف = تعداد × قیمت واحد − تخفیف کل</span>@endif
    </caption>
    @php($columnCount = $isWarehouseMode ? ($showProductPrices ? 9 : 5) : 7)
    @php($variantRowNumber = 0)
    <thead>
        <tr>
            <th class="col-index">ردیف</th>
            <th class="col-desc">مدل / رنگ</th>
            @if($isWarehouseMode)<th class="col-code">کد انبار</th>@endif
            <th class="col-qty">تعداد</th>
            @if($showProductPrices)
                <th class="col-price">قیمت واحد</th>
                <th class="col-discount-unit">تخفیف واحد</th>
                <th class="col-discount-total">تخفیف کل</th>
                <th class="col-total">مبلغ نهایی</th>
            @endif
            @if($isWarehouseMode)<th class="col-check">کنترل انبار</th>@endif
        </tr>
    </thead>
    @forelse($printData['customerGroups'] as $group)
        <tbody class="customer-product-group">
            <tr class="product-heading">
                <td colspan="{{ $columnCount }}">
                    <div class="product-heading-content">
                        <strong>{{ $fa($group['name']) }}</strong>
                        <span class="product-summary">جمع تعداد: {{ $num($group['quantity']) }}</span>
                    </div>
                </td>
            </tr>
            @foreach($group['items'] as $item)
                @php($variantRowNumber++)
                <tr class="variant-row">
                    <td class="col-index"><span class="model-row-number">{{ $num($variantRowNumber) }}</span></td>
                    <td class="col-desc">
                        <span class="variant-name">{{ $fa($item['variantDescription']) }}</span>
                        @if($item['sourceWarehouseId'])
                            <strong class="showroom-source">شوروم</strong>
                        @endif
                        @foreach($item['bundle_contents'] as $part)
                            <small class="bundle-detail">{{ $fa($part['name']) }}@if($part['variant'] && $part['variant'] !== $part['name']) — {{ $fa($part['variant']) }}@endif × {{ $num($part['quantity']) }} در هر بسته</small>
                        @endforeach
                    </td>
                    @if($isWarehouseMode)
                        <td class="col-code">{{ $item['inventoryCode'] !== '—' ? $fa($item['inventoryCode']) : '—' }}</td>
                    @endif
                    <td class="col-qty">{{ $num($item['quantity']) }}</td>
                    @if($showProductPrices)
                        <td class="col-price">{{ $num($item['unitPrice']) }}</td>
                        <td class="col-discount-unit">
                            @if($item['lineDiscount'] > 0)
                                @if($item['unitDiscountApproximate'])
                                    <span class="approximate-discount" title="تخفیف واحد تقریبی است؛ مبلغ دقیق در ستون تخفیف کل آمده است" aria-label="تخفیف واحد تقریبی">≈{{ $num($item['unitDiscount']) }}</span>
                                @else
                                    {{ $num($item['unitDiscount']) }}
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td class="col-discount-total">@if($item['lineDiscount'] > 0){{ $num($item['lineDiscount']) }}@else — @endif</td>
                        <td class="col-total">{{ $num($item['lineTotal']) }}</td>
                    @endif
                    @if($isWarehouseMode)
                        <td class="col-check"><span class="collection-checkbox" aria-label="کنترل جمع‌آوری"></span></td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    @empty
        <tbody><tr><td colspan="{{ $columnCount }}">آیتمی ثبت نشده است.</td></tr></tbody>
    @endforelse
    <tfoot>
        <tr>
            <td></td>
            <td>{{ $showProductPrices ? 'جمع کالاها پس از تخفیف کالا' : 'جمع تعداد اقلام' }}</td>
            @if($isWarehouseMode)<td></td>@endif
            <td class="col-qty">{{ $num($totalQuantity) }}</td>
            @if($showProductPrices)
                <td></td>
                <td></td>
                <td class="col-discount-total">{{ $num($printData['totals']['itemsDiscount']) }}</td>
                <td class="col-total">{{ $num($totalLineAmount) }}</td>
            @endif
            @if($isWarehouseMode)<td></td>@endif
        </tr>
    </tfoot>
</table>