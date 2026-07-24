@extends('layouts.app')

@section('title', 'فاکتورهای فروش')
@section('content_class', 'app-content-wide')

@section('content')
@php
  use Morilog\Jalali\Jalalian;
  use Illuminate\Support\Str;
  $rial = fn($amount) => \App\Support\Currency::formatRial((int) $amount);
  $statusFa = fn($s) => ($statusLabels[$s] ?? ($s ?: '—'));
  $isLegacy = fn($s) => in_array((string) $s, $legacyStatuses ?? [], true);
  $statusBadge = fn($s) => match($s){
    'pending_collection','warehouse_received','collecting' => 'text-bg-primary',
    'pending_finance_reapproval','returned_to_sales_after_collection' => 'text-bg-warning text-dark',
    'ready_to_ship' => 'text-bg-info',
    'shipped' => 'text-bg-success',
    default => $isLegacy($s) ? 'text-bg-secondary' : 'text-bg-light text-dark',
  };
  $paymentMeta = function($paid, $total) {
    $paid=(int)$paid; $total=(int)$total;
    if ($paid <= 0) return ['پرداخت‌نشده','text-bg-danger'];
    if ($paid < $total) return ['پرداخت ناقص','text-bg-warning text-dark'];
    if ($paid > $total) return ['تسویه‌شده با هشدار پرداخت اضافه','text-bg-danger'];
    return ['تسویه‌شده','text-bg-success'];
  };
  $warningsFor = function($inv) use ($isLegacy) {
    $paid=(int)($inv->paid_total??0); $total=(int)$inv->total; $snapshot=(int)($inv->snapshot_items_total??$total); $warnings=[];
    if ((int)($inv->zero_price_items_count??0)>0) $warnings[]=['قیمت صفر','text-bg-danger'];
    if (abs($total-$snapshot)>1) $warnings[]=['مغایرت مبلغ','text-bg-warning text-dark'];
    if ($paid>$total) $warnings[]=['پرداخت اضافه','text-bg-danger'];
    if (blank($inv->uuid)) $warnings[]=['شماره نامعتبر','text-bg-danger'];
    if ($isLegacy($inv->status)) $warnings[]=['وضعیت قدیمی','text-bg-secondary'];
    if ((int)($inv->ledger_debit_count??0)>1) $warnings[]=['ledger مشکوک','text-bg-dark'];
    return $warnings;
  };
  $canManageInvoice = fn($inv) => auth()->user()?->hasAnyRole(['admin','Admin','Manager','manager','finance','Accountant']);
  $activeFilterCount = collect($filters ?? [])->reject(fn($v, $k) => in_array($k, ['quick_range'], true) ? blank($v) : blank($v))->count();
  $filtersOpen = $activeFilterCount > 0 || !empty($filterErrors);
@endphp

