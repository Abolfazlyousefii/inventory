<div class="modal fade" id="financePaymentComposer" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">افزودن پرداخت</h5>
        <button type="button" class="btn-close" aria-label="بستن" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label" for="paymentTypeInput">نوع پرداخت</label>
          <select class="form-select" id="paymentTypeInput">
            <option value="cash">نقدی</option>
            <option value="cheque">چکی</option>
          </select>
        </div>

        <div id="cashFields">
          <div class="row g-2">
            <div class="col-md-4">
              <label class="form-label" for="cashAmountInput">مبلغ</label>
              <input type="text" inputmode="numeric" id="cashAmountInput" class="form-control" data-finance-number placeholder="مثلاً 5,000,000">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="cashPaidAtInput">تاریخ پرداخت</label>
              <input type="text" id="cashPaidAtInput" class="form-control" data-jdp data-jdp-only-date autocomplete="off" dir="ltr" value="{{ \Morilog\Jalali\Jalalian::fromCarbon(now())->format('Y/m/d') }}">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="cashBankNameInput">اسم بانک</label>
              <input type="text" id="cashBankNameInput" class="form-control" placeholder="مثال: ملی">
            </div>
            <div class="col-12">
              <label class="form-label" for="cashNoteInput">توضیحات (اختیاری)</label>
              <textarea id="cashNoteInput" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>

        <div id="chequeFields" class="d-none">
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label" for="chequeAmountInput">مبلغ چک</label>
              <input type="text" inputmode="numeric" id="chequeAmountInput" class="form-control" data-finance-number placeholder="مثلاً 5,000,000">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="chequeNumberInput">شماره سریال چک</label>
              <input type="text" id="chequeNumberInput" class="form-control" placeholder="شماره سریال چک">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="chequeBankNameInput">بانک</label>
              <input type="text" id="chequeBankNameInput" class="form-control" placeholder="مثال: ملی">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="chequeReceivedAtInput">تاریخ ثبت چک</label>
              <input type="text" id="chequeReceivedAtInput" class="form-control" data-jdp data-jdp-only-date autocomplete="off" dir="ltr" placeholder="1405/01/10">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="chequeDueDateInput">تاریخ سررسید چک</label>
              <input type="text" id="chequeDueDateInput" class="form-control" data-jdp data-jdp-only-date autocomplete="off" dir="ltr" placeholder="1405/01/20">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="chequeStatusInput">وضعیت چک</label>
              <select id="chequeStatusInput" class="form-select">
                <option value="unregistered">ثبت‌نشده</option>
                <option value="registered">ثبت‌شده</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="chequeNoteInput">توضیحات اختیاری</label>
              <textarea id="chequeNoteInput" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div id="paymentModalError" class="alert alert-danger mt-3 d-none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">انصراف</button>
        <button type="button" class="btn btn-primary" id="savePaymentBtn">افزودن به پرداخت‌های آماده</button>
      </div>
    </div>
  </div>
</div>

