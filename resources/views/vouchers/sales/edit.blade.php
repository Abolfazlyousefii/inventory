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
  <div id="addItemNotice" class="alert alert-info d-none">کالا به فاکتور اضافه شد. تغییر موجودی هنگام ارجاع به مالی اعمال می‌شود.</div>

  @unless($canEditItems)
    <div class="alert alert-warning">این فاکتور در وضعیت «{{ $statusLabels[$invoice->status] ?? $invoice->status }}» قابل حذف و اضافه توسط انبار نیست.</div>
  @endunless

  <form method="POST" action="{{ route('vouchers.sales.update', $invoice->uuid) }}" class="card border-0 shadow-sm" id="salesItemsForm">
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

      <div class="d-flex justify-content-between align-items-center mb-2 gap-2 flex-wrap">
        <h6 class="mb-0">اقلام فاکتور</h6>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addInvoiceItemModal" @disabled(!$canEditItems)>+ افزودن کالا</button>
      </div>
      <div class="table-responsive">
        <table class="table align-middle" id="invoiceItemsTable">
          <thead><tr><th>محصول</th><th>مدل</th><th>تعداد</th><th>قیمت</th><th>تخفیف</th><th>حذف</th></tr></thead>
          <tbody id="invoiceItemsBody">
            @foreach($invoice->items as $it)
              <tr data-variant-id="{{ $it->variant_id }}">
                <td>{{ $it->product?->name ?? '#'.$it->product_id }}</td>
                <td>{{ $it->variant?->variant_name ?? '—' }}</td>
                <td>
                  <input type="hidden" name="items[{{ $loop->index }}][id]" value="{{ $it->id }}">
                  <input type="hidden" name="items[{{ $loop->index }}][product_id]" value="{{ $it->product_id }}">
                  <input type="hidden" name="items[{{ $loop->index }}][variant_id]" value="{{ $it->variant_id }}">
                  <input type="number" min="0" name="items[{{ $loop->index }}][quantity]" value="{{ (int)$it->quantity }}" data-original="{{ (int)$it->quantity }}" class="form-control js-item-field js-item-quantity" @disabled(!$canEditItems)>
                </td>
                <td class="text-nowrap">{{ number_format((int)$it->price) }}</td>
                <td class="text-nowrap">{{ number_format((int)($it->line_discount_amount ?? 0)) }}</td>
                <td><button type="button" class="btn btn-outline-danger btn-sm js-zero-item" @disabled(!$canEditItems)>حذف از فاکتور</button></td>
              </tr>
            @endforeach
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

<script>
const canEditItems = @json($canEditItems);
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
  document.getElementById('invoiceItemsBody').insertAdjacentHTML('beforeend', `<tr data-variant-id="${variant.id}" data-new-row="1" class="table-success"><td>${selectedProduct.name} <span class="badge text-bg-primary">جدید</span><input type="hidden" name="items[${idx}][product_id]" value="${variant.product_id}"></td><td>${variant.title}<input type="hidden" name="items[${idx}][variant_id]" value="${variant.id}"></td><td><input type="number" min="0" name="items[${idx}][quantity]" value="${qty}" data-original="0" class="form-control js-item-field js-item-quantity"></td><td class="text-nowrap">${Number(variant.sell_price).toLocaleString()}<input type="hidden" name="items[${idx}][price]" value="${variant.sell_price}" readonly></td><td class="text-nowrap">0</td><td><button type="button" class="btn btn-outline-danger btn-sm js-zero-item">حذف از فاکتور</button></td></tr>`);
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
@endsection
