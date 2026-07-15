@extends('layouts.app')
@section('content')
@php
$isEdit = $document->exists;
$items = old('items', $isEdit ? $document->items->map(fn($item)=>[
  'item_name' => $item->item_name,
  'quantity' => $item->quantity,
  'asset_codes_input' => $item->codes->pluck('asset_code')->join("\n"),
  'description' => $item->description,
])->values()->all() : [['item_name'=>'','quantity'=>1,'asset_codes_input'=>'','description'=>'']]);
@endphp
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">{{ $isEdit ? 'ویرایش سند اموال پرسنل' : 'ثبت سند اموال پرسنل' }}</h4>
  <a href="{{ route('asset.documents.index') }}" class="btn btn-outline-secondary">بازگشت</a>
</div>

<form method="POST" action="{{ $action }}" class="card border-0 shadow-sm" id="assetDocumentForm" enctype="multipart/form-data">
  @csrf
  @if($method !== 'POST') @method($method) @endif

  <div class="card-body">
    <div class="row g-3 mb-3">
      <div class="col-md-4"><label class="form-label">تاریخ سند</label><input type="date" name="document_date" class="form-control" required value="{{ old('document_date', optional($document->document_date)->toDateString() ?: now()->toDateString()) }}"></div>
      <div class="col-md-8"><label class="form-label">پرسنل / تحویل‌گیرنده اموال</label><input type="text" class="form-control form-control-sm mb-1 user-select-filter" data-target="trusteeUserSelect" placeholder="جستجو بر اساس نام، تلفن، ایمیل یا کد پرسنلی"><select id="trusteeUserSelect" name="trustee_user_id" class="form-select" required><option value="">انتخاب پرسنل...</option>@foreach($trusteeUsers as $user)<option value="{{ $user->id }}" data-search="{{ trim($user->name.' '.$user->phone.' '.$user->email.' '.$user->personnel_code) }}" @selected((string) old('trustee_user_id', $document->trustee_user_id) === (string) $user->id)>{{ $user->name }}{{ $user->phone ? ' - '.$user->phone : '' }}</option>@endforeach</select></div>
      <div class="col-12"><label class="form-label">توضیحات کلی (اختیاری)</label><textarea name="description" class="form-control" rows="2">{{ old('description', $document->description) }}</textarea></div>
      <div class="col-12">
        <label class="form-label">نامه / فرم امضاشده (اختیاری)</label>
        <input type="file" name="signed_form" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf">
        <small class="text-muted">فرمت‌های مجاز: jpg، jpeg، png، webp، pdf (حداکثر ۵ مگابایت)</small>
        @if($isEdit && $document->signed_form_path)
          <div class="mt-2">
            <a href="{{ route('asset.documents.signed-form.view', $document) }}" target="_blank" class="btn btn-sm btn-outline-primary">مشاهده فایل فعلی</a>
            <a href="{{ route('asset.documents.signed-form.download', $document) }}" class="btn btn-sm btn-outline-secondary">دانلود فایل فعلی</a>
          </div>
        @endif
      </div>
    </div>

    <hr>
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h6 class="mb-0">ردیف‌های اموال</h6>
      <button type="button" class="btn btn-sm btn-outline-primary" id="addItemBtn">+ افزودن ردیف</button>
    </div>

    <datalist id="assetItemNameSuggestions">
      @foreach($itemNameSuggestions as $name)
        <option value="{{ $name }}"></option>
      @endforeach
    </datalist>

    <div id="itemsWrap" class="d-grid gap-2"></div>
  </div>

  <div class="card-footer bg-white d-flex justify-content-end">
    <button class="btn btn-success">ذخیره سند</button>
  </div>
</form>

<script>
document.querySelectorAll('.user-select-filter').forEach(input=>input.addEventListener('input',()=>{const select=document.getElementById(input.dataset.target);const term=input.value.trim().toLowerCase();[...select.options].forEach((option,index)=>{if(index===0)return;option.hidden=term!=='' && !String(option.dataset.search||option.textContent).toLowerCase().includes(term);});}));
const itemsWrap = document.getElementById('itemsWrap');
const assetDocumentForm = document.getElementById('assetDocumentForm');
const submitBtn = assetDocumentForm.querySelector('button[type="submit"], button:not([type])');
const initialItems = @json($items);
const serverErrors = @json($errors->getMessages());

function normalizeDigits(value){
  return String(value || '').replace(/[۰-۹٠-٩]/g, ch => ({'۰':'0','۱':'1','۲':'2','۳':'3','۴':'4','۵':'5','۶':'6','۷':'7','۸':'8','۹':'9','٠':'0','١':'1','٢':'2','٣':'3','٤':'4','٥':'5','٦':'6','٧':'7','٨':'8','٩':'9'}[ch] || ch));
}

function parseCodes(text){
  const normalized = normalizeDigits(text).trim().replace(/[\s,،]+/g, ',');
  if(!normalized) return [];
  return normalized.split(',').map(x=>x.trim()).filter(Boolean);
}

function firstDuplicate(values){
  const seen = new Set();
  for (const value of values) {
    if (seen.has(value)) return value;
    seen.add(value);
  }
  return null;
}

function setRowError(row, message, tone = 'danger'){
  const codes = row.querySelector('.codes');
  const help = row.querySelector('.codes-help');
  codes.classList.toggle('is-invalid', Boolean(message) && tone !== 'success');
  help.textContent = message || help.dataset.defaultText || '';
  help.className = message ? `text-${tone} small codes-help` : 'text-success small codes-help';
}

