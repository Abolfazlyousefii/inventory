@extends('layouts.app')

@section('content')
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">🧾 ویرایش فاکتور توسط مالی</h4>
    <div class="d-flex gap-2">
      <a href="{{ route('invoices.print', $invoice->uuid) }}" target="_blank" class="btn btn-outline-success">چاپ</a>
      <a href="{{ route('invoices.show', $invoice->uuid) }}" class="btn btn-outline-secondary">نمایش</a>
      <a href="{{ route('invoices.index') }}" class="btn btn-outline-dark">بازگشت</a>
    </div>
  </div>

  @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
  @if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif

  <form method="POST" action="{{ route('invoices.update', $invoice->uuid) }}" class="card border-0 shadow-sm" id="sales-items-form">
    @csrf
    @method('PUT')
    <div class="card-body">
      <div class="row g-2 mb-3">
        <div class="col-md-6"><b>شماره فاکتور:</b> {{ $invoice->uuid }}</div>
        <div class="col-md-6 text-md-end"><b>وضعیت:</b> {{ $statusLabels[$invoice->status] ?? $invoice->status }}</div>
      </div>

      <div class="row g-2 mb-3">
        <div class="col-md-4">
          <label class="form-label">نام مشتری</label>
          <input type="text" class="form-control" value="{{ $invoice->customer_name ?: $invoice->customer?->display_name }}" readonly>
        </div>
        <div class="col-md-4">
          <label class="form-label">موبایل مشتری</label>
          <input type="text" class="form-control" value="{{ $invoice->customer_mobile ?: $invoice->customer?->mobile }}" readonly>
        </div>
        <div class="col-md-4">
          <label class="form-label">کد مشتری</label>
          <input type="text" class="form-control" value="{{ $invoice->customer?->crm_customer_id ?: $invoice->customer_id }}" readonly>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>محصول</th><th>مدل</th><th>تعداد</th><th>قیمت</th><th>تخفیف ردیفی</th><th>جمع ردیف</th><th>حذف</th></tr></thead>
          <tbody>
            @foreach($invoice->items as $it)
              <tr>
                <td>{{ $it->product?->name ?? '#'.$it->product_id }}</td>
                <td>{{ $it->variant?->variant_name ?? '—' }}</td>
                <td>
                  <input type="hidden" name="items[{{ $loop->index }}][id]" value="{{ $it->id }}"><input type="hidden" name="items[{{ $loop->index }}][product_id]" value="{{ $it->product_id }}"><input type="hidden" name="items[{{ $loop->index }}][variant_id]" value="{{ $it->variant_id }}" class="js-item-field" data-original="{{ $it->variant_id }}">
                  <input type="hidden" name="items[{{ $loop->index }}][sort_order]" value="{{ $it->sort_order ?? $it->id }}">
                  <input type="number" min="0" name="items[{{ $loop->index }}][quantity]" value="{{ (int)$it->quantity }}" data-original="{{ (int)$it->quantity }}" class="form-control js-item-field" >
                </td>
                <td>
                  <input type="text" inputmode="numeric" value="{{ number_format((int)$it->price) }}" data-raw-target="item-price-{{ $loop->index }}" class="form-control js-money" >
                  <input id="item-price-{{ $loop->index }}" type="hidden" name="items[{{ $loop->index }}][price]" value="{{ (int)$it->price }}" data-original="{{ (int)$it->price }}" class="js-item-field">
                </td>
                <td>
                  <input type="text" inputmode="numeric" value="{{ number_format((int)($it->line_discount_amount ?? 0)) }}" data-raw-target="item-discount-{{ $loop->index }}" class="form-control js-money">
                  <input id="item-discount-{{ $loop->index }}" type="hidden" name="items[{{ $loop->index }}][line_discount_amount]" value="{{ (int)($it->line_discount_amount ?? 0) }}" data-original="{{ (int)($it->line_discount_amount ?? 0) }}" class="js-item-field js-line-discount">
                </td>
                <td class="js-row-total fw-bold">—</td>
                <td><button type="button" class="btn btn-outline-danger btn-sm js-zero-item" >حذف از فاکتور</button></td>
              </tr>
            @endforeach
          </tbody>
        </table>
        <button type="button" class="btn btn-outline-primary" id="open-add-item-modal" >افزودن کالا</button>
      </div>
      <div class="row g-2 mt-3">
        <div class="col-md-4">
          <label class="form-label">تخفیف کلی فاکتور</label>
          <input type="text" inputmode="numeric" value="{{ number_format((int) old('discount_amount', $invoice->discount_amount)) }}" data-raw-target="invoice-discount" class="form-control js-money">
          <input id="invoice-discount" type="hidden" name="discount_amount" value="{{ (int) old('discount_amount', $invoice->discount_amount) }}">
        </div>
        <div class="col-md-4">
          <label class="form-label">هزینه ارسال</label>
          <input type="text" inputmode="numeric" value="{{ number_format((int) old('shipping_price', $invoice->shipping_price)) }}" data-raw-target="invoice-shipping" class="form-control js-money">
          <input id="invoice-shipping" type="hidden" name="shipping_price" value="{{ (int) old('shipping_price', $invoice->shipping_price) }}">
        </div>
        <div class="col-md-4">
          <label class="form-label">یادداشت ویرایش مالی <span class="text-danger">*</span></label>
          <textarea name="edit_reason" id="edit_reason" form="sales-items-form" class="form-control @error('edit_reason') is-invalid @enderror" rows="2" required placeholder="علت و شرح ویرایش فاکتور را بنویسید">{{ old('edit_reason') }}</textarea>
          @error('edit_reason')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
          <div class="invalid-feedback d-block js-edit-reason-error" style="display:none !important;">لطفاً دلیل ویرایش مالی را وارد کنید.</div>
        </div>
      </div>
      <div class="alert alert-info mt-3" id="invoiceTotalsBox">مجموع‌ها پس از تغییر اقلام و تخفیف‌ها به صورت زنده در مرورگر محاسبه می‌شود و در ذخیره نهایی دوباره در سرور کنترل خواهد شد.</div>
    </div>
    <div class="card-footer text-end">
      <button class="btn btn-success" >ذخیره ویرایش فاکتور</button>
    </div>
  </form>

  <div class="modal fade" id="addItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">افزودن کالا به فاکتور</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body row g-3">
        <div class="col-md-6"><label class="form-label">دسته‌بندی اصلی</label><select id="modal-category" class="form-select"><option value="">انتخاب کنید...</option></select></div>
        <div class="col-md-6"><label class="form-label">زیر دسته‌بندی</label><select id="modal-subcategory" class="form-select"><option value="">ابتدا دسته اصلی...</option></select></div>
        <div class="col-md-8"><label class="form-label">جستجوی نام کالا / کد کالا / SKU</label><input id="modal-product-search" class="form-control" placeholder="حداقل ۲ حرف وارد کنید"></div>
        <div class="col-md-4"><label class="form-label">کالا</label><select id="modal-product" class="form-select"><option value="">جستجو کنید...</option></select></div>
        <div class="col-md-6"><label class="form-label">تنوع کالا</label><select id="modal-variant" class="form-select"><option value="">ابتدا کالا...</option></select></div>
        <div class="col-md-6"><label class="form-label">موجودی آزاد انبار مرکزی</label><input id="modal-stock" class="form-control" readonly value="—"></div>
        <div class="col-md-6"><label class="form-label">تعداد</label><input id="modal-quantity" type="number" min="1" value="1" class="form-control"></div>
        <div class="col-md-6"><label class="form-label">قیمت همین فاکتور</label><input id="modal-price" inputmode="numeric" class="form-control js-money-standalone"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button><button type="button" class="btn btn-primary" id="add-modal-item">افزودن به فاکتور</button></div>
    </div></div>
  </div>
