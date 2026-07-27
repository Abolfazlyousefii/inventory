@extends('layouts.app')

@section('title', 'دسترسی غیرمجاز')

@section('content')
<div class="container py-5" dir="rtl">
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm text-center overflow-hidden">
                <div class="card-body p-5">
                    <div class="display-1 fw-bold text-danger mb-3">403</div>
                    <h1 class="h4 fw-bold mb-3">دسترسی غیرمجاز</h1>
                    <p class="text-muted mb-4">شما دسترسی لازم برای مشاهده این بخش را ندارید.</p>
                    <div class="d-flex gap-2 justify-content-center flex-wrap">
                        @auth
                            <a href="{{ route('dashboard') }}" class="btn btn-primary px-4">بازگشت به داشبورد</a>
                        @else
                            <a href="{{ route('login') }}" class="btn btn-primary px-4">ورود به حساب کاربری</a>
                        @endauth
                        <button type="button" onclick="history.back()" class="btn btn-outline-secondary px-4">بازگشت</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
