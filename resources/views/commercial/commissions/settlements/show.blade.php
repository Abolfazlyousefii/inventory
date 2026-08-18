@extends('layouts.app')
@section('title', 'تسویه '.$settlement->settlement_number)
@section('page-title', 'بازرگانی / پورسانت / تسویه')
@section('content')
<div class="container-fluid">
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div class="card p-3 mb-3"><div class="d-flex justify-content-between"><div><h1 class="h4">تسویه پورسانت فروشنده</h1><div>{{ $settlement->settlement_number }} — {{ $settlement->seller->name }} — {{ $settlement->period->label }}</div></div><a class="btn btn-outline-dark" target="_blank" href="{{ route('commercial.commissions.settlements.print',$settlement) }}">چاپ</a></div></div>
    <div class="row g-3 mb-3">@foreach(['net_payable'=>'پورسانت نهایی','paid_amount'=>'پرداخت‌شده','remaining_amount'=>'مانده'] as $key=>$label)<div class="col-md-4"><div class="card p-3"><small>{{ $label }}</small><strong>{{ \App\Support\Currency::formatToman($settlement->$key) }}</strong></div></div>@endforeach</div>
    <div class="card p-3 mb-3"><h2 class="h5">ثبت پرداخت</h2>
        @if($canRecordPayment && $settlement->period->status === \App\Models\CommissionPeriod::STATUS_CLOSED && $settlement->remaining_amount > 0)
            @if($pilotMode)<div class="alert alert-warning"><strong>حالت آزمایشی فعال است.</strong> مبلغ، فروشنده و سند نهایی را پیش از ثبت پرداخت دوباره بررسی کنید.</div>@endif
            <form method="post" action="{{ route('commercial.commissions.settlements.payments.store',$settlement) }}" class="row g-2">@csrf
                <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                <div class="col-md-2"><div class="input-group"><input name="amount_toman" inputmode="numeric" class="form-control" placeholder="مبلغ" required><span class="input-group-text">تومان</span></div></div>
                <div class="col-md-2"><input name="paid_at" class="form-control" data-jdp placeholder="تاریخ پرداخت" required></div>
                <div class="col-md-2"><select name="payment_method" class="form-select"><option value="">روش پرداخت</option><option value="bank_transfer">انتقال بانکی</option><option value="cash">نقدی</option><option value="other">سایر</option></select></div>
                <div class="col-md-2"><input name="reference_number" class="form-control" placeholder="شماره مرجع"></div>
                <div class="col-md-3"><input name="notes" class="form-control" placeholder="یادداشت"></div><div class="col-md-1"><button class="btn btn-primary">ثبت</button></div>
            </form>
        @else<div class="text-muted">این تسویه در وضعیت قابل پرداخت نیست یا مجوز ثبت پرداخت ندارید.</div>@endif
    </div>
    <div class="card p-3"><h2 class="h5">تاریخچه پرداخت</h2><div class="table-responsive"><table class="table"><thead><tr><th>مبلغ</th><th>تاریخ</th><th>روش</th><th>مرجع</th><th>ثبت‌کننده</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
        @forelse($settlement->payments as $payment)<tr><td>{{ \App\Support\Currency::formatToman($payment->amount) }}</td><td>{{ \App\Support\JalaliDate::dateTime($payment->paid_at) }}</td><td>{{ ['bank_transfer'=>'انتقال بانکی','cash'=>'نقدی','other'=>'سایر'][$payment->payment_method] ?? '—' }}</td><td>{{ $payment->reference_number ?: '—' }}</td><td>{{ $payment->creator?->name }}</td><td>{{ $payment->status === 'recorded' ? 'ثبت‌شده' : 'باطل‌شده' }} @if($payment->void_reason)— {{ $payment->void_reason }}@endif</td><td>@if($canVoidPayment && $payment->status === \App\Models\CommissionPayment::STATUS_RECORDED && $settlement->period->status !== \App\Models\CommissionPeriod::STATUS_PAID)<form method="post" action="{{ route('commercial.commissions.settlements.payments.void',[$settlement,$payment]) }}">@csrf<input name="reason" required placeholder="دلیل ابطال"><button class="btn btn-sm btn-outline-danger">ابطال</button></form>@endif</td></tr>
        @empty<tr><td colspan="7">پرداختی ثبت نشده است.</td></tr>@endforelse
    </tbody></table></div></div>
</div>
@endsection
