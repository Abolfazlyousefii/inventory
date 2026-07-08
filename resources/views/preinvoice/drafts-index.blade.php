@extends('layouts.app')

@php
  use Morilog\Jalali\Jalalian;
  use Illuminate\Support\Str;
@endphp

@section('content')
<style>
  .finance-queue-card { overflow: hidden; }
  .finance-queue-table-desktop { max-width: 100%; overflow-x: hidden; }
  .finance-queue-table { table-layout: fixed; width: 100%; }
  .finance-queue-table th,
  .finance-queue-table td { vertical-align: middle; }
  .finance-queue-table th { font-size: .78rem; white-space: nowrap; }
  .finance-queue-table td { font-size: .84rem; }
  .finance-order-main { min-width: 0; }
  .finance-order-main .order-code,
  .finance-order-main .customer-name { overflow-wrap: anywhere; }
  .finance-payment-note {
    white-space: normal;
    overflow-wrap: anywhere;
    font-size: .78rem;
    color: #475569;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .reservation-timer-pill { display: inline-flex; align-items: center; gap: .35rem; flex-wrap: wrap; }
  .reservation-countdown { direction: ltr; unicode-bidi: plaintext; font-variant-numeric: tabular-nums; }
  .finance-actions { display: flex; flex-wrap: wrap; gap: 6px; justify-content: flex-end; }
  .finance-actions .btn { white-space: nowrap; }
  .finance-queue-table tr.is-expired { opacity: .78; }
  .finance-queue-mobile { display: none; }
  .finance-mobile-card { border: 1px solid #e5e7eb; border-radius: .75rem; padding: .85rem; background: #fff; }
  .finance-mobile-meta { display: grid; gap: .65rem; }
  @media (max-width: 767.98px) {
    .container { max-width: 100%; overflow-x: hidden; }
    .finance-queue-table-desktop { display: none; }
    .finance-queue-mobile { display: grid; gap: .75rem; }
    .finance-actions { justify-content: stretch; }
    .finance-actions .btn,
    .finance-actions form { flex: 1 1 calc(50% - 6px); }
    .finance-actions form .btn { width: 100%; }
  }
</style>

<div class="container py-4">
  <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
      <h4 class="mb-1">صف تایید مالی</h4>
      <div class="text-muted small">پیش‌فاکتورهای ثبت نهایی‌شده برای بررسی مالی</div>
    </div>
    <a href="{{ route('preinvoice.create') }}" class="btn btn-sm btn-primary">ایجاد پیش‌فاکتور</a>
  </div>

  @if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
  @endif


  <div class="card shadow-sm border-0 finance-queue-card mb-4">
    <div class="card-header bg-white py-3">
      <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center">
        <div>
          <h6 class="mb-1">فاکتورهای نیازمند تایید مجدد مالی</h6>
          <small class="text-muted">فاکتورهایی که اقلام آن‌ها توسط انبار حذف و اضافه شده‌اند.</small>
        </div>
        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">{{ number_format($financeReapprovalInvoices->count()) }} مورد</span>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>شماره فاکتور</th><th>مشتری</th><th>مبلغ جدید</th><th>فروشنده/اپراتور</th><th>توضیح انبار</th><th>تاریخ تغییر</th><th class="text-end">عملیات</th></tr></thead>
        <tbody>
          @forelse($financeReapprovalInvoices as $invoice)
            <tr>
              <td>{{ $invoice->uuid }}</td>
              <td>{{ $invoice->customer_name ?: '—' }}</td>
              <td>{{ number_format((int) $invoice->total) }}</td>
              <td>{{ $invoice->preinvoiceOrder?->creator?->name ?? '—' }}</td>
              <td>{{ $invoice->collection_note ?: '—' }}</td>
              <td>{{ $invoice->items_updated_at ? Jalalian::fromDateTime($invoice->items_updated_at)->format('Y/m/d H:i') : '—' }}</td>
              <td class="text-end">
                <form class="d-inline" method="POST" action="{{ route('finance.invoices.reapprove', $invoice->uuid) }}">@csrf<button class="btn btn-sm btn-success">تایید و ارسال بار</button></form>
                <form class="d-inline-flex gap-1" method="POST" action="{{ route('finance.invoices.return-to-sales', $invoice->uuid) }}">
                  @csrf
                  <input name="reason" class="form-control form-control-sm" placeholder="علت ارجاع" required>
                  <button class="btn btn-sm btn-outline-warning text-nowrap">ارجاع به اپراتور</button>
                </form>
              </td>
            </tr>
          @empty
            <tr><td colspan="7" class="text-center py-4 text-muted">فاکتور نیازمند تایید مجدد مالی وجود ندارد.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="card shadow-sm border-0 finance-queue-card">
    <div class="card-header bg-white py-3">
      <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center">
        <div>
          <h6 class="mb-1">پیش‌فاکتورهای در انتظار تایید مالی</h6>
          <small class="text-muted">تیم مالی می‌تواند پیش‌فاکتورهای آماده بررسی را تایید، ارجاع، کنسل یا چاپ کند.</small>
        </div>
        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
          {{ number_format($orders->total()) }} مورد در انتظار بررسی
        </span>
      </div>
    </div>

    <div class="table-responsive finance-queue-table-desktop">
      <table class="table table-hover align-middle mb-0 finance-queue-table">
        <colgroup>
          <col style="width: 25%;">
          <col style="width: 18%;">
          <col style="width: 14%;">
          <col style="width: 12%;">
          <col style="width: 11%;">
          <col style="width: 20%;">
        </colgroup>
        <thead>
          <tr class="table-light">
            <th>پیش‌فاکتور</th>
            <th>نقدی / چکی</th>
            <th>رزرو</th>
            <th class="text-nowrap">مبلغ</th>
            <th class="text-nowrap">ثبت</th>
            <th class="text-end">عملیات</th>
          </tr>
        </thead>
        <tbody>
          @forelse($orders as $o)
            @php
              $paymentTerms = trim((string) ($o->payment_terms_note ?? ''));
              $isVip = ($o->customer?->reservation_tier === 'vip');
              $isExpired = !$isVip && $o->stock_frozen_until && $o->stock_frozen_until->isPast();
              $expiresIso = $o->stock_frozen_until?->toIso8601String();
              $expiresTitle = $o->stock_frozen_until ? Jalalian::fromDateTime($o->stock_frozen_until)->format('Y/m/d H:i') : '';
              $createdAt = $o->created_at ? Jalalian::fromDateTime($o->created_at)->format('Y/m/d H:i') : '—';
            @endphp
            <tr class="{{ $isExpired ? 'is-expired' : '' }}" data-reservation-row>
              <td>
                <div class="finance-order-main">
                  <div class="fw-semibold order-code">{{ $o->uuid }}</div>
                  <div class="customer-name">{{ $o->customer_name ?: '—' }}</div>
                  @if($o->customer_mobile)
                    <div class="small text-muted">{{ $o->customer_mobile }}</div>
                  @endif
                  <div class="small text-muted">ثبت‌کننده: {{ $o->creator?->name ?? '—' }}</div>
                </div>
              </td>
              <td>
                <div class="finance-payment-note" title="{{ $paymentTerms }}">{{ $paymentTerms !== '' ? Str::limit($paymentTerms, 90) : '—' }}</div>
              </td>
              <td class="small">
                <div class="reservation-timer-pill" title="{{ $expiresTitle }}">
                  @if($isExpired)
                    <span class="badge bg-danger-subtle text-danger-emphasis border reservation-status">منقضی‌شده</span>
                  @elseif($isVip && !$o->stock_frozen_until)
                    <span class="badge bg-warning-subtle text-warning-emphasis border reservation-status">VIP / بدون انقضا</span>
                  @elseif(!$o->stock_frozen_until)
                    <span class="badge bg-secondary-subtle text-secondary-emphasis border reservation-status">بدون زمان</span>
                  @else
                    <span class="badge bg-success-subtle text-success-emphasis border reservation-status">فعال</span>
                  @endif
                  <span class="reservation-countdown" data-expires-at="{{ $expiresIso }}" data-is-vip="{{ $isVip ? '1' : '0' }}">{{ $isExpired ? '00:00:00' : '—' }}</span>
                </div>
              </td>
              <td class="fw-semibold text-nowrap">{{ number_format((int)$o->total_price) }}</td>
              <td class="small text-muted">{{ $createdAt }}</td>
              <td class="text-end">
                @include('preinvoice.partials.finance-actions', ['o' => $o, 'isExpired' => $isExpired])
              </td>
            </tr>
          @empty
            <tr><td colspan="6" class="text-center py-4">موردی نیست</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div class="finance-queue-mobile p-3">
      @forelse($orders as $o)
        @php
          $paymentTerms = trim((string) ($o->payment_terms_note ?? ''));
          $isVip = ($o->customer?->reservation_tier === 'vip');
          $isExpired = !$isVip && $o->stock_frozen_until && $o->stock_frozen_until->isPast();
          $expiresIso = $o->stock_frozen_until?->toIso8601String();
          $expiresTitle = $o->stock_frozen_until ? Jalalian::fromDateTime($o->stock_frozen_until)->format('Y/m/d H:i') : '';
          $createdAt = $o->created_at ? Jalalian::fromDateTime($o->created_at)->format('Y/m/d H:i') : '—';
        @endphp
        <div class="finance-mobile-card {{ $isExpired ? 'is-expired' : '' }}" data-reservation-row>
          <div class="d-flex justify-content-between gap-2 mb-2">
            <div class="min-w-0">
              <div class="fw-semibold text-break">{{ $o->uuid }}</div>
              <div>{{ $o->customer_name ?: '—' }}</div>
              <div class="small text-muted">{{ $o->customer_mobile ?: 'بدون موبایل' }}</div>
            </div>
            <div class="fw-semibold text-nowrap">{{ number_format((int)$o->total_price) }}</div>
          </div>
          <div class="finance-mobile-meta small mb-3">
            <div><span class="text-muted">ثبت‌کننده:</span> {{ $o->creator?->name ?? '—' }}</div>
            <div><span class="text-muted">ثبت:</span> {{ $createdAt }}</div>
            <div><span class="text-muted">نقدی / چکی:</span> <span title="{{ $paymentTerms }}">{{ $paymentTerms !== '' ? Str::limit($paymentTerms, 90) : '—' }}</span></div>
            <div title="{{ $expiresTitle }}">
              <span class="text-muted">رزرو:</span>
              @if($isExpired)
                <span class="badge bg-danger-subtle text-danger-emphasis border reservation-status">منقضی‌شده</span>
              @elseif($isVip && !$o->stock_frozen_until)
                <span class="badge bg-warning-subtle text-warning-emphasis border reservation-status">VIP / بدون انقضا</span>
              @elseif(!$o->stock_frozen_until)
                <span class="badge bg-secondary-subtle text-secondary-emphasis border reservation-status">بدون زمان</span>
              @else
                <span class="badge bg-success-subtle text-success-emphasis border reservation-status">فعال</span>
              @endif
              <span class="reservation-countdown" data-expires-at="{{ $expiresIso }}" data-is-vip="{{ $isVip ? '1' : '0' }}">{{ $isExpired ? '00:00:00' : '—' }}</span>
            </div>
          </div>
          @include('preinvoice.partials.finance-actions', ['o' => $o, 'isExpired' => $isExpired])
        </div>
      @empty
        <div class="text-center py-4 text-muted">موردی نیست</div>
      @endforelse
    </div>
  </div>

  <div class="mt-3">
    {{ $orders->links() }}
  </div>
</div>
@endsection

@push('scripts')
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const pad = (value) => String(value).padStart(2, '0');

    const formatRemaining = (milliseconds) => {
      const totalSeconds = Math.max(0, Math.floor(milliseconds / 1000));
      const days = Math.floor(totalSeconds / 86400);
      const hours = Math.floor((totalSeconds % 86400) / 3600);
      const minutes = Math.floor((totalSeconds % 3600) / 60);
      const seconds = totalSeconds % 60;

      if (days > 0) {
        return `${days}d ${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
      }

      return `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
    };

    const expireRow = (element) => {
      const row = element.closest('[data-reservation-row]');
      row?.classList.add('is-expired');
      const status = row?.querySelector('.reservation-status');
      if (status) {
        status.className = 'badge bg-danger-subtle text-danger-emphasis border reservation-status';
        status.textContent = 'منقضی‌شده';
      }
      row?.querySelectorAll('[data-finance-approve]').forEach((button) => {
        button.classList.add('disabled');
        button.setAttribute('aria-disabled', 'true');
        button.setAttribute('tabindex', '-1');
        button.addEventListener('click', (event) => event.preventDefault());
      });
      row?.querySelectorAll('[data-expired-message]').forEach((message) => {
        message.classList.remove('d-none');
      });
    };

    const updateCountdowns = () => {
      const now = Date.now();
      document.querySelectorAll('.reservation-countdown').forEach((element) => {
        const expiresAt = element.dataset.expiresAt;
        const isVip = element.dataset.isVip === '1';

        if (!expiresAt) {
          element.textContent = isVip ? 'بدون انقضا' : 'نامشخص';
          return;
        }

        const expiresTime = new Date(expiresAt).getTime();
        if (Number.isNaN(expiresTime) || expiresTime <= now) {
          element.textContent = '00:00:00';
          expireRow(element);
          return;
        }

        element.textContent = `${formatRemaining(expiresTime - now)} مانده`;
      });
    };

    updateCountdowns();
    window.setInterval(updateCountdowns, 1000);
  });
</script>
@endpush
