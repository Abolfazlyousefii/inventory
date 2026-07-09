@extends('layouts.app')
@php
  use Morilog\Jalali\Jalalian;
  $rial = fn($v) => number_format((int) $v) . ' ریال';
  $statusFa = fn($s) => $statusLabels[$s] ?? ($s ?: '—');
  $paymentText = $paidTotal > $invoice->total ? 'پرداخت اضافه' : ($remainingAmount === 0 ? 'تسویه‌شده' : ($paidTotal > 0 ? 'پرداخت ناقص' : 'پرداخت‌نشده'));
@endphp
@section('content')
<style>
.invoice-edit-page{--blue:#2563eb;--blue-soft:#eff6ff;--border:#dbeafe;--text:#0f172a;--muted:#64748b;max-width:100%;overflow-x:hidden;background:#f8fbff;font-size:.88rem;color:var(--text)}.invoice-edit-card{background:#fff;border:1px solid rgba(37,99,235,.12);border-radius:18px;box-shadow:0 12px 28px rgba(15,23,42,.05);overflow:hidden}.invoice-edit-card__head{background:#f8fbff;border-bottom:1px solid var(--border);padding:13px 16px;font-weight:900;color:#1e3a8a}.invoice-edit-actionbar{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-start;margin-bottom:16px}.invoice-edit-actionbar .btn{border-radius:12px;font-size:.8rem;font-weight:800;padding:8px 13px}.info-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.75rem}.info-box{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:.75rem;min-width:0}.info-label{font-size:.76rem;color:var(--muted)}.info-value{font-weight:800;overflow-wrap:anywhere}.soft-table{width:100%;border-collapse:separate;border-spacing:0 6px}.soft-table th{font-size:.75rem;color:var(--muted);font-weight:800}.soft-table td{background:#fff;border-top:1px solid #eef2ff;border-bottom:1px solid #eef2ff;padding:.65rem}.soft-table td:first-child{border-right:1px solid #eef2ff;border-radius:0 12px 12px 0}.soft-table td:last-child{border-left:1px solid #eef2ff;border-radius:12px 0 0 12px}.table-responsive{overflow-x:auto}#invoiceItemsTable{min-width:760px}.modal{z-index:1060}.modal-backdrop{z-index:1055}.modal-content{border-radius:18px;border:0}.modal-body{overflow-x:hidden}.payment-segment .btn{border-radius:999px;font-weight:900}.notes-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:.75rem}@media(max-width:768px){.info-grid{grid-template-columns:1fr 1fr}.invoice-edit-actionbar .btn{flex:1 1 150px}.modal-dialog{max-width:calc(100% - 16px);margin:.5rem auto}}@media(max-width:576px){.info-grid{grid-template-columns:1fr}.soft-table{min-width:680px}#invoiceItemsTable{min-width:680px}}
</style>
<div class="container py-4 invoice-edit-page">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div><h3 class="mb-1">ویرایش فاکتور {{ $invoice->uuid }}</h3><div class="text-muted">مدیریت اقلام، پرداخت‌ها و یادداشت‌های فاکتور</div></div>
  </div>

  @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
  @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
  @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

  <div class="invoice-edit-actionbar">
    <a class="btn btn-outline-secondary" href="{{ route('invoices.show', $invoice->uuid) }}">بازگشت به مشاهده</a>
    <a class="btn btn-outline-success" href="{{ route('invoices.print', $invoice->uuid) }}" target="_blank">چاپ فاکتور</a>
    <a class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#itemsModal" href="#">افزودن / ویرایش اقلام</a>
    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#paymentModal" @disabled(!$canRegisterPayments || $remainingAmount <= 0)>افزودن پرداخت</button>
    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#noteModal">افزودن یادداشت</button>
  </div>

  <div class="invoice-edit-card mb-3"><div class="invoice-edit-card__head">خلاصه فاکتور</div><div class="card-body"><div class="info-grid">
    @foreach([['شماره فاکتور',$invoice->uuid],['مشتری',$invoice->customer_name ?: $invoice->customer?->display_name],['موبایل',$invoice->customer_mobile ?: $invoice->customer?->mobile],['فروشنده',$invoice->preinvoiceOrder?->creator?->name],['تاریخ صدور',$invoice->created_at ? Jalalian::fromDateTime($invoice->created_at)->format('Y/m/d H:i') : '—'],['وضعیت فعلی',$statusFa($invoice->status)],['مبلغ کل',$rial($invoice->total)],['پرداخت‌شده',$rial($paidTotal)],['مانده',$rial($remainingAmount)],['وضعیت پرداخت',$paymentText]] as [$label,$value])
      <div class="info-box"><div class="info-label">{{ $label }}</div><div class="info-value">{{ $value ?: '—' }}</div></div>
    @endforeach
  </div></div></div>

  <div class="invoice-edit-card mb-3"><div class="invoice-edit-card__head d-flex justify-content-between align-items-center gap-2 flex-wrap"><span>اقلام فاکتور</span><button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#itemsModal">افزودن / ویرایش اقلام</button></div><div class="card-body"><div class="table-responsive"><table class="soft-table"><thead><tr><th>محصول</th><th>تنوع / مدل</th><th>کد کالا</th><th>تعداد</th><th>قیمت snapshot</th><th>تخفیف ردیف</th><th>جمع</th></tr></thead><tbody>@foreach($invoice->items as $it)<tr><td>{{ $it->product?->name ?? '#'.$it->product_id }}</td><td>{{ $it->variant?->variant_name ?? $it->variant?->name ?? '—' }}</td><td>{{ $it->variant?->sku ?? $it->variant?->code ?? '—' }}</td><td>{{ (int)$it->quantity }}</td><td>{{ $rial($it->price) }}</td><td>{{ $rial($it->line_discount_amount ?? 0) }}</td><td>{{ $rial($it->line_total ?? ((int)$it->quantity * (int)$it->price - (int)($it->line_discount_amount ?? 0))) }}</td></tr>@endforeach</tbody></table></div></div></div>

  <div class="invoice-edit-card mb-3"><div class="invoice-edit-card__head d-flex justify-content-between align-items-center gap-2 flex-wrap"><span>پرداخت‌ها</span><button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#paymentModal" @disabled(!$canRegisterPayments || $remainingAmount <= 0)>افزودن پرداخت</button></div><div class="card-body"><div class="info-grid mb-3"><div class="info-box"><div class="info-label">پرداخت‌شده</div><div class="info-value text-success">{{ $rial($paidTotal) }}</div></div><div class="info-box"><div class="info-label">مانده</div><div class="info-value {{ $remainingAmount > 0 ? 'text-danger' : 'text-success' }}">{{ $rial($remainingAmount) }}</div></div></div>@forelse($invoice->payments->sortByDesc('created_at')->take(3) as $payment)<div class="notes-box mb-2 d-flex justify-content-between gap-2 flex-wrap"><span>{{ $payment->method === 'cheque' ? 'چکی' : 'نقدی' }} - {{ $rial($payment->amount) }}</span><span class="text-muted small">{{ $payment->created_at ? Jalalian::fromDateTime($payment->created_at)->format('Y/m/d H:i') : '—' }}</span></div>@empty<div class="text-muted">پرداختی ثبت نشده است.</div>@endforelse</div></div>

  <div class="invoice-edit-card"><div class="invoice-edit-card__head d-flex justify-content-between align-items-center gap-2 flex-wrap"><span>یادداشت‌ها</span><button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#noteModal">افزودن یادداشت</button></div><div class="card-body">@forelse($invoice->notes as $note)<div class="border rounded p-2 mb-2 bg-white">{{ $note->body ?? $note->note }}<div class="small text-muted">{{ $note->user?->name ?? '—' }} | {{ $note->created_at ? Jalalian::fromDateTime($note->created_at)->format('Y/m/d H:i') : '—' }}</div></div>@empty<div class="text-muted">یادداشتی ثبت نشده است.</div>@endforelse</div></div>
</div>

<div class="modal fade" id="itemsModal" tabindex="-1" aria-hidden="true" dir="rtl"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
  <form method="POST" action="{{ route('invoices.update', $invoice->uuid) }}" class="d-block" id="items-editor">
    @csrf @method('PUT')
    <div class="modal-header bg-primary-subtle"><h5 class="modal-title">افزودن / ویرایش اقلام فاکتور</h5><button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><div class="d-flex justify-content-end mb-2"><button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addInvoiceItemModal" @disabled(!$canEditItemsWithCollectionFlow)>+ افزودن کالا</button></div>
      <div id="addItemNotice" class="alert alert-info d-none">کالا به فاکتور اضافه شد. برای اعمال، «ذخیره تغییرات اقلام» را بزنید.</div>
      @unless($canEditItemsWithCollectionFlow)<div class="alert alert-warning">وضعیت فعلی برای حذف و اضافه اقلام مجاز نیست.</div>@endunless
      <div class="table-responsive"><table class="table align-middle" id="invoiceItemsTable"><thead><tr><th>محصول</th><th>مدل</th><th>تعداد</th><th>قیمت snapshot</th><th>تخفیف</th><th>حذف</th></tr></thead><tbody id="invoiceItemsBody">
        @foreach($invoice->items as $it)
          <tr data-variant-id="{{ $it->variant_id }}"><td>{{ $it->product?->name ?? '#'.$it->product_id }}</td><td>{{ $it->variant?->variant_name ?? $it->variant?->name ?? '—' }}</td><td><input type="hidden" name="items[{{ $loop->index }}][id]" value="{{ $it->id }}"><input type="hidden" name="items[{{ $loop->index }}][product_id]" value="{{ $it->product_id }}"><input type="hidden" name="items[{{ $loop->index }}][variant_id]" value="{{ $it->variant_id }}"><input type="number" min="0" name="items[{{ $loop->index }}][quantity]" value="{{ (int)$it->quantity }}" data-original="{{ (int)$it->quantity }}" class="form-control js-item-field js-item-quantity" @disabled(!$canEditItemsWithCollectionFlow)></td><td class="text-nowrap">{{ number_format((int)$it->price) }}</td><td class="text-nowrap">{{ number_format((int)($it->line_discount_amount ?? 0)) }}</td><td><button type="button" class="btn btn-outline-danger btn-sm js-zero-item" @disabled(!$canEditItemsWithCollectionFlow)>حذف</button></td></tr>
        @endforeach
      </tbody></table></div>
      <div class="row g-2"><div class="col-md-4"><label class="form-label">دلیل تغییر اقلام</label><select name="change_reason" class="form-select" @disabled(!$canEditItemsWithCollectionFlow)><option value="">انتخاب کنید</option><option value="physical_shortage">کسری فیزیکی کالا</option><option value="customer_cancelled">انصراف مشتری</option><option value="wrong_item">کالای اشتباه ثبت شده بود</option><option value="warehouse_correction">اصلاح موجودی/انبار</option><option value="replacement">جایگزینی کالا</option><option value="other">سایر</option></select></div><div class="col-md-8"><label class="form-label">توضیح تغییر</label><input name="change_note" class="form-control" placeholder="توضیح تکمیلی حذف، کاهش، افزایش یا افزودن کالا" @disabled(!$canEditItemsWithCollectionFlow)></div></div>
    <div class="alert alert-light border mt-3 mb-0">تغییرات اقلام با منطق امن موجودی ثبت می‌شود. قیمت آیتم جدید از سیستم خوانده می‌شود و قیمت ارسالی از فرم پذیرفته نمی‌شود.</div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button><button class="btn btn-success" @disabled(!$canEditItemsWithCollectionFlow)>ذخیره تغییرات اقلام</button></div>
  </form>

</div></div></div>

<div class="modal fade" id="addInvoiceItemModal" tabindex="-1" aria-labelledby="addInvoiceItemModalLabel" aria-hidden="true" dir="rtl">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-primary-subtle">
        <h5 class="modal-title" id="addInvoiceItemModalLabel">افزودن کالا به فاکتور</h5>
        <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="بستن"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">دسته‌بندی اصلی</label><select class="form-select" id="mainCategorySelect"><option value="">در حال بارگذاری...</option></select></div>
          <div class="col-md-6"><label class="form-label">زیر‌دسته‌بندی</label><select class="form-select" id="childCategorySelect" disabled><option value="">ابتدا دسته اصلی را انتخاب کنید</option></select></div>
          <div class="col-lg-5">
            <label class="form-label">جستجوی کالا</label>
            <input type="search" class="form-control mb-2" id="productSearchInput" placeholder="نام، کد یا SKU" disabled>
            <div class="border rounded bg-light p-2" id="productsList" style="min-height:220px;max-height:360px;overflow:auto;">ابتدا دسته‌بندی و زیر‌دسته را انتخاب کنید.</div>
          </div>
          <div class="col-lg-7">
            <div class="d-flex justify-content-between align-items-center mb-2"><label class="form-label mb-0">تنوع‌های قابل فروش</label><span class="badge text-bg-light" id="selectedProductBadge">محصولی انتخاب نشده</span></div>
            <div class="border rounded p-2" id="variantsList" style="min-height:260px;max-height:420px;overflow:auto;">بعد از انتخاب محصول، تنوع‌های موجودی‌دار نمایش داده می‌شود.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer justify-content-between position-sticky bottom-0 bg-white">
        <div class="fw-bold">جمع تعداد انتخاب‌شده: <span id="selectedQtyTotal">0</span></div>
        <div class="d-flex gap-2"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">بستن</button><button type="button" class="btn btn-primary" id="confirmAddItemsBtn" disabled>افزودن به فاکتور</button></div>
      </div>
    </div>
  </div>
</div>



<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true" dir="rtl"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header bg-primary-subtle"><h5 class="modal-title">افزودن پرداخت</h5><button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="alert alert-light border d-flex justify-content-between flex-wrap"><span>مانده قابل پرداخت:</span><strong>{{ $rial($remainingAmount) }}</strong></div>@if($remainingAmount <= 0)<div class="text-muted">این فاکتور تسویه شده است و پرداخت جدید قابل ثبت نیست.</div>@elseif($canRegisterPayments)<form method="POST" action="{{ route('invoices.payments.store', $invoice->uuid) }}" enctype="multipart/form-data" class="row g-3 payment-fields" id="invoiceEditPaymentForm">@csrf<div class="col-md-6"><label class="form-label">روش پرداخت</label><div class="btn-group w-100 payment-segment" role="group"><input type="radio" class="btn-check" name="method" id="method_cash" value="cash" checked><label class="btn btn-outline-primary" for="method_cash">نقدی</label><input type="radio" class="btn-check" name="method" id="method_cheque" value="cheque"><label class="btn btn-outline-primary" for="method_cheque">چکی</label></div></div><div class="col-md-6"><label class="form-label">مبلغ پرداخت</label><input name="amount" type="number" min="1" max="{{ $remainingAmount }}" class="form-control" required></div><div class="col-md-6"><label class="form-label">تاریخ پرداخت شمسی</label><input name="payment_date" type="text" class="form-control" required data-jdp data-jdp-only-date></div><div class="col-md-6 common-bank"><label class="form-label">اسم بانک / نام بانک</label><input name="bank_name" class="form-control"></div><div class="col-md-6 cash-field"><label class="form-label">شماره پیگیری / رسید</label><input name="tracking_number" class="form-control"></div><div class="col-md-6 cash-field"><label class="form-label">تصویر رسید</label><input name="receipt_image" type="file" class="form-control" accept="image/*,application/pdf"></div><div class="col-md-6 cheque-field d-none"><label class="form-label">شماره چک</label><input name="cheque_number" class="form-control"></div><div class="col-md-6 cheque-field d-none"><label class="form-label">نام شعبه</label><input name="branch_name" class="form-control"></div><div class="col-md-6 cheque-field d-none"><label class="form-label">تاریخ سررسید</label><input name="due_date" type="text" class="form-control" data-jdp data-jdp-only-date></div><div class="col-md-6 cheque-field d-none"><label class="form-label">تاریخ دریافت</label><input name="received_date" type="text" class="form-control" data-jdp data-jdp-only-date></div><div class="col-md-6 cheque-field d-none"><label class="form-label">نام مشتری</label><input name="cheque_owner_name" class="form-control" placeholder="{{ $invoice->customer_name ?: $invoice->customer?->display_name }}"></div><div class="col-md-6 cheque-field d-none"><label class="form-label">کد مشتری</label><input name="customer_code" class="form-control" placeholder="{{ $invoice->customer?->crm_customer_id ?: $invoice->customer_id }}"></div><div class="col-md-6 cheque-field d-none"><label class="form-label">وضعیت چک</label><select name="cheque_status" class="form-select"><option value="pending">در انتظار وصول</option><option value="passed">وصول شده</option><option value="bounced">برگشتی</option><option value="cancelled">کنسل شده</option></select></div><div class="col-12"><label class="form-label">توضیحات</label><textarea name="description" class="form-control" rows="2"></textarea></div><div class="col-12 d-flex gap-2 justify-content-end"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button><button class="btn btn-success">ثبت پرداخت</button></div></form>@else<div class="text-muted">ثبت پرداخت فقط برای نقش مالی/admin/manager فعال است.</div>@endif</div></div></div></div>
<div class="modal fade" id="noteModal" tabindex="-1" aria-hidden="true" dir="rtl"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-primary-subtle"><h5 class="modal-title">افزودن یادداشت فاکتور</h5><button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal"></button></div><form method="POST" action="{{ route('invoices.notes.store', $invoice->uuid) }}">@csrf<div class="modal-body"><label class="form-label">متن یادداشت</label><textarea name="body" class="form-control" rows="4" placeholder="یادداشت جدید را وارد کنید..." required></textarea></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button><button class="btn btn-primary">ثبت یادداشت</button></div></form></div></div></div>
<script>
const canEditItems = @json($canEditItemsWithCollectionFlow);
const endpoints = {categories: @json(route('vouchers.sales.products.categories')), products: @json(route('vouchers.sales.products.by-category')), variantsBase: @json(url('/vouchers/sales/products'))};
let categories = [], selectedProduct = null, debounceTimer = null, nextItemIndex = {{ $invoice->items->count() }};
const reasonSelect = document.querySelector('select[name="change_reason"]');
const modalEl = document.getElementById('addInvoiceItemModal');
const mainCategorySelect = document.getElementById('mainCategorySelect');
const childCategorySelect = document.getElementById('childCategorySelect');
const productSearchInput = document.getElementById('productSearchInput');
const productsList = document.getElementById('productsList');
const variantsList = document.getElementById('variantsList');
const selectedProductBadge = document.getElementById('selectedProductBadge');
const selectedQtyTotal = document.getElementById('selectedQtyTotal');
const confirmAddItemsBtn = document.getElementById('confirmAddItemsBtn');

function itemFields() { return document.querySelectorAll('.js-item-field'); }
function syncChangeReasonRequired() {
  const changed = Array.from(itemFields()).some((field) => String(field.value || '') !== String(field.dataset.original || ''));
  if (reasonSelect) reasonSelect.required = changed;
}
function bindRowButtons(scope = document) {
  scope.querySelectorAll('.js-zero-item').forEach((button) => {
    if (button.dataset.bound) return;
    button.dataset.bound = '1';
    button.addEventListener('click', () => {
      const row = button.closest('tr');
      if (row?.dataset.newRow === '1') row.remove();
      else {
        const quantity = row?.querySelector('input[name$="[quantity]"]');
        if (quantity) { quantity.value = 0; row.classList.add('table-danger'); }
      }
      syncChangeReasonRequired();
    });
  });
  scope.querySelectorAll('.js-item-field').forEach((field) => field.addEventListener('input', syncChangeReasonRequired));
}
async function fetchJson(url) { const res = await fetch(url, {headers: {'Accept': 'application/json'}}); if (!res.ok) throw new Error('خطا در دریافت اطلاعات'); return res.json(); }
function resetModal() {
  selectedProduct = null; productSearchInput.value = ''; productSearchInput.disabled = true;
  productsList.textContent = 'ابتدا دسته‌بندی و زیر‌دسته را انتخاب کنید.'; variantsList.textContent = 'بعد از انتخاب محصول، تنوع‌های موجودی‌دار نمایش داده می‌شود.';
  selectedProductBadge.textContent = 'محصولی انتخاب نشده'; selectedQtyTotal.textContent = '0'; confirmAddItemsBtn.disabled = true;
  childCategorySelect.innerHTML = '<option value="">ابتدا دسته اصلی را انتخاب کنید</option>'; childCategorySelect.disabled = true;
}
async function loadCategories() {
  resetModal(); mainCategorySelect.innerHTML = '<option value="">در حال بارگذاری...</option>';
  const data = await fetchJson(endpoints.categories); categories = data.categories || [];
  mainCategorySelect.innerHTML = '<option value="">انتخاب دسته اصلی</option>' + categories.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
}
function loadChildCategories() {
  const parent = categories.find(c => String(c.id) === String(mainCategorySelect.value));
  childCategorySelect.disabled = !parent; productSearchInput.disabled = true; productsList.textContent = 'ابتدا دسته‌بندی و زیر‌دسته را انتخاب کنید.'; variantsList.textContent = 'بعد از انتخاب محصول، تنوع‌های موجودی‌دار نمایش داده می‌شود.';
  childCategorySelect.innerHTML = '<option value="">انتخاب زیر‌دسته</option>' + (parent?.children || []).map(c => `<option value="${c.id}">${c.name}</option>`).join('');
}
async function loadProducts() {
  if (!childCategorySelect.value) return;
  productSearchInput.disabled = false; productsList.textContent = 'در حال دریافت کالاها...';
  const url = `${endpoints.products}?category_id=${encodeURIComponent(childCategorySelect.value)}&q=${encodeURIComponent(productSearchInput.value || '')}`;
  const data = await fetchJson(url); const products = data.products || [];
  if (!products.length) { productsList.textContent = 'کالایی در این دسته پیدا نشد.'; return; }
  productsList.innerHTML = products.map(p => `<div class="card mb-2 product-card" data-id="${p.id}" data-name="${p.name}" data-sku="${p.sku || ''}"><div class="card-body py-2 d-flex justify-content-between gap-2"><div><div class="fw-bold">${p.name}</div><div class="small text-muted">${p.sku || 'بدون کد'} | ${p.active_variants_count} تنوع فعال</div></div><button type="button" class="btn btn-sm btn-outline-primary js-select-product">انتخاب</button></div></div>`).join('');
}
async function loadVariants(productId, productName) {
  selectedProduct = {id: productId, name: productName}; selectedProductBadge.textContent = productName; variantsList.textContent = 'در حال دریافت تنوع‌ها...';
  const data = await fetchJson(`${endpoints.variantsBase}/${productId}/variants`); const variants = data.variants || [];
  if (!variants.length) { variantsList.textContent = 'تنوع قابل فروش برای این محصول وجود ندارد.'; updateSelectedTotal(); return; }
  variantsList.innerHTML = variants.map(v => `<div class="border rounded p-2 mb-2 variant-row" data-variant='${JSON.stringify(v).replace(/'/g, '&#39;')}'><div class="row g-2 align-items-center"><div class="col-md-5"><div class="fw-bold">${v.title}</div><div class="small text-muted">${v.sku || 'بدون کد'}</div></div><div class="col-6 col-md-2"><span class="badge text-bg-success">موجودی: ${v.available_stock}</span></div><div class="col-6 col-md-2 small">${Number(v.sell_price).toLocaleString()} ریال</div><div class="col-md-3"><input type="number" min="0" max="${v.available_stock}" value="0" class="form-control variant-qty" placeholder="تعداد"></div><div class="col-12 small text-danger variant-error d-none">تعداد از موجودی قابل فروش بیشتر است.</div></div></div>`).join('');
  updateSelectedTotal();
}
function updateSelectedTotal() {
  let total = 0; document.querySelectorAll('.variant-qty').forEach(input => { const max = Number(input.max || 0); let val = Math.max(0, Number(input.value || 0)); if (val > max) { val = max; input.value = max; input.closest('.variant-row').querySelector('.variant-error')?.classList.remove('d-none'); } else input.closest('.variant-row').querySelector('.variant-error')?.classList.add('d-none'); total += val; });
  selectedQtyTotal.textContent = total; confirmAddItemsBtn.disabled = total <= 0;
}
function addItemRow(variant, qty) {
  const existing = document.querySelector(`#invoiceItemsBody tr[data-variant-id="${variant.id}"]`);
  if (existing) { const input = existing.querySelector('input[name$="[quantity]"]'); input.value = Number(input.value || 0) + qty; existing.classList.add('table-warning'); return; }
  const idx = nextItemIndex++;
  document.getElementById('invoiceItemsBody').insertAdjacentHTML('beforeend', `<tr data-variant-id="${variant.id}" data-new-row="1" class="table-success"><td>${selectedProduct.name} <span class="badge text-bg-primary">جدید</span><input type="hidden" name="items[${idx}][product_id]" value="${variant.product_id}"></td><td>${variant.title}<input type="hidden" name="items[${idx}][variant_id]" value="${variant.id}"></td><td><input type="number" min="0" name="items[${idx}][quantity]" value="${qty}" data-original="0" class="form-control js-item-field js-item-quantity"></td><td class="text-nowrap">${Number(variant.sell_price).toLocaleString()}</td><td class="text-nowrap">0</td><td><button type="button" class="btn btn-outline-danger btn-sm js-zero-item">حذف از فاکتور</button></td></tr>`);
  bindRowButtons(document.getElementById('invoiceItemsBody'));
}
modalEl?.addEventListener('shown.bs.modal', () => { if (canEditItems) loadCategories().catch(e => mainCategorySelect.innerHTML = `<option value="">${e.message}</option>`); });
mainCategorySelect?.addEventListener('change', loadChildCategories);
childCategorySelect?.addEventListener('change', loadProducts);
productSearchInput?.addEventListener('input', () => { clearTimeout(debounceTimer); debounceTimer = setTimeout(loadProducts, 300); });
productsList?.addEventListener('click', (e) => { const card = e.target.closest('.product-card'); if (card && e.target.classList.contains('js-select-product')) loadVariants(card.dataset.id, card.dataset.name); });
variantsList?.addEventListener('input', (e) => { if (e.target.classList.contains('variant-qty')) updateSelectedTotal(); });
confirmAddItemsBtn?.addEventListener('click', () => { document.querySelectorAll('.variant-row').forEach(row => { const qty = Number(row.querySelector('.variant-qty').value || 0); if (qty > 0) addItemRow(JSON.parse(row.dataset.variant), qty); }); bootstrap.Modal.getInstance(modalEl)?.hide(); document.getElementById('addItemNotice')?.classList.remove('d-none'); syncChangeReasonRequired(); });
bindRowButtons(); syncChangeReasonRequired();
</script>

<script>(function(){const methods=document.querySelectorAll('input[name="method"]');function selectedMethod(){return document.querySelector('input[name="method"]:checked')?.value || 'cash';}function toggle(){const cheque=selectedMethod()==='cheque';document.querySelectorAll('.cheque-field').forEach(el=>el.classList.toggle('d-none',!cheque));document.querySelectorAll('.cash-field').forEach(el=>el.classList.toggle('d-none',cheque));document.querySelectorAll('[name="cheque_number"],[name="due_date"]').forEach(el=>el.required=cheque);}methods.forEach(el=>el.addEventListener('change',toggle));toggle();})();</script>
@endsection
