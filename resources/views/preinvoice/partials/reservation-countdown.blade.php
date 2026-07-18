@php
  $timerOrder = $order ?? $o ?? null;
  $expires = $expiresAt ?? $expires_at ?? $timerOrder?->stock_frozen_until;
  $server = $serverNow ?? $server_now ?? now();
  $remaining = $remainingSeconds ?? $remaining_seconds ?? ($expires ? max(0, $server->diffInSeconds($expires, false)) : null);
  $expired = $isExpired ?? $is_expired ?? ($timerOrder?->status === \App\Models\PreinvoiceOrder::STATUS_RESERVATION_EXPIRED || ($expires && $expires->lte($server)));
@endphp
@include('preinvoice.partials.reservation-timer', [
  'order' => $timerOrder,
  'expiresAt' => $expires,
  'serverNow' => $server,
  'remainingSeconds' => $remaining,
  'isExpired' => $expired,
  'compact' => $compact ?? false,
])
