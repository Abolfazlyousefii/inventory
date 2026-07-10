@extends('layouts.app')
@php
  use Morilog\Jalali\Jalalian;
  $rial = fn($v) => number_format((int) $v) . ' ریال';
  $statusFa = fn($s) => $statusLabels[$s] ?? ($s ?: '—');
  $badgeStatus = fn($s) => match($s){
    'shipped' => 'text-bg-success', 'ready_to_ship' => 'text-bg-info', 'pending_finance_reapproval' => 'text-bg-warning',
    'not_shipped' => 'text-bg-secondary', default => 'text-bg-primary'
  };
  $paymentText = $paidTotal > $invoice->total ? 'پرداخت اضافه' : ($remainingAmount === 0 ? 'تسویه‌شده' : ($paidTotal > 0 ? 'پرداخت ناقص' : 'پرداخت‌نشده'));
  $paymentBadge = $paidTotal > $invoice->total ? 'text-bg-danger' : ($remainingAmount === 0 ? 'text-bg-success' : ($paidTotal > 0 ? 'text-bg-warning' : 'text-bg-secondary'));
  $itemsTotal = (int) $invoice->items->sum(fn($item) => max(((int)$item->quantity * (int)$item->price) - (int)($item->line_discount_amount ?? 0), 0));
  $hasZeroPrice = $invoice->items->contains(fn($item) => (int)$item->quantity > 0 && (int)$item->price <= 0);
  $hasMismatch = abs((int)$invoice->total - $itemsTotal) > 1;
