@extends('layouts.app')
@php
    use Morilog\Jalali\Jalalian;
    $date = fn($value) => $value ? Jalalian::fromDateTime($value)->format('Y/m/d') : '';
    $cheque = $payment->cheque;
    $value = fn($name, $default = '') => old($name, $default);
    $methods = ['cash'=>'نقدی', 'cheque'=>'چک'];
    if (!isset($methods[$payment->method])) $methods[$payment->method] = match($payment->method) {'card'=>'کارت','bank_transfer'=>'حواله بانکی','online'=>'آنلاین','wallet'=>'کیف پول', default=>$payment->method};
    $returnToAccount = request('return_to') === 'account-statement';
    $backRoute = !$returnToAccount && \App\Support\PageAccessCatalog::userCanRoute(auth()->user(), 'invoices.show') ? route('invoices.show', $payment->invoice->uuid) : route('account-statements.show', $customer->id);
@endphp
@push('styles')
<link rel="stylesheet" href="{{ asset('css/payment-management.css') }}?v=20261011">
@endpush
@section('content')
<div class="payment-management">
    <header class="pm-head"><div><h1>اصلاح پرداخت</h1><p>{{ $customer->display_name }} · فاکتور {{ $payment->invoice->uuid }} · سند PAY-{{ $payment->id }}</p></div><a class="btn btn-outline-secondary" href="{{ $backRoute }}">بازگشت</a></header>
    @if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if($managementIssue)<div class="alert alert-warning" role="alert">سوابق مالی این پرداخت هماهنگ نیست. ویرایش و حذف آن تا بررسی سند گردش حساب غیرفعال است.</div>@endif
    <div class="pm-summary"><span>مبلغ ثبت‌شده</span><strong>{{ \App\Support\Currency::formatRial($payment->amount) }}</strong></div>
    <form action="{{ route('account-statements.payments.update', [$customer->id, $payment->id]) }}" method="POST" class="pm-card" data-payment-edit>
        @csrf @method('PUT')
        @if($returnToAccount)<input type="hidden" name="return_to" value="account-statement">@endif
        <input type="hidden" name="version" value="{{ old('version', $version) }}">
        <div class="pm-grid">
            <label>روش پرداخت<select name="method" class="form-select" data-payment-method>@foreach($methods as $key=>$label)<option value="{{ $key }}" @selected($value('method',$payment->method)===$key)>{{ $label }}</option>@endforeach</select></label>
            <label>مبلغ پرداخت (ریال)<input class="form-control" name="amount" inputmode="numeric" required value="{{ $value('amount',$payment->amount) }}" data-payment-amount></label>
            <label>بانک<input class="form-control" name="bank_name" maxlength="255" value="{{ $value('bank_name',$cheque?->bank_name ?? $payment->bank_name) }}"></label>
            <div data-payment-cash class="pm-fields">
                <label>تاریخ پرداخت (شمسی)<input class="form-control" name="payment_date" placeholder="1405/07/19" dir="ltr" required value="{{ $value('payment_date',$date($payment->paid_at)) }}"></label>
                <label>شماره پیگیری / رسید<input class="form-control" name="tracking_number" maxlength="190" value="{{ $value('tracking_number',$payment->payment_identifier) }}"></label>
            </div>
            <div data-payment-cheque class="pm-fields">
                <label>شماره چک<input class="form-control" name="cheque_number" required maxlength="255" value="{{ $value('cheque_number',$cheque?->cheque_number) }}"></label>
                <label>تاریخ دریافت (شمسی)<input class="form-control" name="received_date" dir="ltr" required value="{{ $value('received_date',$date($cheque?->received_at ?? $payment->paid_at)) }}"></label>
                <label>سررسید (شمسی)<input class="form-control" name="due_date" dir="ltr" required value="{{ $value('due_date',$date($cheque?->due_date)) }}"></label>
                <label>وضعیت صیادی<select class="form-select" name="cheque_status"><option value="unregistered" @selected($value('cheque_status',$cheque?->status)==='unregistered')>ثبت‌نشده</option><option value="registered" @selected($value('cheque_status',$cheque?->status)==='registered')>ثبت‌شده</option>@if($cheque?->status && !in_array($cheque->status,['registered','unregistered']))<option value="{{ $cheque->status }}" @selected($value('cheque_status',$cheque->status)===$cheque->status)>{{ $cheque->status }}</option>@endif</select></label>
                <label>شعبه<input class="form-control" name="cheque_branch_name" maxlength="255" value="{{ $value('cheque_branch_name',$cheque?->branch_name) }}"></label>
                <label>نام صاحب چک<input class="form-control" name="cheque_customer_name" maxlength="255" value="{{ $value('cheque_customer_name',$cheque?->customer_name ?? $customer->display_name) }}"></label>
                <label>صاحب حساب<input class="form-control" name="cheque_account_holder" maxlength="255" value="{{ $value('cheque_account_holder',$cheque?->account_holder) }}"></label>
                <label>شماره حساب<input class="form-control" name="cheque_account_number" maxlength="255" value="{{ $value('cheque_account_number',$cheque?->account_number) }}"></label>
            </div>
            <label class="pm-wide">توضیحات<textarea class="form-control" name="note" rows="2" maxlength="2000">{{ $value('note',$payment->note) }}</textarea></label>
            <label class="pm-wide">دلیل ویرایش<input class="form-control" name="reason" required minlength="5" maxlength="1000" placeholder="دلیل اصلاح را بنویسید" value="{{ old('_delete_payment') ? '' : old('reason') }}"></label>
        </div>
        <footer><span>تغییرات با نام شما و دلیل اصلاح ثبت می‌شود.</span><button type="submit" class="btn btn-success" @disabled($managementIssue)>ذخیره تغییرات</button></footer>
    </form>
    <details class="pm-delete" @if(old('_delete_payment')) open @endif><summary>حذف پرداخت</summary>
        <p>با حذف این پرداخت، مبلغ آن از دریافتی مشتری کم و مانده حساب دوباره محاسبه می‌شود. سابقه حذف باقی می‌ماند.</p>
        <form action="{{ route('account-statements.payments.destroy', [$customer->id, $payment->id]) }}" method="POST" data-payment-delete>
            @csrf @method('DELETE')<input type="hidden" name="version" value="{{ old('version', $version) }}"><input type="hidden" name="_delete_payment" value="1">
            @if($returnToAccount)<input type="hidden" name="return_to" value="account-statement">@endif
            <label>دلیل حذف<input class="form-control" name="reason" required minlength="5" maxlength="1000" value="{{ old('_delete_payment') ? old('reason') : '' }}"></label>
            <button class="btn btn-outline-danger" type="submit" @disabled($managementIssue)>حذف این پرداخت</button>
        </form>
    </details>
</div>
@endsection
@push('scripts')<script src="{{ asset('js/payment-management.js') }}?v=20261011" defer></script>@endpush
