@extends('layouts.app')

@section('content')
@php
  use Morilog\Jalali\Jalalian;

  $totals = \App\Support\SalesDocumentTotals::calculate($invoice->items, (int) $invoice->discount_amount, (int) $invoice->shipping_price, ['discount_allocation_mode' => $invoice->discount_allocation_mode]);

  $methodFa = fn($m) => match($m){
    'cash' => 'نقدی',
    'cheque' => 'چک',
    default => $m,
  };

  $statusFa = fn($s) => match($s){
    'pending_warehouse_approval' => 'در انتظار تایید انبار',
    'collecting' => 'در حال جمع‌آوری',
    'checking_discrepancy' => 'در حال بررسی',
    'packing' => 'بسته‌بندی بار',
    'shipped' => 'ارسال شد',
    'not_shipped' => 'کنسل شده',
    default => $s,
  };

  $jalali = function($dt){
    if (!$dt) return '—';
    return Jalalian::fromDateTime($dt)->format('Y/m/d');
  };

  $jalaliDT = function($dt){
    if (!$dt) return '—';
    return Jalalian::fromDateTime($dt)->format('Y/m/d H:i');
  };

  $rial = function($amount){
    $n = (int)($amount ?? 0);
    return \App\Support\Currency::formatRial($n);
  };

  $productTitle = function($it){
    return $it->product?->title
        ?? $it->product?->name
        ?? ('#'.$it->product_id);
  };

  $variantTitle = function($it){
    if ($it->variant) {
      if (!empty($it->variant->title)) return $it->variant->title;
      if (!empty($it->variant->name)) return $it->variant->name;
      if (!empty($it->variant->variant_name)) return $it->variant->variant_name;
      if (!empty($it->variant->unique_attributes_key)) return $it->variant->unique_attributes_key;
    }
    return $it->variant_id ? ('#'.$it->variant_id) : 'بدون مدل';
  };

  $badgeStatus = fn($s) => match($s){
    'pending_warehouse_approval' => 'bg-warning text-dark',
    'collecting' => 'bg-primary',
    'checking_discrepancy' => 'bg-info text-dark',
    'packing' => 'bg-secondary',
    'shipped' => 'bg-success',
    'not_shipped' => 'bg-danger',
    default => 'bg-secondary',
  };

  $badgeMethod = fn($m) => match($m){
    'cash' => 'bg-success',
    'cheque' => 'bg-warning text-dark',
    default => 'bg-secondary',
  };
@endphp

