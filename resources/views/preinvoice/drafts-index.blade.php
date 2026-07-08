@extends('layouts.app')

@php
  use Morilog\Jalali\Jalalian;
@endphp

@section('content')
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0">💰 صف مالی پیش‌فاکتورها</h4>
    <a href="{{ route('preinvoice.create') }}" class="btn btn-primary">➕ ایجاد پیش‌فاکتور</a>
  </div>

  @if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
  @endif

  <div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3">
      <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center">
        <div>
          <h6 class="mb-1">پیش‌فاکتورهای در انتظار تایید مالی</h6>
          <small class="text-muted">در این بخش تیم مالی می‌تواند پیش‌فاکتورها را بررسی و مشاهده کند.</small>
        </div>
        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
          {{ number_format($orders->total()) }} مورد در انتظار بررسی
        </span>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr class="table-light">
            <th>#</th>
            <th>کد</th>
            <th>مشتری</th>
            <th>ثبت‌کننده</th>
            <th>موبایل</th>
            <th>توضیحات</th>
            <th>شرایط پرداخت</th>
            <th>انقضای رزرو</th>
            <th class="text-nowrap">جمع کل (ریال)</th>
            <th class="text-nowrap">تاریخ ثبت</th>
            <th class="text-end"></th>
          </tr>
        </thead>
        <tbody>
          @forelse($orders as $o)
            <tr>
              <td>{{ $o->id }}</td>
              <td>{{ $o->uuid }}</td>
              <td>{{ $o->customer_name }}</td>
              <td>{{ $o->creator?->name ?? '—' }}</td>
              <td>{{ $o->customer_mobile }}</td>
              <td class="text-muted small" style="min-width: 220px; max-width: 320px; white-space: normal;">
                {{ $o->description ? \Illuminate\Support\Str::limit($o->description, 120) : '—' }}
              </td>
              <td class="text-muted small">{{ $o->payment_terms_note ? \Illuminate\Support\Str::limit($o->payment_terms_note, 100) : '—' }}</td>
              <td class="small">
                @if(!$o->stock_frozen_until)
                  <span class="badge bg-info-subtle text-info-emphasis border">VIP / بدون انقضا</span>
                @elseif($o->stock_frozen_until->isPast())
                  <span class="badge bg-danger-subtle text-danger-emphasis border">منقضی‌شده</span>
                  <div class="text-muted">{{ Jalalian::fromDateTime($o->stock_frozen_until)->format('Y/m/d H:i') }}</div>
                @else
                  <span class="badge bg-success-subtle text-success-emphasis border">معتبر تا</span>
                  <div class="text-muted">{{ Jalalian::fromDateTime($o->stock_frozen_until)->format('Y/m/d H:i') }}</div>
                @endif
              </td>
              <td>{{ number_format((int)$o->total_price) }}</td>
              <td>{{ $o->created_at ? Jalalian::fromDateTime($o->created_at)->format('Y/m/d H:i') : '—' }}</td>
              <td class="text-end">
                <div class="d-flex gap-2 justify-content-end">
                  <a class="btn btn-sm btn-success" href="{{ route('preinvoice.draft.finance', $o->uuid) }}">تایید</a>
                  <form method="POST" action="{{ route('preinvoice.draft.return', $o->uuid) }}" class="d-inline" onsubmit="return confirm('پیش‌فاکتور به فروشنده ارجاع شود؟')">
                    @csrf
                    <input type="hidden" name="reason" value="ارجاع توسط مالی از صف مالی">
                    <button class="btn btn-sm btn-outline-warning">ارجاع</button>
                  </form>
                  <form method="POST" action="{{ route('preinvoice.draft.cancel', $o->uuid) }}" class="d-inline" onsubmit="return confirm('پیش‌فاکتور کنسل شود؟')">
                    @csrf
                    <input type="hidden" name="reason" value="کنسل توسط مالی از صف مالی">
                    <button class="btn btn-sm btn-outline-danger">کنسل</button>
                  </form>
                  <a class="btn btn-sm btn-outline-dark" href="{{ route('archive.preinvoices.show', $o->uuid) }}?print=1" target="_blank">پرینت</a>
                </div>
              </td>
            </tr>
          @empty
            <tr><td colspan="11" class="text-center py-4">موردی نیست</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="mt-3">
    {{ $orders->links() }}
  </div>
</div>
@endsection
