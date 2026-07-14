@extends('layouts.app')

@php
  $formatWarehouseLocation = function ($product): string {
      if (! $product) {
          return '—';
      }

      $zone = $product->warehouse_zone ? 'Z' . (int) $product->warehouse_zone : null;
      $rows = collect((array) ($product->warehouse_rows ?? []))
          ->filter(fn ($row) => $row !== null && $row !== '')
          ->map(fn ($row) => 'R' . (int) $row)
          ->implode('/');
      $bins = collect((array) ($product->warehouse_bins ?? []))
          ->filter(fn ($bin) => $bin !== null && $bin !== '')
          ->map(fn ($bin) => 'B' . (int) $bin)
          ->implode('/');

      return collect([$zone, $rows ?: null, $bins ?: null])->filter()->implode(' | ') ?: '—';
  };
@endphp

@section('content')
<div class="container py-4">
  @if($invoice->isFinanciallyLocked())<div class="alert alert-success">ارسال‌شده و قفل مالی</div>@endif
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">📄 نمایش فقط‌خواندنی حواله فروش</h4>
    <div class="d-flex gap-2">
      <a href="{{ route('vouchers.sales.print', $invoice->uuid) }}" target="_blank" class="btn btn-outline-success">چاپ</a>
      <a href="{{ route('vouchers.sales.edit', $invoice->uuid) }}" class="btn btn-outline-primary">ویرایش</a>
      <a href="{{ route('vouchers.sales.index') }}" class="btn btn-outline-secondary">بازگشت</a>
    </div>
  </div>

  @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
  @if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif

  <form method="POST" action="{{ route('vouchers.sales.status', $invoice->uuid) }}" class="card border-0 shadow-sm mb-3">
    @csrf
    <div class="card-body row g-2 align-items-end">
      <div class="col-md-5">
        <label class="form-label">تغییر وضعیت حواله</label>
        <select name="status" class="form-select">
          @foreach(['checking_discrepancy'=>'در حال بررسی','collecting'=>'در حال جمع‌آوری','shipped'=>'ارسال شده'] as $key => $label)
            <option value="{{ $key }}" @selected($invoice->status===$key)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label">یادداشت</label>
        <input name="note" class="form-control" placeholder="اختیاری">
      </div>
      <div class="col-md-2">
        <button class="btn btn-primary w-100">ثبت وضعیت</button>
      </div>
    </div>
  </form>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body row g-2">
      <div class="col-md-4"><b>کد سند:</b> {{ $invoice->uuid }}</div>
      <div class="col-md-4"><b>مشتری:</b> {{ $invoice->customer_name }}</div>
      <div class="col-md-4"><b>وضعیت:</b> {{ $statusLabels[$invoice->status] ?? $invoice->status }}</div>
      <div class="col-md-4"><b>مبلغ کل:</b> {{ \App\Support\Currency::formatRial($invoice->total) }}</div>
      <div class="col-md-8"><b>آدرس:</b> {{ $invoice->customer_address ?: '—' }}</div>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white">اقلام حواله</div>
    <div class="table-responsive">
      <table class="table mb-0">
        <thead><tr><th>محصول</th><th>کد کالا</th><th>Z/R/B</th><th>مدل</th><th>تعداد</th><th>قیمت</th><th>جمع</th></tr></thead>
        <tbody>
          @foreach($invoice->items as $it)
            @php
              $itemProductCode = $it->product?->code
                  ?: ($it->variant?->variant_code
                      ?: ($it->product?->sku ?: '—'));
              $itemWarehouseLocation = $formatWarehouseLocation($it->product);
            @endphp
            <tr>
              <td>{{ $it->product?->name ?? '#'.$it->product_id }}</td>
              <td dir="ltr" class="text-nowrap">{{ $itemProductCode }}</td>
              <td dir="ltr" class="text-nowrap">{{ $itemWarehouseLocation }}</td>
              <td>{{ $it->variant?->variant_name ?? '—' }}</td>
              <td>{{ number_format((int)$it->quantity) }}</td>
              <td>{{ number_format((int)$it->price) }}</td>
              <td>{{ number_format((int)$it->line_total) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white">یادداشت‌ها</div>
    <div class="card-body">
      @forelse($invoice->notes as $note)
        <div class="border-bottom py-2">{{ $note->body ?? $note->note ?? $note->description ?? '—' }}</div>
      @empty
        <div class="text-muted">یادداشتی ثبت نشده است.</div>
      @endforelse
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white">تاریخچه تغییرات</div>
    <div class="table-responsive">
      <table class="table mb-0">
        <thead><tr><th>عملیات</th><th>فیلد</th><th>قدیم</th><th>جدید</th><th>کاربر</th><th>زمان</th></tr></thead>
        <tbody>
          @forelse($invoice->histories as $h)
            <tr>
              <td>{{ $h->action_type }}</td>
              <td>{{ $h->field_name ?: '—' }}</td>
              <td>{{ $h->old_value ?: '—' }}</td>
              <td>{{ $h->new_value ?: '—' }}</td>
              <td>{{ $h->actor?->name ?: '—' }}</td>
              <td>{{ optional($h->done_at)->format('Y-m-d H:i') ?: '—' }}</td>
            </tr>
          @empty
            <tr><td colspan="6" class="text-center text-muted">تاریخچه‌ای ثبت نشده است.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>
@endsection
