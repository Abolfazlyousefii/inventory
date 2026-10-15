@php
    $financeActor = auth()->user();
    $canManagePayment = $financeActor && ($financeActor->hasAnyRole(['admin','Admin','Manager','manager','finance','Accountant']) || $financeActor->can('finance.approve'));
@endphp
@if($canManagePayment && $payment->customer_id && \App\Support\PageAccessCatalog::userCanRoute($financeActor, 'account-statements.payments.edit'))
@if($compactPaymentLink ?? false)
<a class="asd-payment-action" href="{{ route('account-statements.payments.edit', [$payment->customer_id, $payment->id, 'return_to' => 'account-statement']) }}" title="ویرایش یا حذف پرداخت" aria-label="ویرایش یا حذف این پرداخت">
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 5l5 5M4 20l4.5-1 11-11a2.5 2.5 0 0 0-3.5-3.5l-11 11L4 20Z"/></svg>
    <span>ویرایش / حذف</span>
</a>
@else
<a class="btn btn-sm btn-outline-primary" href="{{ route('account-statements.payments.edit', [$payment->customer_id, $payment->id]) }}">ویرایش / حذف</a>
@endif
@endif