function validateRow(row, crossRowCounts = null){
  const qty = row.querySelector('.qty');
  const codes = row.querySelector('.codes');
  const help = row.querySelector('.codes-help');
  const needed = parseInt(qty.value || '0', 10);
  const parsed = parseCodes(codes.value);
  const validFourDigit = parsed.filter(code => /^\d{4}$/.test(code));
  help.dataset.defaultText = `${validFourDigit.length} کد وارد شده از ${needed} کد مورد نیاز`;

  const invalidCode = parsed.find(code => !/^\d{4}$/.test(code));
  if (invalidCode) {
    setRowError(row, 'همه کدهای اموال باید دقیقاً ۴ رقم باشند.');
    return false;
  }

  const duplicate = firstDuplicate(parsed);
  if (duplicate) {
    setRowError(row, `کد تکراری: ${duplicate}`);
    return false;
  }

  if (crossRowCounts) {
    const crossDuplicate = parsed.find(code => crossRowCounts.get(code) > 1);
    if (crossDuplicate) {
      setRowError(row, `کد اموال ${crossDuplicate} در چند ردیف این سند تکرار شده است.`);
      return false;
    }
  }

  if (validFourDigit.length < needed) {
    setRowError(row, 'تعداد کدهای واردشده کمتر از تعداد کالا است.', 'warning');
    return false;
  }

  if (validFourDigit.length > needed) {
    setRowError(row, 'تعداد کدهای واردشده بیشتر از تعداد کالا است.');
    return false;
  }

  setRowError(row, help.dataset.defaultText, 'success');
  return true;
}

function validateAllRows(){
  const rows = [...itemsWrap.children];
  const counts = new Map();
  rows.forEach(row => parseCodes(row.querySelector('.codes')?.value || '').forEach(code => counts.set(code, (counts.get(code) || 0) + 1)));
  const valid = rows.map(row => validateRow(row, counts)).every(Boolean);
  if (submitBtn) submitBtn.disabled = !valid;
  return valid;
}

function applyServerErrors(){
  let hasServerCodeError = false;
  [...itemsWrap.children].forEach((row, index) => {
    const key = `items.${index}.asset_codes_input`;
    if (serverErrors[key]?.length) {
      setRowError(row, serverErrors[key][0]);
      hasServerCodeError = true;
    }
  });
  if (hasServerCodeError && submitBtn) submitBtn.disabled = true;
  return hasServerCodeError;
}

function createRow(item = {item_name:'',quantity:1,asset_codes_input:'',description:''}) {
  const row = document.createElement('div');
  row.className = 'border rounded p-2';
  row.innerHTML = `
    <div class="row g-2">
      <div class="col-md-4"><label class="form-label">نام کالا</label><input data-name-template="items[__INDEX__][item_name]" class="form-control" list="assetItemNameSuggestions" value="${item.item_name||''}" required></div>
      <div class="col-md-2"><label class="form-label">تعداد</label><div class="input-group"><button type="button" class="btn btn-outline-secondary qty-minus">-</button><input data-name-template="items[__INDEX__][quantity]" type="number" min="1" class="form-control text-center qty" value="${item.quantity||1}" required><button type="button" class="btn btn-outline-secondary qty-plus">+</button></div></div>
      <div class="col-md-6"><label class="form-label">کدهای اموال</label><textarea data-name-template="items[__INDEX__][asset_codes_input]" class="form-control codes" rows="3" placeholder="هر کد 4 رقمی را در یک خط یا با ویرگول وارد کنید" required>${item.asset_codes_input||''}</textarea><small class="text-muted codes-help"></small></div>
      <div class="col-md-10"><label class="form-label">توضیحات ردیف</label><input data-name-template="items[__INDEX__][description]" class="form-control" value="${item.description||''}"></div>
      <div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-outline-danger w-100 remove-row">حذف</button></div>
    </div>
  `;

  const qty = row.querySelector('.qty');
  const codes = row.querySelector('.codes');

  qty.addEventListener('input', validateAllRows);
  codes.addEventListener('input', () => {
    codes.value = normalizeDigits(codes.value);
    validateAllRows();
  });
  row.querySelector('.qty-plus').addEventListener('click', () => {
    qty.value = Math.max(1, (parseInt(qty.value || '1', 10) || 1) + 1);
    validateAllRows();
  });
  row.querySelector('.qty-minus').addEventListener('click', () => {
    qty.value = Math.max(1, (parseInt(qty.value || '1', 10) || 1) - 1);
    validateAllRows();
  });
  row.querySelector('.remove-row').addEventListener('click', () => {
    row.remove();
    reindexRows();
    validateAllRows();
  });

  itemsWrap.appendChild(row);
  reindexRows();
  validateAllRows();
}

document.getElementById('addItemBtn').addEventListener('click', ()=> createRow());
initialItems.forEach(createRow);
if(!initialItems.length){ createRow(); }

function reindexRows(){
  [...itemsWrap.children].forEach((row, index) => {
    row.querySelectorAll('[data-name-template]').forEach(el => {
      const tpl = el.getAttribute('data-name-template');
      el.name = tpl.replace('__INDEX__', String(index));
    });
  });
}

assetDocumentForm.addEventListener('submit', function (event) {
  reindexRows();
  if (!validateAllRows()) {
    event.preventDefault();
    event.stopPropagation();
  }
});

validateAllRows();
applyServerErrors();
</script>
@endsection
