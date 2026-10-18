<td class="asd-date" data-label="تاریخ سند">
    <span>{{ \App\Support\JalaliDate::date($payment?->paid_at ?: $ledger->created_at) }}</span>
    @if($payment)
        <small>ثبت در سامانه: {{ \App\Support\JalaliDate::dateTime($ledger->created_at) }}</small>
    @else
        <small>{{ $ledger->created_at?->format('H:i') }}</small>
    @endif
</td>
