@extends('layouts.app')

@section('title', 'خلاصه تأیید پورسانت')
@section('content_class', 'app-content-wide')

@section('content')
@php
  $rial = fn($amount) => \App\Support\Currency::formatRial((int) $amount);
  $jalali = fn($date) => \App\Support\JalaliDate::date($date);
  $jalaliDateTime = fn($date) => \App\Support\JalaliDate::dateTime($date);
  $printMode = $printMode ?? false;
@endphp

<style>
  .batch-page{max-width:1100px; margin:0 auto;}
  .batch-head,.batch-card{border:0; border-radius:18px; box-shadow:0 10px 28px rgba(15,23,42,.06);}
  .batch-head{background:linear-gradient(135deg,#fff,#f8fafc); padding:18px;}
  .info-box{border:1px solid #eef2f7; border-radius:16px; padding:14px; background:#fff; height:100%;}
  .info-box .label{font-size:.78rem; color:#64748b; font-weight:800;}
  .info-box .value{font-weight:950; margin-top:6px;}
  .money-cell{white-space:nowrap; font-variant-numeric:tabular-nums; text-align:left; direction:ltr;}
  @media print{.no-report-print{display:none!important}.batch-head,.batch-card{box-shadow:none!important;border:1px solid #ddd!important}body{background:#fff!important}}
</style>

<div class="batch-page">
  <div class="batch-head mb-3 d-flex justify-content-between align-items-start flex-wrap gap-3">
    <div>
      <div class="h4 fw-black mb-1">خلاصه تأیید فاکتورهای پورسانت</div>
      <div class="text-muted small">Batch شماره {{ $batch->id }}</div>
    </div>
    <div class="d-flex gap-2 flex-wrap no-report-print">
      <a class="btn btn-outline-secondary" href="{{ route('finance.reports.sales-visitors') }}">بازگشت به گزارش</a>
      <a class="btn btn-outline-success" href="{{ route('finance.reports.sales-visitors.commission-batches.export', $batch) }}?format=excel">خروجی Excel</a>
      <a class="btn btn-outline-success" href="{{ route('finance.reports.sales-visitors.commission-batches.export', $batch) }}">خروجی CSV</a>
      <a class="btn btn-outline-dark" href="{{ route('finance.reports.sales-visitors.commission-batches.print', $batch) }}" target="_blank">چاپ</a>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-sm-6 col-xl-3"><div class="info-box"><div class="label">نام ویزیتور</div><div class="value">{{ $batch->visitor?->name ?: '—' }}</div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="info-box"><div class="label">بازه گزارش</div><div class="value">{{ $jalali($batch->from_date) }} تا {{ $jalali($batch->to_date) }}</div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="info-box"><div class="label">تعداد فاکتورهای انتخاب‌شده</div><div class="value">{{ number_format($batch->invoice_count) }}</div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="info-box"><div class="label">جمع مبلغ انتخاب‌شده</div><div class="value money-cell">{{ $rial($batch->total_amount) }}</div></div></div>
    <div class="col-sm-6"><div class="info-box"><div class="label">کاربر مالی تاییدکننده</div><div class="value">{{ $batch->approver?->name ?: '—' }}</div></div></div>
    <div class="col-sm-6"><div class="info-box"><div class="label">تاریخ تایید</div><div class="value">{{ $jalaliDateTime($batch->approved_at) }}</div></div></div>
  </div>

  @if($batch->note)
    <div class="alert alert-light border">{{ $batch->note }}</div>
  @endif

  <div class="card batch-card">
    <div class="card-header bg-white fw-bold">فاکتورهای تاییدشده</div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>شماره فاکتور</th><th>تاریخ فاکتور</th><th>نام مشتری</th><th>موبایل مشتری</th><th class="text-end">مبلغ فاکتور</th><th>وضعیت فاکتور</th></tr></thead><tbody>@foreach($batch->items as $item)<tr><td>{{ $item->invoice_uuid }}</td><td>{{ $jalali($item->invoice_date) }}</td><td>{{ $item->customer_name ?: '—' }}</td><td>{{ $item->customer_mobile ?: '—' }}</td><td class="money-cell fw-bold">{{ $rial($item->invoice_total) }}</td><td>{{ ['shipped' => 'ارسال‌شده', 'finance_approved' => 'تایید مالی‌شده'][$item->invoice_status] ?? ($item->invoice_status ?: '—') }}</td></tr>@endforeach</tbody></table></div>
  </div>
</div>

@if($printMode)
<script>window.addEventListener('load', function(){ window.print(); });</script>
@endif
@endsection
