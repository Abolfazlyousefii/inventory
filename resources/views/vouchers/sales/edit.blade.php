@extends('layouts.app')

@section('content')
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">🧾 ویرایش حواله فروش</h4>
    <div class="d-flex gap-2">
      <a href="{{ route('vouchers.sales.print', $invoice->uuid) }}" target="_blank" class="btn btn-outline-success">چاپ</a>
      <a href="{{ route('vouchers.sales.show', $invoice->uuid) }}" class="btn btn-outline-secondary">نمایش</a>
      <a href="{{ route('vouchers.sales.index') }}" class="btn btn-outline-dark">بازگشت</a>
    </div>
  </div>

  @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
  @if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif

  @unless($canEditItems)
    <div class="alert alert-warning">{{ $invoice->isFinanciallyLocked() ? 'ارسال‌شده و قفل مالی' : 'این حواله در وضعیت «'.($statusLabels[$invoice->status] ?? $invoice->status).'» قابل ویرایش آیتم نیست.' }}</div>
  @endunless

  @unless($invoice->isFinanciallyLocked())
  <form method="POST" action="{{ route('vouchers.sales.status', $invoice->uuid) }}" class="card border-0 shadow-sm mb-3" id="sales-status-form">
    @csrf
    <div class="card-header bg-white fw-bold">تغییر وضعیت حواله فروش</div>
    <div class="card-body row g-2 align-items-end">
      <div class="col-md-5">
        <label class="form-label">وضعیت جدید</label>
        <select name="status" class="form-select">
          @foreach(['checking_discrepancy'=>'در حال بررسی','collecting'=>'در حال جمع‌آوری','shipped'=>'ارسال شده'] as $key => $label)
            <option value="{{ $key }}" @selected($invoice->status===$key)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label">یادداشت</label>
        <textarea name="note" id="status-note" class="form-control" rows="2" placeholder="اختیاری"></textarea>
      </div>
      <div class="col-md-2">
        <button class="btn btn-primary w-100">ثبت تغییر وضعیت</button>
      </div>
    </div>
  </form>

  @endunless

  <form method="POST" action="{{ route('vouchers.sales.update', $invoice->uuid) }}" class="card border-0 shadow-sm" id="sales-items-form">
    @csrf
    @method('PUT')
    <div class="card-body">
      <div class="row g-2 mb-3">
        <div class="col-md-6"><b>کد حواله:</b> {{ $invoice->uuid }}</div>
        <div class="col-md-6 text-md-end"><b>وضعیت:</b> {{ $statusLabels[$invoice->status] ?? $invoice->status }}</div>
      </div>

      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>محصول</th><th>مدل</th><th>تعداد</th><th>قیمت</th><th>حذف</th></tr></thead>
          <tbody>
            @foreach($invoice->items as $it)
              <tr>
                <td>{{ $it->product?->name ?? '#'.$it->product_id }}</td>
                <td>{{ $it->variant?->variant_name ?? '—' }}</td>
                <td>
                  <input type="hidden" name="items[{{ $loop->index }}][id]" value="{{ $it->id }}">
                  <input type="hidden" name="items[{{ $loop->index }}][sort_order]" value="{{ $it->sort_order ?? $it->id }}">
                  @php($limit = $stockLimits[(int) $it->variant_id] ?? ['free_stock' => 0, 'current_total' => (int) $it->quantity, 'max_allowed' => (int) $it->quantity])
                  <input type="number" min="0" max="{{ (int) $limit['max_allowed'] }}" name="items[{{ $loop->index }}][quantity]" value="{{ (int)$it->quantity }}" data-original="{{ (int)$it->quantity }}" class="form-control js-item-field" @disabled(!$canEditItems)>
                  <div class="form-text small">
                    موجودی آزاد: {{ number_format((int) $limit['free_stock']) }} عدد<br>
                    @if((int) $limit['free_stock'] === 0)
                      قابل نگهداری تا تعداد فعلی: {{ number_format((int) $limit['current_total']) }} عدد
                    @else
                      حداکثر قابل ثبت: {{ number_format((int) $limit['max_allowed']) }} عدد
                    @endif
                  </div>
                </td>
                <td>
                  <input type="text" inputmode="numeric" value="{{ number_format((int)$it->price) }}" data-raw-target="item-price-{{ $loop->index }}" class="form-control js-money" @disabled(!$canEditItems)>
                  <input id="item-price-{{ $loop->index }}" type="hidden" name="items[{{ $loop->index }}][price]" value="{{ (int)$it->price }}" data-original="{{ (int)$it->price }}" class="js-item-field">
                </td>
                <td><button type="button" class="btn btn-outline-danger btn-sm js-zero-item" @disabled(!$canEditItems)>حذف از فاکتور</button></td>
              </tr>
            @endforeach
          </tbody>
        </table>
        <button type="button" class="btn btn-outline-primary" id="open-add-item-modal" @disabled(!$canEditItems)>افزودن کالا</button>
      </div>
      <div class="row g-2">
        <div class="col-md-4">
          <label class="form-label">دلیل تغییر اقلام <span class="text-danger">*</span></label>
          <select name="change_reason" class="form-select" required @disabled(!$canEditItems)>
            <option value="">انتخاب کنید</option>
            <option value="price_correction">اصلاح قیمت برای همین فاکتور</option>
            <option value="customer_quantity_change">تغییر تعداد به درخواست مشتری</option>
            <option value="item_removed">حذف کالا از فاکتور</option>
            <option value="item_added">افزودن کالا به فاکتور</option>
            <option value="warehouse_correction">اصلاح انبار</option>
            <option value="other">سایر</option>
          </select>
        </div>
        <div class="col-md-8">
          <label class="form-label">توضیح تغییر</label>
          <input name="change_note" class="form-control" placeholder="توضیح تکمیلی حذف، کاهش، افزایش یا افزودن کالا" @disabled(!$canEditItems)>
        </div>
      </div>
    </div>
    <div class="card-footer text-end">
      <button class="btn btn-success" @disabled(!$canEditItems)>ذخیره تغییرات حواله فروش</button>
    </div>
  </form>

  <div class="modal fade" id="addItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">افزودن کالا به حواله</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body row g-3">
        <div class="col-md-6"><label class="form-label">دسته‌بندی اصلی</label><select id="modal-category" class="form-select"><option value="">انتخاب کنید...</option></select></div>
        <div class="col-md-6"><label class="form-label">زیر دسته‌بندی</label><select id="modal-subcategory" class="form-select"><option value="">ابتدا دسته اصلی...</option></select></div>
        <div class="col-md-8"><label class="form-label">جستجوی نام کالا / کد کالا / SKU</label><input id="modal-product-search" class="form-control" placeholder="حداقل ۲ حرف وارد کنید"></div>
        <div class="col-md-4"><label class="form-label">کالا</label><select id="modal-product" class="form-select"><option value="">جستجو کنید...</option></select></div>
        <div class="col-md-6"><label class="form-label">تنوع کالا</label><select id="modal-variant" class="form-select"><option value="">ابتدا کالا...</option></select></div>
        <div class="col-md-6"><label class="form-label">موجودی آزاد انبار مرکزی</label><input id="modal-stock" class="form-control" readonly value="—"></div>
        <div class="col-md-6"><label class="form-label">تعداد</label><input id="modal-quantity" type="number" min="1" value="1" class="form-control"><div id="modal-stock-help" class="form-text small">برای کالای جدید، حداکثر قابل ثبت برابر موجودی آزاد است.</div></div>
        <div class="col-md-6"><label class="form-label">قیمت همین فاکتور</label><input id="modal-price" inputmode="numeric" class="form-control js-money-standalone"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button><button type="button" class="btn btn-primary" id="add-modal-item">افزودن به حواله</button></div>
    </div></div>
  </div>
