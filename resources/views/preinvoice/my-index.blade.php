@extends('layouts.app')

@section('title', 'فاکتورها و پیش‌فاکتورهای من')
@section('content_class', 'app-content-wide')

@section('content')
@php
  use Illuminate\Support\Str;

  $toJalali = function ($date) {
      if (!$date) return '—';
      if (class_exists(\Morilog\Jalali\Jalalian::class)) {
          return \Morilog\Jalali\Jalalian::fromDateTime($date)->format('Y/m/d H:i');
      }
      return optional($date)->format('Y/m/d H:i') ?? '—';
  };

  $statusBadge = fn($summary) => match($summary['status_key']) {
      \App\Models\PreinvoiceOrder::STATUS_DRAFT => 'text-bg-secondary',
      \App\Models\PreinvoiceOrder::STATUS_PENDING_FINANCE,
      \App\Models\Invoice::STATUS_PENDING_FINANCE_REAPPROVAL => 'text-bg-warning',
      \App\Models\PreinvoiceOrder::STATUS_RETURNED_TO_SALES,
      \App\Models\Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION => 'text-bg-danger',
      \App\Models\PreinvoiceOrder::STATUS_RESERVATION_EXPIRED,
      \App\Models\PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE => 'text-bg-danger',
      \App\Models\Invoice::STATUS_READY_TO_SHIP,
      \App\Models\Invoice::STATUS_SHIPPED => 'text-bg-success',
      default => $summary['has_invoice'] ? 'text-bg-info' : 'text-bg-light border text-dark',
  };

  $timelineSteps = [
      'finance' => 'مالی',
      'collection' => 'جمع‌آوری',
      'reapproval' => 'تایید مجدد',
      'shipping' => 'ارسال',
  ];

  $activeTimeline = function ($summary) {
      return match($summary['status_key']) {
          \App\Models\Invoice::STATUS_PENDING_COLLECTION => ['finance'],
          \App\Models\Invoice::STATUS_WAREHOUSE_RECEIVED,
          \App\Models\Invoice::STATUS_COLLECTING => ['finance', 'collection'],
          \App\Models\Invoice::STATUS_PENDING_FINANCE_REAPPROVAL => ['finance', 'collection', 'reapproval'],
          \App\Models\Invoice::STATUS_READY_TO_SHIP => ['finance', 'collection', 'reapproval', 'shipping'],
          \App\Models\Invoice::STATUS_SHIPPED => ['finance', 'collection', 'reapproval', 'shipping'],
          default => [],
      };
  };
@endphp

