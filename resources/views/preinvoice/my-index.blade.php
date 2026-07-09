@extends('layouts.app')

@section('title', 'پیش‌فاکتورها و فاکتورهای من')
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
  $isCancelledStatus = fn($order) => in_array($order->status, [
      \App\Models\PreinvoiceOrder::STATUS_CANCELLED_BY_WAREHOUSE,
      \App\Models\PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE,
  ], true) || (($order->invoice?->status ?? null) === \App\Models\Invoice::STATUS_NOT_SHIPPED);
  $effectiveStatus = fn($order) => $isCancelledStatus($order) ? \App\Models\PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE : $order->status;
  $invoiceStatusLabels = app(\App\Services\SalesHavalehStatusService::class)->labels();
  $effectiveStatusLabel = fn($order) => (($order->invoice?->status ?? null) === \App\Models\Invoice::STATUS_NOT_SHIPPED && ! in_array($order->status, [\App\Models\PreinvoiceOrder::STATUS_CANCELLED_BY_WAREHOUSE, \App\Models\PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE], true))
      ? 'لغوشده به دلیل کنسلی فاکتور مرتبط'
      : ($order->invoice ? ($invoiceStatusLabels[$order->invoice->status] ?? $order->invoice->status) : ($statusLabels[$order->status] ?? $order->status_label ?? $order->status));
  $latestTotal = fn($order) => $order->invoice ? (int) $order->invoice->total : (int) $order->total_price;
  $latestItemsCount = fn($order) => $order->invoice ? (int) $order->invoice->items->sum('quantity') : (int) $order->items_count;
  $latestUpdatedAt = fn($order) => $order->invoice ? ($order->invoice->items_updated_at ?? $order->invoice->status_changed_at ?? $order->invoice->created_at) : $order->created_at;
  $canEditOrder = fn($order) => ! $order->invoice && in_array($order->status, [\App\Models\PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE, \App\Models\PreinvoiceOrder::STATUS_RETURNED_TO_WAREHOUSE], true);

  $statusBadge = fn($status) => match($status) {
      \App\Models\PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE => 'text-bg-success',
      \App\Models\PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE,
      \App\Models\PreinvoiceOrder::STATUS_WAREHOUSE_REVIEWING => 'text-bg-info',
      \App\Models\PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE,
      \App\Models\PreinvoiceOrder::STATUS_FINANCE_REVIEWING => 'text-bg-warning',
      \App\Models\PreinvoiceOrder::STATUS_CANCELLED_BY_WAREHOUSE,
      \App\Models\PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE => 'text-bg-danger',
      default => 'text-bg-secondary',
  };
@endphp

