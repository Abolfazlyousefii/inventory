@php
    $restoreFinancialDocument = old('_financial_document') === 'yes';
    $financialOld = fn ($key,$default='') => $restoreFinancialDocument ? old($key,$default) : $default;
    $financialDate = $financialOld('document_date',\App\Support\JalaliDate::date(now()));
    if(is_string($financialDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$financialDate))$financialDate=\App\Support\JalaliDate::date($financialDate);
@endphp
<link rel="stylesheet" href="{{ asset('css/payment-entry.css') }}?v=20261007">
<div class="modal fade unified-payment-modal" id="financialDocumentModal" tabindex="-1" aria-labelledby="financialDocumentTitle" aria-hidden="true" dir="rtl" @if($restoreFinancialDocument && $errors->any()) data-financial-document-reopen @endif>
    <div class="modal-dialog modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="financialDocumentTitle">افزودن سند مالی</h5><button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="بستن"></button></div>
        <div class="modal-body">
            <p class="small"><strong>{{ $financialDocumentPersonName }}</strong></p>
            <form method="POST" action="{{ $financialDocumentAction }}" class="row g-3">
                @csrf
                <input type="hidden" name="_financial_document" value="yes">
                <input type="hidden" name="submission_token" value="{{ $financialOld('submission_token',(string)\Illuminate\Support\Str::uuid()) }}">
                @if(count($financialDocumentRoles)>1)
                    <div class="col-12"><label class="form-label" for="financialAccountRole">دفتر حساب</label><select class="form-select" id="financialAccountRole" name="account_role" required><option value="">انتخاب دفتر</option>@foreach($financialDocumentRoles as $value=>$label)<option value="{{ $value }}" @selected($financialOld('account_role')===$value)>{{ $label }}</option>@endforeach</select></div>
                @else<input type="hidden" name="account_role" value="{{ array_key_first($financialDocumentRoles) }}">@endif
                <div class="col-12"><label class="form-label" for="financialTitle">عنوان سند</label><input class="form-control" id="financialTitle" name="title" maxlength="190" value="{{ $financialOld('title') }}" placeholder="مثلاً اصلاح توافق حساب" required></div>
                <div class="col-12"><label class="form-label" for="financialType">اثر سند روی حساب شخص</label><select class="form-select" id="financialType" name="type" required>
                    <option value="">انتخاب بدهکار یا بستانکار</option>
                    <option value="debit" @selected($financialOld('type')==='debit')>بدهکار — شخص به شرکت بدهکار می‌شود</option>
                    <option value="credit" @selected($financialOld('type')==='credit')>بستانکار — شرکت به شخص بدهکار می‌شود</option>
                </select></div>
                <div class="col-md-6"><label class="form-label" for="financialAmount">مبلغ سند (ریال)</label><input class="form-control" id="financialAmount" name="document_amount" data-rial type="text" inputmode="numeric" maxlength="32" autocomplete="off" value="{{ $financialOld('document_amount') }}" required></div>
                <div class="col-md-6"><label class="form-label" for="financialDate">تاریخ سند (شمسی)</label><input class="form-control" id="financialDate" name="document_date" data-jdp data-jdp-only-date autocomplete="off" value="{{ $financialDate }}" required></div>
                <div class="col-12"><label class="form-label" for="financialDescription">توضیح سند <span class="text-danger">*</span></label><textarea class="form-control" id="financialDescription" name="description" rows="2" minlength="3" maxlength="2000" required>{{ $financialOld('description') }}</textarea></div>
                <div class="col-12 small text-muted">این مبلغ به گردش حساب اضافه می‌شود؛ سند مالی به‌تنهایی پرداخت نقدی یا بانکی ثبت نمی‌کند.</div>
                <div class="col-12 d-flex gap-2 justify-content-end"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button><button type="submit" class="btn btn-success">ثبت سند مالی</button></div>
            </form>
        </div>
    </div></div>
</div>
@push('scripts')<script src="{{ asset('js/financial-account-document.js') }}?v=20261114" defer></script>@endpush
