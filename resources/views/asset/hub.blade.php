@extends('layouts.app')

@section('content')
<style>
  .asset-hub-card{
    border:0;
    border-radius:18px;
    box-shadow:0 10px 28px rgba(15,23,42,.08);
    transition:all .2s ease;
    height:100%;
    text-decoration:none;
    color:inherit;
    display:block;
  }
  .asset-hub-card:hover{transform:translateY(-4px);box-shadow:0 14px 34px rgba(15,23,42,.14)}
  .asset-hub-icon{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;background:#eef4ff}
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="mb-0">🧩 امین اموال</h4>
    <div class="text-muted small">داشبورد اصلی ماژول مدیریت اموال تخصیص‌داده‌شده به پرسنل</div>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-md-6 col-xl-4">
    <a href="{{ route('asset.documents.index') }}" class="asset-hub-card card p-3">
      <div class="asset-hub-icon mb-3">📄</div>
      <h5 class="fw-bold">اسناد ثبت‌شده</h5>
      <p class="text-muted mb-0">مشاهده و مدیریت اسناد اموال ثبت‌شده برای پرسنل</p>
    </a>
  </div>

  <div class="col-12 col-md-6 col-xl-4">
    <a href="{{ route('asset.codes.search') }}" class="asset-hub-card card p-3">
      <div class="asset-hub-icon mb-3">🔎</div>
      <h5 class="fw-bold">جستجو با کد اموال</h5>
      <p class="text-muted mb-0">جستجوی کالا یا سند بر اساس کد 4 رقمی برچسب اموال</p>
    </a>
  </div>
</div>
@endsection
