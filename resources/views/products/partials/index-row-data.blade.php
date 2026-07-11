@php
    $hasVariants = $p->variants && $p->variants->count() > 0;
    $variantsId = 'productVariants' . $p->id . ($mode ?? '');
    $short = $p->short_barcode ?: ((!empty($p->code) && strlen($p->code) >= 6) ? substr($p->code, 2, 4) : null);
    $validVariantIds = $hasVariants ? $p->variants->pluck('id')->map(fn ($id) => (int) $id)->all() : [];
    $firstVar = $hasVariants ? $p->variants->sortBy('variant_code')->first() : null;
    $sampleBarcode = $firstVar?->variant_code ?: ($p->sku ?: $p->barcode);
    $isSellable = $p->is_sellable ?? true;
    $centralId = (int) ($centralWarehouseId ?? 0);
    $centralRows = $centralId > 0 ? $p->warehouseStocks->where('warehouse_id', $centralId) : collect();
    $centralStock = (int) ($p->stock ?? 0);
    if ($centralId > 0 && $centralRows->isNotEmpty()) {
        $variantCentralTotal = (int) $centralRows->whereIn('product_variant_id', $validVariantIds)->sum('quantity');
        $aggregateCentralTotal = (int) $centralRows->whereNull('product_variant_id')->sum('quantity');
        $centralStock = $variantCentralTotal > 0 || $hasVariants ? $variantCentralTotal : $aggregateCentralTotal;
    }
    $reservedQty = $hasVariants ? max(0, (int) $p->variants->sum(fn ($variant) => (int) ($variant->reserved ?? 0))) : max(0, (int) ($p->reserved ?? 0));
    $centralVariantQty = function ($variant) use ($p, $centralId) {
        if ($centralId <= 0) return (int) ($variant->stock ?? 0);
        $qty = (int) $p->warehouseStocks->where('warehouse_id', $centralId)->where('product_variant_id', (int) $variant->id)->sum('quantity');
        return max(0, $qty);
    };
    $stockRowsPayload = [];
    foreach ($p->warehouseStocks->whereIn('product_variant_id', $validVariantIds)->groupBy(fn ($row) => ((int) $row->product_variant_id) . ':' . ((int) $row->warehouse_id)) as $rows) {
        $first = $rows->first();
        $variant = $p->variants->firstWhere('id', (int) $first?->product_variant_id);
        $qty = (int) $rows->sum('quantity');
        if ($qty <= 0) continue;
        $stockRowsPayload[] = [
            'product' => $p->name,
            'variant_id' => (int) ($first?->product_variant_id ?? 0),
            'variant' => $variant?->variant_name ?: 'بدون تنوع',
            'warehouse' => $first?->warehouse?->name ?: '—',
            'qty' => $qty,
        ];
    }
    if (!$stockRowsPayload) {
        foreach ($p->warehouseStocks->whereNull('product_variant_id')->groupBy('warehouse_id') as $rows) {
            $first = $rows->first();
            $qty = (int) $rows->sum('quantity');
            if ($qty <= 0) continue;
            $stockRowsPayload[] = ['product' => $p->name, 'variant_id' => 0, 'variant' => 'کل کالا', 'warehouse' => $first?->warehouse?->name ?: '—', 'qty' => $qty];
        }
    }
    $variantsPayload = $p->variants->sortBy('variant_code')->values()->map(fn ($v) => [
        'id' => (int) $v->id,
        'name' => $v->variant_name,
        'code' => $v->variant_code,
        'sku' => $v->sku,
        'barcode' => $v->barcode,
        'central_stock' => $centralVariantQty($v),
        'sale_price' => (int) ($v->sell_price ?? 0),
        'reserved' => max(0, (int) ($v->reserved ?? 0)),
        'is_active' => (bool) $v->is_active,
    ])->all();
    $checkboxAttrs = [
        'data-edit-url' => route('products.edit', ['product' => $p, 'return_to' => request()->fullUrl()]),
        'data-delete-url' => route('products.destroy', $p),
        'data-sales-ledger-url' => route('products.sales-ledger', $p),
        'data-purchase-ledger-url' => route('products.purchase-ledger', $p),
        'data-deactivate-url' => route('product-deactivation-documents.create', ['product_id' => $p->id]),
        'data-deactivation-history-url' => route('product-deactivation-documents.index', ['product_name' => $p->name]),
        'data-warehouse-stock-url' => route('products.warehouse-stock', $p),
        'data-is-sellable' => $isSellable ? '1' : '0',
        'data-product-name' => $p->name,
        'data-reserved-qty' => $reservedQty,
        'data-stock-rows' => json_encode($stockRowsPayload, JSON_UNESCAPED_UNICODE),
    ];
@endphp

