@extends('layouts.app')
@section('title', 'بررسی مالی پیش‌فاکتور '.$order->uuid)
@php
    use Morilog\Jalali\Jalalian;
    $rial = fn($v) => number_format((int)$v).' ریال';
    $money = fn($v) => number_format((int)\App\Support\DiscountMath::input($v));
    $date = fn($v) => $v ? Jalalian::fromDateTime($v)->format('Y/m/d H:i') : '—';
    $invoiceDiscount = $discountEditorState['invoice_discount'] ?? [];
    $invoiceType = old('invoice_discount_type', $invoiceDiscount['type'] ?? ($order->invoice_discount_type ?: 'none'));
    $invoiceValue = old('invoice_discount_value', $invoiceDiscount['value'] ?? $order->invoice_discount_value ?? 0);
    $groups = collect($discountEditorState['product_groups'] ?? [])->keyBy('product_id');
    $indices = $order->items->values()->mapWithKeys(fn($item,$index) => [$item->id => $index]);
@endphp
@push('styles')<link rel="stylesheet" href="{{ asset('css/finance-review.css') }}?v=20261026b">@endpush
@section('content')
<div class="finance-review" dir="rtl">
    <header class="fr-heading"><div><span class="fr-eyebrow">مالی / بررسی اولیه</span><h1>بررسی پیش‌فاکتور <b>{{ $order->uuid }}</b></h1><p>اقلام و تخفیف‌ها را بررسی کنید؛ سپس ذخیره یا تأیید کنید.</p></div><a class="btn btn-outline-secondary" href="{{ route('preinvoice.draft.index') }}">بازگشت به صف مالی</a></header>
    @foreach(['success'=>'success','warning'=>'warning','error'=>'danger'] as $key=>$type)
        @if(session($key))<div class="alert alert-{{ $type }}" role="status">{{ session($key) }}</div>@endif
    @endforeach
    @if($errors->any())<div class="alert alert-danger" role="alert"><strong>ذخیره انجام نشد؛ موارد زیر را بررسی کنید.</strong><ul class="mb-0">@foreach(collect($errors->getMessages())->except('edit_reason')->flatten() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if($discountEditorState['is_legacy_unstructured'] ?? false)<div class="alert alert-info">{{ $discountEditorState['legacy_message'] ?? 'تخفیف سند قدیمی است؛ پس از ذخیره، جزئیات تفکیکی ثبت می‌شود.' }}</div>@endif
    <section class="fr-card fr-customer" aria-label="اطلاعات مشتری">
        <div><span>مشتری</span><h2>{{ $order->customer_name ?: '—' }}</h2><small dir="ltr">{{ $order->customer_mobile }}</small></div>
        <div><span>مانده حساب فعلی</span><strong class="{{ ($customerBalance ?? 0)>0 ? 'fr-danger' : 'fr-success' }}">{{ $rial(abs($customerBalance ?? 0)) }}</strong><small>{{ ($customerBalance ?? 0)>0 ? 'بدهکار' : (($customerBalance ?? 0)<0 ? 'بستانکار' : 'تسویه') }}</small></div>
        <div><span>فروشنده</span><strong>{{ $order->creator?->name ?? '—' }}</strong><small>{{ $date($order->display_document_date) }}</small></div>
        <div><span>وضعیت سند</span><strong>{{ $order->status_label }}</strong><small>مهلت رزرو: {{ $date($order->stock_frozen_until) }}</small></div>
        <details class="fr-metadata"><summary>توضیحات و اطلاعات ارسال</summary><p>روش ارسال: {{ $order->shippingMethod?->name ?? '—' }} · شرایط پرداخت: {{ $order->payment_terms_note ?: '—' }}</p><p>{{ $order->description ?: 'توضیحی ثبت نشده است.' }}</p></details>
    </section>
    <form method="POST" action="{{ route('preinvoice.draft.finance.update',$order->uuid) }}" id="financeEditForm">
        @csrf @method('PUT')
        <input type="hidden" name="finance_version" value="{{ old('finance_version', \App\Support\FinanceDocumentVersion::token($order)) }}">
        <div class="fr-workspace"><main class="fr-items">
            <div class="fr-section-heading"><div><h2>کالاها و تخفیف محصول</h2><p>مبالغ به ریال · تغییر تخفیف هر کالا یا کل فاکتور</p></div></div>
            @if(count($zeroPriceItems))<div class="alert alert-warning">قیمت برخی اقلام صفر است؛ برای اصلاح، پیش‌فاکتور را به فروشنده ارجاع دهید.</div>@endif
            @foreach($order->items->groupBy('product_id') as $productId=>$groupItems)
                @php
                    $group = $groups->get($productId, []); $groupIndex = $loop->index;
                    $type = old('product_discounts.'.$groupIndex.'.type', $group['discount_type'] ?? 'amount');
                    $value = old('product_discounts.'.$groupIndex.'.value', $group['discount_value'] ?? 0);
                @endphp
                <section class="fr-card fr-product" data-product-discount-group data-product-id="{{ $productId }}">
                    <header><div><span class="fr-product-icon" aria-hidden="true">{{ $loop->iteration }}</span><h3>{{ $groupItems->first()->product?->name ?? '#'.$productId }}</h3></div></header>
                    <div class="fr-product-totals"><span>جمع قبل از تخفیف <strong data-group-gross>—</strong></span><span>تخفیف این کالا <strong class="fr-discount" data-group-discount-amount>—</strong></span><span>بعد از تخفیف محصول <strong class="fr-success" data-group-final>—</strong></span></div>
                    <div class="fr-discount-editor"><label for="product-discount-{{ $productId }}">تخفیف روی مجموع این کالا<small>روی مجموع تعداد × قیمت واحد محاسبه می‌شود.</small></label><div class="fr-input-pair"><select class="form-select" aria-label="نوع تخفیف کالا {{ $loop->iteration }}" name="product_discounts[{{ $groupIndex }}][type]" data-product-discount-type><option value="amount" @selected($type==='amount')>مبلغ (ریال)</option><option value="percent" @selected($type==='percent')>درصد</option></select><input id="product-discount-{{ $productId }}" type="text" inputmode="decimal" autocomplete="off" class="form-control" data-finance-number data-product-discount-value name="product_discounts[{{ $groupIndex }}][value]" value="{{ $type==='percent' ? $value : $money($value) }}"></div><input type="hidden" name="product_discounts[{{ $groupIndex }}][product_id]" value="{{ $productId }}"></div>
                    @foreach($groupItems as $item)
                        @php($idx=$indices[$item->id])
                        <div hidden data-finance-item-row data-product-id="{{ $productId }}" data-item-id="{{ $item->id }}">
                            <input type="hidden" name="items[{{ $idx }}][id]" value="{{ $item->id }}">
                            <input type="hidden" class="js-qty" name="items[{{ $idx }}][quantity]" value="{{ (int)$item->quantity }}">
                            <input type="hidden" class="js-price" name="items[{{ $idx }}][price]" value="{{ (int)$item->price }}">
                        </div>
                    @endforeach
                </section>
            @endforeach
            <section class="fr-card"><div class="fr-section-heading"><div><h2>دریافت از مشتری <span class="fr-tag">اختیاری</span></h2><p>پرداخت‌ها فقط با «ذخیره و تأیید مالی» ثبت می‌شوند.</p></div><button type="button" class="btn btn-outline-primary" id="addPaymentRow">＋ افزودن پرداخت</button></div><div id="paymentRows" class="fr-payment-list"></div><p id="paymentGuide" class="fr-empty">هنوز پرداختی آماده نشده است.</p><div class="fr-line-totals"><span>نقدی <b id="cashTotalLabel">۰ ریال</b></span><span>چک <b id="chequeTotalLabel">۰ ریال</b></span></div></section>
            <section class="fr-card"><label for="edit_reason" class="fr-label">دلیل بررسی / ویرایش مالی <span class="fr-danger">*</span></label><textarea id="edit_reason" name="edit_reason" rows="2" class="form-control @error('edit_reason') is-invalid @enderror" required minlength="3" maxlength="1000" placeholder="مثلاً تأیید تخفیف طبق توافق با مشتری">{{ old('edit_reason') }}</textarea>@error('edit_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror</section>
        </main><aside class="fr-summary fr-card" aria-label="جمع‌بندی فاکتور">
            <h2>جمع‌بندی مالی</h2><p class="fr-unit">تمام مبالغ به ریال</p>
            <div class="fr-summary-row"><span>جمع قبل از تخفیف</span><strong data-summary-gross>—</strong></div><div class="fr-summary-row fr-discount"><span>تخفیف کالاها</span><strong data-summary-product-discount>—</strong></div>
            <div class="fr-global-discount"><label for="invoice-discount-value" class="fr-label">تخفیف کلی فاکتور</label><div class="fr-input-pair"><select name="invoice_discount_type" class="form-select" data-invoice-discount-type aria-label="نوع تخفیف کلی"><option value="none" @selected($invoiceType==='none')>بدون تخفیف</option><option value="amount" @selected($invoiceType==='amount')>مبلغ (ریال)</option><option value="percent" @selected($invoiceType==='percent')>درصد</option></select><input id="invoice-discount-value" type="text" inputmode="decimal" autocomplete="off" data-finance-number name="invoice_discount_value" class="form-control" value="{{ $invoiceType==='percent' ? $invoiceValue : $money($invoiceValue) }}" data-invoice-discount-value></div><small>پس از تخفیف کالاها: <b data-summary-invoice-discount>—</b></small></div>
            <div class="fr-summary-row"><span>هزینه ارسال</span><strong data-summary-shipping data-value="{{ (int)$order->shipping_price }}">{{ $rial($order->shipping_price) }}</strong></div><div class="fr-summary-row fr-discount"><span>مجموع تخفیف</span><strong data-summary-total-discount>—</strong></div><div class="fr-grand"><span>مبلغ نهایی فاکتور</span><strong data-summary-grand-total>—</strong></div><div class="fr-summary-row"><span>پرداخت آماده ثبت</span><strong id="allTotalLabel">۰ ریال</strong></div><div class="fr-summary-row"><span>مانده این فاکتور</span><strong data-summary-remaining>—</strong></div>
            <div class="fr-actions"><button type="submit" class="btn btn-success" name="intent" value="save_and_finalize" formaction="{{ route('preinvoice.draft.finance.save-and-finalize',$order->uuid) }}" formmethod="POST" onclick="return confirm('تغییرات و پرداخت‌های آماده‌شده ثبت و فاکتور برای جمع‌آوری ارسال شود؟')">ذخیره و تأیید مالی ←</button><button type="submit" class="btn btn-outline-primary" name="intent" value="save">فقط ذخیره تغییرات</button></div><p class="fr-unit">تأیید مالی، سند را به صف جمع‌آوری انبار می‌فرستد.</p>
        </aside></div>
    </form>
    <details class="fr-card"><summary>ارجاع به فروشنده یا لغو پیش‌فاکتور</summary><div class="fr-secondary-forms"><form method="POST" action="{{ route('preinvoice.draft.return',$order->uuid) }}" onsubmit="return confirm('به فروشنده ارجاع شود؟')">@csrf<label>دلیل ارجاع<input name="reason" class="form-control" required maxlength="1000"></label><button class="btn btn-outline-warning">ارجاع به فروشنده</button></form><form method="POST" action="{{ route('preinvoice.draft.cancel',$order->uuid) }}" onsubmit="return confirm('پیش‌فاکتور لغو شود؟')">@csrf<label>دلیل لغو<input name="reason" class="form-control" required maxlength="1000"></label><button class="btn btn-outline-danger">لغو پیش‌فاکتور</button></form></div></details>
    @include('preinvoice.partials.finance-payment-modal')
    <script type="application/json" id="financeStagedPayments">@json(old('payments', []))</script>
</div>
@endsection
@push('scripts')
<script src="{{ asset('js/sales-discount-math.js') }}?v=1"></script>
<script src="{{ asset('js/finance-review.js') }}?v=20261026b"></script>
<script src="{{ asset('js/finance-review-payments.js') }}?v=20261026b"></script>
<script src="{{ asset('js/finance-preinvoice-payload.js') }}?v=20261026b"></script>
@endpush