<style>
  .my-sales-head,.my-sales-card{border:0;border-radius:18px;box-shadow:0 10px 28px rgba(15,23,42,.06)}
  .my-sales-head{background:linear-gradient(135deg,#fff,#f8fafc);padding:18px}
  .document-card{border:1px solid #e5e7eb;border-radius:16px;background:#fff;box-shadow:none;overflow:hidden}
  .document-card.is-open{border-color:#bfdbfe}
  .document-summary{width:100%;border:0;background:#fff;padding:12px 14px;cursor:pointer;text-align:inherit;transition:background-color .18s ease}
  .document-summary:hover,.document-summary:focus-visible{background:#f8fafc}
  .document-summary-grid{display:grid;grid-template-columns:1.1fr 1.45fr 1fr 1.1fr .7fr 1.15fr auto;gap:12px;align-items:center}
  .summary-cell{min-width:0}.summary-label{display:block;font-size:.72rem;color:#64748b;margin-bottom:3px}.summary-value{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .document-code{direction:ltr;unicode-bidi:plaintext;display:inline-block;font-weight:800}.document-type{font-size:.74rem;color:#64748b;margin-top:2px}
  .summary-actions{display:flex;gap:8px;align-items:center;justify-content:flex-end;flex-wrap:wrap}.summary-actions a,.summary-actions button{white-space:nowrap}
  .document-toggle-icon{display:inline-block;margin-inline-start:4px}.document-toggle-text{min-width:86px;display:inline-block}
  .header-badges{display:flex;gap:5px;flex-wrap:wrap;margin-top:6px}.header-badges .badge{font-weight:600}
  .document-details{display:none;border-top:1px solid #e5e7eb;background:#fbfdff;padding:14px;animation:documentDetailsIn .18s ease}
  .document-card.is-open .document-details{display:block}@keyframes documentDetailsIn{from{opacity:.35;transform:translateY(-3px)}to{opacity:1;transform:none}}
  .document-detail-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:12px}
  .meta-box{background:#fff;border:1px solid #eef2f7;border-radius:10px;padding:8px 10px;min-height:auto}.meta-box .label{font-size:.72rem;color:#64748b;margin-bottom:2px}.meta-box .value{font-weight:700;font-size:.9rem;word-break:break-word}
  .timeline{display:flex;gap:6px;flex-wrap:wrap}.timeline .step{border:1px solid #e5e7eb;border-radius:999px;padding:.16rem .5rem;font-size:.72rem;color:#64748b;background:#fff}.timeline .step.active{background:#e0f2fe;border-color:#38bdf8;color:#075985;font-weight:700}
  .detail-section-title{font-size:.78rem;font-weight:800;color:#475569;margin-bottom:7px}.detail-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.detail-badges{display:flex;gap:6px;flex-wrap:wrap}
  @media(max-width:1199px){.document-summary-grid{grid-template-columns:1fr 1.3fr .9fr 1fr .65fr auto}.summary-updated{display:none}.document-detail-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
  @media(max-width:991px){.document-summary-grid{grid-template-columns:1fr 1.2fr .9fr auto}.summary-amount,.summary-items{display:none}.document-detail-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
  @media(max-width:575px){.document-summary{padding:12px}.document-summary-grid{display:block}.summary-cell{margin-bottom:7px}.summary-mobile-top{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.summary-value{white-space:normal}.summary-status{margin-bottom:8px}.summary-actions{justify-content:stretch;margin-top:10px}.summary-actions a,.summary-actions button{flex:1 1 0}.document-detail-grid{grid-template-columns:1fr}.document-details{padding:12px}.document-card{border-radius:14px}}
</style>

<div class="py-2">
  <div class="my-sales-head mb-3 d-flex justify-content-between align-items-start gap-3 flex-wrap">
    <div>
      <h4 class="fw-bold mb-1">فاکتورها و پیش‌فاکتورهای من</h4>
      <div class="text-muted small">مشاهده آخرین وضعیت تمام اسناد فروش ثبت‌شده</div>
    </div>
    <a href="{{ route('preinvoice.create') }}" class="btn btn-primary">➕ ثبت پیش‌فاکتور جدید</a>
  </div>

  <div class="card my-sales-card mb-3"><div class="card-body">
    <form class="row g-2 align-items-end" method="GET" action="{{ route('preinvoice.my.index') }}">
      <div class="col-md-3 col-xl-2"><label class="form-label fw-bold text-muted small">شماره سند</label><input name="q" class="form-control" value="{{ $filters['q'] ?? '' }}" placeholder="پیش‌فاکتور یا فاکتور"></div>
      <div class="col-md-3 col-xl-2"><label class="form-label fw-bold text-muted small">نام مشتری</label><input name="customer" class="form-control" value="{{ $filters['customer'] ?? '' }}" placeholder="نام مشتری"></div>
      <div class="col-md-3 col-xl-2">
        <label class="form-label fw-bold text-muted small">وضعیت</label>
        <select name="status" class="form-select">
          <option value="">همه وضعیت‌ها</option>
          @foreach($statusLabels as $key => $label)
            <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-md-3 col-xl-2"><label class="form-label fw-bold text-muted small">نوع سند</label><select name="type" class="form-select"><option value="">همه</option><option value="preinvoice" @selected(($filters['type'] ?? '') === 'preinvoice')>پیش‌فاکتور</option><option value="invoice" @selected(($filters['type'] ?? '') === 'invoice')>فاکتور</option></select></div>
      <div class="col-md-3 col-xl-2"><label class="form-label fw-bold text-muted small">از تاریخ</label><input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}"></div>
      <div class="col-md-3 col-xl-2"><label class="form-label fw-bold text-muted small">تا تاریخ</label><input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}"></div>
      <div class="col-md-3 col-xl-2"><label class="form-check mt-4"><input class="form-check-input" type="checkbox" name="changed_only" value="1" @checked($filters['changed_only'] ?? false)> فقط تغییرکرده‌ها</label></div>
      <div class="col-md-auto d-flex gap-2"><button class="btn btn-primary">اعمال فیلتر</button><a href="{{ route('preinvoice.my.index') }}" class="btn btn-outline-secondary">حذف فیلتر</a></div>
    </form>
  </div></div>

  <div class="row g-2" data-sales-documents>
    @forelse($orders as $order)
      @php
        $summary = $order->current_document;
        $activeSteps = $activeTimeline($summary);
        $documentDomId = 'sales-document-details-' . preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $summary['document_number']);
        $changeBadges = [];
        if ($summary['has_total_changed']) {
            $changeBadges[] = ['class' => 'text-bg-warning', 'text' => 'مبلغ تغییر کرده', 'detail' => 'مبلغ تغییر کرده (' . number_format($summary['total_difference']) . ' ریال)'];
        }
        if ($summary['has_items_changed']) {
            $changeBadges[] = ['class' => 'text-bg-warning', 'text' => 'اقلام اصلاح شده', 'detail' => 'اقلام اصلاح شده'];
            $changeBadges[] = ['class' => 'text-bg-light border text-dark', 'text' => 'اصلاح انبار', 'detail' => 'اقلام فاکتور توسط انبار اصلاح شده است.'];
        }
        if ($summary['status_key'] === \App\Models\Invoice::STATUS_PENDING_FINANCE_REAPPROVAL) {
            $changeBadges[] = ['class' => 'text-bg-danger', 'text' => 'نیازمند تأیید مجدد', 'detail' => 'در انتظار تایید مجدد مالی پس از اصلاح انبار'];
        } elseif ($summary['status_key'] === \App\Models\Invoice::STATUS_READY_TO_SHIP) {
            $changeBadges[] = ['class' => 'text-bg-success', 'text' => 'آماده ارسال', 'detail' => 'آماده ارسال بار'];
        } elseif ($summary['status_key'] === \App\Models\Invoice::STATUS_SHIPPED) {
            $changeBadges[] = ['class' => 'text-bg-success', 'text' => 'ارسال‌شده', 'detail' => 'ارسال‌شده'];
        } elseif ($summary['status_key'] === \App\Models\Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION) {
            $changeBadges[] = ['class' => 'text-bg-danger', 'text' => 'برگشت پس از جمع‌آوری', 'detail' => 'برگشت‌خورده پس از جمع‌آوری؛ در این نسخه فقط مشاهده و پیگیری فعال است.'];
        }
        $headerBadges = array_slice($changeBadges, 0, 2);
        $extraBadgesCount = max(count($changeBadges) - count($headerBadges), 0);
      @endphp
      <div class="col-12">
        <article class="document-card" data-document-card>
          <div class="document-summary" role="button" tabindex="0" data-document-toggle aria-expanded="false" aria-controls="{{ $documentDomId }}">
            <span class="document-summary-grid">
              <span class="summary-cell summary-mobile-top">
                <span><span class="summary-value document-code">{{ Str::limit($summary['document_number'], 18, '…') }}</span><span class="document-type">{{ $summary['has_invoice'] ? 'فاکتور' : 'پیش‌فاکتور' }}</span></span>
                <span class="summary-status d-sm-none"><span class="badge {{ $statusBadge($summary) }}">{{ $summary['status_label'] }}</span></span>
              </span>
              <span class="summary-cell"><span class="summary-label">مشتری</span><span class="summary-value">{{ $summary['customer_name'] ?: '—' }}</span></span>
              <span class="summary-cell summary-status d-none d-sm-block"><span class="summary-label">وضعیت</span><span class="badge {{ $statusBadge($summary) }}">{{ $summary['status_label'] }}</span></span>
              <span class="summary-cell summary-amount"><span class="summary-label">مبلغ فعلی</span><span class="summary-value">{{ \App\Support\Currency::formatRial($summary['total_amount']) }}</span></span>
              <span class="summary-cell summary-items"><span class="summary-label">اقلام</span><span class="summary-value">{{ number_format($summary['items_count']) }} قلم</span></span>
              <span class="summary-cell summary-updated"><span class="summary-label">آخرین تغییر</span><span class="summary-value">{{ $toJalali($summary['last_changed_at']) }}</span></span>
              <span class="summary-actions" data-document-actions>
                <a href="{{ $summary['view_url'] }}" class="btn btn-sm btn-outline-primary" data-document-action>{{ $summary['has_invoice'] ? 'مشاهده فاکتور' : 'مشاهده سند' }}</a>
                <span class="btn btn-sm btn-outline-secondary" role="button"><span class="document-toggle-text">نمایش جزئیات</span><span class="document-toggle-icon">▼</span></span>
              </span>
            </span>
            <span class="header-badges">
              @foreach($headerBadges as $badge)
                <span class="badge {{ $badge['class'] }}">{{ $badge['text'] }}</span>
              @endforeach
              @if($extraBadgesCount > 0)
                <span class="badge text-bg-light border text-dark">+ {{ $extraBadgesCount }} تغییر دیگر</span>
              @endif
            </span>
          </div>

          <div class="document-details" id="{{ $documentDomId }}" data-document-details hidden>
            <div class="document-detail-grid">
              <div class="meta-box"><div class="label">مشتری</div><div class="value">{{ $summary['customer_name'] ?: '—' }}</div></div>
              <div class="meta-box"><div class="label">موبایل</div><div class="value">{{ $summary['customer_mobile'] ?: '—' }}</div></div>
              <div class="meta-box"><div class="label">مبلغ اولیه پیش‌فاکتور</div><div class="value">{{ \App\Support\Currency::formatRial($summary['original_total_amount']) }}</div></div>
              <div class="meta-box"><div class="label">مبلغ فعلی</div><div class="value">{{ \App\Support\Currency::formatRial($summary['total_amount']) }}</div></div>
              <div class="meta-box"><div class="label">تعداد اقلام</div><div class="value">{{ number_format($summary['items_count']) }} قلم</div></div>
              <div class="meta-box"><div class="label">پرداخت‌شده</div><div class="value">{{ is_null($summary['paid_amount']) ? '—' : \App\Support\Currency::formatRial($summary['paid_amount']) }}</div></div>
              <div class="meta-box"><div class="label">مانده</div><div class="value">{{ is_null($summary['remaining_amount']) ? '—' : \App\Support\Currency::formatRial($summary['remaining_amount']) }}</div></div>
              <div class="meta-box"><div class="label">وضعیت پرداخت</div><div class="value">{{ $summary['payment_status'] ?? '—' }}</div></div>
              <div class="meta-box"><div class="label">وضعیت فعلی سند</div><div class="value">{{ $summary['status_label'] }}</div></div>
              <div class="meta-box"><div class="label">آخرین بروزرسانی</div><div class="value">{{ $toJalali($summary['last_changed_at']) }}</div></div>
              <div class="meta-box"><div class="label">شماره پیش‌فاکتور اولیه</div><div class="value document-code">{{ $summary['preinvoice_uuid'] ?: '—' }}</div></div>
              <div class="meta-box"><div class="label">اقدام بعدی</div><div class="value">{{ $summary['next_action_label'] }}</div></div>
            </div>

            @if(count($changeBadges))
              <div class="mb-3"><div class="detail-section-title">تغییرات و هشدارها</div><div class="detail-badges">
                @foreach($changeBadges as $badge)
                  <span class="badge {{ $badge['class'] }}">{{ $badge['detail'] }}</span>
                @endforeach
              </div></div>
            @endif

            @if($summary['has_invoice'])
              <div class="mb-3"><div class="detail-section-title">روند مراحل سند</div><div class="timeline">
                @foreach($timelineSteps as $key => $label)
                  <span class="step {{ in_array($key, $activeSteps, true) ? 'active' : '' }}">{{ $label }}</span>
                @endforeach
              </div></div>
            @endif

            <div class="detail-actions">
              <a href="{{ $summary['view_url'] }}" class="btn btn-sm btn-outline-primary">{{ $summary['has_invoice'] ? 'مشاهده فاکتور فقط‌خواندنی' : 'مشاهده سند' }}</a>
              <a href="{{ $summary['print_url'] }}" target="_blank" class="btn btn-sm btn-outline-dark">پرینت</a>
              @if($summary['edit_url'])
                <a href="{{ $summary['edit_url'] }}" class="btn btn-sm btn-outline-warning">{{ $summary['status_key'] === \App\Models\PreinvoiceOrder::STATUS_DRAFT ? 'ویرایش' : 'ویرایش و ارسال مجدد' }}</a>
                <a href="{{ $summary['edit_url'] }}" class="btn btn-sm btn-outline-success">{{ $summary['status_key'] === \App\Models\PreinvoiceOrder::STATUS_RESERVATION_EXPIRED ? 'ثبت مجدد' : 'ثبت نهایی' }}</a>
              @endif
              <button type="button" class="btn btn-sm btn-outline-secondary" data-document-toggle aria-expanded="true" aria-controls="{{ $documentDomId }}">بستن جزئیات ▲</button>
            </div>
          </div>
        </article>
      </div>
    @empty
      <div class="col-12"><div class="document-card text-center text-muted p-3">{{ request()->query() ? 'سندی مطابق فیلترهای انتخاب‌شده پیدا نشد.' : 'هنوز پیش‌فاکتور یا فاکتوری توسط شما ثبت نشده است.' }}</div></div>
    @endforelse
  </div>

  <script>
    document.addEventListener('click', function (event) {
      if (event.target.closest('[data-document-action]')) return;
      const trigger = event.target.closest('[data-document-toggle]');
      if (!trigger) return;
      const card = trigger.closest('[data-document-card]');
      if (!card) return;
      const details = card.querySelector('[data-document-details]');
      const summaryTrigger = card.querySelector('.document-summary[data-document-toggle]');
      if (!details || !summaryTrigger) return;
      const shouldOpen = summaryTrigger.getAttribute('aria-expanded') !== 'true';
      document.querySelectorAll('[data-document-card].is-open').forEach(function (openCard) {
        if (openCard !== card) setDocumentOpen(openCard, false);
      });
      setDocumentOpen(card, shouldOpen);
    });


    document.addEventListener('keydown', function (event) {
      const trigger = event.target.closest('[data-document-toggle]');
      if (!trigger) return;
      if (event.key !== 'Enter' && event.key !== ' ') return;
      event.preventDefault();
      trigger.click();
    });

    document.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') return;
      document.querySelectorAll('[data-document-card].is-open').forEach(function (card) { setDocumentOpen(card, false); });
    });

    function setDocumentOpen(card, isOpen) {
      const details = card.querySelector('[data-document-details]');
      const toggles = card.querySelectorAll('[data-document-toggle]');
      const text = card.querySelector('.document-toggle-text');
      const icon = card.querySelector('.document-toggle-icon');
      card.classList.toggle('is-open', isOpen);
      if (details) details.hidden = !isOpen;
      toggles.forEach(function (toggle) { toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false'); });
      if (text) text.textContent = isOpen ? 'بستن جزئیات' : 'نمایش جزئیات';
      if (icon) icon.textContent = isOpen ? '▲' : '▼';
    }
  </script>

  @if(method_exists($orders, 'links'))
    <div class="mt-3">{{ $orders->links() }}</div>
  @endif
</div>
@endsection
