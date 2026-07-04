@extends('layouts.app')

@php
  use Morilog\Jalali\Jalalian;

  $totals = \App\Support\SalesDocumentTotals::calculate($order->items, (int) $order->discount_amount, (int) $order->shipping_price, ['discount_allocation_mode' => $order->discount_allocation_mode]);
  $subtotal = $totals['subtotal_before_discount'];
  $shipping = $totals['shipping'];
  $productDiscount = (int) ($order->product_discount_amount ?? 0);
  $invoiceDiscount = (int) ($order->invoice_discount_amount ?? 0);
  $discount = $totals['total_discount'];
  $grandTotal = $totals['grand_total'];
  $rial = fn ($value) => \App\Support\Currency::formatRial($value);
@endphp

@section('content')
<style>
  .datepicker-container,
  .datepicker-plot-area {
    z-index: 200000 !important;
  }
</style>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0">✅ مشاهده و تایید مالی پیش‌فاکتور</h4>
    <a href="{{ route('preinvoice.draft.index') }}" class="btn btn-outline-secondary">بازگشت به صف مالی</a>
  </div>

  @if($errors->any())
    <div class="alert alert-danger">
      <ul class="mb-0">
        @foreach($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <div class="card mb-3 shadow-sm border-0">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-3 col-sm-6">
          <div class="text-muted small mb-1">کد پیش‌فاکتور</div>
          <div class="fw-semibold">{{ $order->uuid }}</div>
        </div>
        <div class="col-md-3 col-sm-6">
          <div class="text-muted small mb-1">تاریخ ثبت پیش‌فاکتور</div>
          <div class="fw-semibold">{{ \App\Support\JalaliDate::dateTime($order->display_document_date) }}</div>
        </div>
        <div class="col-md-3 col-sm-6">
          <div class="text-muted small mb-1">مشتری</div>
          <div class="fw-semibold">{{ $order->customer_name ?: '—' }}</div>
          <small class="text-muted">{{ $order->customer_mobile ?: 'شماره تماس ثبت نشده' }}</small>
        </div>
        <div class="col-md-3 col-sm-6">
          <div class="text-muted small mb-1">ثبت‌شده توسط</div>
          <div class="fw-semibold">{{ $order->creator?->name ?? '—' }}</div>
        </div>
        <div class="col-md-3 col-sm-6">
          <div class="text-muted small mb-1">وضعیت حساب مشتری</div>
          <div class="fw-semibold {{ $customerBalanceStatus === 'بدهکار' ? 'text-danger' : ($customerBalanceStatus === 'بستانکار' ? 'text-success' : '') }}">
            {{ $customerBalanceStatus }} {{ $customerBalanceStatus === 'تسویه شده' ? '' : \App\Support\Currency::formatRial($customerBalanceAmount) }}
          </div>
        </div>
        <div class="col-md-3 col-sm-6">
          <div class="text-muted small mb-1">وضعیت</div>
          <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">در انتظار تایید مالی</span>
        </div>
        <div class="col-md-3 col-sm-6">
          <div class="text-muted small mb-1">جمع کل فاکتور</div>
          <div class="fw-bold">{{ $rial($grandTotal) }}</div>
        </div>
      </div>
    </div>
  </div>


  <div class="card border-info-subtle shadow-sm mb-3">
    <div class="card-body">
      <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">توضیحات پیش‌فاکتور</span>
        <span class="text-muted small">یادداشت ثبت‌کننده برای تصمیم‌گیری مالی و انبار</span>
      </div>
      <div class="text-body" style="white-space: pre-wrap;">{{ $order->description ?: 'توضیحی برای این پیش‌فاکتور ثبت نشده است.' }}</div>
    </div>
  </div>

  <form id="finalizePreinvoiceForm" method="POST" action="{{ route('preinvoice.draft.finalize', $order->uuid) }}" enctype="multipart/form-data" class="card shadow-sm border-0">
    @csrf
    <div class="card-body">
      <div class="row g-3">
        <div class="col-lg-5">
          <div class="card border h-100">
            <div class="card-header bg-white">
              <h6 class="mb-0">ثبت فیش / چک مشتری (اصلی)</h6>
              <small class="text-muted">می‌توانید چند ردیف پرداخت (نقدی و چک) ثبت کنید.</small>
            </div>
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0">لیست پرداخت‌های ثبت‌شده</h6>
                <button type="button" class="btn btn-sm btn-outline-primary" id="addPaymentRow">+ افزودن پرداخت</button>
              </div>

              <div id="paymentRows" class="d-grid gap-3"></div>
              <div class="border rounded p-2 small mb-3 d-none" id="paymentTotals">
                <div class="d-flex justify-content-between"><span>جمع نقدی</span><strong id="cashTotalLabel">0 ریال</strong></div>
                <div class="d-flex justify-content-between"><span>جمع چکی</span><strong id="chequeTotalLabel">0 ریال</strong></div>
                <div class="d-flex justify-content-between"><span>جمع کل پرداختی</span><strong id="allTotalLabel">0 ریال</strong></div>
              </div>
              <div class="alert alert-light border mb-0 small text-muted" id="paymentGuide">
                هنوز پرداختی اضافه نشده است. در صورت نیاز روی «افزودن پرداخت» بزنید.
              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-7">
          <div class="card border h-100">
            <div class="card-header bg-white">
              <h6 class="mb-0">اقلام پیش‌فاکتور و خلاصه مالی</h6>
            </div>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>محصول</th>
                    <th>مدل</th>
                    <th>تعداد</th>
                    <th>مبلغ واحد</th>
                    <th>جمع ردیف</th>
                  </tr>
                </thead>
                <tbody>
                  @foreach($order->items as $it)
                    <tr>
                      <td>{{ $it->product?->name ?? ('#'.$it->product_id) }}</td>
                      <td>{{ $it->variant?->variant_name ?? '—' }}</td>
                      <td>{{ number_format((int) $it->quantity) }}</td>
                      <td>{{ number_format((int) $it->price) }}</td>
                      <td>{{ number_format(((int) $it->price) * ((int) $it->quantity)) }}</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
            <div class="card-body border-top">
              <div class="d-flex justify-content-between mb-2">
                <span class="text-muted">جمع اقلام</span>
                <strong>{{ $rial($subtotal) }}</strong>
              </div>
              <div class="d-flex justify-content-between mb-2">
                <span class="text-muted">هزینه ارسال</span>
                <strong>{{ $rial($shipping) }}</strong>
              </div>
              @if(($order->discount_allocation_mode ?? null) === 'allocated_lines')
              <div class="d-flex justify-content-between mb-2">
                <span class="text-muted">تخفیف کالاها</span>
                <strong class="text-danger">- {{ $rial($productDiscount) }}</strong>
              </div>
              <div class="d-flex justify-content-between mb-2">
                <span class="text-muted">تخفیف کلی فاکتور</span>
                <strong class="text-danger">- {{ $rial($invoiceDiscount) }}</strong>
              </div>
              @endif
              <div class="d-flex justify-content-between mb-2">
                <span class="text-muted">جمع تخفیف لحاظ شده</span>
                <strong class="text-danger">- {{ $rial($discount) }}</strong>
              </div>
              <hr>
              <div class="d-flex justify-content-between">
                <span class="fw-semibold">مبلغ نهایی فاکتور</span>
                <strong class="fs-5">{{ $rial($grandTotal) }}</strong>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card-footer d-flex justify-content-end gap-2 flex-wrap">
      <input name="reason" form="cancelPreinvoiceForm" class="form-control" style="max-width: 320px;" placeholder="دلیل کنسلی" required>
      <button class="btn btn-outline-danger" form="cancelPreinvoiceForm">کنسل پیش‌فاکتور</button>
      <a href="{{ route('preinvoice.draft.edit', $order->uuid) }}" class="btn btn-outline-secondary">ویرایش فاکتور</a>
      <button id="finalizePreinvoiceBtn" class="btn btn-success" onclick="return confirm('تاییدیه نهایی مالی ثبت شود؟ با این کار، پیش‌فاکتور به فاکتور تبدیل می‌شود و در صف حواله فروش انبار قرار می‌گیرد.')">تاییدیه نهایی پیش‌فاکتور از سمت مالی</button>
    </div>
  </form>

  <form id="cancelPreinvoiceForm" method="POST" action="{{ route('preinvoice.draft.cancel', $order->uuid) }}" onsubmit="return confirm('پیش‌فاکتور کنسل شود؟')" class="d-none">
    @csrf
  </form>
</div>

<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">افزودن پرداخت</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">نوع پرداخت</label>
          <select class="form-select" id="paymentTypeInput">
            <option value="cash">نقدی</option>
            <option value="cheque">چکی</option>
          </select>
        </div>

        <div id="cashFields">
          <div class="row g-2">
            <div class="col-md-4">
              <label class="form-label">مبلغ</label>
              <input type="text" inputmode="numeric" id="cashAmountInput" class="form-control money" placeholder="مثلاً 5,000,000">
            </div>
            <div class="col-md-4">
              <label class="form-label">تاریخ پرداخت</label>
              <input type="text" id="cashPaidAtInput" class="form-control" data-jdp data-jdp-only-date autocomplete="off" dir="ltr" placeholder="1405/01/15">
            </div>
            <div class="col-md-4">
              <label class="form-label">اسم بانک</label>
              <input type="text" id="cashBankNameInput" class="form-control" placeholder="مثال: ملی">
            </div>
            <div class="col-12">
              <label class="form-label">توضیحات (اختیاری)</label>
              <textarea id="cashNoteInput" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>

        <div id="chequeFields" class="d-none">
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label">مبلغ چک</label>
              <input type="text" inputmode="numeric" id="chequeAmountInput" class="form-control money" placeholder="مثلاً 5,000,000">
            </div>
            <div class="col-md-6">
              <label class="form-label">شماره سریال چک</label>
              <input type="text" id="chequeNumberInput" class="form-control" placeholder="شماره سریال چک">
            </div>
            <div class="col-md-6">
              <label class="form-label">بانک</label>
              <input type="text" id="chequeBankNameInput" class="form-control" placeholder="مثال: ملی">
            </div>
            <div class="col-md-6">
              <label class="form-label">تاریخ ثبت چک</label>
              <input type="text" id="chequeReceivedAtInput" class="form-control" data-jdp data-jdp-only-date autocomplete="off" dir="ltr" placeholder="1405/01/10">
            </div>
            <div class="col-md-6">
              <label class="form-label">تاریخ سررسید چک</label>
              <input type="text" id="chequeDueDateInput" class="form-control" data-jdp data-jdp-only-date autocomplete="off" dir="ltr" placeholder="1405/01/20">
            </div>
            <div class="col-md-6">
              <label class="form-label">توضیحات اختیاری</label>
              <textarea id="chequeNoteInput" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div id="paymentModalError" class="alert alert-danger mt-3 d-none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">انصراف</button>
        <button type="button" class="btn btn-primary" id="savePaymentBtn">ثبت پرداخت</button>
      </div>
    </div>
  </div>
</div>

<script>
  (function () {
    const finalizeForm = document.getElementById('finalizePreinvoiceForm');
    const finalizeBtn = document.getElementById('finalizePreinvoiceBtn');
    const rowsWrap = document.getElementById('paymentRows');
    const addBtn = document.getElementById('addPaymentRow');
    const guide = document.getElementById('paymentGuide');
    if (!rowsWrap || !addBtn || !guide) return;

    const form = rowsWrap.closest('form');
    const paymentModalEl = document.getElementById('paymentModal');
    const paymentModal = window.bootstrap?.Modal
      ? window.bootstrap.Modal.getOrCreateInstance(paymentModalEl)
      : null;
    const paymentTypeInput = document.getElementById('paymentTypeInput');
    const paymentModalError = document.getElementById('paymentModalError');
    const payments = [];
    const paymentTotals = document.getElementById('paymentTotals');
    const cashTotalLabel = document.getElementById('cashTotalLabel');
    const chequeTotalLabel = document.getElementById('chequeTotalLabel');
    const allTotalLabel = document.getElementById('allTotalLabel');

    function normalizeAmount(value) {
      return (value || '').toString().replace(/[^\d]/g, '');
    }

    function div(a, b) { return ~~(a / b); }
    function pad(v) { return String(v).padStart(2, '0'); }
    function jalaliToGregorian(jy, jm, jd) {
      jy += 1595;
      let days = -355668 + (365 * jy) + div(jy, 33) * 8 + div((jy % 33) + 3, 4) + jd + ((jm < 7) ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);
      let gy = 400 * div(days, 146097);
      days %= 146097;
      if (days > 36524) {
        gy += 100 * div(--days, 36524);
        days %= 36524;
        if (days >= 365) days++;
      }
      gy += 4 * div(days, 1461);
      days %= 1461;
      if (days > 365) {
        gy += div(days - 1, 365);
        days = (days - 1) % 365;
      }
      let gd = days + 1;
      const sal_a = [0,31,((gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0)) ? 29 : 28,31,30,31,30,31,31,30,31,30,31];
      let gm = 0;
      for (gm = 1; gm <= 12 && gd > sal_a[gm]; gm++) gd -= sal_a[gm];
      return `${gy}-${pad(gm)}-${pad(gd)}`;
    }
    function normalizeDate(value) {
      const raw = (value || '').trim();
      if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw;
      const m = raw.match(/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})$/);
      if (!m) return '';
      return jalaliToGregorian(Number(m[1]), Number(m[2]), Number(m[3]));
    }

    function togglePaymentTypeFields() {
      const isCheque = paymentTypeInput.value === 'cheque';
      document.getElementById('cashFields').classList.toggle('d-none', isCheque);
      document.getElementById('chequeFields').classList.toggle('d-none', !isCheque);
    }

    function buildHiddenInput(name, value) {
      return `<input type="hidden" name="${name}" value="${String(value ?? '').replace(/"/g, '&quot;')}">`;
    }

    function renderPaymentsList() {
      if (payments.length === 0) {
        rowsWrap.innerHTML = '';
        guide.classList.remove('d-none');
        paymentTotals.classList.add('d-none');
        return;
      }

      guide.classList.add('d-none');
      let cashTotal = 0, chequeTotal = 0;
      rowsWrap.innerHTML = payments.map((payment, idx) => {
        const amount = Number(payment.amount || 0);
        if (payment.method === 'cash') cashTotal += amount; else chequeTotal += amount;
        const title = payment.method === 'cash'
          ? `نقدی | مبلغ: ${Number(payment.amount || 0).toLocaleString('en-US')} ریال`
          : `چک | شماره: ${payment.cheque_number} | بانک: ${payment.bank_name || '—'} | مبلغ: ${Number(payment.amount || 0).toLocaleString('en-US')} ریال`;

        const hiddenInputs = Object.entries(payment).map(([key, val]) => buildHiddenInput(`payments[${idx}][${key}]`, val)).join('');

        return `
          <div class="border rounded p-2 bg-light d-flex justify-content-between align-items-start gap-2">
            <div>
              <div class="fw-semibold">${title}</div>
              <small class="text-muted">${payment.note || '—'}</small>
              ${hiddenInputs}
            </div>
            <button type="button" class="btn btn-sm btn-outline-danger js-remove-payment" data-index="${idx}">حذف</button>
          </div>
        `;
      }).join('');
      const allTotal = cashTotal + chequeTotal;
      paymentTotals.classList.remove('d-none');
      cashTotalLabel.textContent = `${cashTotal.toLocaleString('en-US')} ریال`;
      chequeTotalLabel.textContent = `${chequeTotal.toLocaleString('en-US')} ریال`;
      allTotalLabel.textContent = `${allTotal.toLocaleString('en-US')} ریال`;

      rowsWrap.querySelectorAll('.js-remove-payment').forEach((btn) => {
        btn.addEventListener('click', () => {
          payments.splice(Number(btn.dataset.index), 1);
          renderPaymentsList();
        });
      });
    }

    function readCashPayment() {
      const amount = normalizeAmount(document.getElementById('cashAmountInput').value);
      const paidAt = normalizeDate(document.getElementById('cashPaidAtInput').value);
      const bankName = (document.getElementById('cashBankNameInput').value || '').trim();
      const note = (document.getElementById('cashNoteInput').value || '').trim();

      if (!amount || !paidAt) {
        return { error: 'برای پرداخت نقدی، مبلغ و تاریخ پرداخت الزامی است.' };
      }

      return {
        method: 'cash',
        amount,
        paid_at: paidAt,
        bank_name: bankName,
        note,
      };
    }

    function readChequePayment() {
      const payload = {
        method: 'cheque',
        amount: normalizeAmount(document.getElementById('chequeAmountInput').value),
        paid_at: normalizeDate(document.getElementById('chequeReceivedAtInput').value),
        received_at: normalizeDate(document.getElementById('chequeReceivedAtInput').value),
        due_date: normalizeDate(document.getElementById('chequeDueDateInput').value),
        cheque_number: (document.getElementById('chequeNumberInput').value || '').trim(),
        bank_name: (document.getElementById('chequeBankNameInput').value || '').trim(),
        note: (document.getElementById('chequeNoteInput').value || '').trim(),
      };

      if (!payload.amount || !payload.received_at || !payload.due_date || !payload.cheque_number || !payload.bank_name) {
        return { error: 'برای ثبت چک، مبلغ، شماره سریال، بانک، تاریخ ثبت و تاریخ سررسید الزامی است.' };
      }

      return payload;
    }

    function clearModalFields() {
      paymentModalEl.querySelectorAll('input, textarea').forEach((el) => el.value = '');
      paymentModalError.classList.add('d-none');
      paymentModalError.textContent = '';
      paymentTypeInput.value = 'cash';
      togglePaymentTypeFields();
    }

    addBtn.addEventListener('click', () => {
      clearModalFields();
      if (paymentModal) {
        paymentModal.show();
      } else {
        paymentModalEl.style.display = 'block';
        paymentModalEl.classList.add('show');
      }
      if (typeof initJalaliDatepickers === 'function') {
        initJalaliDatepickers();
      }
      if (window.jalaliDatepicker) {
        window.jalaliDatepicker.startWatch({ minDate: 'attr', maxDate: 'attr', time: true });
      }
    });

    paymentTypeInput.addEventListener('change', togglePaymentTypeFields);

    document.getElementById('savePaymentBtn').addEventListener('click', () => {
      paymentModalError.classList.add('d-none');
      let payload = paymentTypeInput.value === 'cheque' ? readChequePayment() : readCashPayment();
      if (payload.error) {
        paymentModalError.textContent = payload.error;
        paymentModalError.classList.remove('d-none');
        return;
      }

      payments.push(payload);
      renderPaymentsList();
      if (paymentModal) {
        paymentModal.hide();
      } else {
        paymentModalEl.style.display = 'none';
        paymentModalEl.classList.remove('show');
      }
    });

    renderPaymentsList();
    initDatepickersSafe();

    if (finalizeForm) {
        finalizeForm.addEventListener('submit', function () {
            if (finalizeBtn) {
                finalizeBtn.disabled = true;
                finalizeBtn.textContent = 'در حال ثبت...';
            }
        });
    }
})();
</script>
@endsection
