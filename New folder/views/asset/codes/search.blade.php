@extends('layouts.app')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">🔎 جستجو بر اساس کد اموال</h4>
  <a href="{{ route('asset.documents.index') }}" class="btn btn-outline-secondary">اسناد اموال</a>
</div>

<form method="GET" class="card card-body mb-3">
  <div class="row g-2">
    <div class="col-md-10"><input name="code" class="form-control" maxlength="4" value="{{ $code }}" placeholder="کد 4 رقمی اموال"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100">جستجو</button></div>
  </div>
</form>

@if($code !== '')
  <div class="card border-0 shadow-sm">
    <div class="card-body">
      @if($result)
        <div class="row g-2">
          <div class="col-md-4"><b>کد اموال:</b> {{ $result->asset_code }}</div>
          <div class="col-md-4"><b>کالا:</b> {{ $result->item?->item_name }}</div>
          <div class="col-md-4"><b>پرسنل:</b> {{ $result->item?->document?->trusteeDisplayName() }}</div>
          <div class="col-md-4"><b>شماره سند:</b> {{ $result->item?->document?->document_number }}</div>
          <div class="col-md-4"><b>تاریخ سند:</b> {{ optional($result->item?->document?->document_date)->format('Y-m-d') }}</div>
          <div class="col-md-4"><b>وضعیت سند:</b> {{ $result->item?->document?->status }}</div>
        </div>
      @else
        <div class="text-danger">برای این کد، اطلاعاتی یافت نشد.</div>
      @endif
    </div>
  </div>
@endif
@endsection
