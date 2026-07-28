@foreach($invoices as $invoice)
@php($meta = $invoice->live_meta)
<tr>
    <td><strong class="invoice-code">{{ $meta['number'] }}</strong><small>{{ $meta['date'] }}</small>@if($meta['preinvoice'])<small>پیش‌فاکتور: {{ $meta['preinvoice'] }}</small>@endif</td>
    <td><strong title="{{ $meta['customer_name'] }}">{{ $meta['customer_name'] }}</strong><small>{{ $meta['customer_mobile'] }} · کد {{ $meta['customer_code'] }}</small></td>
    <td>{{ $meta['seller'] }}</td>
    <td><span class="invoice-badge invoice-badge--{{ $meta['status_tone'] }}">{{ $meta['status_label'] }}</span>@if($meta['legacy']) <span class="invoice-badge invoice-badge--secondary">قدیمی</span>@endif<br><span class="invoice-badge invoice-badge--{{ $meta['payment_tone'] }}">{{ $meta['payment_label'] }}</span><div class="invoice-warnings">@foreach($meta['warnings'] as $warning)<span class="invoice-badge invoice-badge--{{ $warning['tone'] }}">{{ $warning['label'] }}</span>@endforeach</div></td>
    <td><small>کل: <strong>{{ $meta['total'] }}</strong></small><small class="text-success">دریافت: {{ $meta['paid'] }}</small><small class="{{ $meta['remaining_value'] ? 'text-danger' : 'text-success' }}">مانده: {{ $meta['remaining'] }}</small></td>
    <td class="text-end">@include('invoices.partials.actions', ['meta' => $meta])</td>
</tr>
@endforeach
