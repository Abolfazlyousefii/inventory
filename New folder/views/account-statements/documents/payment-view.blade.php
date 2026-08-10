@extends('layouts.app')

@php
    use Morilog\Jalali\Jalalian;
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">💳 نمایش جزئیات پرداخت</h4>
        <div class="text-muted small">این بخش فقط نمایشی است و هیچ فیلدی قابل تغییر نیست.</div>
    </div>
    <a href="{{ url()->previous() }}" class="btn btn-outline-secondary">بازگشت</a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4"><strong>نوع پرداخت:</strong>
                @if($payment->method === 'cheque')
                    <span class="badge bg-warning text-dark">چکی</span>
                @else
                    <span class="badge bg-success">نقدی</span>
                @endif
            </div>
            <div class="col-md-4"><strong>مبلغ:</strong> {{ \App\Support\Currency::formatRial($payment->amount) }}</div>
            <div class="col-md-4"><strong>تاریخ پرداخت:</strong> {{ $payment->paid_at ? Jalalian::fromDateTime($payment->paid_at)->format('Y/m/d') : '—' }}</div>
            <div class="col-md-6"><strong>شناسه پرداخت:</strong> {{ $payment->payment_identifier ?: '—' }}</div>
            <div class="col-md-6"><strong>فاکتور:</strong> {{ $payment->invoice?->uuid ?: '—' }}</div>
            <div class="col-md-12"><strong>یادداشت:</strong> {{ $payment->note ?: '—' }}</div>
        </div>

        @if($payment->method === 'cheque')
            <hr>
            <h6 class="fw-bold mb-3">اطلاعات چک</h6>
            <div class="row g-3">
                <div class="col-md-4"><strong>شماره چک:</strong> {{ $payment->cheque?->cheque_number ?: '—' }}</div>
                <div class="col-md-4"><strong>بانک:</strong> {{ $payment->cheque?->bank_name ?: '—' }}</div>
                <div class="col-md-4"><strong>شعبه:</strong> {{ $payment->cheque?->branch_name ?: '—' }}</div>
                <div class="col-md-4"><strong>صاحب حساب:</strong> {{ $payment->cheque?->account_holder ?: '—' }}</div>
                <div class="col-md-4"><strong>شماره حساب:</strong> {{ $payment->cheque?->account_number ?: '—' }}</div>
                <div class="col-md-4"><strong>مبلغ چک:</strong> {{ \App\Support\Currency::formatRial($payment->cheque?->amount ?: 0) }}</div>
                <div class="col-md-4"><strong>تاریخ سررسید:</strong> {{ $payment->cheque?->due_date ? Jalalian::fromDateTime($payment->cheque->due_date)->format('Y/m/d') : '—' }}</div>
                <div class="col-md-4"><strong>تاریخ دریافت:</strong> {{ $payment->cheque?->received_at ? Jalalian::fromDateTime($payment->cheque->received_at)->format('Y/m/d') : '—' }}</div>
                <div class="col-md-4"><strong>وضعیت چک:</strong> {{ $payment->cheque?->status ?: '—' }}</div>
            </div>
        @endif
    </div>
</div>
@endsection
