@extends('layouts.app')
@section('title', 'ویرایش سند اصلاح مانده')
@section('content')
<div class="container py-3" dir="rtl" style="max-width:760px">
    <div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h4">سند اصلاح مانده #{{ $adjustment->id }}</h1><a class="btn btn-outline-secondary" href="{{ route('account-statements.show', $customer) }}">بازگشت</a></div>
    <p>{{ $customer->display_name }} · مبلغ فعلی: {{ \App\Support\Currency::formatRial($ledger->amount) }} {{ $ledger->type === 'debit' ? 'بدهکار' : 'بستانکار' }}</p>
    <p class="text-muted">فقط اختلاف مبلغ همین سند روی ماندهٔ جاری اعمال می‌شود. تراکنش‌های بعد از این سند تغییر نمی‌کنند.</p>
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="{{ route('account-statements.adjustments.update', [$customer, $adjustment]) }}" class="card card-body gap-3">
        @csrf @method('PUT')
        <input type="hidden" name="version" value="{{ old('version', $version) }}">
        <label>نوع سند<select name="type" class="form-select" required><option value="debit" @selected(old('type', $ledger->type) === 'debit')>بدهکار</option><option value="credit" @selected(old('type', $ledger->type) === 'credit')>بستانکار</option></select></label>
        <label>مبلغ سند (ریال)<input name="amount" class="form-control" inputmode="numeric" value="{{ old('amount', $ledger->amount) }}" required></label>
        <label>دلیل ویرایش<textarea name="reason" class="form-control" minlength="10" maxlength="1000" required>{{ old('_delete_adjustment') ? '' : old('reason') }}</textarea></label>
        <button class="btn btn-primary align-self-start">ذخیره تغییرات</button>
    </form>
    <details class="card card-body mt-3" @if(old('_delete_adjustment')) open @endif><summary class="text-danger">حذف سند اصلاحی</summary>
        <p class="mt-3">اثر این سند از مانده حذف می‌شود. سابقهٔ ثبت، ویرایش و حذف برای پیگیری باقی می‌ماند.</p>
        <form method="POST" action="{{ route('account-statements.adjustments.destroy', [$customer, $adjustment]) }}" class="d-grid gap-3">
            @csrf @method('DELETE')<input type="hidden" name="_delete_adjustment" value="1"><input type="hidden" name="version" value="{{ old('version', $version) }}">
            <label>دلیل حذف<textarea name="reason" class="form-control" minlength="10" maxlength="1000" required>{{ old('_delete_adjustment') ? old('reason') : '' }}</textarea></label>
            <button class="btn btn-outline-danger justify-self-start">حذف اثر سند از حساب</button>
        </form>
    </details>
</div>
@endsection
