<link rel="stylesheet" href="{{ asset('css/payment-entry.css') }}?v=20261007">
<div class="modal fade unified-payment-modal" id="supplierPaymentModal" tabindex="-1" aria-labelledby="supplierPaymentModal-title" aria-hidden="true" dir="rtl">
    <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="supplierPaymentModal-title">افزودن پرداخت / دریافت</h5><button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="بستن"></button></div>
        <div class="modal-body">
            <form method="POST" action="{{ route('persons.supplier-transactions.store', request()->route('personKey')) }}" class="row g-3 payment-fields">
                @csrf
                <input type="hidden" name="submission_token" value="{{ old('submission_token', $supplierSubmissionToken) }}">
                <div class="col-md-6"><label class="form-label" for="supplierTransactionDirection">نوع تراکنش</label><select name="direction" id="supplierTransactionDirection" class="form-select" required>
                    <option value="payment" @selected(old('direction', 'payment') === 'payment')>پرداخت به شخص</option>
                    <option value="receipt" @selected(old('direction') === 'receipt')>دریافت از شخص</option>
                </select></div>
                <div class="col-md-6"><label class="form-label" for="supplierTransactionAmount">مبلغ (ریال)</label><input type="number" name="amount" id="supplierTransactionAmount" min="1" max="9000000000000000" class="form-control" value="{{ old('amount') }}" required></div>
                <div class="col-md-6"><label class="form-label" for="supplierTransactionDate">تاریخ سند</label><input type="date" name="transaction_date" id="supplierTransactionDate" class="form-control" value="{{ substr((string) old('transaction_date', now()->toDateString()), 0, 10) }}" required></div>
                <div class="col-md-6"><label class="form-label" for="supplierTransactionMethod">روش پرداخت</label><input type="text" name="method" id="supplierTransactionMethod" maxlength="50" class="form-control" placeholder="نقدی / کارت / حواله" value="{{ old('method') }}"></div>
                <div class="col-md-6"><label class="form-label" for="supplierTransactionReference">شماره پیگیری</label><input type="text" name="reference" id="supplierTransactionReference" maxlength="100" class="form-control" value="{{ old('reference') }}"></div>
                <div class="col-12"><label class="form-label" for="supplierTransactionNote">شرح</label><textarea name="note" id="supplierTransactionNote" maxlength="255" rows="2" class="form-control">{{ old('note') }}</textarea></div>
                <div class="col-12 d-flex gap-2 justify-content-end"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button><button type="submit" class="btn btn-success">ثبت در گردش حساب</button></div>
            </form>
        </div>
    </div></div>
</div>
