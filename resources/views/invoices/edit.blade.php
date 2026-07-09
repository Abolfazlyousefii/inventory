@extends('layouts.app')
@php
  use Morilog\Jalali\Jalalian;
  $rial = fn($v) => number_format((int) $v) . ' ریال';
  $statusFa = fn($s) => $statusLabels[$s] ?? ($s ?: '—');
  $paymentText = $paidTotal > $invoice->total ? 'پرداخت اضافه' : ($remainingAmount === 0 ? 'تسویه‌شده' : ($paidTotal > 0 ? 'پرداخت ناقص' : 'پرداخت‌نشده'));
@endphp
@section('content')
<style>
.invoice-edit-page{background:#f8fbff}.edit-card{border:1px solid #dbeafe;border-radius:16px;box-shadow:0 8px 24px rgba(30,64,175,.06)}.edit-card .card-header{background:#eff6ff;border-bottom:1px solid #dbeafe;font-weight:800;color:#1e3a8a}.info-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.75rem}.info-box{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:.75rem}.info-label{font-size:.78rem;color:#64748b}.info-value{font-weight:800}.payment-panel{background:linear-gradient(135deg,#eff6ff,#fff)}@media(max-width: 768px){.info-grid{grid-template-columns:1fr}.payment-fields .col-md-6{width:100%}}
</style>
<div class="container py-4 invoice-edit-page">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div><h3 class="mb-1">ویرایش فاکتور {{ $invoice->uuid }}</h3><div class="text-muted">حذف و اضافه اقلام، ثبت پرداخت و یادداشت‌های فاکتور</div></div>
    <div class="d-flex gap-2 flex-wrap"><a class="btn btn-outline-secondary" href="{{ route('invoices.show', $invoice->uuid) }}">بازگشت به مشاهده</a><a class="btn btn-outline-dark" href="{{ route('invoices.print', $invoice->uuid) }}" target="_blank">چاپ</a></div>
  </div>

  <div class="card edit-card mb-3"><div class="card-header">خلاصه فاکتور</div><div class="card-body"><div class="info-grid">
    @foreach([['شماره فاکتور',$invoice->uuid],['مشتری',$invoice->customer_name ?: $invoice->customer?->display_name],['موبایل',$invoice->customer_mobile ?: $invoice->customer?->mobile],['وضعیت فعلی',$statusFa($invoice->status)],['مبلغ کل',$rial($invoice->total)],['پرداخت‌شده',$rial($paidTotal)],['مانده',$rial($remainingAmount)],['وضعیت پرداخت',$paymentText]] as [$label,$value])
      <div class="info-box"><div class="info-label">{{ $label }}</div><div class="info-value">{{ $value ?: '—' }}</div></div>
    @endforeach
  </div></div></div>

  <div class="card edit-card mb-3"><div class="card-header">اقلام فاکتور / حذف و اضافه</div><div class="card-body">
    <div class="table-responsive mb-3"><table class="table align-middle"><thead><tr><th>محصول</th><th>تنوع</th><th>تعداد</th><th>قیمت snapshot</th><th>جمع</th></tr></thead><tbody>@foreach($invoice->items as $item)<tr><td>{{ $item->product?->name ?? ('#'.$item->product_id) }}</td><td>{{ $item->variant?->variant_name ?? $item->variant?->name ?? '—' }}</td><td>{{ number_format((int)$item->quantity) }}</td><td>{{ $rial($item->price) }}</td><td>{{ $rial($item->line_total ?? ((int)$item->quantity * (int)$item->price)) }}</td></tr>@endforeach</tbody></table></div>
    <p class="text-muted">حذف، کم‌کردن، تغییر تعداد و افزودن کالا از منطق delta-safe صف جمع‌آوری استفاده می‌کند؛ price از request پذیرفته نمی‌شود، قیمت آیتم جدید در backend خوانده می‌شود و در صورت قیمت صفر یا کمبود موجودی rollback می‌شود.</p>
    @if($canEditItemsWithCollectionFlow)
      <a class="btn btn-primary" href="{{ route('vouchers.sales.edit', $invoice->uuid) }}">افزودن کالا / حذف یا تغییر تعداد</a>
    @else
      <div class="alert alert-warning mb-0">وضعیت فعلی برای حذف و اضافه اقلام در صف جمع‌آوری مجاز نیست.</div>
    @endif
  </div></div>

  <div class="card edit-card payment-panel mb-3" id="payments"><div class="card-header">افزودن پرداخت</div><div class="card-body">
    <div class="alert alert-light border d-flex justify-content-between flex-wrap"><span>مانده قابل پرداخت:</span><strong>{{ $rial($remainingAmount) }}</strong></div>
    @if($remainingAmount <= 0)
      <div class="text-muted">این فاکتور تسویه شده است و پرداخت جدید قابل ثبت نیست.</div>
    @elseif($canRegisterPayments)
      <form method="POST" action="{{ route('invoices.payments.store', $invoice->uuid) }}" enctype="multipart/form-data" class="row g-3 payment-fields" id="invoiceEditPaymentForm">@csrf
        <div class="col-md-6"><label class="form-label">روش پرداخت</label><select name="method" id="payment_method" class="form-select" required><option value="cash">نقدی</option><option value="cheque">چکی</option></select></div>
        <div class="col-md-6"><label class="form-label">مبلغ پرداخت</label><input name="amount" type="number" min="1" max="{{ $remainingAmount }}" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label">تاریخ پرداخت شمسی</label><input name="payment_date" type="text" class="form-control" required data-jdp data-jdp-only-date></div>
        <div class="col-md-6 common-bank"><label class="form-label">اسم بانک / نام بانک</label><input name="bank_name" class="form-control"></div>
        <div class="col-md-6 cash-field"><label class="form-label">شماره پیگیری / رسید</label><input name="tracking_number" class="form-control"></div>
        <div class="col-md-6 cash-field"><label class="form-label">تصویر رسید</label><input name="receipt_image" type="file" class="form-control" accept="image/*,application/pdf"></div>
        <div class="col-md-6 cheque-field d-none"><label class="form-label">شماره چک</label><input name="cheque_number" class="form-control"></div>
        <div class="col-md-6 cheque-field d-none"><label class="form-label">نام شعبه</label><input name="branch_name" class="form-control"></div>
        <div class="col-md-6 cheque-field d-none"><label class="form-label">تاریخ سررسید</label><input name="due_date" type="text" class="form-control" data-jdp data-jdp-only-date></div>
        <div class="col-md-6 cheque-field d-none"><label class="form-label">تاریخ دریافت</label><input name="received_date" type="text" class="form-control" data-jdp data-jdp-only-date></div>
        <div class="col-md-6 cheque-field d-none"><label class="form-label">نام مشتری</label><input name="cheque_owner_name" class="form-control" placeholder="{{ $invoice->customer_name ?: $invoice->customer?->display_name }}"></div>
        <div class="col-md-6 cheque-field d-none"><label class="form-label">کد مشتری</label><input name="customer_code" class="form-control" placeholder="{{ $invoice->customer?->crm_customer_id ?: $invoice->customer_id }}"></div>
        <div class="col-md-6 cheque-field d-none"><label class="form-label">وضعیت چک</label><select name="cheque_status" class="form-select"><option value="pending">در انتظار وصول</option><option value="passed">وصول شده</option><option value="bounced">برگشتی</option><option value="cancelled">کنسل شده</option></select></div>
        <div class="col-12"><label class="form-label">توضیحات</label><textarea name="description" class="form-control" rows="2"></textarea></div>
        <div class="col-12 d-flex gap-2 justify-content-end"><button type="reset" class="btn btn-outline-secondary">پاک‌کردن/انصراف</button><button class="btn btn-success">ثبت پرداخت</button></div>
      </form>
    @else
      <div class="text-muted">ثبت پرداخت فقط برای نقش مالی/admin/manager فعال است.</div>
    @endif
  </div></div>

  <div class="card edit-card"><div class="card-header">یادداشت فاکتور</div><div class="card-body">
    <form method="POST" action="{{ route('invoices.notes.store', $invoice->uuid) }}" class="mb-3">@csrf<label class="form-label">یادداشت جدید</label><textarea name="body" class="form-control" rows="3"></textarea><div class="text-end mt-2"><button class="btn btn-primary">ثبت یادداشت</button></div></form>
    @forelse($invoice->notes as $note)<div class="border rounded p-2 mb-2">{{ $note->body ?? $note->note }}<div class="small text-muted">{{ $note->user?->name ?? '—' }} | {{ $note->created_at ? Jalalian::fromDateTime($note->created_at)->format('Y/m/d H:i') : '—' }}</div></div>@empty<div class="text-muted">یادداشتی ثبت نشده است.</div>@endforelse
  </div></div>
</div>
<script>(function(){const method=document.getElementById('payment_method');function toggle(){const cheque=method?.value==='cheque';document.querySelectorAll('.cheque-field').forEach(el=>el.classList.toggle('d-none',!cheque));document.querySelectorAll('.cash-field').forEach(el=>el.classList.toggle('d-none',cheque));document.querySelectorAll('[name="cheque_number"],[name="due_date"]').forEach(el=>el.required=cheque);}method?.addEventListener('change',toggle);toggle();})();</script>
@endsection
