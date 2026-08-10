@extends('layouts.app')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">مشاهده پرسنل</h4>
  <a href="{{ route('asset.personnel.index') }}" class="btn btn-outline-secondary">بازگشت</a>
</div>
<div class="card card-body">
  <div class="row g-2">
    <div class="col-md-6"><b>نام:</b> {{ $personnel->full_name }}</div>
    <div class="col-md-6"><b>کد پرسنلی:</b> {{ $personnel->personnel_code }}</div>
    <div class="col-md-6"><b>واحد:</b> {{ $personnel->department ?: '—' }}</div>
    <div class="col-md-6"><b>سمت:</b> {{ $personnel->position ?: '—' }}</div>
    <div class="col-md-6"><b>موبایل:</b> {{ $personnel->mobile ?: '—' }}</div>
    <div class="col-md-6"><b>وضعیت:</b> {{ $personnel->is_active ? 'فعال' : 'غیرفعال' }}</div>
    <div class="col-md-12"><b>تعداد اسناد اموال:</b> {{ $personnel->documents_count }}</div>
  </div>
</div>
@endsection