<style>
  .sales-wide-page{max-width:100%;overflow-x:hidden;font-size:.88rem}.sales-page-head,.sales-card{border:1px solid #e7eef8;border-radius:18px;box-shadow:0 10px 26px rgba(30,64,175,.06)}
  .sales-page-head{background:linear-gradient(135deg,#eff6ff,#fff);padding:18px}.sales-filter-card .form-label{font-size:.78rem;color:#475569;font-weight:800}.summary-title{font-weight:900;color:#1e3a8a;margin:.3rem 0 .6rem}.summary-card{border:1px solid #e5edf8;border-radius:16px;padding:13px;background:#fff;height:100%}.summary-card .label{font-size:.77rem;color:#64748b;font-weight:800}.summary-card .value{font-size:1rem;font-weight:950;margin-top:6px}.sales-table{table-layout:fixed;width:100%}.sales-table th{font-size:.74rem;color:#64748b;white-space:nowrap}.sales-table td{font-size:.81rem;vertical-align:middle;padding:.55rem .45rem}.code-cell{direction:ltr;unicode-bidi:plaintext;display:inline-block;max-width:135px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.truncate-cell{display:inline-block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.money-cell{white-space:nowrap;font-variant-numeric:tabular-nums;text-align:left;direction:ltr}.invoice-mobile-card{border:1px solid #dbeafe;border-radius:16px;padding:14px;background:#fff;box-shadow:0 6px 18px rgba(30,64,175,.05)}.quick-ranges .btn{font-size:.76rem;padding:.25rem .55rem}.badge-wrap{display:flex;gap:.25rem;flex-wrap:wrap}@media print{.no-report-print,.modal,.pagination{display:none!important}.sales-card,.sales-page-head{box-shadow:none!important;border:1px solid #ddd!important}.d-lg-none{display:none!important}}
</style>

<div class="sales-wide-page">
  <div class="sales-page-head mb-3 d-flex justify-content-between align-items-start flex-wrap gap-3">
    <div><div class="h4 fw-black mb-1">فاکتورهای فروش</div><div class="text-muted small">مرکز پیگیری مالی، وضعیت عملیاتی و پرداخت فاکتورهای فروش</div></div>
    <div class="d-flex gap-2 flex-wrap align-items-center justify-content-end no-report-print">
      <a class="btn btn-outline-danger btn-sm" href="{{ route('invoices.cancelled') }}">بایگانی فاکتورهای لغوشده</a>
      <a class="btn btn-outline-secondary btn-sm" href="{{ route('vouchers.index', ['voucher_type' => 'sale']) }}">حواله‌های قدیمی فروش</a>
      @if($canRegisterPayments ?? false)<a class="btn btn-outline-primary btn-sm" href="{{ request()->fullUrlWithQuery(['export' => 'excel']) }}">خروجی Excel</a><a class="btn btn-outline-primary btn-sm" href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}">خروجی CSV</a>@endif
      <button type="button" class="btn btn-outline-dark btn-sm" onclick="window.print()">چاپ گزارش</button>
    </div>
  </div>

  @if(!empty($filterErrors))<div class="alert alert-danger no-report-print">@foreach($filterErrors as $error)<div>{{ $error }}</div>@endforeach</div>@endif

  <div class="card sales-card sales-filter-card mb-3 no-report-print">
    <div class="card-body py-3">
      <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <div>
          <div class="fw-black text-primary">فیلتر و جستجوی فاکتورها</div>
          <div class="small text-muted">برای مشاهده فیلدهای کامل، پنل فیلترها را باز کنید.</div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-outline-primary btn-sm position-relative" type="button" data-bs-toggle="collapse" data-bs-target="#invoiceFiltersPanel" aria-expanded="{{ $filtersOpen ? 'true' : 'false' }}" aria-controls="invoiceFiltersPanel">
            فیلترها
            @if($activeFilterCount > 0)<span class="position-absolute top-0 start-0 translate-middle badge rounded-pill text-bg-primary">{{ $activeFilterCount }}</span>@endif
          </button>
        </div>
      </div>
      <div class="collapse {{ $filtersOpen ? 'show' : '' }} mt-3" id="invoiceFiltersPanel">
        <form class="row g-3 align-items-end" method="GET" action="{{ route('invoices.index') }}">
          <div class="col-12 quick-ranges d-flex gap-2 flex-wrap">@foreach(['today'=>'امروز','yesterday'=>'دیروز','this_week'=>'این هفته','this_month'=>'این ماه','last_month'=>'ماه قبل'] as $key=>$label)<button class="btn btn-outline-primary" name="quick_range" value="{{ $key }}">{{ $label }}</button>@endforeach <span class="small text-muted align-self-center">ملاک تاریخ: created_at</span></div>
          <div class="col-sm-6 col-xl-2"><label class="form-label">شماره فاکتور</label><input class="form-control" name="invoice_number" value="{{ $filters['invoice_number'] ?? '' }}"></div>
          <div class="col-sm-6 col-xl-3"><label class="form-label">مشتری / موبایل / کد مشتری</label><input class="form-control" name="customer_name" value="{{ $filters['customer_name'] ?? '' }}" placeholder="نام مشتری"><div class="row g-1 mt-1"><div class="col"><input class="form-control form-control-sm" name="customer_mobile" value="{{ $filters['customer_mobile'] ?? '' }}" placeholder="موبایل"></div><div class="col"><input class="form-control form-control-sm" name="customer_code" value="{{ $filters['customer_code'] ?? '' }}" placeholder="کد"></div></div></div>
          <div class="col-sm-6 col-xl-2"><label class="form-label">فروشنده</label><input class="form-control" name="seller" value="{{ $filters['seller'] ?? '' }}"></div>
          <div class="col-sm-6 col-xl-2"><label class="form-label">وضعیت پرداخت</label><select class="form-select" name="payment_status"><option value="">همه</option><option value="paid" @selected(($filters['payment_status']??'')==='paid')>تسویه‌شده</option><option value="partial" @selected(($filters['payment_status']??'')==='partial')>پرداخت ناقص</option><option value="unpaid" @selected(($filters['payment_status']??'')==='unpaid')>پرداخت‌نشده</option><option value="overpaid" @selected(($filters['payment_status']??'')==='overpaid')>پرداخت اضافه</option></select></div>
          <div class="col-sm-6 col-xl-3"><label class="form-label">وضعیت عملیاتی</label><select class="form-select" name="status"><option value="">همه وضعیت‌ها</option><optgroup label="Workflow جدید">@foreach($newWorkflowStatuses as $key)<option value="{{ $key }}" @selected(($filters['status']??'')===$key)>{{ $statusFa($key) }}</option>@endforeach</optgroup><optgroup label="Legacy / قدیمی">@foreach($legacyStatuses as $key)<option value="{{ $key }}" @selected(($filters['status']??'')===$key)>{{ $statusFa($key) }}</option>@endforeach</optgroup></select></div>
          <div class="col-sm-6 col-xl-2"><label class="form-label">از تاریخ شمسی</label><input type="text" class="form-control" name="date_from" value="{{ $filters['date_from'] ?? '' }}" dir="ltr" data-jdp data-jdp-only-date></div><div class="col-sm-6 col-xl-2"><label class="form-label">تا تاریخ شمسی</label><input type="text" class="form-control" name="date_to" value="{{ $filters['date_to'] ?? '' }}" dir="ltr" data-jdp data-jdp-only-date></div>
          <div class="col-sm-6 col-xl-2"><label class="form-label">حداقل مبلغ</label><input class="form-control" name="min_amount" value="{{ $filters['min_amount'] ?? '' }}"></div><div class="col-sm-6 col-xl-2"><label class="form-label">حداکثر مبلغ</label><input class="form-control" name="max_amount" value="{{ $filters['max_amount'] ?? '' }}"></div><div class="col-sm-6 col-xl-2"><label class="form-label">روش ارسال</label><input class="form-control" name="shipping_method" value="{{ $filters['shipping_method'] ?? '' }}" placeholder="شناسه روش ارسال"></div>
          <div class="col-12 d-flex gap-3 flex-wrap"><label class="form-check"><input class="form-check-input" type="checkbox" name="only_remaining" value="1" @checked(($filters['only_remaining']??'')==='1')> فقط مانده‌دارها</label><label class="form-check"><input class="form-check-input" type="checkbox" name="has_cheque" value="1" @checked(($filters['has_cheque']??'')==='1')> فقط دارای چک</label><label class="form-check"><input class="form-check-input" type="checkbox" name="has_warnings" value="1" @checked(($filters['has_warnings']??'')==='1')> فقط دارای هشدار</label><label class="form-check"><input class="form-check-input" type="checkbox" name="overpaid_only" value="1" @checked(($filters['overpaid_only']??'')==='1')> فقط پرداخت بیشتر از فاکتور</label><label class="form-check"><input class="form-check-input" type="checkbox" name="legacy_only" value="1" @checked(($filters['legacy_only']??'')==='1')> فقط وضعیت‌های legacy</label></div>
          <div class="col-12 d-flex gap-2 justify-content-end flex-wrap"><a class="btn btn-outline-secondary" href="{{ route('invoices.index') }}">پاک کردن فیلترها</a><button class="btn btn-primary px-4">اعمال فیلتر</button></div>
        </form>
      </div>
    </div>
  </div>

  <div class="summary-title">گزارش مالی</div><div class="row g-3 mb-3">@foreach([['جمع فروش',$summary['total_sales']??0,'text-primary','rial'],['دریافت‌شده',$summary['paid_amount']??0,'text-success','rial'],['مانده قابل دریافت',$summary['remaining_amount']??0,'text-danger','rial'],['تعداد فاکتورها',$summary['invoice_count']??0,'text-dark','count']] as [$label,$value,$class,$type])<div class="col-6 col-lg-3"><div class="summary-card"><div class="label">{{ $label }}</div><div class="value {{ $class }}">{{ $type==='rial' ? $rial($value) : number_format($value).' فاکتور' }}</div></div></div>@endforeach</div>
  <div class="summary-title">وضعیت‌ها</div><div class="row g-3 mb-3">@foreach([['تسویه‌شده',$summary['paid_count']??0,'text-success'],['پرداخت ناقص',$summary['partial_count']??0,'text-warning'],['پرداخت‌نشده',$summary['unpaid_count']??0,'text-danger'],['پرداخت اضافه',$summary['overpaid_count']??0,'text-danger'],['آماده ارسال',$summary['ready_to_ship_count']??0,'text-info'],['ارسال‌شده',$summary['shipped_count']??0,'text-success'],['نیازمند تایید مجدد مالی',$summary['pending_finance_reapproval_count']??0,'text-warning']] as [$label,$value,$class])<div class="col-6 col-lg-3 col-xxl"><div class="summary-card"><div class="label">{{ $label }}</div><div class="value {{ $class }}">{{ number_format($value) }} فاکتور</div></div></div>@endforeach</div>

  <div class="card sales-card d-none d-lg-block"><div class="table-responsive invoice-table-wrap"><table class="table table-hover align-middle mb-0 sales-table"><colgroup><col style="width:13%"><col style="width:16%"><col style="width:10%"><col style="width:12%"><col style="width:11%"><col style="width:15%"><col style="width:12%"><col style="width:11%"></colgroup><thead class="table-light"><tr><th>فاکتور</th><th>مشتری</th><th>فروشنده</th><th>وضعیت عملیاتی</th><th>وضعیت پرداخت</th><th>مبلغ</th><th>هشدارها</th><th class="text-end">عملیات</th></tr></thead><tbody>
  @forelse($invoices as $inv)@php $paid=(int)($inv->paid_total??0);$remaining=max((int)$inv->total-$paid,0);$customerCode=$inv->customer?->crm_customer_id ?: $inv->customer_id;$customerName=$inv->customer_name ?: $inv->customer?->display_name ?: '—';[$payText,$payCls]=$paymentMeta($paid,$inv->total);$warnings=$warningsFor($inv); @endphp
    <tr><td><div class="fw-bold code-cell" title="{{ $inv->uuid }}">{{ $inv->uuid ?: '—' }}</div><div class="small text-muted">{{ $inv->created_at ? Jalalian::fromDateTime($inv->created_at)->format('Y/m/d') : '—' }}</div>@if($inv->preinvoiceOrder)<div class="small text-muted">پیش‌فاکتور: {{ $inv->preinvoiceOrder->uuid ?? $inv->preinvoice_order_id }}</div>@endif</td><td><span class="truncate-cell fw-bold" title="{{ $customerName }}">{{ $customerName }}</span><div class="small text-muted">{{ $inv->customer_mobile ?: $inv->customer?->mobile ?: '—' }}</div><div class="small text-muted">کد: {{ $customerCode ?: '—' }}</div></td><td><span class="truncate-cell">{{ $inv->preinvoiceOrder?->creator?->name ?? '—' }}</span></td><td><span class="badge {{ $statusBadge($inv->status) }}">{{ $statusFa($inv->status) }}</span>@if($isLegacy($inv->status))<span class="badge text-bg-secondary mt-1">legacy</span>@endif</td><td><span class="badge {{ $payCls }}">{{ $payText }}</span></td><td><div class="money-cell">کل: {{ $rial($inv->total) }}</div><div class="money-cell text-success">دریافت: {{ $rial($paid) }}</div><div class="money-cell {{ $remaining>0?'text-danger':'text-success' }}">مانده: {{ $rial($remaining) }}</div></td><td><div class="badge-wrap">@forelse($warnings as [$w,$c])<span class="badge {{ $c }}">{{ $w }}</span>@empty<span class="text-muted small">—</span>@endforelse</div></td><td><div class="dropdown"><button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">عملیات</button><ul class="dropdown-menu"><li><a class="dropdown-item" href="{{ route('invoices.show', $inv->uuid) }}">مشاهده فاکتور</a></li><li><a class="dropdown-item" href="{{ route('invoices.print', $inv->uuid) }}" target="_blank">چاپ فاکتور</a></li>@if($canManageInvoice($inv))<li><a class="dropdown-item" href="{{ route('invoices.edit', $inv->uuid) }}">ویرایش فاکتور</a></li>@endif @if(($canCancelInvoices ?? false) && ! $inv->isCancelled())<li><hr class="dropdown-divider"></li><li><button type="button" class="dropdown-item text-danger fw-bold" data-bs-toggle="modal" data-bs-target="#cancelInvoiceModal{{ $inv->id }}">حذف / لغو فاکتور</button></li>@endif</ul></div></td></tr>
    @if(($canCancelInvoices ?? false) && ! $inv->isCancelled())
    <div class="modal fade" id="cancelInvoiceModal{{ $inv->id }}" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('invoices.cancel', $inv->uuid) }}">@csrf<div class="modal-header bg-danger text-white"><h5 class="modal-title">لغو فاکتور {{ $inv->uuid }}</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="alert alert-warning small mb-3">با لغو این فاکتور:<br>- موجودی اقلام به انبار مرکزی بازمی‌گردد.<br>- بدهکاری این فاکتور از گردش حساب مشتری حذف می‌شود.<br>- پرداخت‌ها و چک‌های ثبت‌شده مشتری حذف نمی‌شوند.<br>- فاکتور به بایگانی فاکتورهای لغوشده منتقل می‌شود.</div>@if($inv->status === \App\Models\Invoice::STATUS_SHIPPED)<div class="alert alert-danger small">این فاکتور قبلاً ارسال شده است. تنها در صورتی ادامه دهید که بازگشت فیزیکی کالا به انبار تأیید شده باشد.</div><label class="form-check mb-3"><input class="form-check-input cancel-physical" type="checkbox" name="physical_return_confirmed" value="1" data-confirm-for="{{ $inv->id }}"> بازگشت فیزیکی کالا به انبار را تأیید می‌کنم.</label>@endif<div class="mb-3"><label class="form-label">علت لغو <span class="text-danger">*</span></label><textarea class="form-control" name="cancellation_reason" required rows="2"></textarea></div><div class="mb-3"><label class="form-label">تأیید شماره فاکتور <span class="text-danger">*</span></label><input class="form-control cancel-confirm-input" name="confirm_invoice_uuid" required autocomplete="off" data-expected="{{ $inv->uuid }}" data-button="cancelSubmit{{ $inv->id }}" data-physical="{{ $inv->status === \App\Models\Invoice::STATUS_SHIPPED ? '1' : '0' }}" data-invoice="{{ $inv->id }}" placeholder="{{ $inv->uuid }}"></div><div class="mb-0"><label class="form-label">توضیحات تکمیلی</label><textarea class="form-control" name="cancellation_note" rows="2"></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">انصراف</button><button id="cancelSubmit{{ $inv->id }}" class="btn btn-danger" disabled>لغو قطعی فاکتور</button></div></form></div></div>
    @endif
  @empty<tr><td colspan="8" class="text-center text-muted py-4">هیچ فاکتوری با فیلترهای انتخاب‌شده یافت نشد.</td></tr>@endforelse
  </tbody><tfoot class="table-light"><tr><th colspan="5">جمع همین صفحه ({{ number_format($pageTotals['count'] ?? 0) }} فاکتور)</th><th class="money-cell">{{ $rial($pageTotals['total'] ?? 0) }} / {{ $rial($pageTotals['paid'] ?? 0) }} / {{ $rial($pageTotals['remaining'] ?? 0) }}</th><th colspan="2"></th></tr></tfoot></table></div></div>

  <div class="d-lg-none vstack gap-2">@forelse($invoices as $inv)@php $paid=(int)($inv->paid_total??0);$remaining=max((int)$inv->total-$paid,0);$customerName=$inv->customer_name ?: $inv->customer?->display_name ?: '—';[$payText,$payCls]=$paymentMeta($paid,$inv->total);$warnings=$warningsFor($inv); @endphp<div class="invoice-mobile-card"><div class="d-flex justify-content-between gap-2 mb-2"><div><div class="fw-bold">{{ $inv->uuid ?: '—' }}</div><div class="small text-muted">{{ $inv->created_at ? Jalalian::fromDateTime($inv->created_at)->format('Y/m/d') : '—' }}</div></div><span class="badge {{ $statusBadge($inv->status) }} align-self-start">{{ $statusFa($inv->status) }}</span></div><div class="fw-bold">{{ $customerName }}</div><div class="small text-muted">{{ $inv->customer_mobile ?: $inv->customer?->mobile ?: '—' }} | فروشنده: {{ $inv->preinvoiceOrder?->creator?->name ?? '—' }}</div><div class="my-2"><span class="badge {{ $payCls }}">{{ $payText }}</span></div><div class="small d-flex justify-content-between"><span>مبلغ</span><strong>{{ $rial($inv->total) }}</strong></div><div class="small d-flex justify-content-between"><span>پرداخت‌شده</span><strong>{{ $rial($paid) }}</strong></div><div class="small d-flex justify-content-between"><span>مانده</span><strong class="{{ $remaining>0?'text-danger':'text-success' }}">{{ $rial($remaining) }}</strong></div><div class="badge-wrap mt-2">@foreach($warnings as [$w,$c])<span class="badge {{ $c }}">{{ $w }}</span>@endforeach</div><div class="d-flex flex-wrap gap-2 mt-3"><a class="btn btn-sm btn-outline-primary" href="{{ route('invoices.show', $inv->uuid) }}">مشاهده فاکتور</a><a class="btn btn-sm btn-outline-dark" href="{{ route('invoices.print', $inv->uuid) }}" target="_blank">چاپ فاکتور</a>@if($canManageInvoice($inv))<a class="btn btn-sm btn-primary" href="{{ route('invoices.edit', $inv->uuid) }}">ویرایش فاکتور</a>@endif</div></div>@empty<div class="invoice-mobile-card text-center text-muted">هیچ فاکتوری یافت نشد.</div>@endforelse</div>
  <div class="mt-3 no-report-print">{{ $invoices->links() }}</div>
</div>

<script>
document.addEventListener('input', function(e){ if(e.target.classList.contains('cancel-confirm-input')) toggleCancelInvoiceButton(e.target); });
document.addEventListener('change', function(e){ if(e.target.classList.contains('cancel-physical')) document.querySelectorAll('.cancel-confirm-input[data-invoice="'+e.target.dataset.confirmFor+'"]').forEach(toggleCancelInvoiceButton); });
function toggleCancelInvoiceButton(input){ var btn=document.getElementById(input.dataset.button); if(!btn) return; var ok=input.value===input.dataset.expected; if(input.dataset.physical==='1'){ var cb=document.querySelector('.cancel-physical[data-confirm-for="'+input.dataset.invoice+'"]'); ok=ok && cb && cb.checked; } btn.disabled=!ok; }
</script>

@endsection
