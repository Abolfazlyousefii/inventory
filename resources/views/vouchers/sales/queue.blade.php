@extends('layouts.app')

@php
  use App\Models\Invoice;
  use Morilog\Jalali\Jalalian;

  $toJalali = fn($date) => $date ? Jalalian::fromDateTime($date)->format('Y/m/d H:i') : '—';
  $statusBadgeClass = function (?string $status): string {
    return match ($status) {
      Invoice::STATUS_PENDING_COLLECTION => 'collection-status-pending',
      Invoice::STATUS_WAREHOUSE_RECEIVED => 'collection-status-received',
      Invoice::STATUS_COLLECTING => 'collection-status-collecting',
      Invoice::STATUS_READY_TO_SHIP => 'collection-status-ready',
      default => 'collection-status-default',
    };
  };
@endphp

@section('content')
<style>
  .collection-page {
    --collection-blue: #2563eb;
    --collection-blue-dark: #1d4ed8;
    --collection-blue-soft: #eff6ff;
    --collection-border: #dbeafe;
    --collection-text: #0f172a;
    --collection-muted: #64748b;
    max-width: 100%;
    overflow-x: hidden;
    color: var(--collection-text);
  }
  .collection-toolbar {
    background: #fff;
    border: 1px solid rgba(37, 99, 235, .12);
    border-radius: 18px;
    box-shadow: 0 12px 30px rgba(15, 23, 42, .05);
    padding: 14px 16px;
  }
  .collection-title { font-weight: 900; font-size: 1.1rem; margin: 0; color: var(--collection-text); }
  .collection-subtitle { color: var(--collection-muted); font-size: .8rem; margin-top: 4px; }
  .collection-count-badge {
    display: inline-flex; align-items: center; border-radius: 999px; padding: 5px 10px;
    background: var(--collection-blue-soft); color: var(--collection-blue-dark); border: 1px solid var(--collection-border);
    font-size: .76rem; font-weight: 800; white-space: nowrap;
  }
  .collection-nav { display: flex; gap: 8px; flex-wrap: wrap; }
  .collection-nav .btn { border-radius: 999px; padding: 6px 12px; font-size: .78rem; font-weight: 800; }
  .collection-nav .btn.active { background: var(--collection-blue); border-color: var(--collection-blue); color: #fff; }
  .collection-card {
    max-width: 100%; background: #fff; border: 1px solid rgba(37, 99, 235, .12);
    border-radius: 18px; box-shadow: 0 12px 30px rgba(15, 23, 42, .05); overflow: hidden;
  }
  .collection-desktop-table { max-width: 100%; overflow: hidden; }
  .collection-table { width: 100%; table-layout: fixed; margin-bottom: 0; }
  .collection-table th {
    background: #f8fbff; color: #475569; font-size: .76rem; font-weight: 800;
    padding: 11px 10px; border-bottom: 1px solid #e2e8f0; white-space: nowrap;
  }
  .collection-table td {
    padding: 12px 10px; vertical-align: middle; font-size: .83rem; color: var(--collection-text);
    border-bottom: 1px solid #eef2f7;
  }
  .collection-table td, .collection-table th { overflow: hidden; }
  .collection-table tbody tr:last-child td { border-bottom: 0; }
  .collection-main-text { font-weight: 850; color: var(--collection-text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .collection-sub-text { font-size: .74rem; color: var(--collection-muted); margin-top: 3px; overflow-wrap: anywhere; }
  .collection-meta { display: grid; gap: 3px; color: var(--collection-muted); font-size: .74rem; }
  .collection-meta strong { color: var(--collection-text); font-weight: 850; }
  .collection-time-line { display: flex; justify-content: space-between; gap: 8px; color: var(--collection-muted); font-size: .73rem; line-height: 1.45; }
  .collection-time-line span:last-child { color: var(--collection-text); text-align: left; direction: ltr; unicode-bidi: plaintext; }
  .collection-actions { display: flex; flex-wrap: wrap; gap: 6px; justify-content: flex-end; align-items: center; }
  .collection-actions .btn { font-size: .74rem; padding: 5px 9px; border-radius: 10px; font-weight: 800; white-space: nowrap; }
  .collection-action-primary { background: var(--collection-blue); border-color: var(--collection-blue); color: #fff; }
  .collection-action-primary:hover { background: var(--collection-blue-dark); border-color: var(--collection-blue-dark); color: #fff; }
  .collection-action-success { background: #ecfdf5; border-color: #bbf7d0; color: #047857; }
  .collection-action-success:hover { background: #d1fae5; border-color: #86efac; color: #065f46; }
  .collection-status-badge {
    display: inline-flex; align-items: center; max-width: 100%; border-radius: 999px; padding: 4px 9px;
    font-size: .72rem; font-weight: 850; border: 1px solid transparent; white-space: nowrap;
  }
  .collection-status-pending { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
  .collection-status-received { background: #ecfeff; color: #0e7490; border-color: #a5f3fc; }
  .collection-status-collecting { background: #fffbeb; color: #b45309; border-color: #fde68a; }
  .collection-status-ready { background: #ecfdf5; color: #047857; border-color: #bbf7d0; }
  .collection-status-default { background: #f8fafc; color: #475569; border-color: #e2e8f0; }
  .collection-empty {
    margin: 14px; padding: 24px 16px; text-align: center; background: var(--collection-blue-soft);
    border: 1px dashed var(--collection-border); border-radius: 16px; color: var(--collection-muted); font-weight: 800;
  }
  .collection-mobile-list { display: none; }
  .collection-mobile-card { background: #fff; border: 1px solid rgba(37, 99, 235, .14); border-radius: 16px; padding: 12px; box-shadow: 0 10px 24px rgba(15, 23, 42, .05); }
  .collection-mobile-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px; margin-top: 10px; }
  .collection-mobile-field { background: #f8fbff; border: 1px solid #eef2f7; border-radius: 12px; padding: 8px; min-width: 0; }
  .collection-mobile-label { color: var(--collection-muted); font-size: .7rem; margin-bottom: 3px; }
  .collection-mobile-value { color: var(--collection-text); font-weight: 800; font-size: .8rem; overflow-wrap: anywhere; }
  .collection-mobile-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-top: 10px; }
  .collection-mobile-actions .btn, .collection-mobile-actions form, .collection-mobile-actions button { width: 100%; }
  @media (max-width: 767.98px) {
    .collection-page { padding-inline: 10px !important; }
    .collection-toolbar { padding: 12px; }
    .collection-nav { width: 100%; }
    .collection-nav .btn { flex: 1 1 auto; }
    .collection-desktop-table { display: none; }
    .collection-mobile-list { display: grid; gap: 10px; padding: 10px; }
    .collection-card { background: #f8fbff; }
  }
</style>

<div class="container py-4 collection-page">
  <div class="collection-toolbar d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <div class="d-flex align-items-start gap-2 flex-wrap">
      <div>
        <h4 class="collection-title">{{ $isShippedPage ? 'آماده ارسال' : 'صف جمع‌آوری فاکتورها' }}</h4>
        <div class="collection-subtitle">{{ $subtitle ?? 'فاکتورهای تاییدشده مالی که باید توسط انبار جمع‌آوری شوند.' }}</div>
      </div>
      <span class="collection-count-badge">{{ number_format($invoices->total()) }} فاکتور در صف</span>
    </div>
    <div class="collection-nav">
      <a class="btn btn-outline-primary {{ !$isShippedPage ? 'active' : '' }}" href="{{ route('vouchers.sales.queue') }}">صف جمع‌آوری</a>
      <a class="btn btn-outline-primary {{ $isShippedPage ? 'active' : '' }}" href="{{ route('vouchers.sales.shipped') }}">آماده ارسال</a>
      <a class="btn btn-outline-secondary" href="{{ route('vouchers.sales.index') }}">همه حواله‌ها</a>
    </div>
  </div>

  <div class="collection-card">
    <div class="collection-desktop-table">
      <table class="table collection-table" id="sales-queue-table">
        <colgroup>
          <col style="width: 19%;">
          <col style="width: 20%;">
          <col style="width: 13%;">
          <col style="width: 19%;">
          <col style="width: 11%;">
          <col style="width: 18%;">
        </colgroup>
        <thead>
          <tr>
            <th>فاکتور</th>
            <th>مشتری</th>
            <th>خلاصه</th>
            <th>زمان‌بندی انبار</th>
            <th>آخرین بروزرسانی</th>
            <th class="text-end">عملیات</th>
          </tr>
        </thead>
        <tbody id="collectionDesktopRows">
          @forelse($invoices as $inv)
            <tr data-invoice-uuid="{{ $inv->uuid }}">
              <td>
                <div class="collection-main-text" title="{{ $inv->uuid }}">{{ $inv->uuid }}</div>
                <div class="mt-1"><span class="collection-status-badge {{ $statusBadgeClass($inv->status) }}">{{ $statusLabels[$inv->status] ?? $inv->status }}</span></div>
                <div class="collection-sub-text">ثبت: {{ $toJalali($inv->created_at) }}</div>
              </td>
              <td>
                <div class="collection-main-text" title="{{ $inv->customer_name }}">{{ $inv->customer_name ?: '—' }}</div>
                <div class="collection-sub-text">{{ $inv->customer_mobile ?: '—' }}</div>
                <div class="collection-sub-text">فروشنده: {{ $inv->preinvoiceOrder?->creator?->name ?? '—' }}</div>
              </td>
              <td>
                <div class="collection-meta">
                  <div><strong>{{ number_format((int) $inv->items->sum('quantity')) }}</strong> قلم</div>
                  <div><strong>{{ number_format((int) $inv->total) }}</strong></div>
                </div>
              </td>
              <td>
                <div class="collection-meta">
                  <div class="collection-time-line"><span>دریافت:</span><span>{{ $toJalali($inv->warehouse_received_at) }}</span></div>
                  <div class="collection-time-line"><span>شروع:</span><span>{{ $toJalali($inv->collection_started_at) }}</span></div>
                  <div class="collection-time-line"><span>اتمام:</span><span>{{ $toJalali($inv->collected_at) }}</span></div>
                </div>
              </td>
              <td><div class="collection-sub-text">{{ $toJalali($inv->updated_at) }}</div></td>
              <td>
                <div class="collection-actions">
                  <a class="btn btn-outline-secondary" href="{{ route('vouchers.sales.show', $inv->uuid) }}">مشاهده</a>
                  @unless($isShippedPage)
                    @if($inv->status === Invoice::STATUS_PENDING_COLLECTION)
                      <form class="d-inline" method="POST" action="{{ route('vouchers.sales.queue.receive', $inv->uuid) }}">@csrf<button class="btn collection-action-primary">دریافت شد</button></form>
                    @endif
                    @if($inv->status === Invoice::STATUS_WAREHOUSE_RECEIVED)
                      <form class="d-inline" method="POST" action="{{ route('vouchers.sales.queue.start-collection', $inv->uuid) }}">@csrf<button class="btn collection-action-primary">شروع جمع‌آوری</button></form>
                    @endif
                    @if($inv->status === Invoice::STATUS_COLLECTING)
                      <form class="d-inline" method="POST" action="{{ route('vouchers.sales.queue.complete-collection', $inv->uuid) }}">@csrf<button class="btn collection-action-success">اتمام و ارسال</button></form>
                      <a class="btn btn-outline-primary" href="{{ route('vouchers.sales.collection.edit', $inv->uuid) }}">حذف و اضافه</a>
                    @endif
                  @endunless
                  <a class="btn btn-outline-secondary" target="_blank" href="{{ route('vouchers.sales.print', $inv->uuid) }}">چاپ</a>
                </div>
              </td>
            </tr>
          @empty
            <tr><td colspan="6"><div class="collection-empty">فعلاً فاکتوری برای جمع‌آوری وجود ندارد.</div></td></tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div class="collection-mobile-list" id="collectionMobileRows">
      @forelse($invoices as $inv)
        <div class="collection-mobile-card" data-invoice-uuid="{{ $inv->uuid }}">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div class="collection-main-text">{{ $inv->uuid }}</div>
            <span class="collection-status-badge {{ $statusBadgeClass($inv->status) }}">{{ $statusLabels[$inv->status] ?? $inv->status }}</span>
          </div>
          <div class="collection-mobile-grid">
            <div class="collection-mobile-field"><div class="collection-mobile-label">مشتری</div><div class="collection-mobile-value">{{ $inv->customer_name ?: '—' }}</div></div>
            <div class="collection-mobile-field"><div class="collection-mobile-label">موبایل</div><div class="collection-mobile-value">{{ $inv->customer_mobile ?: '—' }}</div></div>
            <div class="collection-mobile-field"><div class="collection-mobile-label">فروشنده</div><div class="collection-mobile-value">{{ $inv->preinvoiceOrder?->creator?->name ?? '—' }}</div></div>
            <div class="collection-mobile-field"><div class="collection-mobile-label">خلاصه</div><div class="collection-mobile-value">{{ number_format((int) $inv->items->sum('quantity')) }} قلم / {{ number_format((int) $inv->total) }}</div></div>
            <div class="collection-mobile-field"><div class="collection-mobile-label">ثبت</div><div class="collection-mobile-value">{{ $toJalali($inv->created_at) }}</div></div>
            <div class="collection-mobile-field"><div class="collection-mobile-label">آخرین بروزرسانی</div><div class="collection-mobile-value">{{ $toJalali($inv->updated_at) }}</div></div>
          </div>
          <div class="collection-meta mt-2">
            <div class="collection-time-line"><span>دریافت:</span><span>{{ $toJalali($inv->warehouse_received_at) }}</span></div>
            <div class="collection-time-line"><span>شروع:</span><span>{{ $toJalali($inv->collection_started_at) }}</span></div>
            <div class="collection-time-line"><span>اتمام:</span><span>{{ $toJalali($inv->collected_at) }}</span></div>
          </div>
          <div class="collection-mobile-actions">
            <a class="btn btn-outline-secondary" href="{{ route('vouchers.sales.show', $inv->uuid) }}">مشاهده</a>
            @unless($isShippedPage)
              @if($inv->status === Invoice::STATUS_PENDING_COLLECTION)
                <form method="POST" action="{{ route('vouchers.sales.queue.receive', $inv->uuid) }}">@csrf<button class="btn collection-action-primary">دریافت شد</button></form>
              @endif
              @if($inv->status === Invoice::STATUS_WAREHOUSE_RECEIVED)
                <form method="POST" action="{{ route('vouchers.sales.queue.start-collection', $inv->uuid) }}">@csrf<button class="btn collection-action-primary">شروع جمع‌آوری</button></form>
              @endif
              @if($inv->status === Invoice::STATUS_COLLECTING)
                <form method="POST" action="{{ route('vouchers.sales.queue.complete-collection', $inv->uuid) }}">@csrf<button class="btn collection-action-success">اتمام و ارسال</button></form>
                <a class="btn btn-outline-primary" href="{{ route('vouchers.sales.collection.edit', $inv->uuid) }}">حذف و اضافه</a>
              @endif
            @endunless
            <a class="btn btn-outline-secondary" target="_blank" href="{{ route('vouchers.sales.print', $inv->uuid) }}">چاپ</a>
          </div>
        </div>
      @empty
        <div class="collection-empty">فعلاً فاکتوری برای جمع‌آوری وجود ندارد.</div>
      @endforelse
    </div>
  </div>
  <div class="mt-3">{{ $invoices->links() }}</div>
</div>

@unless($isShippedPage)
<script>
(() => {
  const csrfToken = @json(csrf_token());
  const emptyDesktop = '<tr><td colspan="6"><div class="collection-empty">فعلاً فاکتوری برای جمع‌آوری وجود ندارد.</div></td></tr>';
  const emptyMobile = '<div class="collection-empty">فعلاً فاکتوری برای جمع‌آوری وجود ندارد.</div>';
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'}[char]));
  const statusClass = (status) => ({
    pending_collection: 'collection-status-pending',
    warehouse_received: 'collection-status-received',
    collecting: 'collection-status-collecting',
    ready_to_ship: 'collection-status-ready'
  }[status] || 'collection-status-default');
  const formButton = (url, label, className) => url ? `<form class="d-inline" method="POST" action="${escapeHtml(url)}"><input type="hidden" name="_token" value="${csrfToken}"><button class="btn ${className}">${label}</button></form>` : '';
  const actionButtons = (row, mobile = false) => {
    const formClass = mobile ? '' : 'd-inline';
    const receive = row.receive_url ? `<form class="${formClass}" method="POST" action="${escapeHtml(row.receive_url)}"><input type="hidden" name="_token" value="${csrfToken}"><button class="btn collection-action-primary">دریافت شد</button></form>` : '';
    const start = row.start_collection_url ? `<form class="${formClass}" method="POST" action="${escapeHtml(row.start_collection_url)}"><input type="hidden" name="_token" value="${csrfToken}"><button class="btn collection-action-primary">شروع جمع‌آوری</button></form>` : '';
    const complete = row.status === 'collecting' && row.complete_collection_url ? `<form class="${formClass}" method="POST" action="${escapeHtml(row.complete_collection_url)}"><input type="hidden" name="_token" value="${csrfToken}"><button class="btn collection-action-success">اتمام و ارسال</button></form>` : '';
    const edit = row.status === 'collecting' && row.edit_items_url ? `<a class="btn btn-outline-primary" href="${escapeHtml(row.edit_items_url)}">حذف و اضافه</a>` : '';
    return `
      <a class="btn btn-outline-secondary" href="${escapeHtml(row.show_url)}">مشاهده</a>
      ${receive}${start}${complete}${edit}
      <a class="btn btn-outline-secondary" target="_blank" href="${escapeHtml(row.print_url)}">چاپ</a>`;
  };
  const renderCollectionDesktopRow = (row) => `
    <tr data-invoice-uuid="${escapeHtml(row.uuid)}">
      <td>
        <div class="collection-main-text" title="${escapeHtml(row.uuid)}">${escapeHtml(row.uuid)}</div>
        <div class="mt-1"><span class="collection-status-badge ${statusClass(row.status)}">${escapeHtml(row.status_label)}</span></div>
        <div class="collection-sub-text">ثبت: ${escapeHtml(row.created_at || '—')}</div>
      </td>
      <td>
        <div class="collection-main-text" title="${escapeHtml(row.customer_name || '—')}">${escapeHtml(row.customer_name || '—')}</div>
        <div class="collection-sub-text">${escapeHtml(row.customer_mobile || '—')}</div>
        <div class="collection-sub-text">فروشنده: ${escapeHtml(row.seller || '—')}</div>
      </td>
      <td><div class="collection-meta"><div><strong>${Number(row.items_count || 0).toLocaleString()}</strong> قلم</div><div><strong>${Number(row.total || 0).toLocaleString()}</strong></div></div></td>
      <td><div class="collection-meta"><div class="collection-time-line"><span>دریافت:</span><span>${escapeHtml(row.warehouse_received_at || '—')}</span></div><div class="collection-time-line"><span>شروع:</span><span>${escapeHtml(row.collection_started_at || '—')}</span></div><div class="collection-time-line"><span>اتمام:</span><span>${escapeHtml(row.collected_at || '—')}</span></div></div></td>
      <td><div class="collection-sub-text">${escapeHtml(row.updated_at || '—')}</div></td>
      <td><div class="collection-actions">${actionButtons(row, false)}</div></td>
    </tr>`;
  const renderCollectionMobileCard = (row) => `
    <div class="collection-mobile-card" data-invoice-uuid="${escapeHtml(row.uuid)}">
      <div class="d-flex justify-content-between align-items-start gap-2"><div class="collection-main-text">${escapeHtml(row.uuid)}</div><span class="collection-status-badge ${statusClass(row.status)}">${escapeHtml(row.status_label)}</span></div>
      <div class="collection-mobile-grid">
        <div class="collection-mobile-field"><div class="collection-mobile-label">مشتری</div><div class="collection-mobile-value">${escapeHtml(row.customer_name || '—')}</div></div>
        <div class="collection-mobile-field"><div class="collection-mobile-label">موبایل</div><div class="collection-mobile-value">${escapeHtml(row.customer_mobile || '—')}</div></div>
        <div class="collection-mobile-field"><div class="collection-mobile-label">فروشنده</div><div class="collection-mobile-value">${escapeHtml(row.seller || '—')}</div></div>
        <div class="collection-mobile-field"><div class="collection-mobile-label">خلاصه</div><div class="collection-mobile-value">${Number(row.items_count || 0).toLocaleString()} قلم / ${Number(row.total || 0).toLocaleString()}</div></div>
        <div class="collection-mobile-field"><div class="collection-mobile-label">ثبت</div><div class="collection-mobile-value">${escapeHtml(row.created_at || '—')}</div></div>
        <div class="collection-mobile-field"><div class="collection-mobile-label">آخرین بروزرسانی</div><div class="collection-mobile-value">${escapeHtml(row.updated_at || '—')}</div></div>
      </div>
      <div class="collection-meta mt-2"><div class="collection-time-line"><span>دریافت:</span><span>${escapeHtml(row.warehouse_received_at || '—')}</span></div><div class="collection-time-line"><span>شروع:</span><span>${escapeHtml(row.collection_started_at || '—')}</span></div><div class="collection-time-line"><span>اتمام:</span><span>${escapeHtml(row.collected_at || '—')}</span></div></div>
      <div class="collection-mobile-actions">${actionButtons(row, true)}</div>
    </div>`;

  setInterval(async () => {
    try {
      const res = await fetch(@json(route('vouchers.sales.queue.data')), {headers: {'Accept': 'application/json'}});
      if (!res.ok) return;
      const data = await res.json();
      const rows = Array.isArray(data.rows) ? data.rows : [];
      const desktop = document.getElementById('collectionDesktopRows');
      const mobile = document.getElementById('collectionMobileRows');
      if (desktop) desktop.innerHTML = rows.length ? rows.map(renderCollectionDesktopRow).join('') : emptyDesktop;
      if (mobile) mobile.innerHTML = rows.length ? rows.map(renderCollectionMobileCard).join('') : emptyMobile;
    } catch (e) {}
  }, 30000);
})();
</script>
@endunless
@endsection
