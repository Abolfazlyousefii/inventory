@php($expiresIso = $order->stock_frozen_until?->toIso8601String())
<span class="fq-timer" data-simple-timer data-expires-at="{{ $expiresIso }}"><span data-timer-value class="fq-timer-value {{ $expiresIso ? 'fq-green' : '' }}">{{ $expiresIso ? '—' : 'بدون انقضا' }}</span><span class="fq-timer-label">زمان باقی‌مانده رزرو</span></span>
