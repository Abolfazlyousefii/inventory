@foreach($invoices as $invoice)
@php($meta = $invoice->live_meta)
<article class="invoice-mobile-card">
    @if($canReassignSeller ?? false)<label class="form-check"><input class="form-check-input" type="checkbox" name="invoice_ids[]" value="{{ $meta['id'] }}" form="bulkSellerForm"> انتخاب برای تغییر گروهی</label>@endif
    <div class="invoice-mobile-card__top"><div><strong>{{ $meta['number'] }}</strong><small>{{ $meta['date'] }}</small></div><span class="invoice-badge invoice-badge--{{ $meta['status_tone'] }}">{{ $meta['status_label'] }}</span></div>
    <h3>{{ $meta['customer_name'] }}</h3><small>{{ $meta['customer_mobile'] }} · فروشنده: {{ $meta['seller'] }}</small>
    <div class="invoice-mobile-card__money"><span>کل <strong>{{ $meta['total'] }}</strong></span><span>دریافت <strong class="text-success">{{ $meta['paid'] }}</strong></span><span>مانده <strong class="{{ $meta['remaining_value'] ? 'text-danger' : 'text-success' }}">{{ $meta['remaining'] }}</strong></span></div>
    <div class="invoice-warnings">@foreach($meta['warnings'] as $warning)<span class="invoice-badge invoice-badge--{{ $warning['tone'] }}">{{ $warning['label'] }}</span>@endforeach</div>
    <div class="invoice-mobile-card__actions">@include('invoices.partials.actions', ['meta' => $meta])</div>
</article>
@endforeach
