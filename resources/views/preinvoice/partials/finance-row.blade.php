@php
  $rial = fn($v) => number_format((int) $v) . ' ریال';
  $fmtDate = fn($d) => $d ? Morilog\Jalali\Jalalian::fromDateTime($d)->format('Y/m/d H:i') : '—';
  $expired = (bool) $o->getAttribute('finance_reservation_expired');
  $seconds = $o->getAttribute('finance_seconds_remaining');
  $label = $o->getAttribute('finance_reservation_label');
  $expiresIso = $o->stock_frozen_until?->toIso8601String();
@endphp
@if(!($mobile ?? false))
<tr data-reservation-row class="{{ $expired ? 'table-danger' : '' }}"><td><a class="fw-bold" href="{{ route('preinvoice.draft.finance',$o->uuid) }}">{{ $o->uuid }}</a><div>{{ $o->customer_name ?: '—' }}</div><div class="small fq-muted">{{ $o->customer_mobile ?: 'بدون موبایل' }}</div></td><td>{{ $o->creator?->name ?? '—' }}</td><td>{{ number_format($o->items->sum('quantity')) }}</td><td>{{ $rial($o->total_price) }}</td><td class="small text-break">{{ Str::limit($o->payment_terms_note ?: '—',70) }}</td><td>{{ $fmtDate($o->created_at) }}</td><td>@include('preinvoice.partials.reservation-timer',['o'=>$o])</td><td class="text-end">@include('preinvoice.partials.finance-actions',['o'=>$o,'isExpired'=>$expired])</td></tr>
@else
<div class="doc-mobile {{ $expired ? 'border-danger' : '' }}" data-reservation-row><div class="d-flex justify-content-between gap-2"><a class="fw-bold" href="{{ route('preinvoice.draft.finance',$o->uuid) }}">{{ $o->uuid }}</a><span>{{ $rial($o->total_price) }}</span></div><div class="small fq-muted">{{ $o->customer_name ?: '—' }} | {{ $o->customer_mobile ?: 'بدون موبایل' }}</div><div class="doc-grid my-2"><div><span class="label">فروشنده</span>{{ $o->creator?->name ?? '—' }}</div><div><span class="label">اقلام</span>{{ number_format($o->items->sum('quantity')) }}</div><div><span class="label">ثبت</span>{{ $fmtDate($o->created_at) }}</div><div><span class="label">شرایط پرداخت</span>{{ Str::limit($o->payment_terms_note ?: '—',50) }}</div></div>@include('preinvoice.partials.reservation-timer',['o'=>$o])<div class="mt-2">@include('preinvoice.partials.finance-actions',['o'=>$o,'isExpired'=>$expired])</div></div>
@endif
