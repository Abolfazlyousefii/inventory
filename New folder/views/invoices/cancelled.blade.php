@extends('layouts.app')

@section('title', 'بایگانی فاکتورهای لغوشده')
@section('content_class', 'app-content-wide')

@section('content')
@php
  use Morilog\Jalali\Jalalian;
  $rial = fn($amount) => \App\Support\Currency::formatRial((int) $amount);
@endphp
<div class="sales-wide-page">
  <div class="sales-page-head mb-3 d-flex justify-content-between align-items-start flex-wrap gap-3">
    <div><div class="h4 fw-black mb-1">بایگانی فاکتورهای لغوشده</div><div class="text-muted small">فقط فاکتورهای لغوشده و غیرعملیاتی نمایش داده می‌شوند.</div></div>
    <a class="btn btn-outline-secondary btn-sm" href="{{ route('invoices.index') }}">بازگشت به فاکتورهای فعال</a>
  </div>

  <div class="alert alert-warning">فاکتورهای این صفحه فقط برای سوابق مالی و انبار نگهداری می‌شوند و امکان ویرایش، پرداخت، عملیات انبار یا بازگردانی لغو ندارند.</div>

  <div class="card mb-3"><div class="card-body"><form class="row g-2 align-items-end" method="GET" action="{{ route('invoices.cancelled') }}">
    <div class="col-md-4"><label class="form-label">جست‌وجو شماره فاکتور / مشتری / موبایل</label><input class="form-control" name="q" value="{{ $filters['q'] ?? '' }}"></div>
    <div class="col-md-3"><label class="form-label">از تاریخ لغو</label><input class="form-control" name="date_from" value="{{ $filters['date_from'] ?? '' }}" dir="ltr" data-jdp data-jdp-only-date></div>
    <div class="col-md-3"><label class="form-label">تا تاریخ لغو</label><input class="form-control" name="date_to" value="{{ $filters['date_to'] ?? '' }}" dir="ltr" data-jdp data-jdp-only-date></div>
    <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary flex-fill">جست‌وجو</button><a class="btn btn-light" href="{{ route('invoices.cancelled') }}">پاک</a></div>
  </form></div></div>

  <div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>شماره فاکتور</th><th>مشتری</th><th>شماره تماس</th><th>فروشنده</th><th>مبلغ اصلی</th><th>مبلغ پرداخت‌شده باقی‌مانده</th><th>تاریخ ثبت</th><th>تاریخ لغو</th><th>لغوکننده</th><th>علت لغو</th><th class="text-end">عملیات</th></tr></thead><tbody>
    @forelse($invoices as $invoice)
      @php $paid=(int)($invoice->paid_total ?? 0); @endphp
      <tr>
        <td class="fw-bold" dir="ltr">{{ $invoice->uuid }}</td>
        <td>{{ $invoice->customer_name ?: $invoice->customer?->display_name ?: '—' }}</td>
        <td>{{ $invoice->customer_mobile ?: $invoice->customer?->mobile ?: '—' }}</td>
        <td>{{ $invoice->effectiveSeller()?->name ?? '—' }}</td>
        <td>{{ $rial($invoice->total) }}</td>
        <td><div class="text-success">پرداخت: {{ $rial($paid) }}</div><div class="text-muted">مانده: {{ $rial(max((int)$invoice->total - $paid, 0)) }}</div></td>
        <td>{{ $invoice->created_at ? Jalalian::fromDateTime($invoice->created_at)->format('Y/m/d H:i') : '—' }}</td>
        <td>{{ $invoice->cancelled_at ? Jalalian::fromDateTime($invoice->cancelled_at)->format('Y/m/d H:i') : ($invoice->status_changed_at ? Jalalian::fromDateTime($invoice->status_changed_at)->format('Y/m/d H:i') : '—') }}</td>
        <td>{{ $invoice->canceller?->name ?? $invoice->statusChangedByUser?->name ?? '—' }}</td>
        <td class="small">{{ \Illuminate\Support\Str::limit($invoice->cancellation_reason ?: '—', 80) }}</td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('archive.invoices.show', $invoice->uuid) }}">مشاهده فاکتور</a></td>
      </tr>
    @empty
      <tr><td colspan="11" class="text-center text-muted py-4">فاکتور لغوشده‌ای یافت نشد.</td></tr>
    @endforelse
  </tbody></table></div></div>
  <div class="mt-3">{{ $invoices->links() }}</div>
</div>
@endsection
