@extends('layouts.app')

@php
  use Morilog\Jalali\Jalalian;
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="page-title mb-0">گردش انبار</h4>
</div>

<form class="card filter-card mb-3" method="GET" action="{{ route('movements.index') }}">
  <div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-md-4">
        <label class="form-label">جستجو (نام یا SKU)</label>
        <input name="q" class="form-control" value="{{ request('q') }}" placeholder="مثلاً گارد یا GR-1004">
      </div>

      <div class="col-md-2">
        <label class="form-label">نوع</label>
        <select name="type" class="form-select">
          <option value="">همه</option>
          <option value="in" @selected(request('type')==='in')>ورود</option>
          <option value="out" @selected(request('type')==='out')>خروج</option>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label">علت</label>
        <select name="reason" class="form-select">
          <option value="">همه</option>
          <option value="purchase" @selected(request('reason')==='purchase')>خرید</option>
          <option value="sale" @selected(request('reason')==='sale')>فروش</option>
          <option value="return" @selected(request('reason')==='return')>مرجوعی</option>
          <option value="transfer" @selected(request('reason')==='transfer')>انتقال</option>
          <option value="adjustment" @selected(request('reason')==='adjustment')>اصلاح</option>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label">بازه تاریخ</label>
        <div class="input-group">
          <input type="date" name="from" class="form-control" value="{{ request('from') }}">
          <input type="date" name="to" class="form-control" value="{{ request('to') }}">
        </div>
      </div>

      <div class="col-12 d-flex gap-2">
        <button class="btn btn-primary">اعمال فیلتر</button>
        <a class="btn btn-outline-secondary" href="{{ route('movements.index') }}">پاک کردن</a>
      </div>
    </div>
  </div>
</form>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover table-striped align-middle mb-0">
        <thead>
          <tr>
            <th>زمان</th>
            <th>محصول</th>
            <th>نوع</th>
            <th>علت</th>
            <th>تعداد</th>
            <th>قبل</th>
            <th>بعد</th>
            <th>ارجاع</th>
            <th>کاربر</th>
          </tr>
        </thead>
        <tbody>
          @forelse($movements as $m)
            <tr>
              <td class="text-muted small">{{ Jalalian::fromDateTime($m->created_at)->format('Y/m/d H:i') }}</td>
              <td class="fw-semibold">
                {{ $m->product?->name }}
                <div class="text-muted small">{{ $m->product?->sku }}</div>
              </td>
              <td>
                @if($m->type==='in')
                  <span class="badge text-bg-success">ورود</span>
                @else
                  <span class="badge text-bg-danger">خروج</span>
                @endif
              </td>
              <td class="text-muted">
                {{ [
                  'purchase'=>'خرید',
                  'sale'=>'فروش',
                  'return'=>'مرجوعی',
                  'transfer'=>'انتقال',
                  'adjustment'=>'اصلاح'
                ][$m->reason] ?? $m->reason }}
              </td>
              <td class="fw-bold">{{ $m->quantity }}</td>
              <td>{{ $m->stock_before }}</td>
              <td>{{ $m->stock_after }}</td>
              <td class="text-muted">{{ $m->reference }}</td>
              <td class="text-muted">{{ $m->user?->name }}</td>
            </tr>
          @empty
            <tr><td colspan="9" class="text-center text-muted py-5">هیچ گردش انباری ثبت نشده.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div class="mt-3">{{ $movements->links() }}</div>
  </div>
</div>
@endsection