<style>
  .my-sales-page{max-width:100%; overflow-x:hidden;}
  .my-sales-head,.my-sales-card{border:0; border-radius:18px; box-shadow:0 10px 28px rgba(15,23,42,.06);}
  .my-sales-head{background:linear-gradient(135deg,#fff,#f8fafc); padding:18px;}
  .my-sales-table{table-layout:fixed; width:100%;}
  .my-sales-table th{font-size:.78rem; color:#64748b; white-space:nowrap;}
  .my-sales-table td{font-size:.88rem; vertical-align:middle;}
  .code-cell{direction:ltr; unicode-bidi:plaintext; display:inline-block; max-width:112px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;}
  .desc-cell{max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#64748b;}
  .customer-cell{max-width:170px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;}
  .action-stack{display:flex; gap:.35rem; justify-content:flex-end; flex-wrap:wrap;}
  .action-stack .btn{padding:.22rem .45rem; font-size:.76rem;}
  .preinvoice-mobile-card{border:1px solid #e5e7eb; border-radius:16px; padding:14px; background:#fff; box-shadow:0 6px 18px rgba(15,23,42,.04);}
  @media (min-width: 992px){.preinvoice-table-wrap{overflow-x:visible!important;}}
</style>

<div class="my-sales-page py-2">
  <div class="my-sales-head mb-3 d-flex justify-content-between align-items-start gap-3 flex-wrap">
    <div>
      <h4 class="fw-bold mb-1">پیش‌فاکتورها و فاکتورهای من</h4>
      <div class="text-muted small">تاریخچه کامل فعالیت فروش شما، شامل پیش‌فاکتورهای باز و موارد فاکتور شده</div>
    </div>
    <a href="{{ route('preinvoice.create') }}" class="btn btn-primary">➕ ثبت پیش‌فاکتور جدید</a>
  </div>

  <div class="card my-sales-card mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="GET" action="{{ route('preinvoice.my.index') }}">
        <div class="col-md-4 col-xl-3">
          <label class="form-label fw-bold text-muted small">وضعیت</label>
          <select name="status" class="form-select">
            <option value="">همه وضعیت‌ها</option>
            @foreach($statusLabels as $key => $label)
              <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-auto d-flex gap-2">
          <button class="btn btn-primary">اعمال فیلتر</button>
          <a href="{{ route('preinvoice.my.index') }}" class="btn btn-outline-secondary">حذف فیلتر</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card my-sales-card d-none d-lg-block">
    <div class="table-responsive preinvoice-table-wrap">
      <table class="table table-hover align-middle mb-0 my-sales-table">
        <colgroup>
          <col style="width:8%"><col style="width:13%"><col style="width:9%"><col style="width:15%"><col style="width:6%"><col style="width:10%"><col style="width:12%"><col style="width:8%"><col style="width:8%"><col style="width:11%">
        </colgroup>
        <thead class="table-light">
          <tr>
            <th>کد</th><th>مشتری</th><th>موبایل</th><th>توضیحات</th><th>اقلام</th><th>مبلغ</th><th>وضعیت</th><th>فاکتور</th><th>تاریخ</th><th class="text-end">عملیات</th>
          </tr>
        </thead>
        <tbody>
          @forelse($orders as $order)
            @php
              $statusLabel = $effectiveStatusLabel($order);
              $invoiceUuid = $order->invoice?->uuid;
              $isCancelled = $isCancelledStatus($order);
              $documentKind = $isCancelled ? 'کنسل شده' : ($order->invoice ? 'فاکتور شده' : 'پیش‌فاکتور');
              $badgeStatus = $effectiveStatus($order);
            @endphp
            <tr>
              <td><span class="code-cell fw-bold" title="{{ $order->uuid }}">{{ Str::limit($order->uuid, 10, '…') }}</span></td>
              <td><span class="customer-cell" title="{{ $order->customer_name }}">{{ $order->customer_name }}</span></td>
              <td class="text-nowrap">{{ $order->customer_mobile ?: '—' }}</td>
              <td><span class="desc-cell" title="{{ $order->description ?: '' }}">{{ $order->description ?: '—' }}</span></td>
              <td>{{ number_format($latestItemsCount($order)) }}</td>
              <td class="text-nowrap">{{ \App\Support\Currency::formatRial($latestTotal($order)) }}</td>
              <td>
                <span class="badge {{ $statusBadge($badgeStatus) }}">{{ $documentKind }}</span>
                <div class="small text-muted mt-1">{{ $statusLabel }}</div>
              </td>
              <td>
                @if($invoiceUuid)
                  <a class="code-cell" href="{{ route('invoices.show', $invoiceUuid) }}" title="{{ $invoiceUuid }}">{{ Str::limit($invoiceUuid, 10, '…') }}</a>
                @else
                  —
                @endif
              </td>
              <td class="text-nowrap">{{ $toJalali($latestUpdatedAt($order)) }}</td>
              <td>
                <div class="action-stack">
                  <a href="{{ route('preinvoice.my.show', $order->uuid) }}" class="btn btn-sm btn-outline-primary">مشاهده</a>
                  @if($canEditOrder($order))
                    <a href="{{ route('preinvoice.draft.edit', $order->uuid) }}" class="btn btn-sm btn-outline-warning">ویرایش</a>
                  @else
                    <a href="{{ route('preinvoice.my.show', $order->uuid) }}" class="btn btn-sm btn-outline-secondary">جزئیات</a>
                  @endif
                  <a href="{{ route('preinvoice.my.show', $order->uuid) }}?print=1" target="_blank" class="btn btn-sm btn-outline-dark">پرینت</a>
                </div>
              </td>
            </tr>
          @empty
            <tr><td colspan="10" class="text-center text-muted py-4">پیش‌فاکتوری توسط شما ثبت نشده است.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="d-lg-none vstack gap-2">
    @forelse($orders as $order)
      @php
        $statusLabel = $effectiveStatusLabel($order);
        $invoiceUuid = $order->invoice?->uuid;
        $isCancelled = $isCancelledStatus($order);
        $documentKind = $isCancelled ? 'کنسل شده' : ($order->invoice ? 'فاکتور شده' : 'پیش‌فاکتور');
        $badgeStatus = $effectiveStatus($order);
      @endphp
      <div class="preinvoice-mobile-card">
        <div class="d-flex justify-content-between gap-2 mb-2">
          <strong>{{ $order->customer_name }}</strong>
          <span class="code-cell" title="{{ $order->uuid }}">{{ Str::limit($order->uuid, 12, '…') }}</span>
        </div>
        <div class="small text-muted mb-2">{{ $order->customer_mobile ?: '—' }}</div>
        <div class="d-flex flex-wrap gap-2 mb-2">
          <span class="badge {{ $statusBadge($badgeStatus) }}">{{ $documentKind }}</span>
          <span class="badge text-bg-light border">{{ $statusLabel }}</span>
        </div>
        <div class="small text-muted mb-2">{{ $order->description ? Str::limit($order->description, 120) : 'بدون توضیحات' }}</div>
        <div class="small d-flex justify-content-between"><span>مبلغ</span><strong>{{ \App\Support\Currency::formatRial($latestTotal($order)) }}</strong></div>
        @if($invoiceUuid)
          <div class="small d-flex justify-content-between"><span>فاکتور</span><a class="code-cell" href="{{ route('invoices.show', $invoiceUuid) }}">{{ Str::limit($invoiceUuid, 12, '…') }}</a></div>
        @endif
        <div class="action-stack mt-3 justify-content-start">
          <a href="{{ route('preinvoice.my.show', $order->uuid) }}" class="btn btn-sm btn-outline-primary">مشاهده</a>
          @if($canEditOrder($order))
            <a href="{{ route('preinvoice.draft.edit', $order->uuid) }}" class="btn btn-sm btn-outline-warning">ویرایش</a>
          @else
            <a href="{{ route('preinvoice.my.show', $order->uuid) }}" class="btn btn-sm btn-outline-secondary">جزئیات</a>
          @endif
          <a href="{{ route('preinvoice.my.show', $order->uuid) }}?print=1" target="_blank" class="btn btn-sm btn-outline-dark">پرینت</a>
        </div>
      </div>
    @empty
      <div class="preinvoice-mobile-card text-center text-muted">پیش‌فاکتوری توسط شما ثبت نشده است.</div>
    @endforelse
  </div>

  @if(method_exists($orders, 'links'))
    <div class="mt-3">{{ $orders->links() }}</div>
  @endif
</div>
@endsection