</div>
<script>
const form = document.getElementById('sales-items-form');
const reasonSelect = document.querySelector('select[name="_unused_change_reason"]');
const tbody = form?.querySelector('tbody');
let nextItemIndex = 1000;
const faMap = {'۰':'0','۱':'1','۲':'2','۳':'3','۴':'4','۵':'5','۶':'6','۷':'7','۸':'8','۹':'9','٠':'0','١':'1','٢':'2','٣':'3','٤':'4','٥':'5','٦':'6','٧':'7','٨':'8','٩':'9'};
const rawNumber = (value) => String(value || '').replace(/[۰-۹٠-٩]/g, ch => faMap[ch] || ch).replace(/[٬,\s]/g, '').replace(/[^0-9]/g, '');
const formatMoney = (value) => { const raw = rawNumber(value); return raw ? Number(raw).toLocaleString('en-US') : ''; };
function bindMoneyInputs(root = document) {
  root.querySelectorAll('.js-money').forEach((input) => {
    const sync = () => {
      const raw = rawNumber(input.value);
      const target = document.getElementById(input.dataset.rawTarget);
      if (target) { target.value = raw || '0'; target.dispatchEvent(new Event('input', {bubbles:true})); }
      input.value = formatMoney(raw);
    };
    input.addEventListener('input', sync); sync();
  });
  root.querySelectorAll('.js-money-standalone').forEach((input) => input.addEventListener('input', () => input.value = formatMoney(input.value)));
}
const itemFields = () => Array.from(document.querySelectorAll('.js-item-field'));
const syncChangeReasonRequired = () => {
  const changed = itemFields().some((field) => String(field.value || '') !== String(field.dataset.original || ''));
  if (reasonSelect) reasonSelect.required = changed;
};
document.addEventListener('input', (e) => { if (e.target.classList.contains('js-item-field')) syncChangeReasonRequired(); });
document.querySelectorAll('.js-zero-item').forEach((button) => {
  button.addEventListener('click', () => {
    const row = button.closest('tr');
    const quantity = row?.querySelector('input[name$="[quantity]"]');
    if (quantity) { quantity.value = 0; quantity.dispatchEvent(new Event('input', {bubbles:true})); row.classList.add('table-danger'); }
  });
});
bindMoneyInputs(); syncChangeReasonRequired();
function calculateTotals(){
  let subtotal=0, lineDiscount=0;
  document.querySelectorAll('#sales-items-form tbody tr').forEach(row=>{
    const q=Number(rawNumber(row.querySelector('input[name$="[quantity]"]')?.value)||0);
    const p=Number(rawNumber(row.querySelector('input[name$="[price]"]')?.value)||0);
    const d=Number(rawNumber(row.querySelector('input[name$="[line_discount_amount]"]')?.value)||0);
    const rowSubtotal=q*p; const rowDiscount=Math.min(d,rowSubtotal); subtotal+=rowSubtotal; lineDiscount+=rowDiscount;
    const cell=row.querySelector('.js-row-total'); if(cell) cell.textContent=(rowSubtotal-rowDiscount).toLocaleString('en-US');
  });
  const invoiceDiscount=Number(rawNumber(document.getElementById('invoice-discount')?.value)||0);
  const shipping=Number(rawNumber(document.getElementById('invoice-shipping')?.value)||0);
  const total=Math.max(subtotal-lineDiscount-invoiceDiscount,0)+shipping;
  const box=document.getElementById('invoiceTotalsBox'); if(box) box.innerHTML=`جمع کالا: <b>${subtotal.toLocaleString('en-US')}</b> | تخفیف ردیفی: <b>${lineDiscount.toLocaleString('en-US')}</b> | تخفیف کلی: <b>${invoiceDiscount.toLocaleString('en-US')}</b> | مبلغ نهایی: <b>${total.toLocaleString('en-US')}</b> ریال`;
}
document.addEventListener('input', calculateTotals);
calculateTotals();

