@extends('layouts.app')
@php
  use Morilog\Jalali\Jalalian;
  use App\Support\SalesDocumentTotals;
  $rial = fn($v) => number_format((int)$v).' ریال';
  $fmtDate = fn($d) => $d ? Jalalian::fromDateTime($d)->format('Y/m/d H:i') : '—';
  $firstZero = $order->items->first(fn($it)=>(int)$it->price<=0);
@endphp
@section('content')
<style>
.finance-edit-row-zero{border:2px solid #dc3545}.finance-edit-table input{min-width:110px}.line-preview{font-weight:700;white-space:nowrap}@media(max-width:767.98px){.finance-edit-actions .btn{width:100%}.finance-edit-table{font-size:.82rem}}
</style>
<div class="container py-4" dir="rtl">
  <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-3">
    <div><h4 class="mb-1">ویرایش مالی پیش‌فاکتور {{ $order->uuid }}</h4><div class="text-muted small">ویرایش امن تعداد، قیمت snapshot و تخفیف ردیف قبل از تأیید نهایی</div></div>
    <a class="btn btn-outline-secondary" href="{{ route('preinvoice.draft.index') }}">بازگشت به صف مالی</a>
  </div>
  @foreach(['success'=>'success','warning'=>'warning','error'=>'danger'] as $key=>$type) @if(session($key))<div class="alert alert-{{ $type }}">{{ session($key) }}</div>@endif @endforeach
  @php($summaryErrors = collect($errors->getMessages())->reject(fn($messages, $key) => $key === 'edit_reason' || str_starts_with($key, 'items.'))->flatten())
  @if($summaryErrors->isNotEmpty())<div class="alert alert-danger"><strong>خطاها:</strong><ul class="mb-0">@foreach($summaryErrors as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
  @if($firstZero)<div class="alert alert-warning d-flex justify-content-between flex-wrap gap-2"><span>برای تأیید مالی، قیمت تمام اقلام باید بیشتر از صفر باشد.</span><a class="btn btn-sm btn-danger" href="#item-row-{{ $firstZero->id }}">رفتن به اولین ردیف مشکل‌دار</a></div>@endif

  <div class="card mb-3"><div class="card-body"><div class="row g-3 small">
    <div class="col-md-3"><span class="text-muted">شماره:</span> <strong>{{ $order->uuid }}</strong></div><div class="col-md-3"><span class="text-muted">مشتری:</span> {{ $order->customer_name ?: '—' }}</div><div class="col-md-3"><span class="text-muted">فروشنده:</span> {{ $order->creator?->name ?? '—' }}</div><div class="col-md-3"><span class="text-muted">تاریخ:</span> {{ $fmtDate($order->display_document_date) }}</div>
    <div class="col-md-3"><span class="text-muted">وضعیت:</span> {{ $order->status_label }}</div><div class="col-md-3"><span class="text-muted">ارسال:</span> {{ $order->shippingMethod?->name ?? '—' }}</div><div class="col-md-3"><span class="text-muted">شرایط پرداخت:</span> {{ $order->payment_terms_note ?: '—' }}</div><div class="col-md-3"><span class="text-muted">توضیحات:</span> {{ $order->description ?: '—' }}</div>
  </div></div></div>

  <form method="POST" action="{{ route('preinvoice.draft.finance.update',$order->uuid) }}" id="financeEditForm">@csrf @method('PUT')
    <div class="card"><div class="table-responsive"><table class="table table-sm align-middle finance-edit-table mb-0"><thead class="table-light"><tr><th>کالا</th><th>تنوع/SKU</th><th>موجودی مرجع</th><th>تعداد</th><th>قیمت واحد</th><th>قیمت مرجع</th><th>تخفیف کل ردیف</th><th>ناخالص</th><th>خالص</th></tr></thead><tbody>
      @foreach($order->items as $idx=>$it) @php($isZero=(int)$it->price<=0) @php($gross=(int)$it->quantity*(int)$it->price) @php($net=SalesDocumentTotals::lineTotal($it))
      <tr id="item-row-{{ $it->id }}" class="{{ $isZero ? 'table-warning finance-edit-row-zero' : '' }}" data-finance-item-row>
        <td>{{ $it->product?->name ?? '#'.$it->product_id }} @if($isZero)<span class="badge text-bg-danger">قیمت صفر</span>@endif<input type="hidden" name="items[{{ $idx }}][id]" value="{{ $it->id }}"></td>
        <td>{{ $it->variant?->variant_name ?? $it->variant?->variety_name ?? '—' }}<div class="text-muted small">{{ $it->variant?->sku ?? $it->variant?->variant_code ?? '—' }}</div></td>
        <td>{{ number_format((int)($it->variant?->available_stock ?? $it->variant?->stock ?? 0)) }}</td>
        <td><input class="form-control form-control-sm js-qty @error('items.'.$idx.'.quantity') is-invalid @enderror" type="number" min="1" name="items[{{ $idx }}][quantity]" value="{{ old('items.'.$idx.'.quantity',(int)$it->quantity) }}">@error('items.'.$idx.'.quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror</td>
        <td><input class="form-control form-control-sm js-price @error('items.'.$idx.'.price') is-invalid @enderror" type="number" min="0" name="items[{{ $idx }}][price]" value="{{ old('items.'.$idx.'.price',(int)$it->price) }}" {{ $isZero ? 'autofocus' : '' }}>@error('items.'.$idx.'.price')<div class="invalid-feedback">{{ $message }}</div>@enderror</td>
        <td>{{ $rial($it->variant?->sell_price ?? 0) }}</td>
        <td><input class="form-control form-control-sm js-discount @error('items.'.$idx.'.line_discount_amount') is-invalid @enderror" type="number" min="0" name="items[{{ $idx }}][line_discount_amount]" value="{{ old('items.'.$idx.'.line_discount_amount',(int)($it->line_discount_amount ?? 0)) }}">@error('items.'.$idx.'.line_discount_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror</td>
        <td class="js-gross line-preview">{{ $rial($gross) }}</td><td class="js-net line-preview">{{ $rial($net) }}</td>
      </tr>@endforeach
    </tbody></table></div><div class="card-body border-top"><label for="edit_reason" class="form-label">دلیل ویرایش <span class="text-danger">*</span></label><textarea id="edit_reason" name="edit_reason" rows="3" class="form-control @error('edit_reason') is-invalid @enderror" required minlength="3" maxlength="1000" placeholder="مثلاً: اصلاح قیمت گارد تدی طبق اعلام واحد مالی">{{ old('edit_reason') }}</textarea>@error('edit_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    <div class="card-footer d-flex gap-2 justify-content-end flex-wrap finance-edit-actions"><button class="btn btn-primary" name="action" value="save">ذخیره تغییرات مالی</button><button type="submit" form="finalizePreinvoiceForm" class="btn btn-success" {{ $firstZero ? 'disabled' : '' }}>تأیید نهایی مالی</button></div></div>
  </form>
  <form id="finalizePreinvoiceForm" method="POST" action="{{ route('preinvoice.draft.finalize', $order->uuid) }}" class="d-none">@csrf</form>
</div>
<script>
const fmt=n=>Number(Math.max(0,n||0)).toLocaleString('fa-IR')+' ریال';
document.querySelectorAll('[data-finance-item-row]').forEach(row=>{const calc=()=>{const q=Number(row.querySelector('.js-qty').value||0),p=Number(row.querySelector('.js-price').value||0),d=Number(row.querySelector('.js-discount').value||0),g=q*p,n=Math.max(g-Math.min(Math.max(d,0),g),0);row.querySelector('.js-gross').textContent=fmt(g);row.querySelector('.js-net').textContent=fmt(n)};row.querySelectorAll('input').forEach(i=>i.addEventListener('input',calc));calc();});
@if($errors->has('edit_reason'))document.getElementById('edit_reason')?.focus();@endif
document.querySelector('#financeEditForm')?.addEventListener('submit',e=>{const b=e.submitter;if(b){b.disabled=true;b.innerHTML='<span class="spinner-border spinner-border-sm"></span> در حال ذخیره';}});
</script>
@endsection
