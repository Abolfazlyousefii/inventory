@extends('layouts.app')

@section('title', 'فاکتورهای فروش')
@section('content_class', 'app-content-wide')

@section('content')
@php
  use Morilog\Jalali\Jalalian;
  use Illuminate\Support\Str;

  $statusFa = fn($s) => ($statusLabels[$s] ?? ($s ?: '—'));
  $statusBadge = fn($s) => match($s){
    'pending_warehouse_approval' => 'text-bg-info',
    'collecting', 'checking_discrepancy', 'final_check', 'packing' => 'text-bg-primary',
    'shipped' => 'text-bg-success',
    'not_shipped' => 'text-bg-danger',
    default => 'text-bg-secondary',
  };
  $rial = fn($amount) => \App\Support\Currency::formatRial((int) $amount);
  $payLabel = fn($paid, $total) => max((int)$total - (int)$paid, 0) <= 0 ? 'تسویه‌شده' : ((int)$paid > 0 ? 'پرداخت ناقص' : 'پرداخت‌نشده');
  $payClass = fn($paid, $total) => max((int)$total - (int)$paid, 0) <= 0 ? 'text-bg-success' : ((int)$paid > 0 ? 'text-bg-warning text-dark' : 'text-bg-danger');
@endphp

<style>
  .sales-wide-page{max-width:100%; overflow-x:hidden;}
  .sales-page-head,.sales-card{border:0; border-radius:18px; box-shadow:0 10px 28px rgba(15,23,42,.06);}
  .sales-page-head{background:linear-gradient(135deg,#fff,#f8fafc); padding:20px;}
  .sales-page-title{font-weight:950; letter-spacing:-.02em;}
  .action-toolbar .btn{border-radius:12px; font-weight:800; padding:.55rem .8rem;}
  .btn-pdf{background:#dc2626; border-color:#dc2626; color:#fff; box-shadow:0 8px 18px rgba(220,38,38,.22);}
  .btn-pdf:hover{background:#b91c1c; border-color:#b91c1c; color:#fff;}
  .btn-excel{background:#16a34a; border-color:#16a34a; color:#fff; box-shadow:0 8px 18px rgba(22,163,74,.18);}
  .btn-excel:hover{background:#15803d; border-color:#15803d; color:#fff;}
  .sales-filter-card .card-body{padding:16px;}
  .sales-filter-card .form-label{font-size:.78rem; color:#64748b; font-weight:800; margin-bottom:.35rem;}
  .sales-filter-card .form-control,.sales-filter-card .form-select{border-radius:12px;}
  .summary-card{border:1px solid #eef2f7; border-radius:16px; padding:14px; background:#fff; height:100%; box-shadow:0 6px 18px rgba(15,23,42,.035);}
  .summary-card .label{font-size:.78rem; color:#64748b; font-weight:800;}
  .summary-card .value{font-size:1rem; font-weight:950; margin-top:6px;}
  .sales-table{table-layout:fixed; width:100%;}
  .sales-table th{font-size:.76rem; color:#64748b; white-space:nowrap; font-weight:900;}
  .sales-table td{font-size:.84rem; vertical-align:middle; padding:.55rem .45rem;}
  .code-cell{direction:ltr; unicode-bidi:plaintext; display:inline-block; max-width:120px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;}
  .invoice-number-pill{background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; border-radius:999px; padding:.2rem .45rem; font-weight:950;}
  .customer-cell{max-width:170px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:inline-block;}
  .money-cell{white-space:nowrap; font-variant-numeric:tabular-nums; text-align:left; direction:ltr; font-weight:800;}
  .status-badge{min-width:82px; border-radius:999px; padding:.42em .65em;}
  .invoice-mobile-card{border:1px solid #e5e7eb; border-radius:16px; padding:14px; background:#fff; box-shadow:0 6px 18px rgba(15,23,42,.04);}
  .payment-modal .modal-dialog{max-width:920px;}
  .quick-ranges .btn{font-size:.76rem; padding:.25rem .55rem;}
  @media print{.no-report-print,.modal,.pagination{display:none!important}.sales-card,.sales-page-head{box-shadow:none!important;border:1px solid #ddd!important}.d-lg-none{display:none!important}}
</style>

<div class="sales-wide-page">
  <div class="sales-page-head mb-3 d-flex justify-content-between align-items-start flex-wrap gap-3">
    <div>
      <div class="h4 sales-page-title mb-1">فاکتورهای فروش</div>
      <div class="text-muted small">گزارش، پیگیری و مدیریت مالی فاکتورهای فروش</div>
    </div>
    <div class="action-toolbar d-flex gap-2 flex-wrap align-items-center justify-content-end no-report-print">
      <a class="btn btn-outline-primary" href="{{ route('vouchers.index', ['voucher_type' => 'sale']) }}">حواله فروش کالا</a>
      @if($canRegisterPayments ?? false)
        <a class="btn btn-pdf" href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}">خروجی PDF</a>
        <a class="btn btn-excel" href="{{ request()->fullUrlWithQuery(['export' => 'excel']) }}">خروجی Excel</a>
        <a class="btn btn-outline-success" href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}">خروجی CSV</a>
      @endif
      <button type="button" class="btn btn-outline-dark" onclick="window.print()">چاپ گزارش</button>
    </div>
  </div>

  @if(!empty($filterErrors))
    <div class="alert alert-danger no-report-print">@foreach($filterErrors as $error)<div>{{ $error }}</div>@endforeach</div>
  @endif

  <div class="card sales-card sales-filter-card mb-3 no-report-print">
    <div class="card-body">
      <form class="row g-3 align-items-end" method="GET" action="{{ route('invoices.index') }}">
        <div class="col-12 quick-ranges d-flex gap-2 flex-wrap">
          @foreach(['today'=>'امروز','yesterday'=>'دیروز','this_week'=>'این هفته','this_month'=>'این ماه','last_month'=>'ماه قبل'] as $key => $label)
            <button class="btn btn-outline-primary" name="quick_range" value="{{ $key }}">{{ $label }}</button>
          @endforeach
          <span class="small text-muted align-self-center">ملاک تاریخ: تاریخ اصلی سند (ثبت اولیه پیش‌فاکتور)</span>
        </div>
        <div class="col-sm-6 col-xl-2"><label class="form-label">از تاریخ شمسی</label><input type="text" class="form-control" name="date_from" value="{{ $filters['date_from'] ?? '' }}" dir="ltr" data-jdp data-jdp-only-date placeholder="1403/03/01"></div>
        <div class="col-sm-6 col-xl-2"><label class="form-label">تا تاریخ شمسی</label><input type="text" class="form-control" name="date_to" value="{{ $filters['date_to'] ?? '' }}" dir="ltr" data-jdp data-jdp-only-date placeholder="1403/03/31"></div>
        <div class="col-sm-6 col-xl-2"><label class="form-label">شماره فاکتور</label><input class="form-control" name="invoice_number" value="{{ $filters['invoice_number'] ?? '' }}"></div>
        <div class="col-sm-6 col-xl-2"><label class="form-label">نام مشتری / شخص</label><input class="form-control" name="customer_name" value="{{ $filters['customer_name'] ?? '' }}"></div>
        <div class="col-sm-6 col-xl-2"><label class="form-label">کد شخص / CRM</label><input class="form-control" name="customer_code" value="{{ $filters['customer_code'] ?? '' }}"></div>
        <div class="col-sm-6 col-xl-2"><label class="form-label">موبایل مشتری</label><input class="form-control" name="customer_mobile" value="{{ $filters['customer_mobile'] ?? '' }}"></div>
        <div class="col-sm-6 col-xl-2"><label class="form-label">وضعیت پرداخت</label><select class="form-select" name="payment_status"><option value="">همه</option><option value="paid" @selected(($filters['payment_status'] ?? '')==='paid')>تسویه‌شده</option><option value="partial" @selected(($filters['payment_status'] ?? '')==='partial')>پرداخت ناقص</option><option value="unpaid" @selected(($filters['payment_status'] ?? '')==='unpaid')>پرداخت‌نشده</option></select></div>
        <div class="col-12"><button class="btn btn-link p-0" type="button" data-bs-toggle="collapse" data-bs-target="#moreInvoiceFilters">فیلترهای بیشتر</button></div>
        <div class="collapse col-12 {{ (($filters['status']??'')||($filters['seller']??'')||($filters['only_remaining']??'')||($filters['only_paid']??'')||($filters['has_cheque']??'')||($filters['min_amount']??'')||($filters['max_amount']??'')) ? 'show' : '' }}" id="moreInvoiceFilters">
          <div class="row g-3">
            <div class="col-sm-6 col-xl-2"><label class="form-label">وضعیت عملیاتی</label><select class="form-select" name="status"><option value="">همه</option>@foreach($allowedStatuses as $key)<option value="{{ $key }}" @selected(($filters['status'] ?? '')===$key)>{{ $statusFa($key) }}</option>@endforeach</select></div>
            <div class="col-sm-6 col-xl-2"><label class="form-label">ثبت‌کننده / فروشنده</label><input class="form-control" name="seller" value="{{ $filters['seller'] ?? '' }}"></div>
            <div class="col-sm-6 col-xl-2"><label class="form-label">حداقل مبلغ</label><input class="form-control" name="min_amount" value="{{ $filters['min_amount'] ?? '' }}"></div>
            <div class="col-sm-6 col-xl-2"><label class="form-label">حداکثر مبلغ</label><input class="form-control" name="max_amount" value="{{ $filters['max_amount'] ?? '' }}"></div>
            <div class="col-12 d-flex gap-3 flex-wrap">
              <label class="form-check"><input class="form-check-input" type="checkbox" name="only_remaining" value="1" @checked(($filters['only_remaining'] ?? '')==='1')> فقط مانده‌دارها</label>
              <label class="form-check"><input class="form-check-input" type="checkbox" name="only_paid" value="1" @checked(($filters['only_paid'] ?? '')==='1')> فقط تسویه‌شده‌ها</label>
              <label class="form-check"><input class="form-check-input" type="checkbox" name="has_cheque" value="1" @checked(($filters['has_cheque'] ?? '')==='1')> فقط دارای چک</label>
            </div>
          </div>
        </div>
        <div class="col-12 d-flex gap-2 justify-content-end flex-wrap"><a class="btn btn-outline-secondary" href="{{ route('invoices.index') }}">پاک‌کردن فیلترها</a><button class="btn btn-primary px-4">اعمال فیلتر</button></div>
      </form>
    </div>
  </div>

  <div class="row g-3 mb-3">
    @foreach([
      ['جمع کل فروش',$summary['total_sales'] ?? 0,'text-primary'],['مبلغ دریافت‌شده',$summary['paid_amount'] ?? 0,'text-success'],['مانده قابل دریافت',$summary['remaining_amount'] ?? 0,'text-danger'],['تعداد فاکتورها',$summary['invoice_count'] ?? 0,'text-dark'],['فاکتورهای تسویه‌شده',$summary['paid_count'] ?? 0,'text-success'],['پرداخت ناقص',$summary['partial_count'] ?? 0,'text-warning'],['پرداخت‌نشده',$summary['unpaid_count'] ?? 0,'text-danger']
    ] as [$label,$value,$class])
      <div class="col-6 col-lg-3 col-xxl"><div class="summary-card"><div class="label">{{ $label }}</div><div class="value {{ $class }}">{{ is_int($value) && str_contains($label,'فاکتور') || $label==='تعداد فاکتورها' ? number_format($value) : $rial($value) }}</div></div></div>
    @endforeach
  </div>

  <div class="card sales-card d-none d-lg-block">
    <div class="table-responsive invoice-table-wrap">
      <table class="table table-hover align-middle mb-0 sales-table">
        <colgroup><col style="width:8%"><col style="width:7%"><col style="width:13%"><col style="width:6%"><col style="width:8%"><col style="width:9%"><col style="width:9%"><col style="width:9%"><col style="width:8%"><col style="width:9%"><col style="width:7%"><col style="width:7%"></colgroup>
        <thead class="table-light"><tr><th>شماره</th><th>تاریخ</th><th>مشتری</th><th>کد</th><th>موبایل</th><th class="money-cell">مبلغ</th><th class="money-cell">پرداخت‌شده</th><th class="money-cell">مانده</th><th>وضعیت پرداخت</th><th>وضعیت فاکتور</th><th>ثبت‌کننده</th><th class="text-end">عملیات</th></tr></thead>
        <tbody>
          @forelse($invoices as $inv)
            @php $paid=(int)($inv->paid_total??0); $remaining=max((int)$inv->total-$paid,0); $customerCode=$inv->customer?->crm_customer_id ?: $inv->customer_id; $customerName=$inv->customer_name ?: $inv->customer?->display_name ?: '—'; @endphp
            <tr>
              <td><span class="code-cell invoice-number-pill" title="{{ $inv->uuid }}">{{ Str::limit($inv->uuid, 10, '…') }}</span></td>
              <td class="text-nowrap">{{ \App\Support\JalaliDate::date($inv->display_document_date) }}</td>
              <td><span class="customer-cell" title="{{ $customerName }}">{{ $customerName }}</span></td><td>{{ $customerCode ?: '—' }}</td><td>{{ $inv->customer_mobile ?: $inv->customer?->mobile ?: '—' }}</td>
              <td class="money-cell">{{ $rial($inv->total) }}</td><td class="money-cell text-success">{{ $rial($paid) }}</td><td class="money-cell fw-bold {{ $remaining>0?'text-danger':'text-success' }}">{{ $rial($remaining) }}</td>
              <td><span class="badge status-badge {{ $payClass($paid,$inv->total) }}">{{ $payLabel($paid,$inv->total) }}</span></td><td><span class="badge status-badge {{ $statusBadge($inv->status) }}">{{ $statusFa($inv->status) }}</span></td><td><span class="customer-cell">{{ $inv->preinvoiceOrder?->creator?->name ?? '—' }}</span></td>
              <td>
                <div class="dropdown">
                  <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">عملیات</button>
                  <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="{{ route('invoices.show', $inv->uuid) }}">مشاهده جزئیات</a></li>
                    <li><a class="dropdown-item" href="{{ route('invoices.edit', $inv->uuid) }}">ویرایش</a></li>
                    @if(($canRegisterPayments ?? false) && $remaining > 0)
                      <li>
                        <button type="button" class="dropdown-item js-open-payment" data-action="{{ route('invoices.payments.store', $inv->uuid) }}" data-invoice="{{ $inv->uuid }}" data-customer="{{ $customerName }}" data-remaining="{{ $remaining }}" data-remaining-label="{{ $rial($remaining) }}">ثبت پرداخت</button>
                      </li>
                    @endif
                    <li><a class="dropdown-item" href="{{ route('invoices.print', $inv->uuid) }}" target="_blank">چاپ فاکتور</a></li>
                    @if($inv->customer_id)
                      <li><a class="dropdown-item" href="{{ route('account-statements.show', $inv->customer_id) }}">گردش حساب مشتری</a></li>
                    @endif
                    @if($inv->preinvoiceOrder)
                      <li><a class="dropdown-item" href="{{ route('archive.preinvoices.show', $inv->preinvoiceOrder->uuid) }}">پیش‌فاکتور مرتبط</a></li>
                    @endif
                  </ul>
                </div>
              </td>
            </tr>
          @empty
            <tr><td colspan="12" class="text-center text-muted py-4">هیچ فاکتوری با فیلترهای انتخاب‌شده یافت نشد.</td></tr>
          @endforelse
        </tbody>
        <tfoot class="table-light"><tr><th colspan="5">جمع همین صفحه ({{ number_format($pageTotals['count'] ?? 0) }} فاکتور)</th><th class="money-cell">{{ $rial($pageTotals['total'] ?? 0) }}</th><th class="money-cell text-success">{{ $rial($pageTotals['paid'] ?? 0) }}</th><th class="money-cell text-danger">{{ $rial($pageTotals['remaining'] ?? 0) }}</th><th colspan="4"></th></tr></tfoot>
      </table>
    </div>
  </div>

  <div class="d-lg-none vstack gap-2">
    @forelse($invoices as $inv)
      @php $paid=(int)($inv->paid_total??0); $remaining=max((int)$inv->total-$paid,0); $customerName=$inv->customer_name ?: $inv->customer?->display_name ?: '—'; @endphp
      <div class="invoice-mobile-card">
        <div class="d-flex justify-content-between gap-2 mb-2">
          <div class="fw-bold">{{ $customerName }}</div>
          <span class="code-cell">{{ Str::limit($inv->uuid, 12, '…') }}</span>
        </div>
        <div class="d-flex flex-wrap gap-2 mb-2">
          <span class="badge status-badge {{ $statusBadge($inv->status) }}">{{ $statusFa($inv->status) }}</span>
          <span class="badge status-badge {{ $payClass($paid, $inv->total) }}">{{ $payLabel($paid, $inv->total) }}</span>
        </div>
        <div class="small text-muted d-flex justify-content-between"><span>مبلغ</span><strong>{{ $rial($inv->total) }}</strong></div>
        <div class="small text-muted d-flex justify-content-between"><span>پرداخت‌شده</span><strong>{{ $rial($paid) }}</strong></div>
        <div class="small text-muted d-flex justify-content-between"><span>مانده</span><strong class="{{ $remaining > 0 ? 'text-danger' : 'text-success' }}">{{ $rial($remaining) }}</strong></div>
        <div class="dropdown mt-3">
          <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">عملیات</button>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="{{ route('invoices.show', $inv->uuid) }}">مشاهده جزئیات</a></li>
            <li><a class="dropdown-item" href="{{ route('invoices.edit', $inv->uuid) }}">ویرایش</a></li>
            @if(($canRegisterPayments ?? false) && $remaining > 0)
              <li>
                <button type="button" class="dropdown-item js-open-payment" data-action="{{ route('invoices.payments.store', $inv->uuid) }}" data-invoice="{{ $inv->uuid }}" data-customer="{{ $customerName }}" data-remaining="{{ $remaining }}" data-remaining-label="{{ $rial($remaining) }}">ثبت پرداخت</button>
              </li>
            @endif
            <li><a class="dropdown-item" href="{{ route('invoices.print', $inv->uuid) }}" target="_blank">چاپ فاکتور</a></li>
          </ul>
        </div>
      </div>
    @empty
      <div class="invoice-mobile-card text-center text-muted">هیچ فاکتوری با فیلترهای انتخاب‌شده یافت نشد.</div>
    @endforelse
  </div>

  <div class="mt-3 no-report-print">{{ $invoices->links() }}</div>
</div>

<div class="modal fade payment-modal" id="invoicePaymentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 rounded-4">
      <div class="modal-header bg-light">
        <div>
          <h5 class="modal-title fw-bold">➕ افزودن پرداخت</h5>
          <div class="small text-muted" id="paymentModalSubtitle">—</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
      </div>
      <form method="POST" action="#" enctype="multipart/form-data" id="invoicePaymentQuickForm">
        @csrf
        <div class="modal-body">
          <div class="alert alert-light border d-flex justify-content-between flex-wrap gap-2">
            <span>مانده قابل پرداخت</span><strong id="paymentRemainingLabel">—</strong>
          </div>
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label">روش پرداخت</label>
              <select name="method" id="quick_payment_method" class="form-select" required>
                <option value="cash">نقدی</option>
                <option value="cheque">چکی</option>
              </select>
            </div>
            <div class="col-md-5">
              <label class="form-label">مبلغ پرداخت</label>
              <input id="quick_amount_view" type="text" inputmode="numeric" class="form-control" placeholder="مبلغ (ریال)" required>
              <input name="amount" id="quick_amount" type="hidden">
            </div>
            <div class="col-md-4">
              <label class="form-label">تاریخ پرداخت شمسی</label>
              <input id="quick_paid_at_jalali" type="text" class="form-control" placeholder="1403/03/21" required>
              <input name="paid_at" id="quick_paid_at" type="hidden">
            </div>
            <div class="col-md-4 cash-fields">
              <label class="form-label">اسم بانک</label>
              <input name="bank_name" class="form-control" placeholder="مثال: ملی">
            </div>
            <div class="col-md-4 cash-fields">
              <label class="form-label">شماره پیگیری/رسید</label>
              <input name="payment_identifier" class="form-control" placeholder="اختیاری">
            </div>
            <div class="col-md-4 cash-fields">
              <label class="form-label">تصویر رسید</label>
              <input name="receipt_image" type="file" class="form-control" accept="image/*">
            </div>
            <div class="col-12 cheque-fields d-none">
              <div class="row g-3">
                <div class="col-md-3"><label class="form-label">شماره چک</label><input name="cheque_number" class="form-control"></div>
                <div class="col-md-3"><label class="form-label">نام بانک</label><input name="cheque_bank_name" class="form-control"></div>
                <div class="col-md-3"><label class="form-label">نام شعبه</label><input name="cheque_branch_name" class="form-control"></div>
                <div class="col-md-3"><label class="form-label">تاریخ سررسید</label><input name="cheque_due_date" type="date" class="form-control"></div>
                <div class="col-md-3"><label class="form-label">تاریخ دریافت</label><input name="cheque_received_at" type="date" class="form-control"></div>
                <div class="col-md-3"><label class="form-label">نام مشتری</label><input name="cheque_customer_name" class="form-control"></div>
                <div class="col-md-3"><label class="form-label">کد مشتری</label><input name="cheque_customer_code" class="form-control"></div>
                <div class="col-md-3"><label class="form-label">وضعیت چک</label><select name="cheque_status" class="form-select"><option value="pending">در انتظار وصول</option><option value="cleared">وصول شده</option><option value="bounced">برگشتی</option></select></div>
              </div>
            </div>
            <div class="col-12"><label class="form-label">توضیحات</label><textarea name="note" class="form-control" rows="2"></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button>
          <button class="btn btn-success">ثبت پرداخت</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  const modalEl = document.getElementById('invoicePaymentModal');
  const form = document.getElementById('invoicePaymentQuickForm');
  if (!modalEl || !form) return;
  const modal = new bootstrap.Modal(modalEl);
  const amountView = document.getElementById('quick_amount_view');
  const amountHidden = document.getElementById('quick_amount');
  const method = document.getElementById('quick_payment_method');
  const jalaliInput = document.getElementById('quick_paid_at_jalali');
  const gregInput = document.getElementById('quick_paid_at');
  let maxRemaining = 0;

  function toEnglishDigits(str){return String(str||'').replace(/[۰-۹]/g,d=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g,d=>'٠١٢٣٤٥٦٧٨٩'.indexOf(d));}
  function cleanNumber(str){return toEnglishDigits(str).replace(/[٬,،\s]/g,'').trim();}
  function syncAmount(){const digits=cleanNumber(amountView.value).replace(/\D/g,''); amountHidden.value=digits?String(parseInt(digits,10)):''; amountView.value=digits?parseInt(digits,10).toLocaleString('fa-IR'):'';}
  function div(a,b){return ~~(a/b)}; function pad(v){return String(v).padStart(2,'0')}
  function jalaliToGregorian(jy,jm,jd){jy+=1595;let days=-355668+(365*jy)+div(jy,33)*8+div((jy%33)+3,4)+jd+((jm<7)?(jm-1)*31:((jm-7)*30)+186);let gy=400*div(days,146097);days%=146097;if(days>36524){gy+=100*div(--days,36524);days%=36524;if(days>=365)days++;}gy+=4*div(days,1461);days%=1461;if(days>365){gy+=div(days-1,365);days=(days-1)%365;}let gd=days+1;const sal=[0,31,((gy%4===0&&gy%100!==0)||(gy%400===0))?29:28,31,30,31,30,31,31,30,31,30,31];let gm=1;for(;gm<=12&&gd>sal[gm];gm++)gd-=sal[gm];return [gy,gm,gd];}
  function syncDate(){const m=(jalaliInput.value||'').trim().match(/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})$/); if(!m){gregInput.value=''; return;} const g=jalaliToGregorian(Number(m[1]),Number(m[2]),Number(m[3])); gregInput.value=`${g[0]}-${pad(g[1])}-${pad(g[2])}`;}
  function toggleMethod(){const isCheque=method.value==='cheque'; form.querySelectorAll('.cheque-fields').forEach(e=>e.classList.toggle('d-none',!isCheque)); form.querySelectorAll('.cash-fields').forEach(e=>e.classList.toggle('d-none',isCheque));}

  document.querySelectorAll('.js-open-payment').forEach(btn=>btn.addEventListener('click',()=>{
    form.reset(); amountHidden.value=''; gregInput.value=''; maxRemaining=parseInt(btn.dataset.remaining||'0',10)||0; form.action=btn.dataset.action;
    document.getElementById('paymentModalSubtitle').textContent=`فاکتور ${btn.dataset.invoice} | ${btn.dataset.customer}`;
    document.getElementById('paymentRemainingLabel').textContent=btn.dataset.remainingLabel || '';
    toggleMethod(); modal.show();
  }));
  amountView?.addEventListener('input', syncAmount); amountView?.addEventListener('blur', syncAmount);
  method?.addEventListener('change', toggleMethod);
  if (window.jalaliDatepicker && jalaliInput) { jalaliInput.setAttribute('data-jdp',''); jalaliInput.setAttribute('data-jdp-only-date',''); jalaliDatepicker.startWatch(); }
  jalaliInput?.addEventListener('input', syncDate); jalaliInput?.addEventListener('change', syncDate);
  form.addEventListener('submit', function(e){ syncAmount(); syncDate(); if((parseInt(amountHidden.value||'0',10)||0)>maxRemaining){ e.preventDefault(); alert('مبلغ پرداختی نمی‌تواند بیشتر از مانده فاکتور باشد.'); }});
  toggleMethod();
})();
</script>
@endsection