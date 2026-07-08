@extends('layouts.app')

@section('content')
<div class="container py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
      <h4 class="mb-1">📦 {{ $title }}</h4>
      <div class="text-muted small">{{ $isShippedPage ? 'فقط حواله‌های ارسال‌شده نمایش داده می‌شود.' : 'فقط حواله‌های ارسال‌نشده و باقی‌مانده در صف جمع‌آوری نمایش داده می‌شود.' }}</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-primary" href="{{ route('vouchers.sales.queue') }}">صف جمع‌آوری</a>
      <a class="btn btn-outline-success" href="{{ route('vouchers.sales.shipped') }}">حواله‌های ارسال‌شده</a>
      <a class="btn btn-outline-secondary" href="{{ route('vouchers.sales.index') }}">همه حواله‌ها</a>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="table-responsive">
      <table class="table mb-0 align-middle" id="sales-queue-table">
        <thead>
          <tr>
            <th>شماره فاکتور</th>
            <th>مشتری</th>
            <th>موبایل</th>
            <th>تعداد اقلام</th>
            <th>مبلغ کل</th>
            <th>وضعیت فعلی</th>
            <th>تاریخ تایید/ایجاد</th>
            <th>آخرین بروزرسانی</th>
            <th>فروشنده</th>
            <th class="text-end">دکمه‌ها</th>
          </tr>
        </thead>
        <tbody>
          @forelse($invoices as $inv)
            <tr data-invoice-uuid="{{ $inv->uuid }}">
              <td>{{ $inv->uuid }}</td>
              <td>{{ $inv->customer_name }}</td>
              <td>{{ $inv->customer_mobile }}</td>
              <td>{{ (int) $inv->items->sum('quantity') }}</td>
              <td>{{ number_format((int) $inv->total) }}</td>
              <td><span class="badge bg-light text-dark border">{{ $statusLabels[$inv->status] ?? $inv->status }}</span></td>
              <td>{{ optional($inv->created_at)->format('Y-m-d H:i') }}</td>
              <td>{{ optional($inv->updated_at)->format('Y-m-d H:i') }}</td>
              <td>{{ $inv->preinvoiceOrder?->creator?->name ?? '—' }}</td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('vouchers.sales.show', $inv->uuid) }}">مشاهده</a>
                @unless($isShippedPage)
                  @if(in_array($inv->status, [\App\Models\Invoice::STATUS_PENDING_COLLECTION, \App\Models\Invoice::STATUS_PENDING_WAREHOUSE_APPROVAL], true))
                    <form class="d-inline" method="POST" action="{{ route('vouchers.sales.queue.receive', $inv->uuid) }}">@csrf<button class="btn btn-sm btn-outline-info">دریافت شد</button></form>
                  @endif
                  @if(in_array($inv->status, [\App\Models\Invoice::STATUS_PENDING_COLLECTION, \App\Models\Invoice::STATUS_WAREHOUSE_RECEIVED], true))
                    <form class="d-inline" method="POST" action="{{ route('vouchers.sales.queue.start-collection', $inv->uuid) }}">@csrf<button class="btn btn-sm btn-outline-warning">شروع جمع‌آوری</button></form>
                  @endif
                  @if(in_array($inv->status, [\App\Models\Invoice::STATUS_WAREHOUSE_RECEIVED, \App\Models\Invoice::STATUS_COLLECTING], true))
                    <form class="d-inline" method="POST" action="{{ route('vouchers.sales.queue.complete-collection', $inv->uuid) }}">@csrf<button class="btn btn-sm btn-outline-success">اتمام بدون تغییر</button></form>
                  @endif
                  <a class="btn btn-sm btn-outline-primary" href="{{ route('vouchers.sales.edit', $inv->uuid) }}">ویرایش</a>
                @endunless
                <a class="btn btn-sm btn-outline-success" target="_blank" href="{{ route('vouchers.sales.print', $inv->uuid) }}">چاپ</a>
                <a class="btn btn-sm btn-outline-dark" href="{{ route('vouchers.sales.history', $inv->uuid) }}">تاریخچه</a>
              </td>
            </tr>
          @empty
            <tr><td colspan="10" class="text-center text-muted py-3">موردی نیست</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
  <div class="mt-3">{{ $invoices->links() }}</div>
</div>

@unless($isShippedPage)
<script>
setInterval(async () => {
  try {
    const res = await fetch(@json(route('vouchers.sales.queue.data')), {headers: {'Accept': 'application/json'}});
    if (!res.ok) return;
    const data = await res.json();
    const body = document.querySelector('#sales-queue-table tbody');
    if (!body || !Array.isArray(data.rows)) return;
    body.innerHTML = data.rows.map(row => `
      <tr data-invoice-uuid="${row.uuid}">
        <td>${row.uuid}</td><td>${row.customer_name ?? ''}</td><td>${row.customer_mobile ?? ''}</td>
        <td>${row.items_count}</td><td>${Number(row.total).toLocaleString()}</td>
        <td><span class="badge bg-light text-dark border">${row.status_label}</span></td>
        <td>${row.created_at ?? ''}</td><td>${row.updated_at ?? ''}</td><td>${row.seller ?? '—'}</td>
        <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="${row.show_url}">مشاهده</a> <a class="btn btn-sm btn-outline-primary" href="${row.edit_url}">ویرایش</a> <a class="btn btn-sm btn-outline-success" target="_blank" href="${row.print_url}">چاپ</a> <a class="btn btn-sm btn-outline-dark" href="${row.history_url}">تاریخچه</a></td>
      </tr>`).join('') || '<tr><td colspan="10" class="text-center text-muted py-3">موردی نیست</td></tr>';
  } catch (e) {}
}, 30000);
</script>
@endunless
@endsection
