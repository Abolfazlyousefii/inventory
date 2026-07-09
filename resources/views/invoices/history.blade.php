@extends('layouts.app')
@php use Morilog\Jalali\Jalalian; @endphp
@section('content')
<style>.timeline{border-right:3px solid #bfdbfe;margin-right:1rem;padding-right:1.25rem}.timeline-item{position:relative;background:#fff;border:1px solid #dbeafe;border-radius:14px;padding:1rem;margin-bottom:1rem}.timeline-item:before{content:"";position:absolute;right:-1.65rem;top:1.2rem;width:12px;height:12px;border-radius:50%;background:#2563eb}.muted-box{background:#f8fafc;border:1px dashed #cbd5e1;border-radius:12px;padding:1rem}</style>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3"><div><h3>تاریخچه فاکتور {{ $invoice->uuid }}</h3><div class="text-muted">Timeline ساده؛ طراحی فلوچارتی کامل در Patch بعدی تکمیل می‌شود.</div></div><div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="{{ route('invoices.show', $invoice->uuid) }}">مشاهده فاکتور</a><a class="btn btn-outline-dark" href="{{ route('invoices.print', $invoice->uuid) }}" target="_blank">چاپ</a></div></div>
  <div class="muted-box mb-3">لاگ کامل مراحل status changes، item changes، payment changes، note changes و shipping changes به داده‌های ثبت‌شده فعلی وابسته است. اگر رویدادی در لیست نیست، لاگ کامل آن مرحله در Patch بعدی تکمیل می‌شود.</div>
  <div class="timeline">
    <div class="timeline-item"><h6>ایجاد فاکتور</h6><div class="text-muted">{{ $invoice->created_at ? Jalalian::fromDateTime($invoice->created_at)->format('Y/m/d H:i') : '—' }} | فروشنده: {{ $invoice->preinvoiceOrder?->creator?->name ?? '—' }}</div></div>
    @foreach($invoice->histories as $h)
      <div class="timeline-item"><h6>{{ $h->description ?: ($h->action_type ?? 'تغییر') }}</h6><div class="small text-muted">{{ $h->field_name ?: '—' }}: {{ $h->old_value ?: '—' }} ← {{ $h->new_value ?: '—' }}</div><div class="small text-muted">{{ $h->actor?->name ?? '—' }} | {{ $h->done_at ? Jalalian::fromDateTime($h->done_at)->format('Y/m/d H:i') : '—' }}</div></div>
    @endforeach
    @foreach($invoice->payments as $payment)
      <div class="timeline-item"><h6>ثبت پرداخت</h6><div class="small text-muted">{{ number_format((int)$payment->amount) }} ریال | {{ $payment->creator?->name ?? '—' }} | {{ $payment->created_at ? Jalalian::fromDateTime($payment->created_at)->format('Y/m/d H:i') : '—' }}</div></div>
    @endforeach
    @foreach($invoice->notes as $note)
      <div class="timeline-item"><h6>ثبت یادداشت</h6><div>{{ $note->body ?? $note->note }}</div><div class="small text-muted">{{ $note->user?->name ?? '—' }} | {{ $note->created_at ? Jalalian::fromDateTime($note->created_at)->format('Y/m/d H:i') : '—' }}</div></div>
    @endforeach
  </div>
</div>
@endsection
