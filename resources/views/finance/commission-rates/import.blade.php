@extends('layouts.app')
@section('title', 'ورود نرخ پورسانت از اکسل')
@section('page-title', 'مالی / ورود نرخ پورسانت از اکسل')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">ورود نرخ پورسانت از اکسل</h1>
            <p class="text-muted mb-0">نرخ‌های پرشده به‌صورت اختصاصی روی کالا ثبت می‌شوند؛ ردیف خالی نرخ دسته‌بندی را به ارث می‌برد.</p>
        </div>
        <a href="{{ route('finance.commission-rates.index') }}" class="btn btn-outline-secondary">بازگشت به تنظیمات نرخ</a>
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="alert alert-info">
                <strong>منطق اعمال:</strong>
                نرخ هر ردیف از مبلغ پورسانت و قیمت اکسل محاسبه و به عدد صحیح رو‌به‌بالا گرد می‌شود.
                ردیف بدون مبلغ پورسانت، نرخ اختصاصی محصول را ندارد و از دسته‌بندی ارث می‌برد.
                تاریخ جداگانه نمی‌گیریم؛ زمان فشردن دکمه «اعمال» مبنای شروع نرخ‌های جدید است.
                اسناد قبلی که قبلاً ثبت شده‌اند تغییر نمی‌کنند.
            </div>

            <form method="POST" action="{{ route('finance.commission-rates.import.preview') }}" enctype="multipart/form-data">
                @csrf
                <label class="form-label" for="commissionFile">فایل اکسل نرخ‌ها</label>
                <input type="file" class="form-control mb-3" id="commissionFile" name="file" accept=".xlsx,.xls" required>
                <button type="submit" class="btn btn-primary">بررسی و نمایش پیش‌نمایش</button>
            </form>
        </div>
    </div>
</div>
@endsection
