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
  .document-card{border:1px solid #e5e7eb;border-radius:18px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.05);padding:16px;height:100%}
  .document-code{direction:ltr;unicode-bidi:plaintext;display:inline-block;font-weight:700}
  .meta-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
  .meta-box{background:#f8fafc;border:1px solid #eef2f7;border-radius:14px;padding:10px}
  .meta-box .label{font-size:.75rem;color:#64748b;margin-bottom:4px}.meta-box .value{font-weight:700}
  .timeline{display:flex;gap:8px;flex-wrap:wrap}.timeline .step{border:1px solid #e5e7eb;border-radius:999px;padding:.2rem .55rem;font-size:.76rem;color:#64748b;background:#fff}.timeline .step.active{background:#e0f2fe;border-color:#38bdf8;color:#075985;font-weight:700}
  @media(max-width:991px){.meta-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
  @media(max-width:575px){.meta-grid{grid-template-columns:1fr}.document-card{padding:13px}}
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

  <div class="row g-3">
    @forelse($orders as $order)
      @php
        $summary = $order->current_document;
        $activeSteps = $activeTimeline($summary);
      @endphp
      <div class="col-12">
        <div class="document-card">
          <div class="d-flex justify-content-between gap-3 flex-wrap mb-3">
            <div>
              <div class="text-muted small mb-1">{{ $summary['has_invoice'] ? 'فاکتور مشتری' : 'پیش‌فاکتور مشتری' }} «{{ $summary['customer_name'] ?: '—' }}»</div>
              <div class="d-flex gap-2 align-items-center flex-wrap">
                <span class="document-code">{{ Str::limit($summary['document_number'], 18, '…') }}</span>
                <span class="badge {{ $statusBadge($summary) }}">{{ $summary['status_label'] }}</span>
                @if($summary['has_invoice'])
                  <span class="badge text-bg-success">پیش‌فاکتور اولیه: {{ Str::limit($summary['preinvoice_uuid'], 18, '…') }}</span>
                @endif
              </div>
            </div>
            <div class="d-flex gap-2 flex-wrap align-items-start">
              <a href="{{ $summary['view_url'] }}" class="btn btn-sm btn-outline-primary">{{ $summary['has_invoice'] ? 'مشاهده فاکتور فقط‌خواندنی' : 'مشاهده' }}</a>
              @if($summary['edit_url'])
                <a href="{{ $summary['edit_url'] }}" class="btn btn-sm btn-outline-warning">{{ $summary['status_key'] === \App\Models\PreinvoiceOrder::STATUS_DRAFT ? 'ویرایش' : 'ویرایش و ارسال مجدد' }}</a>
                <a href="{{ $summary['edit_url'] }}" class="btn btn-sm btn-outline-success">{{ $summary['status_key'] === \App\Models\PreinvoiceOrder::STATUS_RESERVATION_EXPIRED ? 'ثبت مجدد' : 'ثبت نهایی' }}</a>
              @endif
              <a href="{{ $summary['print_url'] }}" target="_blank" class="btn btn-sm btn-outline-dark">پرینت</a>
            </div>
          </div>

          <div class="meta-grid mb-3">
            <div class="meta-box"><div class="label">مشتری</div><div class="value">{{ $summary['customer_name'] ?: '—' }}</div></div>
            <div class="meta-box"><div class="label">موبایل</div><div class="value">{{ $summary['customer_mobile'] ?: '—' }}</div></div>
            <div class="meta-box"><div class="label">مبلغ فعلی</div><div class="value">{{ \App\Support\Currency::formatRial($summary['total_amount']) }}</div></div>
            <div class="meta-box"><div class="label">اقلام فعلی</div><div class="value">{{ number_format($summary['items_count']) }} قلم</div></div>
            <div class="meta-box"><div class="label">وضعیت فعلی سند</div><div class="value">{{ $summary['status_label'] }}</div></div>
            <div class="meta-box"><div class="label">آخرین بروزرسانی</div><div class="value">{{ $toJalali($summary['last_changed_at']) }}</div></div>
            <div class="meta-box"><div class="label">اقدام بعدی</div><div class="value">{{ $summary['next_action_label'] }}</div></div>
            <div class="meta-box"><div class="label">پرداخت‌شده</div><div class="value">{{ is_null($summary['paid_amount']) ? '—' : \App\Support\Currency::formatRial($summary['paid_amount']) }}</div></div>
            <div class="meta-box"><div class="label">مانده</div><div class="value">{{ is_null($summary['remaining_amount']) ? '—' : \App\Support\Currency::formatRial($summary['remaining_amount']) }}</div></div>
            <div class="meta-box"><div class="label">وضعیت پرداخت</div><div class="value">{{ $summary['payment_status'] ?? '—' }}</div></div>
            <div class="meta-box"><div class="label">مبلغ اولیه پیش‌فاکتور</div><div class="value">{{ \App\Support\Currency::formatRial($summary['original_total_amount']) }}</div></div>
          </div>

          <div class="d-flex flex-wrap gap-2 mb-3">
            @if($summary['has_total_changed'])
              <span class="badge text-bg-warning">مبلغ تغییر کرده ({{ number_format($summary['total_difference']) }} ریال)</span>
            @endif
            @if($summary['has_items_changed'])
              <span class="badge text-bg-warning">اقلام اصلاح شده</span>
              <span class="badge text-bg-light border text-dark">اقلام فاکتور توسط انبار اصلاح شده است.</span>
            @endif
            @if($summary['status_key'] === \App\Models\Invoice::STATUS_PENDING_FINANCE_REAPPROVAL)
              <span class="badge text-bg-danger">در انتظار تایید مجدد مالی پس از اصلاح انبار</span>
            @elseif($summary['status_key'] === \App\Models\Invoice::STATUS_READY_TO_SHIP)
              <span class="badge text-bg-success">آماده ارسال بار</span>
            @elseif($summary['status_key'] === \App\Models\Invoice::STATUS_SHIPPED)
              <span class="badge text-bg-success">ارسال‌شده</span>
            @elseif($summary['status_key'] === \App\Models\Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION)
              <span class="badge text-bg-danger">برگشت‌خورده پس از جمع‌آوری؛ در این نسخه فقط مشاهده و پیگیری فعال است.</span>
            @endif
          </div>

          @if($summary['has_invoice'])
            <div class="timeline">
              @foreach($timelineSteps as $key => $label)
                <span class="step {{ in_array($key, $activeSteps, true) ? 'active' : '' }}">{{ $label }}</span>
              @endforeach
            </div>
          @endif
        </div>
      </div>
    @empty
      <div class="col-12"><div class="document-card text-center text-muted">{{ request()->query() ? 'سندی مطابق فیلترهای انتخاب‌شده پیدا نشد.' : 'هنوز پیش‌فاکتور یا فاکتوری توسط شما ثبت نشده است.' }}</div></div>
    @endforelse
  </div>

  @if(method_exists($orders, 'links'))
    <div class="mt-3">{{ $orders->links() }}</div>
  @endif
</div>
@endsection