<style>
  .card-soft{background:#fff;border:1px solid rgba(0,0,0,.07);border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.04)}
  .section-title{font-weight:800}
  .hint{color:#6c757d;font-size:.9rem}
  .kv{display:flex;gap:.5rem;align-items:center;margin-bottom:.35rem}
  .kv .k{min-width:92px;color:#6c757d}
  .kv .v{font-weight:600}
  .sticky-side{position:sticky;top:16px}
</style>

<div class="container">

  @if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
  @endif
  @if($errors->any())
    <div class="alert alert-danger">
      <ul class="mb-0 ps-3">
        @foreach($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
      <div class="h5 fw-bold mb-0">🧾 فاکتور</div>
      <div class="text-muted small">{{ $invoice->uuid }}</div>
    </div>
    <div class="d-flex gap-2">
      @if($canFinanceApprove)
        <a class="btn btn-success" href="#invoicePayments">افزودن پرداخت</a>
      @endif
      <a class="btn btn-outline-dark" href="{{ route('invoices.print', $invoice->uuid) }}" target="_blank">چاپ فاکتور</a>
      <a class="btn btn-outline-secondary" href="{{ route('invoices.index') }}">بازگشت</a>
    </div>
  </div>

  <div class="row g-3">

    {{-- LEFT --}}
    <div class="col-lg-7">

      {{-- Customer --}}
      <div class="card-soft p-3 p-md-4 mb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div class="section-title">👤 اطلاعات مشتری</div>
          <span class="badge {{ $badgeStatus($invoice->status) }} px-3 py-2">
            {{ $statusFa($invoice->status) }}
          </span>
        </div>

        <div class="mt-3">
          <div class="kv"><div class="k">نام:</div><div class="v">{{ $invoice->customer_name ?: '—' }}</div></div>
          <div class="kv"><div class="k">موبایل:</div><div class="v">{{ $invoice->customer_mobile ?: '—' }}</div></div>
          <div class="kv"><div class="k">ثبت‌کننده:</div><div class="v">{{ $invoice->preinvoiceOrder?->creator?->name ?? '—' }}</div></div>
          <div class="kv"><div class="k">آدرس:</div><div class="v fw-normal">{{ $invoice->customer_address ?: '—' }}</div></div>
        </div>
      </div>

      {{-- Items --}}
      <div class="card-soft overflow-hidden">
        <div class="p-3 p-md-4 border-bottom d-flex justify-content-between align-items-center">
          <div>
            <div class="section-title mb-1">🛍️ آیتم‌ها</div>
            <div class="hint">جزئیات محصولات داخل فاکتور</div>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>محصول</th>
                <th>مدل</th>
                <th class="text-nowrap">تعداد</th>
                <th class="text-nowrap">قیمت</th>
                <th class="text-nowrap">جمع</th>
              </tr>
            </thead>
            <tbody>
              @foreach($invoice->items as $it)
                @php $line = \App\Support\SalesDocumentTotals::lineTotal($it); @endphp
                <tr>
                  <td class="fw-semibold">{{ $productTitle($it) }}</td>
                  <td>{{ $variantTitle($it) }}</td>
                  <td class="text-nowrap">{{ number_format($it->quantity) }}</td>
                  <td class="text-nowrap">{{ $rial($it->price) }}</td>
                  <td class="text-nowrap fw-bold">{{ $rial($line) }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>

        <div class="p-3 p-md-4 border-top">
          <div class="row g-2">
            <div class="col-6"><span class="text-muted">جمع جزء:</span> <b>{{ $rial($totals['subtotal_before_discount']) }}</b></div>
            <div class="col-6"><span class="text-muted">هزینه ارسال:</span> <b>{{ $rial($totals['shipping']) }}</b></div>
            <div class="col-6"><span class="text-muted">تخفیف:</span> <b>{{ $rial($totals['total_discount']) }}</b></div>
            <div class="col-6 fs-5"><span class="text-muted">مبلغ کل:</span> <b>{{ $rial($totals['grand_total']) }}</b></div>
          </div>
        </div>
      </div>
    </div>

    {{-- RIGHT --}}
    <div class="col-lg-5">
      <div class="sticky-side">

        {{-- Status --}}
        <div class="card-soft p-3 p-md-4 mb-3">
          <div class="section-title mb-2">📦 وضعیت آماده‌سازی حواله انبار</div>
          <form method="POST" action="{{ route('invoices.status', $invoice->uuid) }}" class="d-flex gap-2">
            @csrf
            <select name="status" class="form-select" @disabled($invoice->status==='not_shipped')>
              <option value="checking_discrepancy" @selected($invoice->status==='checking_discrepancy')>در حال بررسی</option>
              <option value="collecting" @selected($invoice->status==='collecting')>در حال جمع‌آوری</option>
              <option value="shipped" @selected($invoice->status==='shipped')>ارسال شده</option>
            </select>
            <button class="btn btn-primary" @disabled($invoice->status==='not_shipped')>ثبت</button>
          </form>
          <div class="hint mt-2">آخرین بروزرسانی: {{ $jalaliDT($invoice->updated_at) }}</div>
        </div>

        <div class="card-soft p-3 p-md-4 mb-3 border border-danger-subtle">
          <div class="section-title mb-2 text-danger">⛔ کنسل فاکتور/حواله</div>
          <form method="POST" action="{{ route('invoices.cancel', $invoice->uuid) }}" onsubmit="return confirm('فاکتور کنسل شود و موجودی به انبار برگردد؟')">
            @csrf
            <textarea name="note" class="form-control mb-2" rows="2" placeholder="دلیل کنسلی (اختیاری)"></textarea>
            <button class="btn btn-danger w-100" @disabled($invoice->status==='not_shipped')>کنسل کردن فاکتور و برگشت موجودی</button>
          </form>

          @if($invoice->status === 'not_shipped' && $canFinanceApprove)
            <hr class="my-3">
            <div class="section-title mb-2 text-success">↩️ لغو کنسلی توسط مالی</div>
            <form method="POST" action="{{ route('invoices.cancel.undo', $invoice->uuid) }}" onsubmit="return confirm('کنسلی فاکتور لغو شود و موجودی دوباره از انبار کسر شود؟')">
              @csrf
              <textarea name="note" class="form-control mb-2" rows="2" placeholder="توضیح لغو کنسلی (اختیاری)"></textarea>
              <button class="btn btn-success w-100">لغو کنسلی و بازگشت به صف انبار</button>
            </form>
          @endif
        </div>

        {{-- Payments --}}
        <div class="card-soft p-3 p-md-4 mb-3" id="invoicePayments">
          @php
            $cashPaidAmount = (int) $invoice->payments->where('method', 'cash')->sum('amount');
            $chequePaidAmount = (int) $invoice->payments->where('method', 'cheque')->sum('amount');
            $paidAmount = $cashPaidAmount + $chequePaidAmount;
            $remainingAmount = max((int) $totals['grand_total'] - $paidAmount, 0);
            $paymentState = $remainingAmount <= 0 ? 'تسویه شده' : ($paidAmount > 0 ? 'پرداخت ناقص' : 'پرداخت نشده');
            $paymentStateClass = $remainingAmount <= 0 ? 'bg-success' : ($paidAmount > 0 ? 'bg-warning text-dark' : 'bg-danger');
          @endphp
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="section-title">💳 پرداخت‌ها</div>
            <span class="badge {{ $paymentStateClass }}">{{ $paymentState }}</span>
          </div>

          <div class="d-flex justify-content-between">
            <div class="hint">پرداخت شده</div>
            <div class="fw-bold">{{ $rial($paidAmount) }}</div>
          </div>
          <div class="d-flex justify-content-between">
            <div class="hint">جمع نقدی</div>
            <div class="fw-bold">{{ $rial($cashPaidAmount) }}</div>
          </div>
          <div class="d-flex justify-content-between">
            <div class="hint">جمع چکی</div>
            <div class="fw-bold">{{ $rial($chequePaidAmount) }}</div>
          </div>
          <div class="d-flex justify-content-between mb-3">
            <div class="hint">مانده</div>
            <div class="fw-bold {{ $remainingAmount > 0 ? 'text-danger' : 'text-success' }}">{{ $rial($remainingAmount) }}</div>
          </div>

          @if($canFinanceApprove)
          <div class="border rounded-3 p-2 p-md-3 d-none" id="invoicePaymentFormWrap" style="background:rgba(25,135,84,.04);border-color:rgba(25,135,84,.25)!important">
            <form method="POST" action="{{ route('invoices.payments.store', $invoice->uuid) }}" enctype="multipart/form-data" id="invoicePaymentForm">
              @csrf
              <div class="row g-2">
                <div class="col-md-4">
                  <label class="form-label">نوع پرداخت</label>
                  <select name="method" class="form-select" id="invoice_payment_method" required>
                    <option value="cash">نقدی</option>
                    <option value="cheque">چکی</option>
                  </select>
                </div>

                <div class="col-md-8">
                  <label class="form-label">مبلغ پرداخت / مبلغ چک</label>
                  <input id="amount_view" type="text" inputmode="numeric" class="form-control" placeholder="مبلغ (ریال)" autocomplete="off" required>
                  <input name="amount" id="amount" type="hidden">
                </div>

                <div class="col-md-4 cash-fields">
                  <label class="form-label">تاریخ پرداخت</label>
                  <input id="paid_at_jalali" type="text" class="form-control" placeholder="تاریخ شمسی">
                  <input name="paid_at" id="paid_at" type="hidden">
                </div>
                <div class="col-md-4 cash-fields">
                  <label class="form-label">اسم بانک (فقط نقدی)</label>
                  <input name="bank_name" class="form-control" placeholder="مثال: ملی">
                </div>
                <div class="col-md-4 cash-fields">
                  <label class="form-label">رسید پرداخت</label>
                  <input name="receipt_image" type="file" class="form-control" accept="image/*">
                </div>

                <div class="col-12 cheque-fields d-none">
                  <div class="row g-2">
                    <div class="col-md-6">
                      <label class="form-label">شماره سریال چک</label>
                      <input name="cheque_number" class="form-control" placeholder="شماره سریال چک">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">تاریخ ثبت چک</label>
                      <input name="received_at" type="date" class="form-control">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">تاریخ سررسید چک</label>
                      <input name="due_date" type="date" class="form-control">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">وضعیت چک</label>
                      <select name="status" class="form-select">
                        <option value="registered">ثبت‌شده</option>
                        <option value="unregistered">ثبت‌نشده</option>
                      </select>
                    </div>
                  </div>
                </div>

                <div class="col-12 cash-fields">
                  <label class="form-label">یادداشت</label>
                  <textarea name="note" class="form-control" rows="2" placeholder="اختیاری"></textarea>
                </div>

                <div class="col-12">
                  <button class="btn btn-success w-100">ثبت پرداخت</button>
                </div>
              </div>
            </form>
          </div>
          @else
            <div class="alert alert-light border">ثبت پرداخت فقط برای کاربران مالی/مدیر فعال است.</div>
          @endif

          <hr class="my-3">

          <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="fw-semibold">واریزی‌های فاکتور</div>
            @if($canFinanceApprove)
              <button type="button" class="btn btn-success btn-sm" id="toggleInvoicePaymentForm">➕ افزودن پرداخت</button>
            @endif
          </div>

          @forelse($invoice->payments as $p)
            @php
              $chequeStatusFa = match($p->cheque?->status){
                'pending' => 'در انتظار / نامشخص',
                'unregistered' => 'ثبت‌نشده',
                'registered' => 'ثبت‌شده',
                'cleared' => 'وصول شده',
                'bounced' => 'برگشتی',
                default => '—',
              };
            @endphp
            <div class="border rounded-3 p-2 mb-2">
              <div class="d-flex justify-content-between align-items-center gap-2">
                <div class="d-flex align-items-center gap-2">
                  <span class="badge {{ $badgeMethod($p->method) }}">{{ $methodFa($p->method) }}</span>
                  <span class="fw-bold">{{ $rial($p->amount) }}</span>
                </div>
                <div class="text-muted small">{{ $jalali($p->paid_at) }}</div>
              </div>

              <div class="small text-muted mt-1">
                ثبت‌کننده: {{ $p->creator?->name ?? '—' }}
                @if($p->method === 'cash')
                  | بانک: {{ $p->bank_name ?: '—' }}
                @endif
              </div>

              @if($p->method === 'cheque' && $p->cheque)
                <div class="small text-muted mt-1">
                  مبلغ چک: {{ $rial($p->cheque->amount ?: $p->amount) }} |
                  شماره سریال چک: {{ $p->cheque->cheque_number ?: '—' }} |
                  تاریخ ثبت چک: {{ $jalali($p->cheque->received_at) }} |
                  تاریخ سررسید چک: {{ $jalali($p->cheque->due_date) }} |
                  وضعیت چک: {{ $chequeStatusFa }}
                </div>
              @endif

              @if($p->receipt_image)
                <div class="mt-2">
                  <a target="_blank" class="btn btn-sm btn-outline-secondary" href="{{ asset('storage/'.$p->receipt_image) }}">📎 رسید پرداخت</a>
                </div>
              @endif

              @if($p->method === 'cheque' && $p->cheque?->image)
                <div class="mt-2">
                  <a target="_blank" class="btn btn-sm btn-outline-warning" href="{{ asset('storage/'.$p->cheque->image) }}">📷 تصویر چک</a>
                </div>
              @endif

              @if($p->note)
                <div class="small mt-2">{{ $p->note }}</div>
              @endif
            </div>
          @empty
            <div class="text-muted small">پرداختی ثبت نشده است.</div>
          @endforelse
        </div>

        {{-- Notes --}}
        <div class="card-soft p-3 p-md-4">
          <div class="section-title mb-2">📝 یادداشت‌ها</div>

          <form method="POST" action="{{ route('invoices.notes.store', $invoice->uuid) }}" class="mb-3">
            @csrf
            <textarea name="body" class="form-control" rows="2" placeholder="یادداشت جدید..." required></textarea>
            <button class="btn btn-primary w-100 mt-2">ثبت یادداشت</button>
          </form>

          @forelse($invoice->notes as $n)
            <div class="border rounded-3 p-2 mb-2">
              <div class="small text-muted">{{ $jalaliDT($n->created_at) }}</div>
              <div>{{ $n->body }}</div>
            </div>
          @empty
            <div class="text-muted small">یادداشتی ثبت نشده است.</div>
          @endforelse
        </div>

      </div>
    </div>

  </div>

</div>

<script>
    (function(){
      const view = document.getElementById('amount_view');
      const hidden = document.getElementById('amount');
      if (!view || !hidden) return;

      function toEnglishDigits(str) {
        return String(str || '')
          .replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))
          .replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
      }

      function cleanNumber(str){
        return toEnglishDigits(str)
          .replaceAll(',', '')
          .replaceAll('٬', '')
          .replaceAll('،', '')
          .replaceAll(' ', '')
          .trim();
      }

      function formatWithComma(nStr){
        const n = parseInt(nStr, 10);
        if (!Number.isFinite(n)) return '';
        return n.toLocaleString('fa-IR'); // جداکننده فارسی
      }

      function sync(){
        const raw = cleanNumber(view.value);
        // فقط عدد
        const onlyDigits = raw.replace(/\D/g,'');
        hidden.value = onlyDigits ? String(parseInt(onlyDigits, 10)) : '';

        // فرمت نمایشی
        view.value = onlyDigits ? formatWithComma(onlyDigits) : '';
      }

      view.addEventListener('input', sync);
      view.addEventListener('blur', sync);

      // قبل submit هم حتما ست شود
      const form = view.closest('form');
      if (form) {
        form.addEventListener('submit', () => {
          sync();
          if (!hidden.value) {
            // اگر خالی بود، فرم required را fail کند
            view.focus();
          }
        }, { capture:true });
      }
    })();
    </script>

<script>
  (function () {
    if (!window.jalaliDatepicker) return;

    const jalaliInput = document.getElementById('paid_at_jalali');
    const gregorianInput = document.getElementById('paid_at');
    if (!jalaliInput || !gregorianInput) return;

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
      return [gy, gm, gd];
    }

    function jalaliStringToGregorian(str) {
      const m = (str || '').trim().match(/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})$/);
      if (!m) return '';
      const g = jalaliToGregorian(Number(m[1]), Number(m[2]), Number(m[3]));
      return `${g[0]}-${pad(g[1])}-${pad(g[2])}`;
    }

    jalaliInput.setAttribute('data-jdp', '');
    jalaliInput.setAttribute('data-jdp-only-date', '');
    jalaliInput.setAttribute('autocomplete', 'off');
    jalaliDatepicker.startWatch();

    function syncPaidAt() {
      gregorianInput.value = jalaliStringToGregorian(jalaliInput.value);
    }

    jalaliInput.addEventListener('change', syncPaidAt);
    jalaliInput.addEventListener('input', syncPaidAt);

    const form = jalaliInput.closest('form');
    if (form) {
      form.addEventListener('submit', syncPaidAt);
    }
  })();
</script>


<script>
  (function () {
    const toggleBtn = document.getElementById('toggleInvoicePaymentForm');
    const formWrap = document.getElementById('invoicePaymentFormWrap');
    const methodSelect = document.getElementById('invoice_payment_method');
    if (!formWrap) return;

    const chequeBlocks = formWrap.querySelectorAll('.cheque-fields');
    const cashBlocks = formWrap.querySelectorAll('.cash-fields');

    function setChequeVisibility() {
      const isCheque = methodSelect && methodSelect.value === 'cheque';
      chequeBlocks.forEach((el) => el.classList.toggle('d-none', !isCheque));
      cashBlocks.forEach((el) => el.classList.toggle('d-none', isCheque));
    }

    toggleBtn?.addEventListener('click', function () {
      formWrap.classList.toggle('d-none');
      if (!formWrap.classList.contains('d-none')) {
        formWrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    });

    methodSelect?.addEventListener('change', setChequeVisibility);
    setChequeVisibility();
  })();
</script>

@endsection
