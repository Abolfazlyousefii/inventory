@php
    $restorePayment = old('_payment_form') === $paymentModalId;
    $paymentOld = fn ($key, $default = '') => $restorePayment ? old($key, $default) : $default;
@endphp
<link rel="stylesheet" href="{{ asset('css/payment-entry.css') }}?v=20261007">
<div class="modal fade unified-payment-modal" id="{{ $paymentModalId }}" tabindex="-1" aria-labelledby="{{ $paymentModalId }}-title" aria-hidden="true" dir="rtl" data-payment-modal @if($restorePayment && $errors->any()) data-payment-reopen @endif>
    <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="{{ $paymentModalId }}-title">افزودن پرداخت</h5><button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="بستن"></button></div>
        <div class="modal-body">
            @if($restorePayment && $errors->any())
                <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            <form method="POST" action="{{ $paymentAction }}" class="row g-3 payment-fields" data-payment-entry>
                @csrf
                <input type="hidden" name="_payment_form" value="{{ $paymentModalId }}">
                @isset($paymentInvoiceOptions)
                    <div class="col-12"><label class="form-label" for="{{ $paymentModalId }}-invoice">فاکتور مرتبط</label>
                        <select name="invoice_id" id="{{ $paymentModalId }}-invoice" class="form-select" required data-payment-invoice>
                            <option value="">انتخاب فاکتور</option>
                            @foreach($paymentInvoiceOptions as $option)
                                <option value="{{ $option['id'] }}" data-remaining="{{ $option['remaining'] }}" @selected((string) $paymentOld('invoice_id') === (string) $option['id'])>{{ $option['uuid'] }} | مانده: {{ \App\Support\Currency::formatRial($option['remaining']) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endisset
                <div class="col-12"><div class="payment-entry-balance"><span>مانده قابل پرداخت:</span><strong data-payment-remaining>@isset($paymentRemaining){{ \App\Support\Currency::formatRial($paymentRemaining) }}@else ابتدا فاکتور را انتخاب کنید @endisset</strong></div></div>
                <div class="col-12"><p class="small text-muted mb-0">پرداخت بیش از مانده هم قابل ثبت است؛ مبلغ مازاد در گردش حساب مشتری بستانکار منظور می‌شود.</p></div>
                <div class="col-md-6"><label class="form-label" for="{{ $paymentModalId }}-method">روش پرداخت</label><select name="method" id="{{ $paymentModalId }}-method" class="form-select" data-payment-method required><option value="cash" @selected($paymentOld('method', 'cash') === 'cash')>نقدی</option><option value="cheque" @selected($paymentOld('method') === 'cheque')>چکی</option></select></div>
                <div class="col-md-6"><label class="form-label" for="{{ $paymentModalId }}-amount">مبلغ پرداخت (ریال)</label><input name="amount" id="{{ $paymentModalId }}-amount" type="text" inputmode="numeric" autocomplete="off" class="form-control" value="{{ $paymentOld('amount') }}" required></div>
                <div class="col-md-6" data-payment-cash><label class="form-label">تاریخ پرداخت شمسی</label><input name="payment_date" type="text" class="form-control" required data-jdp data-jdp-only-date value="{{ $paymentOld('payment_date', \Morilog\Jalali\Jalalian::fromCarbon(now())->format('Y/m/d')) }}"></div>
                <div class="col-md-6"><label class="form-label">نام بانک</label><input name="bank_name" class="form-control" maxlength="255" value="{{ $paymentOld('bank_name') }}"></div>
                <div class="col-md-6" data-payment-cash><label class="form-label">شماره پیگیری / رسید</label><input name="tracking_number" class="form-control" maxlength="190" value="{{ $paymentOld('tracking_number') }}"></div>
                <div class="col-md-6 d-none" data-payment-cheque><label class="form-label">شماره چک</label><input name="cheque_number" class="form-control" maxlength="255" value="{{ $paymentOld('cheque_number') }}"></div>
                <div class="col-md-6 d-none" data-payment-cheque><label class="form-label">تاریخ سررسید شمسی</label><input name="due_date" type="text" class="form-control" data-jdp data-jdp-only-date value="{{ $paymentOld('due_date') }}"></div>
                <div class="col-md-6 d-none" data-payment-cheque><label class="form-label">تاریخ دریافت شمسی</label><input name="received_date" type="text" class="form-control" data-jdp data-jdp-only-date value="{{ $paymentOld('received_date') }}"></div>
                <div class="col-md-6 d-none" data-payment-cheque><label class="form-label">وضعیت ثبت چک</label><select name="cheque_status" class="form-select"><option value="unregistered" @selected($paymentOld('cheque_status', 'unregistered') === 'unregistered')>ثبت‌نشده</option><option value="registered" @selected($paymentOld('cheque_status') === 'registered')>ثبت‌شده</option></select></div>
                <div class="col-12 d-none" data-payment-cheque><details><summary>اطلاعات تکمیلی چک (اختیاری)</summary><div class="row g-3 mt-1">
                    @foreach(['cheque_branch_name'=>'نام شعبه', 'cheque_customer_name'=>'نام مشتری چک', 'cheque_account_number'=>'شماره حساب / شبا', 'cheque_account_holder'=>'صاحب حساب'] as $name => $label)
                        <div class="col-md-6"><label class="form-label">{{ $label }}</label><input name="{{ $name }}" class="form-control" maxlength="255" value="{{ $paymentOld($name) }}"></div>
                    @endforeach
                </div></details></div>
                <div class="col-12"><label class="form-label">توضیحات</label><textarea name="description" class="form-control" rows="2" maxlength="2000">{{ $paymentOld('description') }}</textarea></div>
                <div class="col-12 d-flex gap-2 justify-content-end"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button><button type="submit" class="btn btn-success">ثبت پرداخت</button></div>
            </form>
        </div>
    </div></div>
</div>
<script src="{{ asset('js/payment-entry.js') }}?v=20261007" defer></script>
