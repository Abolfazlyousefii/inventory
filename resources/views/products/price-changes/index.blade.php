@extends('layouts.app')

@section('content')
<div class="container py-4" dir="rtl">
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h1 class="h4 mb-3">تغییرات قیمت کالا</h1>

            @if (session('status'))
                <div class="alert alert-info mb-3">{{ session('status') }}</div>
            @endif

            <p class="text-muted mb-0">
                صفحه تغییرات قیمت کالا آماده است. منطق ثبت و اعمال سند در مراحل بعد تکمیل می‌شود.
            </p>
        </div>
    </div>
</div>
@endsection
