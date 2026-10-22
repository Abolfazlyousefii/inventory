@php
    $displayBundleParts = (isset($snapshot) || !empty($productIsBundle)) ? \App\Support\BundleContentsDisplay::rows(
        $snapshot ?? null,
        isset($variantId) ? (int) $variantId : null,
        isset($productId) ? (int) $productId : null
    ) : [];
    $displayBundleCount = max(1, (int) ($bundleQuantity ?? 1));
@endphp
@if(count($displayBundleParts))
    <div class="bundle-components" dir="rtl" aria-label="محتویات بسته" style="margin-top:6px;display:flex;align-items:center;flex-wrap:wrap;gap:5px 6px;font-size:11px;line-height:1.5;text-align:right;">
        <span style="color:#64748b;font-weight:700;white-space:nowrap;">شامل:</span>
        @foreach($displayBundleParts as $part)
            <span class="bundle-component-chip" title="{{ $part['name'] }}@if($part['variant'] !== '' && $part['variant'] !== $part['name']) — {{ $part['variant'] }}@endif · {{ $part['quantity'] }} عدد در هر بسته" style="display:inline-flex;align-items:center;gap:5px;border:1px solid #bfdbfe;background:#eff6ff;color:#1e40af;padding:4px 9px;border-radius:999px;max-width:100%;white-space:normal;overflow-wrap:anywhere;">
                <span>{{ $part['name'] }}@if($part['variant'] !== '' && $part['variant'] !== $part['name']) <span style="color:#64748b">({{ $part['variant'] }})</span>@endif</span>
                <strong style="white-space:nowrap;direction:ltr;unicode-bidi:isolate;">× {{ number_format($part['quantity']) }}</strong>
                @if($displayBundleCount > 1)<small style="white-space:nowrap;color:#64748b">(جمع {{ number_format($part['quantity'] * $displayBundleCount) }})</small>@endif
            </span>
        @endforeach
    </div>
@endif