@endphp
@section('content')
<style>
.invoice-page{background:#f8fbff}.invoice-card{border:1px solid #dbeafe;border-radius:16px;box-shadow:0 8px 24px rgba(30,64,175,.06)}.invoice-card .card-header{background:#eff6ff;border-bottom:1px solid #dbeafe;font-weight:700;color:#1e3a8a}.info-label{font-size:.78rem;color:#64748b}.info-value{font-weight:700;color:#0f172a}.money-row{display:flex;justify-content:space-between;border-bottom:1px dashed #dbeafe;padding:.45rem 0}.table thead th{background:#eff6ff;color:#1e3a8a}.readonly-note{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:.75rem}
</style>
<div class="container py-4 invoice-page">
  <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
    <div><h3 class="mb-1">فاکتور شماره {{ $invoice->uuid }}</h3><div class="d-flex gap-2 flex-wrap"><span class="badge {{ $badgeStatus($invoice->status) }}">{{ $statusFa($invoice->status) }}</span><span class="badge {{ $paymentBadge }}">{{ $paymentText }}</span></div></div>
    <div class="d-flex gap-2 flex-wrap">
      <a class="btn btn-outline-secondary" href="{{ route('invoices.index') }}">بازگشت</a>
      <a class="btn btn-outline-dark" href="{{ route('invoices.print', $invoice->uuid) }}" target="_blank">چاپ فاکتور</a>
      @if($canManageInvoice)<a class="btn btn-primary" href="{{ route('invoices.edit', $invoice->uuid) }}">ویرایش فاکتور</a>@endif
    </div>
  </div>

  @if($hasZeroPrice || $hasMismatch || $paidTotal > $invoice->total || in_array((string)$invoice->status, ['pending_warehouse_approval','checking_discrepancy','packing'], true))
  <div class="alert alert-warning invoice-card"><strong>هشدارها:</strong>
    @if($hasZeroPrice)<span class="badge text-bg-danger">اقلام با قیمت صفر</span>@endif
    @if($hasMismatch)<span class="badge text-bg-warning">مغایرت مبلغ اقلام و فاکتور</span>@endif
    @if($paidTotal > $invoice->total)<span class="badge text-bg-danger">پرداخت اضافه</span>@endif
    @if(in_array((string)$invoice->status, ['pending_warehouse_approval','checking_discrepancy','packing'], true))<span class="badge text-bg-secondary">وضعیت legacy</span>@endif
  </div>
  @endif

  <div class="row g-3">
    <div class="col-lg-6"><div class="card invoice-card h-100"><div class="card-header">خلاصه فاکتور</div><div class="card-body row g-3">
      @foreach([['مشتری',$invoice->customer_name ?: $invoice->customer?->display_name],['موبایل',$invoice->customer_mobile ?: $invoice->customer?->mobile],['کد مشتری',$invoice->customer?->crm_customer_id ?: $invoice->customer_id],['فروشنده',$invoice->preinvoiceOrder?->creator?->name],['تاریخ صدور',$invoice->created_at ? Jalalian::fromDateTime($invoice->created_at)->format('Y/m/d H:i') : '—'],['پیش‌فاکتور مرتبط',$invoice->preinvoiceOrder?->uuid],['وضعیت فعلی',$statusFa($invoice->status)],['وضعیت پرداخت',$paymentText]] as [$label,$value])
      <div class="col-sm-6"><div class="info-label">{{ $label }}</div><div class="info-value">{{ $value ?: '—' }}</div></div>
      @endforeach
    </div></div></div>
    <div class="col-lg-6"><div class="card invoice-card h-100"><div class="card-header">مالی</div><div class="card-body">
      <div class="money-row"><span>جمع جزء</span><strong>{{ $rial($invoice->subtotal ?? $itemsTotal) }}</strong></div>
      <div class="money-row"><span>تخفیف</span><strong>{{ $rial($invoice->discount_amount ?? 0) }}</strong></div>
      <div class="money-row"><span>هزینه ارسال</span><strong>{{ $rial($invoice->shipping_cost ?? $invoice->shippingMethod?->price ?? 0) }}</strong></div>
      <div class="money-row"><span>مبلغ کل</span><strong>{{ $rial($invoice->total) }}</strong></div>
      <div class="money-row"><span>پرداخت‌شده</span><strong class="text-success">{{ $rial($paidTotal) }}</strong></div>
      <div class="money-row border-0"><span>مانده</span><strong class="{{ $remainingAmount > 0 ? 'text-danger' : 'text-success' }}">{{ $rial($remainingAmount) }}</strong></div>
    </div></div></div>
  </div>

  <div class="card invoice-card mt-3"><div class="card-header">اقلام فاکتور</div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>محصول</th><th>تنوع/مدل</th><th>کد کالا</th><th>تعداد</th><th>قیمت snapshot</th><th>تخفیف ردیف</th><th>جمع</th></tr></thead><tbody>
    @forelse($invoice->items as $item)<tr><td>{{ $item->product?->name ?? ('#'.$item->product_id) }}</td><td>{{ $item->variant?->variant_name ?? $item->variant?->name ?? '—' }}</td><td>{{ $item->variant?->sku ?? $item->product?->sku ?? '—' }}</td><td>{{ number_format((int)$item->quantity) }}</td><td>{{ $rial($item->price) }}</td><td>{{ $rial($item->line_discount_amount ?? 0) }}</td><td>{{ $rial($item->line_total ?? (((int)$item->quantity * (int)$item->price) - (int)($item->line_discount_amount ?? 0))) }}</td></tr>@empty<tr><td colspan="7" class="text-center text-muted py-4">قلمی ثبت نشده است.</td></tr>@endforelse
  </tbody></table></div></div>


  <div class="card invoice-card mt-3"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"><span>پرداخت‌ها</span>@if($canRegisterPayments)<button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#showPaymentModal">افزودن پرداخت</button>@endif</div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>روش</th><th>مبلغ</th><th>تاریخ</th><th>ثبت‌کننده</th><th>توضیحات</th></tr></thead><tbody>@forelse($invoice->payments as $payment)<tr><td>{{ $payment->method === 'cheque' ? 'چکی' : 'نقدی' }}</td><td>{{ $rial($payment->amount) }}</td><td>{{ $payment->paid_at ? Jalalian::fromDateTime($payment->paid_at)->format('Y/m/d') : '—' }}</td><td>{{ $payment->creator?->name ?? '—' }}</td><td>{{ $payment->note ?: '—' }}</td></tr>@empty<tr><td colspan="5" class="text-center text-muted py-3">پرداختی ثبت نشده است.</td></tr>@endforelse</tbody></table></div></div>

  @if($invoice->status === \App\Models\Invoice::STATUS_PENDING_FINANCE_REAPPROVAL)
  <div class="card invoice-card mt-3"><div class="card-header">عملیات مالی</div><div class="card-body d-flex gap-2 flex-wrap justify-content-end"><form method="POST" action="{{ route('finance.invoices.reapprove', $invoice->uuid) }}" data-guard-submit>@csrf<button class="btn btn-success">تأیید مالی و ارسال به صف ارسال</button></form><button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelReapprovalInvoiceModal">لغو فاکتور</button></div></div>
  @endif

  <div class="row g-3 mt-1">
    <div class="col-lg-6"><div class="card invoice-card h-100"><div class="card-header">ارسال</div><div class="card-body">
      @if($invoice->shipping_status || $invoice->shipped_at || $invoice->shippingMethod || $invoice->shipping_note)
        <div class="row g-3"><div class="col-sm-6"><div class="info-label">روش ارسال</div><div class="info-value">{{ $invoice->shippingMethod?->name ?? $invoice->dispatchShippingMethod?->name ?? '—' }}</div></div><div class="col-sm-6"><div class="info-label">زمان ارسال</div><div class="info-value">{{ $invoice->shipped_at ? Jalalian::fromDateTime($invoice->shipped_at)->format('Y/m/d H:i') : '—' }}</div></div><div class="col-sm-6"><div class="info-label">ارسال‌کننده</div><div class="info-value">{{ $invoice->shippedBy?->name ?? '—' }}</div></div><div class="col-12"><div class="info-label">توضیح ارسال</div><div class="info-value">{{ $invoice->shipping_note ?: '—' }}</div></div></div>
      @else <div class="text-muted">اطلاعات ارسال هنوز ثبت نشده است.</div>@endif
    </div></div></div>
    <div class="col-lg-6"><div class="card invoice-card h-100"><div class="card-header">یادداشت‌ها (فقط خواندنی)</div><div class="card-body vstack gap-2">
      @forelse($invoice->notes as $note)<div class="readonly-note"><div>{{ $note->body ?? $note->note }}</div><div class="small text-muted mt-1">{{ $note->user?->name ?? '—' }} | {{ $note->created_at ? Jalalian::fromDateTime($note->created_at)->format('Y/m/d H:i') : '—' }}</div></div>@empty<div class="text-muted">یادداشتی ثبت نشده است.</div>@endforelse
    </div></div></div>
  </div>
</div>

@if($canRegisterPayments)
<div class="modal fade" id="showPaymentModal" tabindex="-1" aria-hidden="true" dir="rtl"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">افزودن پرداخت</h5><button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="alert alert-light border d-flex justify-content-between flex-wrap"><span>مانده قابل پرداخت:</span><strong>{{ $rial($remainingAmount) }}</strong></div><form method="POST" action="{{ route('invoices.payments.store', $invoice->uuid) }}" enctype="multipart/form-data" class="row g-3 payment-fields" data-guard-submit>@csrf<div class="col-md-6"><label class="form-label">روش پرداخت</label><select name="method" class="form-select" data-payment-method><option value="cash">نقدی</option><option value="cheque">چکی</option></select></div><div class="col-md-6"><label class="form-label">مبلغ پرداخت</label><input name="amount" type="number" min="1" max="{{ $remainingAmount }}" class="form-control" required></div><div class="col-md-6"><label class="form-label">تاریخ پرداخت شمسی</label><input name="payment_date" type="text" class="form-control" required data-jdp data-jdp-only-date></div><div class="col-md-6"><label class="form-label">اسم بانک / نام بانک</label><input name="bank_name" class="form-control"></div><div class="col-md-6"><label class="form-label">شماره پیگیری / رسید</label><input name="tracking_number" class="form-control"></div><div class="col-md-6"><label class="form-label">تصویر رسید</label><input name="receipt_image" type="file" class="form-control" accept="image/*,application/pdf"></div><div class="col-md-6 cheque-only d-none"><label class="form-label">شماره چک</label><input name="cheque_number" class="form-control"></div><div class="col-md-6 cheque-only d-none"><label class="form-label">تاریخ سررسید</label><input name="due_date" type="text" class="form-control" data-jdp data-jdp-only-date></div><div class="col-md-6 cheque-only d-none"><label class="form-label">تاریخ دریافت</label><input name="received_date" type="text" class="form-control" data-jdp data-jdp-only-date></div><div class="col-md-6 cheque-only d-none"><label class="form-label">وضعیت چک</label><select name="cheque_status" class="form-select"><option value="pending">در انتظار وصول</option><option value="passed">وصول شده</option><option value="bounced">برگشتی</option><option value="cancelled">کنسل شده</option></select></div><div class="col-12"><label class="form-label">توضیحات</label><textarea name="description" class="form-control" rows="2"></textarea></div><div class="col-12 d-flex gap-2 justify-content-end"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button><button class="btn btn-success">ثبت پرداخت</button></div></form></div></div></div></div>
@endif
@if($invoice->status === \App\Models\Invoice::STATUS_PENDING_FINANCE_REAPPROVAL)
<div class="modal fade" id="cancelReapprovalInvoiceModal" tabindex="-1" aria-hidden="true" dir="rtl"><div class="modal-dialog"><form method="POST" action="{{ route('invoices.cancel', $invoice->uuid) }}" class="modal-content" data-guard-submit>@csrf<div class="modal-header"><h5 class="modal-title text-danger">لغو فاکتور</h5><button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">علت لغو</label><select name="note" class="form-select" required><option value="درخواست مشتری">درخواست مشتری</option><option value="مغایرت اقلام">مغایرت اقلام</option><option value="مغایرت قیمت">مغایرت قیمت</option><option value="ثبت اشتباه">ثبت اشتباه</option><option value="عدم تأیید مالی">عدم تأیید مالی</option><option value="سایر">سایر</option></select><div class="form-text">لغو از مسیر رسمی فاکتور انجام می‌شود و رکورد حذف نمی‌شود.</div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button><button class="btn btn-danger">لغو فاکتور</button></div></form></div></div>
@endif
<script>document.querySelectorAll('[data-guard-submit]').forEach(f=>f.addEventListener('submit',()=>{const b=f.querySelector('button[type="submit"],button:not([type])');if(b){b.disabled=true;b.innerHTML='<span class="spinner-border spinner-border-sm"></span> در حال ارسال';}}));document.querySelectorAll('[data-payment-method]').forEach(s=>s.addEventListener('change',()=>{s.closest('form').querySelectorAll('.cheque-only').forEach(e=>e.classList.toggle('d-none',s.value!=='cheque'));}));</script>

@endsection
