@extends('layouts.app')
@php
  use Morilog\Jalali\Jalalian;
  $rial = fn($v) => number_format((int) $v) . ' ریال';
  $statusFa = fn($s) => $statusLabels[$s] ?? ($s ?: '—');
@endphp
@section('content')
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div><h3 class="mb-1">مدیریت فاکتور {{ $invoice->uuid }}</h3><div class="text-muted">تغییرات این صفحه ثبت لاگ می‌شود و وضعیت فاکتور دستی تغییر نمی‌کند.</div></div>
    <div class="d-flex gap-2 flex-wrap"><a class="btn btn-outline-secondary" href="{{ route('invoices.show', $invoice->uuid) }}">بازگشت به مشاهده</a><a class="btn btn-outline-primary" href="{{ route('invoices.history', $invoice->uuid) }}">تاریخچه</a><a class="btn btn-outline-dark" href="{{ route('invoices.print', $invoice->uuid) }}" target="_blank">چاپ</a></div>
  </div>

  <div class="alert alert-info">تغییر status با select در نسخه جدید حذف شده است. وضعیت فقط از مسیرهای رسمی جمع‌آوری، تایید مجدد مالی، ارسال و برگشت رسمی تغییر می‌کند.</div>

  <div class="row g-3">
    <div class="col-lg-4"><div class="card h-100"><div class="card-header fw-bold">خلاصه read-only</div><div class="card-body vstack gap-2"><div>مشتری: <b>{{ $invoice->customer_name ?: '—' }}</b></div><div>موبایل: <b>{{ $invoice->customer_mobile ?: '—' }}</b></div><div>فروشنده: <b>{{ $invoice->preinvoiceOrder?->creator?->name ?? '—' }}</b></div><div>وضعیت: <b>{{ $statusFa($invoice->status) }}</b></div><div>کل: <b>{{ $rial($invoice->total) }}</b></div><div>پرداخت‌شده: <b class="text-success">{{ $rial($paidTotal) }}</b></div><div>مانده: <b class="{{ $remainingAmount > 0 ? 'text-danger' : 'text-success' }}">{{ $rial($remainingAmount) }}</b></div></div></div></div>

    <div class="col-lg-8" id="payments"><div class="card h-100"><div class="card-header fw-bold">ثبت پرداخت</div><div class="card-body">
      @if($canRegisterPayments && $remainingAmount > 0)
      <form method="POST" action="{{ route('invoices.payments.store', $invoice->uuid) }}" enctype="multipart/form-data" class="row g-3">
        @csrf
        <div class="col-md-3"><label class="form-label">روش</label><select name="method" class="form-select" required><option value="cash">نقدی</option><option value="cheque">چکی</option></select></div>
        <div class="col-md-3"><label class="form-label">مبلغ</label><input name="amount" type="number" min="1" max="{{ $remainingAmount }}" class="form-control" required><div class="form-text">حداکثر {{ $rial($remainingAmount) }}</div></div>
        <div class="col-md-3"><label class="form-label">تاریخ پرداخت</label><input name="paid_at" type="date" class="form-control" required></div>
        <div class="col-md-3"><label class="form-label">نام بانک</label><input name="bank_name" class="form-control"></div><div class="col-md-3"><label class="form-label">رسید/پیگیری</label><input name="payment_identifier" class="form-control"></div>
        <div class="col-12"><label class="form-label">توضیح</label><textarea name="note" class="form-control" rows="2"></textarea></div>
        <div class="col-12 text-end"><button class="btn btn-success">ثبت پرداخت</button></div>
      </form>
      @else <div class="text-muted">ثبت پرداخت فقط برای نقش مالی/admin/manager و در صورت وجود مانده فعال است.</div>@endif
    </div></div></div>
  </div>

  <div class="card mt-3"><div class="card-header fw-bold">یادداشت‌ها</div><div class="card-body">
    <form method="POST" action="{{ route('invoices.notes.store', $invoice->uuid) }}" class="mb-3">@csrf<label class="form-label">افزودن یادداشت مدیریتی</label><textarea name="body" class="form-control" rows="2" required></textarea><div class="text-end mt-2"><button class="btn btn-primary">ثبت یادداشت</button></div></form>
    @forelse($invoice->notes as $note)<div class="border rounded p-2 mb-2">{{ $note->body ?? $note->note }}<div class="small text-muted">{{ $note->user?->name ?? '—' }} | {{ $note->created_at ? Jalalian::fromDateTime($note->created_at)->format('Y/m/d H:i') : '—' }}</div></div>@empty<div class="text-muted">یادداشتی ثبت نشده است.</div>@endforelse
  </div></div>

  <div class="card mt-3"><div class="card-header fw-bold">حذف و اضافه اقلام</div><div class="card-body">
    <p class="text-muted mb-3">این عملیات از UI و منطق delta-safe صف جمع‌آوری سریع reuse می‌شود؛ price از request پذیرفته نمی‌شود و قیمت اقلام جدید در backend از sell_price تنوع خوانده می‌شود.</p>
    @if($canEditItemsWithCollectionFlow)
      <a class="btn btn-primary" href="{{ route('vouchers.sales.edit', $invoice->uuid) }}">باز کردن حذف و اضافه سریع اقلام</a>
    @else
      <div class="alert alert-warning mb-0">وضعیت فعلی برای حذف و اضافه اقلام در صف جمع‌آوری مجاز نیست.</div>
    @endif
  </div></div>

  <div class="card mt-3"><div class="card-header fw-bold">تغییر قیمت آیتم‌ها</div><div class="card-body">
    @if($canEditPrices)
      <div class="alert alert-secondary">فرم تغییر قیمت در این Patch عمداً غیرفعال نگه داشته شد تا workflow کامل لاگ old_price/new_price، دلیل اجباری، تایید مجدد مالی و جلوگیری از sync تکراری ledger در Patch جداگانه پیاده‌سازی شود.</div>
      <div class="table-responsive"><table class="table"><thead><tr><th>محصول</th><th>مدل</th><th>قیمت snapshot فعلی</th><th>دلیل تغییر</th></tr></thead><tbody>@foreach($invoice->items as $item)<tr><td>{{ $item->product?->name ?? ('#'.$item->product_id) }}</td><td>{{ $item->variant?->variant_name ?? '—' }}</td><td>{{ $rial($item->price) }}</td><td><input class="form-control" disabled placeholder="در Patch بعدی اجباری می‌شود"></td></tr>@endforeach</tbody></table></div>
    @else <div class="text-muted">تغییر قیمت فقط برای مالی/admin/manager مجاز است.</div>@endif
  </div></div>

  <div class="card mt-3"><div class="card-header fw-bold">خلاصه تغییرات و ثبت نهایی</div><div class="card-body"><div class="text-muted">در این Patch، ثبت مستقیم تغییرات قیمت/تعداد از فرم فاکتور غیرفعال شده است. عملیات پرداخت و یادداشت فرم‌های مستقل دارند و حذف/اضافه اقلام به flow رسمی صف جمع‌آوری متصل است.</div></div></div>
</div>
@endsection
