@extends('layouts.app')
@php
  use Morilog\Jalali\Jalalian;
  use App\Support\SalesDocumentTotals;
  $rial = fn($v) => number_format((int)$v).' ریال';
  $fmtDate = fn($d) => $d ? Jalalian::fromDateTime($d)->format('Y/m/d H:i') : '—';
  $firstZero = $order->items->first(fn($it)=>(int)$it->price<=0);
  $fmtMoney = fn($v) => number_format((int)$v);
  $currentInvoiceDiscountType = old('invoice_discount_type', $order->invoice_discount_type ?: 'none');
  $currentInvoiceDiscountValue = old('invoice_discount_value', (int)($order->invoice_discount_value ?? 0));
  $breakdownGroups = collect($order->discount_breakdown['groups'] ?? [])->keyBy(fn($g)=>(int)($g['product_id'] ?? 0));
  $productGroups = $order->items->groupBy('product_id');
  $totals = SalesDocumentTotals::calculate($order->items, (int)($order->invoice_discount_amount ?? 0), (int)$order->shipping_price, ['discount_allocation_mode' => $order->discount_allocation_mode]);
  $legacyDiscount = empty($order->discount_breakdown) && (int)($order->discount_amount ?? 0) > 0;
@endphp
@section('content')
<style>
.finance-edit-row-zero{border:2px solid #dc3545}.finance-edit-table input{min-width:110px}.line-preview{font-weight:700;white-space:nowrap}@media(max-width:767.98px){.finance-edit-actions .btn{width:100%}.finance-edit-table{font-size:.82rem}}
</style>
<div class="container py-4" dir="rtl">
  <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-3">
    <div><h4 class="mb-1">ویرایش مالی پیش‌فاکتور {{ $order->uuid }}</h4><div class="text-muted small">ویرایش امن تعداد، قیمت snapshot، تخفیف محصول و تخفیف کلی قبل از تأیید نهایی</div></div>
    <a class="btn btn-outline-secondary" href="{{ route('preinvoice.draft.index') }}">بازگشت به صف مالی</a>
  </div>
  @foreach(['success'=>'success','warning'=>'warning','error'=>'danger'] as $key=>$type) @if(session($key))<div class="alert alert-{{ $type }}">{{ session($key) }}</div>@endif @endforeach
  @php($summaryErrors = collect($errors->getMessages())->reject(fn($messages, $key) => $key === 'edit_reason' || str_starts_with($key, 'items.'))->flatten())
  @if($summaryErrors->isNotEmpty())<div class="alert alert-danger"><strong>خطاها:</strong><ul class="mb-0">@foreach($summaryErrors as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
  @if($legacyDiscount)<div class="alert alert-info">این سند تخفیف قدیمی بدون جزئیات تفکیکی دارد. در این صفحه بدون ذخیره، دیتابیس تغییر نمی‌کند؛ پس از اولین ذخیره معتبر، ساختار تفکیکی کامل ثبت می‌شود.</div>@endif
  @if($firstZero)<div class="alert alert-warning d-flex justify-content-between flex-wrap gap-2"><span>برای تأیید مالی، قیمت تمام اقلام باید بیشتر از صفر باشد.</span><a class="btn btn-sm btn-danger" href="#item-row-{{ $firstZero->id }}">رفتن به اولین ردیف مشکل‌دار</a></div>@endif

  <div class="card mb-3"><div class="card-body"><div class="row g-3 small">
    <div class="col-md-3"><span class="text-muted">شماره:</span> <strong>{{ $order->uuid }}</strong></div><div class="col-md-3"><span class="text-muted">مشتری:</span> {{ $order->customer_name ?: '—' }}</div><div class="col-md-3"><span class="text-muted">فروشنده:</span> {{ $order->creator?->name ?? '—' }}</div><div class="col-md-3"><span class="text-muted">تاریخ:</span> {{ $fmtDate($order->display_document_date) }}</div>
    <div class="col-md-3"><span class="text-muted">وضعیت:</span> {{ $order->status_label }}</div><div class="col-md-3"><span class="text-muted">ارسال:</span> {{ $order->shippingMethod?->name ?? '—' }}</div><div class="col-md-3"><span class="text-muted">شرایط پرداخت:</span> {{ $order->payment_terms_note ?: '—' }}</div><div class="col-md-3"><span class="text-muted">توضیحات:</span> {{ $order->description ?: '—' }}</div>
  </div></div></div>

  <form method="POST" action="{{ route('preinvoice.draft.finance.update',$order->uuid) }}" id="financeEditForm">@csrf @method('PUT')
    <div class="card"><div class="table-responsive"><table class="table table-sm align-middle finance-edit-table mb-0"><thead class="table-light"><tr><th>کالا</th><th>تنوع/SKU</th><th>موجودی مرجع</th><th>تعداد</th><th>قیمت واحد</th><th>قیمت مرجع</th><th>تخفیف تخصیص‌یافته ردیف</th><th>ناخالص</th><th>خالص</th></tr></thead><tbody>
      @foreach($order->items as $idx=>$it) @php($isZero=(int)$it->price<=0) @php($gross=(int)$it->quantity*(int)$it->price) @php($net=SalesDocumentTotals::lineTotal($it))
      <tr id="item-row-{{ $it->id }}" class="{{ $isZero ? 'table-warning finance-edit-row-zero' : '' }}" data-finance-item-row>
        <td>{{ $it->product?->name ?? '#'.$it->product_id }} @if($isZero)<span class="badge text-bg-danger">قیمت صفر</span>@endif<input type="hidden" name="items[{{ $idx }}][id]" value="{{ $it->id }}"></td>
        <td>{{ $it->variant?->variant_name ?? $it->variant?->variety_name ?? '—' }}<div class="text-muted small">{{ $it->variant?->sku ?? $it->variant?->variant_code ?? '—' }}</div></td>
        <td>{{ number_format((int)($it->variant?->available_stock ?? $it->variant?->stock ?? 0)) }}</td>
        <td><input class="form-control form-control-sm js-qty @error('items.'.$idx.'.quantity') is-invalid @enderror" type="number" min="1" name="items[{{ $idx }}][quantity]" value="{{ old('items.'.$idx.'.quantity',(int)$it->quantity) }}">@error('items.'.$idx.'.quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror</td>
        <td><input class="form-control form-control-sm js-price @error('items.'.$idx.'.price') is-invalid @enderror" type="text" inputmode="numeric" name="items[{{ $idx }}][price]" value="{{ $fmtMoney(old('items.'.$idx.'.price',(int)$it->price)) }}" {{ $isZero ? 'autofocus' : '' }}>@error('items.'.$idx.'.price')<div class="invalid-feedback">{{ $message }}</div>@enderror</td>
        <td>{{ $rial($it->variant?->sell_price ?? 0) }}</td>
        <td><span class="js-discount line-preview" data-value="{{ (int)($it->line_discount_amount ?? 0) }}">{{ $rial($it->line_discount_amount ?? 0) }}</span><div class="text-muted small">خروجی تخصیص تخفیف محصول</div></td>
        <td class="js-gross line-preview">{{ $rial($gross) }}</td><td class="js-net line-preview">{{ $rial($net) }}</td>
      </tr>@endforeach
    </tbody></table></div><div class="card-body border-top"><div class="mb-3"><h6>تخفیف محصول / گروه محصول</h6><div class="row g-3">@foreach($productGroups as $productId=>$groupItems) @php($g=$breakdownGroups->get((int)$productId, [])) @php($groupGross=$groupItems->sum(fn($item)=>(int)$item->quantity*(int)$item->price))<div class="col-md-6 col-xl-4"><div class="border rounded p-2 h-100"><strong>{{ $groupItems->first()?->product?->name ?? ('#'.$productId) }}</strong><div class="small text-muted">ناخالص گروه: {{ $rial($groupGross) }}</div><input type="hidden" name="product_discounts[{{ $loop->index }}][product_id]" value="{{ (int)$productId }}"><label class="form-label small mt-2">نوع تخفیف محصول</label><select class="form-select form-select-sm" name="product_discounts[{{ $loop->index }}][type]"><option value="amount" @selected(old('product_discounts.'.$loop->index.'.type', $g['discount_type'] ?? 'amount')==='amount')>مبلغ</option><option value="percent" @selected(old('product_discounts.'.$loop->index.'.type', $g['discount_type'] ?? 'amount')==='percent')>درصد</option></select><label class="form-label small mt-2">مقدار تخفیف محصول</label><input type="text" inputmode="numeric" class="form-control form-control-sm js-money" name="product_discounts[{{ $loop->index }}][value]" value="{{ $fmtMoney(old('product_discounts.'.$loop->index.'.value', $g['discount_value'] ?? 0)) }}"><div class="small mt-2">تخفیف فعلی تخصیص‌یافته: <strong>{{ $rial($groupItems->sum(fn($item)=>(int)($item->line_discount_amount ?? 0))) }}</strong></div></div></div>@endforeach</div></div><div class="row g-3 mb-3"><div class="col-md-4"><label class="form-label">نوع تخفیف کلی</label><select name="invoice_discount_type" class="form-select"><option value="none" @selected($currentInvoiceDiscountType==='none')>بدون تخفیف</option><option value="amount" @selected($currentInvoiceDiscountType==='amount')>مبلغ ثابت</option><option value="percent" @selected($currentInvoiceDiscountType==='percent')>درصدی</option></select></div><div class="col-md-4"><label class="form-label">مقدار تخفیف کلی</label><input type="text" inputmode="numeric" name="invoice_discount_value" class="form-control js-money" value="{{ $fmtMoney($currentInvoiceDiscountValue) }}"></div><div class="col-md-4 small"><div>جمع ناخالص: <strong>{{ $rial($totals['subtotal_before_discount'] ?? 0) }}</strong></div><div>مجموع تخفیف ردیف‌ها: <strong>{{ $rial($totals['items_discount'] ?? 0) }}</strong></div><div>تخفیف کلی: <strong>{{ $rial($order->invoice_discount_amount ?? 0) }}</strong></div><div>هزینه ارسال: <strong>{{ $rial($totals['shipping'] ?? 0) }}</strong></div><div>مبلغ قابل پرداخت: <strong>{{ $rial($totals['grand_total'] ?? 0) }}</strong></div></div></div><label for="edit_reason" class="form-label">دلیل ویرایش <span class="text-danger">*</span></label><textarea id="edit_reason" name="edit_reason" rows="3" class="form-control @error('edit_reason') is-invalid @enderror" required minlength="3" maxlength="1000" placeholder="مثلاً: اصلاح قیمت گارد تدی طبق اعلام واحد مالی">{{ old('edit_reason') }}</textarea>@error('edit_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    <div class="card-footer d-flex gap-2 justify-content-end flex-wrap finance-edit-actions"><button class="btn btn-primary" name="intent" value="save">ذخیره تغییرات</button><button class="btn btn-success" name="intent" value="save_and_finalize" formmethod="POST" formaction="{{ route('preinvoice.draft.finance.save-and-finalize',$order->uuid) }}" onclick="return confirm('ابتدا تغییرات ذخیره و سپس تأیید مالی انجام شود؟')" {{ $firstZero ? 'disabled' : '' }}>ذخیره و تأیید مالی</button></div></div>
  </form>
</div>
<script>
const digits=s=>String(s||'').replace(/[۰-۹]/g,d=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g,d=>'٠١٢٣٤٥٦٧٨٩'.indexOf(d)).replace(/[^0-9]/g,'');
const fmtPlain=n=>Number(digits(n)||0).toLocaleString('en-US');
const fmt=n=>Number(Math.max(0,Number(digits(n))||0)).toLocaleString('fa-IR')+' ریال';
document.querySelectorAll('.js-money,.js-price').forEach(i=>i.addEventListener('input',()=>{i.value=fmtPlain(i.value)}));
document.querySelectorAll('[data-finance-item-row]').forEach(row=>{const calc=()=>{const q=Number(row.querySelector('.js-qty').value||0),p=Number(digits(row.querySelector('.js-price').value)||0),d=Number(row.querySelector('.js-discount')?.dataset.value||0),g=q*p,n=Math.max(g-Math.min(Math.max(d,0),g),0);row.querySelector('.js-gross').textContent=fmt(g);row.querySelector('.js-net').textContent=fmt(n)};row.querySelectorAll('input').forEach(i=>i.addEventListener('input',calc));calc();});
@if($errors->has('edit_reason'))document.getElementById('edit_reason')?.focus();@endif
document.querySelector('#financeEditForm')?.addEventListener('submit',e=>{const b=e.submitter;if(b&&b.value==='save_and_finalize'){const m=e.currentTarget.querySelector('input[name="_method"]'); if(m) m.value='POST';} if(b){b.disabled=true;b.innerHTML='<span class="spinner-border spinner-border-sm"></span> در حال ذخیره';}});
</script>
@endsection