const modalEl = document.getElementById('addItemModal');
const modal = modalEl && window.bootstrap ? new bootstrap.Modal(modalEl) : null;
const urls = {
  categories: @json(route('vouchers.sales.ajax.categories')),
  subcategories: @json(route('vouchers.sales.ajax.subcategories')),
  products: @json(route('vouchers.sales.ajax.products')),
  variants: @json(url('/vouchers/sales/ajax/products')),
};
const category = document.getElementById('modal-category'), subcategory = document.getElementById('modal-subcategory'), productSearch = document.getElementById('modal-product-search'), product = document.getElementById('modal-product'), variant = document.getElementById('modal-variant'), stock = document.getElementById('modal-stock'), qty = document.getElementById('modal-quantity'), price = document.getElementById('modal-price');
const option = (v,t,d={}) => `<option value="${String(v).replaceAll('"','&quot;')}" ${Object.entries(d).map(([k,val])=>`data-${k}="${String(val ?? '').replaceAll('"','&quot;')}"`).join(' ')}>${t}</option>`;
async function loadJson(url) { const r = await fetch(url, {headers:{'Accept':'application/json'}}); if (!r.ok) throw new Error('خطا در دریافت اطلاعات'); return await r.json(); }
document.getElementById('open-add-item-modal')?.addEventListener('click', async () => {
  if (category && category.options.length <= 1) (await loadJson(urls.categories)).forEach(c => category.insertAdjacentHTML('beforeend', option(c.id, c.name)));
  modal?.show();
});
category?.addEventListener('change', async () => {
  subcategory.innerHTML = '<option value="">همه زیرگروه‌ها</option>';
  if (category.value) (await loadJson(`${urls.subcategories}?parent_id=${category.value}`)).forEach(c => subcategory.insertAdjacentHTML('beforeend', option(c.id, c.name)));
});
async function searchProducts() {
  const cat = subcategory.value || category.value || '';
  const q = encodeURIComponent(productSearch.value || '');
  product.innerHTML = '<option value="">انتخاب کنید...</option>';
  (await loadJson(`${urls.products}?category_id=${cat}&q=${q}`)).forEach(p => product.insertAdjacentHTML('beforeend', option(p.id, `${p.name}${p.code ? ' ['+p.code+']' : ''}`)));
}
productSearch?.addEventListener('input', () => { clearTimeout(productSearch._t); productSearch._t = setTimeout(searchProducts, 300); });
subcategory?.addEventListener('change', searchProducts);
product?.addEventListener('change', async () => {
  variant.innerHTML = '<option value="">انتخاب تنوع...</option>'; stock.value = '—'; price.value = '';
  if (!product.value) return;
  (await loadJson(`${urls.variants}/${product.value}/variants`)).forEach(v => variant.insertAdjacentHTML('beforeend', option(v.id, `${v.name}${v.variant_code ? ' ['+v.variant_code+']' : ''}`, {stock:v.available_stock, price:v.sell_price})));
});
variant?.addEventListener('change', () => {
  const opt = variant.selectedOptions[0]; stock.value = opt?.dataset.stock ?? '—'; price.value = formatMoney(opt?.dataset.price || '0');
});
document.getElementById('add-modal-item')?.addEventListener('click', () => {
  if (!product.value || !variant.value || Number(qty.value || 0) < 1) { alert('کالا، تنوع و تعداد معتبر را انتخاب کنید.'); return; }
  const i = nextItemIndex++;
  const tr = document.createElement('tr'); tr.className = 'table-success';
  tr.innerHTML = `<td>${product.selectedOptions[0].text}<input type="hidden" name="items[${i}][product_id]" value="${product.value}"></td><td>${variant.selectedOptions[0].text}<input type="hidden" name="items[${i}][variant_id]" value="${variant.value}" class="js-item-field" data-original=""></td><td><input type="number" min="1" name="items[${i}][quantity]" value="${qty.value}" data-original="0" class="form-control js-item-field"></td><td><input type="text" inputmode="numeric" value="${formatMoney(price.value)}" data-raw-target="item-price-${i}" class="form-control js-money"><input id="item-price-${i}" type="hidden" name="items[${i}][price]" value="${rawNumber(price.value) || 0}" data-original="0" class="js-item-field"></td><td><input type="text" inputmode="numeric" value="0" data-raw-target="item-discount-${i}" class="form-control js-money"><input id="item-discount-${i}" type="hidden" name="items[${i}][line_discount_amount]" value="0" data-original="0" class="js-item-field js-line-discount"></td><td class="js-row-total fw-bold">—</td><td><button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('tr').remove(); calculateTotals();">حذف</button></td>`;
  tbody.appendChild(tr); bindMoneyInputs(tr); syncChangeReasonRequired(); modal?.hide();
});

  document.getElementById('sales-items-form')?.addEventListener('submit', function (event) {
    const reason = document.getElementById('edit_reason');
    const error = document.querySelector('.js-edit-reason-error');
    if (reason && reason.value.trim() === '') {
      event.preventDefault();
      reason.focus();
      if (error) error.style.setProperty('display', 'block', 'important');
    }
  });
</script>
@endsection
