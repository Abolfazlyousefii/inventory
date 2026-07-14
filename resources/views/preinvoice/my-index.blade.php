@extends('layouts.app')

@section('title', 'فاکتورها و پیش‌فاکتورهای من')
@section('content_class', 'app-content-wide')

@section('content')
@php
  use Illuminate\Support\Str;
  use App\Services\MySalesDocumentsService;

  $toJalali = function ($date) {
      if (!$date) return '—';
      if (class_exists(\Morilog\Jalali\Jalalian::class)) return \Morilog\Jalali\Jalalian::fromDateTime($date)->format('Y/m/d H:i');
      return optional($date)->format('Y/m/d H:i') ?? '—';
  };
  $statusBadge = fn($summary) => match($summary['status_key']) {
      \App\Models\PreinvoiceOrder::STATUS_DRAFT => 'text-bg-secondary',
      \App\Models\PreinvoiceOrder::STATUS_RETURNED_TO_SALES,
      \App\Models\PreinvoiceOrder::STATUS_RESERVATION_EXPIRED,
      \App\Models\Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION => 'text-bg-danger',
      \App\Models\PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE,
      \App\Models\PreinvoiceOrder::STATUS_CANCELLED_BY_WAREHOUSE,
      \App\Models\Invoice::STATUS_NOT_SHIPPED => 'text-bg-dark',
      \App\Models\Invoice::STATUS_READY_TO_SHIP,
      \App\Models\Invoice::STATUS_SHIPPED => 'text-bg-success',
      default => 'text-bg-info',
  };
  $emptyText = match($activeTab) {
      MySalesDocumentsService::TAB_DRAFTS => 'پیش‌نویسی ذخیره نشده است.',
      MySalesDocumentsService::TAB_SHIPPED => 'هنوز فاکتور ارسال‌شده‌ای ندارید.',
      MySalesDocumentsService::TAB_NEEDS_CORRECTION => 'سندی برای بررسی و اصلاح به شما ارجاع نشده است.',
      default => 'در حال حاضر سند فعالی در جریان ندارید.',
  };
@endphp