@if(($mode ?? 'desktop') === 'desktop')
<tr>
    <td class="text-center"><input type="checkbox" class="form-check-input product-checkbox" value="{{ $p->id }}" data-product-id="{{ $p->id }}" @foreach($checkboxAttrs as $attr => $value) {{ $attr }}='{{ e($value) }}' @endforeach><script type="application/json" id="product-variants-{{ $p->id }}">{!! json_encode($variantsPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script></td>
    <td class="text-center">@if($hasVariants)<button class="btn btn-outline-primary btn-sm btn-mini variant-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $variantsId }}" aria-expanded="false" aria-controls="{{ $variantsId }}"><span class="variant-symbol">+</span></button>@else<span class="text-muted">—</span>@endif</td>
    <td>@if($p->image_path)<a href="{{ route('products.image', $p) }}" target="_blank" class="product-thumb" title="نمایش عکس کالا"><img src="{{ route('products.image', $p) }}" alt="عکس {{ $p->name }}"></a>@else<span class="product-thumb-placeholder" title="بدون عکس">📷</span>@endif</td>
    <td><span class="truncate fw-bold" title="{{ $p->name }}">{{ $p->name }}</span><div class="small text-muted truncate" title="{{ $p->category?->name ?? 'بدون دسته‌بندی' }}">{{ $p->category?->name ?? 'بدون دسته‌بندی' }}</div></td>
    <td class="mono"><span class="pill pill-gray">{{ $short ?: '—' }}</span></td>
    <td class="mono"><span class="truncate safe-break" title="{{ $sampleBarcode ?: '—' }}">{{ $sampleBarcode ?: '—' }}</span></td>
    <td><span class="pill {{ $centralStock === 0 ? 'pill-danger' : 'pill-success' }}">{{ $toFa($centralStock) }}</span></td>
    <td>@if($isSellable)<span class="sellable-badge active">قابل فروش</span>@else<span class="sellable-badge inactive">غیرفعال</span>@endif</td>
    <td><span class="price-inline">{{ $money($p->price) }}</span></td>
</tr>
@if($hasVariants)
<tr class="collapse" id="{{ $variantsId }}"><td colspan="9"><div class="details-box"><div class="table-responsive"><table class="table table-sm align-middle mb-0 variant-table"><thead><tr><th>تنوع / مدل / طرح / رنگ</th><th>کد / بارکد / SKU</th><th>موجودی مرکزی</th><th>قیمت فروش</th><th>وضعیت</th><th>نقشه انبار</th><th>انتخاب</th></tr></thead><tbody>@foreach($p->variants->sortBy('variant_code') as $v)<tr class="variant-row-selectable" role="button" data-product-id="{{ $p->id }}" data-variant-id="{{ $v->id }}"><td class="fw-bold">{{ $v->variant_name }}</td><td class="mono safe-break">{{ $v->variant_code ?: ($v->sku ?: $v->barcode) }}</td><td><span class="pill {{ $centralVariantQty($v) === 0 ? 'pill-danger' : 'pill-success' }}">{{ $toFa($centralVariantQty($v)) }}</span></td><td>{{ $money($v->sell_price) }}</td><td>{{ !$v->is_active ? 'غیرفعال' : (($v->sales_enabled ?? true) ? 'فعال برای فروش' : 'غیرفعال برای فروش') }}</td><td><span class="text-muted">از نقشه انبار</span></td><td><button class="btn btn-outline-primary btn-sm btn-mini select-variant-btn" type="button" data-product-id="{{ $p->id }}" data-variant-id="{{ $v->id }}">انتخاب این تنوع</button></td></tr>@endforeach</tbody></table></div></div></td></tr>
@endif
@else
<div class="mobile-card">
    <div class="mobile-card-top">
        @if($p->image_path)<a href="{{ route('products.image', $p) }}" target="_blank" class="product-thumb"><img src="{{ route('products.image', $p) }}" alt="عکس {{ $p->name }}"></a>@else<span class="product-thumb-placeholder">📷</span>@endif
        <div class="min-w-0"><div class="mobile-title truncate" title="{{ $p->name }}">{{ $p->name }}</div>@if($isSellable)<span class="sellable-badge active">قابل فروش</span>@else<span class="sellable-badge inactive">غیرفعال</span>@endif</div>
        <input type="checkbox" class="form-check-input product-checkbox" value="{{ $p->id }}" data-product-id="{{ $p->id }}" @foreach($checkboxAttrs as $attr => $value) {{ $attr }}='{{ e($value) }}' @endforeach>
    </div>
    <div class="mobile-meta"><span>کد: <span class="mono">{{ $short ?: '—' }}</span></span><span class="safe-break">بارکد: <span class="mono">{{ $sampleBarcode ?: '—' }}</span></span></div>
    <div class="mobile-values"><span>موجودی مرکزی: <b>{{ $toFa($centralStock) }}</b></span><span>قیمت فروش: <b>{{ $money($p->price) }}</b></span></div>
    <div class="mobile-actions"><button class="btn btn-outline-primary btn-mini mobile-variant-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $variantsId }}">مشاهده تنوع‌ها</button></div>
    <div class="collapse" id="{{ $variantsId }}"><div class="details-box">@if($hasVariants)<div class="variant-list">@foreach($p->variants->sortBy('variant_code') as $v)<div class="variant-row variant-row-selectable" role="button" data-product-id="{{ $p->id }}" data-variant-id="{{ $v->id }}"><b>{{ $v->variant_name }}</b><br><span class="mono safe-break">{{ $v->variant_code ?: ($v->sku ?: $v->barcode) }}</span><br>موجودی مرکزی: {{ $toFa($centralVariantQty($v)) }} | فروش: {{ $money($v->sell_price) }} | {{ !$v->is_active ? 'غیرفعال' : (($v->sales_enabled ?? true) ? 'فعال برای فروش' : 'غیرفعال برای فروش') }}<br><span class="text-muted">نقشه انبار: از نقشه انبار</span><div class="mt-2"><button class="btn btn-outline-primary btn-sm btn-mini select-variant-btn" type="button" data-product-id="{{ $p->id }}" data-variant-id="{{ $v->id }}">انتخاب این تنوع</button></div></div>@endforeach</div>@else<div class="text-muted small">این کالا تنوع ثبت‌شده‌ای ندارد.</div>@endif</div></div>
</div>
@endif
