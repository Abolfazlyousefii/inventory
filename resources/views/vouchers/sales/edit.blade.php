@extends('layouts.app')

@php
  use Morilog\Jalali\Jalalian;
  $toJalali = fn($date) => $date ? Jalalian::fromDateTime($date)->format('Y/m/d H:i') : '—';
@endphp

@section('content')
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h4 class="mb-1">🧾 حذف و اضافه فاکتور</h4>
      <div class="text-muted small">اصلاح اقلام توسط انبار و ارجاع برای تایید مالی</div>
    </div>
    <div class="d-flex gap-2">
      <a href="{{ route('vouchers.sales.print', $invoice->uuid) }}" target="_blank" class="btn btn-outline-success">چاپ</a>
      <a href="{{ route('vouchers.sales.show', $invoice->uuid) }}" class="btn btn-outline-secondary">نمایش</a>
      <a href="{{ route('vouchers.sales.queue') }}" class="btn btn-outline-dark">بازگشت</a>
    </div>
  </div>

  @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
  @if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif

  @unless($canEditItems)
    <div class="alert alert-warning">این فاکتور در وضعیت «{{ $statusLabels[$invoice->status] ?? $invoice->status }}» قابل حذف و اضافه توسط انبار نیست.</div>
  @endunless

  <form method="POST" action="{{ route('vouchers.sales.update', $invoice->uuid) }}" class="card border-0 shadow-sm">
    @csrf
    @method('PUT')
    <div class="card-body">
      <div class="row g-2 mb-3">
        <div class="col-md-3"><b>کد فاکتور:</b> {{ $invoice->uuid }}</div>
        <div class="col-md-3"><b>وضعیت:</b> {{ $statusLabels[$invoice->status] ?? $invoice->status }}</div>
        <div class="col-md-3"><b>ایجاد:</b> {{ $toJalali($invoice->created_at) }}</div>
        <div class="col-md-3"><b>آخرین بروزرسانی:</b> {{ $toJalali($invoice->updated_at) }}</div>
        <div class="col-md-3"><b>دریافت انبار:</b> {{ $toJalali($invoice->warehouse_received_at) }}</div>
        <div class="col-md-3"><b>شروع جمع‌آوری:</b> {{ $toJalali($invoice->collection_started_at) }}</div>
        <div class="col-md-3"><b>اتمام جمع‌آوری:</b> {{ $toJalali($invoice->collected_at) }}</div>
      </div>

      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>محصول</th><th>مدل</th><th>تعداد</th><th>قیمت</th><th>تخفیف</th><th>حذف</th></tr></thead>
          <tbody>
            @foreach($invoice->items as $it)
              <tr>
                <td>{{ $it->product?->name ?? '#'.$it->product_id }}</td>
                <td>{{ $it->variant?->variant_name ?? '—' }}</td>
                <td>
                  <input type="hidden" name="items[{{ $loop->index }}][id]" value="{{ $it->id }}">
                  <input type="number" min="0" name="items[{{ $loop->index }}][quantity]" value="{{ (int)$it->quantity }}" data-original="{{ (int)$it->quantity }}" class="form-control js-item-field" @disabled(!$canEditItems)>
                </td>
                <td class="text-nowrap">{{ number_format((int)$it->price) }}</td>
                <td class="text-nowrap">{{ number_format((int)($it->line_discount_amount ?? 0)) }}</td>
                <td><button type="button" class="btn btn-outline-danger btn-sm js-zero-item" @disabled(!$canEditItems)>حذف از فاکتور</button></td>
              </tr>
            @endforeach
            <tr class="table-info">
                <td><input name="items[999][product_id]" class="form-control" placeholder="شناسه محصول جدید" @disabled(!$canEditItems)></td>
                <td><input name="items[999][variant_id]" data-original="" class="form-control js-item-field" placeholder="شناسه تنوع فعال" @disabled(!$canEditItems)></td>
                <td><input type="number" min="0" name="items[999][quantity]" value="0" data-original="0" class="form-control js-item-field" @disabled(!$canEditItems)></td>
                <td class="small text-muted" colspan="2">قیمت از قیمت فروش تنوع کالا در سیستم خوانده می‌شود و تخفیف کالای جدید صفر است.</td>
                <td class="text-muted small">برای افزودن کالا، شناسه تنوع و تعداد را وارد کنید.</td>
              </tr>
          </tbody>
        </table>
      </div>
      <div class="row g-2">
        <div class="col-md-4">
          <label class="form-label">دلیل تغییر اقلام <span class="text-danger">*</span></label>
          <select name="change_reason" class="form-select" required @disabled(!$canEditItems)>
            <option value="">انتخاب کنید</option>
            <option value="physical_shortage">کالا در نرم‌افزار موجود بود ولی فیزیکی پیدا نشد</option>
            <option value="customer_cancelled">انصراف مشتری</option>
            <option value="wrong_item">کالای اشتباه ثبت شده بود</option>
            <option value="warehouse_correction">اصلاح انبار</option>
            <option value="replacement">جایگزینی کالا</option>
            <option value="other">سایر</option>
          </select>
        </div>
        <div class="col-md-8">
          <label class="form-label">توضیح تغییر / یادداشت انبار</label>
          <input name="change_note" class="form-control" placeholder="توضیح تکمیلی حذف، کاهش، افزایش یا افزودن کالا" @disabled(!$canEditItems)>
        </div>
      </div>
    </div>
    <div class="card-footer text-end">
      <button class="btn btn-success" @disabled(!$canEditItems)>ارجاع به مالی</button>
    </div>
  </form>
</div>
<script>
const reasonSelect = document.querySelector('select[name="change_reason"]');
const itemFields = document.querySelectorAll('.js-item-field');
const syncChangeReasonRequired = () => {
  const changed = Array.from(itemFields).some((field) => String(field.value || '') !== String(field.dataset.original || ''));
  if (reasonSelect) reasonSelect.required = changed;
};
itemFields.forEach((field) => field.addEventListener('input', syncChangeReasonRequired));
document.querySelectorAll('.js-zero-item').forEach((button) => {
  button.addEventListener('click', () => {
    const row = button.closest('tr');
    const quantity = row?.querySelector('input[name$="[quantity]"]');
    if (quantity) {
      quantity.value = 0;
      row.classList.add('table-danger');
      syncChangeReasonRequired();
    }
  });
});
syncChangeReasonRequired();
</script>
@endsection
