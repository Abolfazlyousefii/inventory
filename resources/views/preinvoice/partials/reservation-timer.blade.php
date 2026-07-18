@php
  $timerOrder = $order ?? $o ?? null;
  $expires = $expiresAt ?? $timerOrder?->stock_frozen_until;
  $expiresIso = $expires ? $expires->toIso8601String() : null;
  $remaining = $remainingSeconds ?? ($expires ? max(0, now()->diffInSeconds($expires, false)) : null);
  $expired = $isExpired ?? ($timerOrder?->status === \App\Models\PreinvoiceOrder::STATUS_RESERVATION_EXPIRED || ($expires && $expires->isPast()));
  $label = $expired ? 'منقضی‌شده' : ($expiresIso ? 'فعال' : 'بدون محدودیت زمانی');
@endphp
<div class="reservation-box {{ !empty($compact) ? 'reservation-box--compact' : '' }}" data-reservation-timer data-expires-at="{{ $expiresIso }}" data-server-now="{{ ($serverNow ?? now())->toIso8601String() }}" data-total-seconds="{{ $remaining ?? 0 }}" data-label="{{ $label }}">
  <span class="badge {{ $expired ? 'text-bg-danger' : 'text-bg-success' }} reservation-status">{{ $label }}</span>
  <div class="small fq-muted">زمان باقی‌مانده رزرو:</div>
  <div class="reservation-countdown">{{ $expiresIso ? '—' : $label }}</div>
  @if($expiresIso)<div class="timer-progress"><span style="width:100%"></span></div>@endif
</div>
