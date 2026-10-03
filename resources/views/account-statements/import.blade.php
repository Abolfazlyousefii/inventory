@extends('layouts.app')

@section('content')
<div class="container-fluid" dir="rtl" style="max-width:900px">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div><h4 class="mb-1">ورود اکسل اصلاح مانده</h4><div class="text-muted small">گردش حساب اشخاص</div></div>
        <a href="{{ route('account-statements.index') }}" class="btn btn-outline-secondary">بازگشت</a>
    </div>
    <div class="alert alert-info">فایل باید شامل شناسه، نام و موبایل مشتری، ماندهٔ فعلی و ماندهٔ هدف به ریال و دلیل اصلاح باشد. ابتدا پیش‌نمایش و موارد نامعتبر نمایش داده می‌شود. فقط با تأیید نهایی، اختلاف مانده به‌صورت سند اصلاحی همراه با لاگ ثبت خواهد شد.</div>
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <div class="card"><div class="card-body">
        <form method="POST" action="{{ route('account-statements.import.preview') }}" enctype="multipart/form-data">
            @csrf
            <label for="balanceFile" class="form-label">فایل اکسل آمادهٔ اصلاح مانده</label>
            <input id="balanceFile" name="file" type="file" accept=".xlsx,.xls" required class="form-control mb-3">
            <button class="btn btn-primary">بررسی و نمایش پیش‌نمایش</button>
        </form>
    </div></div>
</div>
@endsection
