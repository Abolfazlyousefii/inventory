@props([
    'customer',
    'compact' => false,
])

@php
    $status = $customer->balance_status;
    $label = $customer->balance_status_label;
    $amount = $customer->balance_amount;
    $badgeClass = match ($status) {
        'debtor' => 'bg-danger-subtle text-danger-emphasis border border-danger-subtle',
        'creditor' => 'bg-success-subtle text-success-emphasis border border-success-subtle',
        default => 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
    };
@endphp

@if($compact)
    <span class="badge {{ $badgeClass }}">
        {{ $label }}: {{ number_format($amount) }} تومان
    </span>
@else
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <div class="text-muted small mb-1">وضعیت حساب / کیف پول مشتری</div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge {{ $badgeClass }} fs-6">{{ $label }}</span>
                    <strong class="fs-5">{{ number_format($amount) }} تومان</strong>
                </div>
            </div>

            <div class="text-muted small">
                @if($status === 'debtor')
                    مشتری {{ number_format($amount) }} تومان بدهکار است.
                @elseif($status === 'creditor')
                    مشتری {{ number_format($amount) }} تومان بستانکار است.
                @else
                    حساب مشتری تسویه است.
                @endif
            </div>
        </div>
    </div>
@endif
