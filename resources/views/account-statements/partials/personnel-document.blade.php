@php
    $restorePersonnel = old('_personnel_document') === 'yes';
    $personnelOld = fn ($key, $default = '') => $restorePersonnel ? old($key, $default) : $default;
    $personnelDate = $personnelOld('document_date', \App\Support\JalaliDate::date(now()));
    if (is_string($personnelDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $personnelDate)) {
        $personnelDate = \App\Support\JalaliDate::date($personnelDate);
    }
@endphp
<div class="modal fade unified-payment-modal" id="personnelDocumentModal" tabindex="-1" aria-labelledby="personnelDocumentTitle" aria-hidden="true" dir="rtl" @if($restorePersonnel && $errors->any()) data-personnel-reopen @endif>
    <div class="modal-dialog modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="personnelDocumentTitle">افزودن سند پرسنلی</h5><button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="بستن"></button></div>
        <div class="modal-body">
            <p><strong>{{ $customer->display_name }}</strong></p>
            <p class="text-muted small">مبلغ این سند به گردش همین شخص اضافه می‌شود. حقوق و مزایا: بستانکار؛ مساعده یا بدهی به شرکت: بدهکار. این سند، ثبت پرداخت بانکی یا نقدی نیست.</p>
            @if($restorePersonnel && $errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <form method="POST" action="{{ route('account-statements.personnel.store', $customer) }}" class="row g-3" data-personnel-document>
                @csrf
                <input type="hidden" name="_personnel_document" value="yes">
                <input type="hidden" name="submission_token" value="{{ $personnelOld('submission_token', (string) \Illuminate\Support\Str::uuid()) }}">
                <div class="col-12"><label class="form-label" for="personnel-type">اثر سند روی حساب شخص</label><select id="personnel-type" name="type" class="form-select" required>
                    <option value="credit" @selected($personnelOld('type', 'credit') === 'credit')>بستانکار — شرکت به شخص بدهکار می‌شود</option>
                    <option value="debit" @selected($personnelOld('type') === 'debit')>بدهکار — شخص به شرکت بدهکار می‌شود</option>
                </select></div>
                <div class="col-12"><label class="form-label" for="personnel-amount">مبلغ سند (ریال)</label><input id="personnel-amount" name="document_amount" class="form-control" type="text" inputmode="numeric" data-rial autocomplete="off" maxlength="32" value="{{ $personnelOld('document_amount') }}" required></div>
                <div class="col-12"><label class="form-label" for="personnel-date">تاریخ سند (شمسی)</label><input id="personnel-date" name="document_date" class="form-control" data-jdp data-jdp-only-date autocomplete="off" value="{{ $personnelDate }}" required></div>
                <div class="col-12"><label class="form-label" for="personnel-reference">شماره سند سازه‌حساب (اختیاری)</label><input id="personnel-reference" name="reference" class="form-control" maxlength="190" value="{{ $personnelOld('reference') }}"></div>
                <div class="col-12"><label class="form-label" for="personnel-description">شرح سند</label><textarea id="personnel-description" name="description" class="form-control" rows="2" minlength="3" maxlength="2000" placeholder="مثلاً حقوق آبان طبق سند سازه‌حساب" required>{{ $personnelOld('description') }}</textarea></div>
                <div class="col-12 d-flex justify-content-end gap-2"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button><button type="submit" class="btn btn-success">ثبت سند پرسنلی</button></div>
            </form>
        </div>
    </div></div>
</div>
@push('scripts')<script src="{{ asset('js/personnel-account-document.js') }}?v=20261109" defer></script>@endpush