<style>
  .my-sales-head,.my-sales-card{border:0;border-radius:18px;box-shadow:0 10px 28px rgba(15,23,42,.06)}
  .my-sales-head{background:linear-gradient(135deg,#fff,#f8fafc);padding:18px}.sales-tabs{display:flex;gap:8px;overflow-x:auto;padding-bottom:4px}.sales-tab{border:1px solid #e5e7eb;border-radius:999px;padding:.55rem .9rem;background:#fff;text-decoration:none;color:#334155;white-space:nowrap;font-weight:700}.sales-tab.active{background:#0d6efd;color:#fff;border-color:#0d6efd}.sales-tab .count{margin-inline-start:4px}.sales-tab.needs-attention .count{background:#fff3cd;color:#b45309;border-radius:999px;padding:.05rem .45rem}.sales-tab .dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#ef4444;margin-inline-end:5px}
  .document-card{border:1px solid #e5e7eb;border-radius:16px;background:#fff;overflow:hidden}.document-card.needs-action{border-color:#fdba74;box-shadow:0 0 0 3px rgba(251,146,60,.08)}.document-card.is-open{border-color:#bfdbfe}.document-summary{width:100%;border:0;background:#fff;padding:12px 14px;cursor:pointer;text-align:inherit}.document-summary:hover{background:#f8fafc}.document-summary-grid{display:grid;grid-template-columns:1.1fr 1.45fr 1fr 1.1fr .7fr 1.15fr auto;gap:12px;align-items:center}.summary-cell{min-width:0}.summary-label{display:block;font-size:.72rem;color:#64748b;margin-bottom:3px}.summary-value{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.document-code{direction:ltr;unicode-bidi:plaintext;display:inline-block;font-weight:800}.document-type{font-size:.74rem;color:#64748b;margin-top:2px}.summary-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}.header-badges{display:flex;gap:5px;flex-wrap:wrap;margin-top:6px}.action-message{font-size:.8rem;color:#9a3412;margin-top:5px}.document-details{display:none;border-top:1px solid #e5e7eb;background:#fbfdff;padding:14px}.document-card.is-open .document-details{display:block}.document-detail-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:12px}.meta-box{background:#fff;border:1px solid #eef2f7;border-radius:10px;padding:8px 10px}.meta-box .label{font-size:.72rem;color:#64748b}.meta-box .value{font-weight:700;font-size:.9rem;word-break:break-word}.detail-actions{display:flex;gap:8px;flex-wrap:wrap}.detail-section-title{font-size:.78rem;font-weight:800;color:#475569;margin-bottom:7px}
  @media(max-width:991px){.document-summary-grid{grid-template-columns:1fr 1.2fr .9fr auto}.summary-amount,.summary-items{display:none}.document-detail-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
  @media(max-width:575px){.sales-tabs{gap:6px}.sales-tab{padding:.48rem .75rem}.document-summary-grid{display:block}.summary-cell{margin-bottom:7px}.summary-actions{justify-content:stretch;margin-top:10px}.summary-actions a,.summary-actions button{flex:1 1 0}.document-detail-grid{grid-template-columns:1fr}.document-card{border-radius:14px}}
</style>

<div class="py-2">
  <div class="my-sales-head mb-3 d-flex justify-content-between align-items-start gap-3 flex-wrap">
    <div><h4 class="fw-bold mb-1">فاکتورها و پیش‌فاکتورهای من</h4><div class="text-muted small">اسناد نیازمند اصلاح، پیش‌نویس‌ها و فاکتورهای جاری شما</div></div>
    <a href="{{ route('preinvoice.create') }}" class="btn btn-primary">➕ ثبت پیش‌فاکتور جدید</a>
  </div>

  <div class="sales-tabs mb-3" role="tablist">
    @foreach($tabs as $tabKey => $tab)
      @php
        $isNeeds = $tabKey === MySalesDocumentsService::TAB_NEEDS_CORRECTION;
        $tabCount = $tabCounts[$tabKey] ?? 0;
      @endphp
      <a class="sales-tab {{ $activeTab === $tabKey ? 'active' : '' }} {{ $isNeeds && $tabCount > 0 ? 'needs-attention' : '' }}" href="{{ route('preinvoice.my.index', array_merge(request()->except('page', 'status'), ['tab' => $tabKey])) }}">
        @if($isNeeds && $tabCount > 0)
          <span class="dot"></span>
        @endif
        {{ $tab['label'] }} <span class="count">({{ number_format($tabCount) }})</span>
      </a>
    @endforeach
  </div>

  <div class="card my-sales-card mb-3"><div class="card-body">
    <form class="row g-2 align-items-end" method="GET" action="{{ route('preinvoice.my.index') }}">
      <input type="hidden" name="tab" value="{{ $activeTab }}">
      <div class="col-md-3 col-xl-2"><label class="form-label fw-bold text-muted small">شماره سند</label><input name="q" class="form-control" value="{{ $filters['q'] ?? '' }}" placeholder="پیش‌فاکتور یا فاکتور"></div>
      <div class="col-md-3 col-xl-2"><label class="form-label fw-bold text-muted small">نام مشتری</label><input name="customer" class="form-control" value="{{ $filters['customer'] ?? '' }}" placeholder="نام مشتری"></div>
      <div class="col-md-3 col-xl-2">
        <label class="form-label fw-bold text-muted small">وضعیت</label>
        <select name="status" class="form-select">
          <option value="">همه وضعیت‌های این تب</option>
          @foreach($statusLabels as $key => $label)
            <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-md-3 col-xl-2"><label class="form-label fw-bold text-muted small">نوع سند</label><select name="type" class="form-select"><option value="">همه</option><option value="preinvoice" @selected(($filters['type'] ?? '') === 'preinvoice')>پیش‌فاکتور</option><option value="invoice" @selected(($filters['type'] ?? '') === 'invoice')>فاکتور</option></select></div>
      <div class="col-md-3 col-xl-2"><label class="form-label fw-bold text-muted small">از تاریخ</label><input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}"></div>
      <div class="col-md-3 col-xl-2"><label class="form-label fw-bold text-muted small">تا تاریخ</label><input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}"></div>
      <div class="col-md-3 col-xl-2"><label class="form-check mt-4"><input class="form-check-input" type="checkbox" name="changed_only" value="1" @checked($filters['changed_only'] ?? false)> فقط تغییرکرده‌ها</label></div>
      <div class="col-md-auto d-flex gap-2"><button class="btn btn-primary">اعمال فیلتر</button><a href="{{ route('preinvoice.my.index', ['tab' => $activeTab]) }}" class="btn btn-outline-secondary">حذف فیلتر</a></div>
    </form>
  </div></div>

  <div class="row g-2" data-sales-documents>
    @forelse($orders as $order)
      @php
        $summary = $order->current_document;
        $isNeedsAction = $summary['bucket'] === MySalesDocumentsService::BUCKET_NEEDS_CORRECTION;
        $isDraft = $summary['bucket'] === MySalesDocumentsService::BUCKET_DRAFT;
        $documentDomId = 'sales-document-details-' . preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $summary['document_number']);
      @endphp
      <div class="col-12">
        <article class="document-card {{ $isNeedsAction ? 'needs-action' : '' }}" data-document-card>
          <div class="document-summary" role="button" tabindex="0" data-document-toggle aria-expanded="false" aria-controls="{{ $documentDomId }}">
            <span class="document-summary-grid">
              <span class="summary-cell">
                <span class="summary-value document-code">{{ Str::limit($summary['document_number'], 18, '…') }}</span>
                <span class="document-type">
                  {{ $summary['has_invoice'] ? 'فاکتور' : 'پیش‌فاکتور' }}
                  @if($summary['has_invoice'])
                    <br><small>پیش‌فاکتور: {{ $summary['preinvoice_uuid'] }}</small>
                  @endif
                </span>
              </span>
              <span class="summary-cell"><span class="summary-label">مشتری</span><span class="summary-value">{{ $summary['customer_name'] ?: '—' }}</span></span>
              <span class="summary-cell"><span class="summary-label">وضعیت</span><span class="badge {{ $statusBadge($summary) }}">{{ $summary['status_label'] }}</span></span>
              <span class="summary-cell summary-amount"><span class="summary-label">مبلغ فعلی</span><span class="summary-value">{{ \App\Support\Currency::formatRial($summary['total_amount']) }}</span></span>
              <span class="summary-cell summary-items"><span class="summary-label">اقلام</span><span class="summary-value">{{ number_format($summary['items_count']) }} قلم</span></span>
              <span class="summary-cell"><span class="summary-label">آخرین تغییر</span><span class="summary-value">{{ $toJalali($summary['last_changed_at']) }}</span></span>
              <span class="summary-actions" data-document-actions>
                <a href="{{ $summary['view_url'] }}" class="btn btn-sm btn-outline-primary" data-document-action>{{ $summary['has_invoice'] ? 'مشاهده فاکتور' : 'مشاهده سند' }}</a>
                @if($summary['edit_url'] && ($isNeedsAction || $isDraft))
                  <a href="{{ $summary['edit_url'] }}" class="btn btn-sm btn-warning" data-document-action>
                    {{ $summary['status_key'] === \App\Models\PreinvoiceOrder::STATUS_RESERVATION_EXPIRED ? 'ادامه ویرایش' : ($isDraft ? 'ادامه ویرایش' : 'اصلاح و ارسال مجدد') }}
                  </a>
                @endif
                <span class="btn btn-sm btn-outline-secondary" role="button"><span class="document-toggle-text">جزئیات</span> ▼</span>
              </span>
            </span>
            <span class="header-badges">
              @if($isNeedsAction)
                <span class="badge text-bg-danger">{{ $summary['needs_action_label'] }}</span>
                <span class="badge text-bg-warning text-dark">{{ $summary['needs_action_reason'] }}</span>
              @endif
              @if($isDraft)
                <span class="badge text-bg-secondary">پیش‌نویس</span>
                @if($order->is_auto_draft)
                  <span class="badge text-bg-info">ذخیره خودکار</span>
                @else
                  <span class="badge text-bg-light border text-dark">در انتظار تکمیل</span>
                @endif
              @endif
              @if(in_array($summary['status_key'], [\App\Models\PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE, \App\Models\PreinvoiceOrder::STATUS_CANCELLED_BY_WAREHOUSE, \App\Models\Invoice::STATUS_NOT_SHIPPED], true))
                <span class="badge text-bg-dark">لغوشده</span>
              @endif
            </span>
            @if($isNeedsAction)
              <div class="action-message">{{ $summary['needs_action_message'] }}</div>
            @endif
          </div>

          <div class="document-details" id="{{ $documentDomId }}" data-document-details hidden>
            <div class="document-detail-grid">
              <div class="meta-box"><div class="label">مشتری</div><div class="value">{{ $summary['customer_name'] ?: '—' }}</div></div>
              <div class="meta-box"><div class="label">موبایل</div><div class="value">{{ $summary['customer_mobile'] ?: '—' }}</div></div>
              <div class="meta-box"><div class="label">مبلغ فعلی</div><div class="value">{{ \App\Support\Currency::formatRial($summary['total_amount']) }}</div></div>
              <div class="meta-box"><div class="label">تعداد اقلام</div><div class="value">{{ number_format($summary['items_count']) }} قلم</div></div>
              <div class="meta-box"><div class="label">پرداخت‌شده</div><div class="value">{{ is_null($summary['paid_amount']) ? '—' : \App\Support\Currency::formatRial($summary['paid_amount']) }}</div></div>
              <div class="meta-box"><div class="label">مانده</div><div class="value">{{ is_null($summary['remaining_amount']) ? '—' : \App\Support\Currency::formatRial($summary['remaining_amount']) }}</div></div>
              <div class="meta-box"><div class="label">شماره پیش‌فاکتور اولیه</div><div class="value document-code">{{ $summary['preinvoice_uuid'] ?: '—' }}</div></div>
              <div class="meta-box"><div class="label">اقدام بعدی</div><div class="value">{{ $summary['next_action_label'] }}</div></div>
            </div>
            @if($isNeedsAction)
              <div class="mb-3">
                <div class="detail-section-title">اطلاعات ارجاع</div>
                <div class="document-detail-grid">
                  <div class="meta-box"><div class="label">ارجاع‌دهنده</div><div class="value">{{ $summary['return_by'] ?: '—' }}</div></div>
                  <div class="meta-box"><div class="label">تاریخ ارجاع</div><div class="value">{{ $toJalali($summary['return_at']) }}</div></div>
                  <div class="meta-box"><div class="label">واحد ارجاع‌دهنده</div><div class="value">{{ $summary['return_unit'] ?: '—' }}</div></div>
                  <div class="meta-box"><div class="label">علت ارجاع</div><div class="value">{{ $summary['return_reason'] ?: 'علت ارجاع ثبت نشده است.' }}</div></div>
                  <div class="meta-box"><div class="label">توضیحات ارجاع</div><div class="value">{{ $summary['return_note'] ?: '—' }}</div></div>
                </div>
              </div>
            @endif
            @if($order->is_auto_draft)
              <div class="small text-muted mb-2">آخرین ذخیره خودکار: {{ $toJalali($order->auto_saved_at) }}</div>
            @endif
            <div class="detail-actions">
              <a href="{{ $summary['view_url'] }}" class="btn btn-sm btn-outline-primary">{{ $summary['has_invoice'] ? 'مشاهده فاکتور' : 'مشاهده سند' }}</a>
              @if($activeTab === MySalesDocumentsService::TAB_ACTIVE || $activeTab === MySalesDocumentsService::TAB_SHIPPED)
                <a href="{{ $summary['print_url'] }}" target="_blank" class="btn btn-sm btn-outline-dark">چاپ</a>
              @endif
              @if($summary['edit_url'] && ($isNeedsAction || $isDraft))
                <a href="{{ $summary['edit_url'] }}" class="btn btn-sm btn-warning">{{ $isDraft ? 'ادامه ویرایش' : 'اصلاح سند' }}</a>
              @endif
              <button type="button" class="btn btn-sm btn-outline-secondary" data-document-toggle aria-expanded="true" aria-controls="{{ $documentDomId }}">بستن جزئیات ▲</button>
            </div>
          </div>
        </div>
      </article></div>
    @empty
      <div class="col-12"><div class="document-card text-center text-muted p-3">{{ request()->except('tab', 'page') ? 'سندی مطابق فیلترهای انتخاب‌شده پیدا نشد.' : $emptyText }}</div></div>
    @endforelse
  </div>

  @if(method_exists($orders, 'links'))<div class="mt-3">{{ $orders->appends(['tab' => $activeTab])->links() }}</div>@endif
</div>

<script>
document.addEventListener('click', function (event) { if (event.target.closest('[data-document-action]')) return; const trigger = event.target.closest('[data-document-toggle]'); if (!trigger) return; const card = trigger.closest('[data-document-card]'); const details = card?.querySelector('[data-document-details]'); if (!card || !details) return; const open = !card.classList.contains('is-open'); document.querySelectorAll('[data-document-card].is-open').forEach(c => { if (c !== card) { c.classList.remove('is-open'); const d=c.querySelector('[data-document-details]'); if(d)d.hidden=true; }}); card.classList.toggle('is-open', open); details.hidden = !open; card.querySelectorAll('[data-document-toggle]').forEach(t => t.setAttribute('aria-expanded', open ? 'true' : 'false')); });
document.addEventListener('keydown', function (event) { const trigger = event.target.closest('[data-document-toggle]'); if (!trigger || (event.key !== 'Enter' && event.key !== ' ')) return; event.preventDefault(); trigger.click(); if (event.key === 'Escape') document.querySelectorAll('[data-document-card].is-open').forEach(c => c.classList.remove('is-open')); });
</script>
@endsection