</div>
<script>
const form = document.getElementById('sales-items-form');
const toFaNumber = (value) => Number(value || 0).toLocaleString('fa-IR');
const reasonSelect = document.querySelector('select[name="change_reason"]');
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
  (await loadJson(`${urls.variants}/${product.value}/variants`)).forEach(v => variant.insertAdjacentHTML('beforeend', option(v.id, `${v.name}${v.variant_code ? ' ['+v.variant_code+']' : ''}`, {stock:v.available_stock, max:v.max_allowed, price:v.sell_price})));
});
variant?.addEventListener('change', () => {
  const opt = variant.selectedOptions[0];
  const freeStock = Number(opt?.dataset.stock || 0);
  const maxAllowed = Number(opt?.dataset.max || freeStock);
  stock.value = opt?.dataset.stock ?? '—';
  qty.max = String(maxAllowed);
  if (Number(qty.value || 0) > maxAllowed) qty.value = maxAllowed > 0 ? maxAllowed : 1;
  document.getElementById('modal-stock-help').textContent = `موجودی آزاد: ${toFaNumber(freeStock)} عدد | حداکثر قابل ثبت: ${toFaNumber(maxAllowed)} عدد`;
  price.value = formatMoney(opt?.dataset.price || '0');
});
document.getElementById('add-modal-item')?.addEventListener('click', () => {
  const maxAllowed = Number(qty.max || 0);
  if (!product.value || !variant.value || Number(qty.value || 0) < 1) { alert('کالا، تنوع و تعداد معتبر را انتخاب کنید.'); return; }
  if (maxAllowed < 1 || Number(qty.value || 0) > maxAllowed) { alert('موجودی آزاد برای افزودن این کالا کافی نیست.'); return; }
  const i = nextItemIndex++;
  const tr = document.createElement('tr'); tr.className = 'table-success';
  tr.innerHTML = `<td>${product.selectedOptions[0].text}<input type="hidden" name="items[${i}][product_id]" value="${product.value}"></td><td>${variant.selectedOptions[0].text}<input type="hidden" name="items[${i}][variant_id]" value="${variant.value}" class="js-item-field" data-original=""></td><td><input type="number" min="1" name="items[${i}][quantity]" value="${qty.value}" max="${maxAllowed}" data-original="0" class="form-control js-item-field"></td><td><input type="text" inputmode="numeric" value="${formatMoney(price.value)}" data-raw-target="item-price-${i}" class="form-control js-money"><input id="item-price-${i}" type="hidden" name="items[${i}][price]" value="${rawNumber(price.value) || 0}" data-original="0" class="js-item-field"></td><td><button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('tr').remove(); syncChangeReasonRequired();">حذف</button></td>`;
  tbody.appendChild(tr); bindMoneyInputs(tr); syncChangeReasonRequired(); modal?.hide();
});
</script>
@endsection
