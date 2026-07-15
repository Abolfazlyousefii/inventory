@extends('layouts.app')

@section('content')
<div class="container py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h4 class="mb-0">📦 لیست حواله‌های فروش</h4>
    <form class="d-flex gap-2" method="GET">
      <input class="form-control" name="q" value="{{ $q }}" placeholder="جستجو">
      <select class="form-select" name="status">
        <option value="">همه وضعیت‌ها</option>
        @foreach($allowedStatuses as $st)
          <option value="{{ $st }}" @selected($status === $st)>{{ $statusLabels[$st] ?? $st }}</option>
        @endforeach
      </select>
      <button class="btn btn-primary">جستجو</button>
    </form>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="table-responsive">
      <table class="table mb-0 align-middle">
        <thead>
          <tr>
            <th>کد حواله/فاکتور</th>
            <th>مشتری</th>
            <th>مبلغ</th>
            <th>وضعیت</th>
            <th>تاریخ</th>
            <th class="text-end">عملیات</th>
          </tr>
        </thead>
        <tbody>
          @forelse($invoices as $inv)
            <tr>
              <td>{{ $inv->uuid }}</td>
              <td>{{ $inv->customer_name }}</td>
              <td>{{ number_format((int)$inv->total) }}</td>
              <td><span class="badge bg-light text-dark border">{{ $statusLabels[$inv->status] ?? $inv->status }}</span></td>
              <td>{{ \App\Support\JalaliDate::dateTime($inv->display_document_date) }}</td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('vouchers.sales.show', $inv->uuid) }}">مشاهده</a>
                <a class="btn btn-sm btn-outline-primary" href="{{ route('vouchers.sales.edit', $inv->uuid) }}">تغییر وضعیت</a>
                <a class="btn btn-sm btn-outline-success" target="_blank" href="{{ route('vouchers.sales.print', $inv->uuid) }}">چاپ</a>
                <a class="btn btn-sm btn-outline-dark" href="{{ route('vouchers.sales.history', $inv->uuid) }}">تاریخچه</a>
              </td>
            </tr>
          @empty
            <tr><td colspan="6" class="text-center text-muted py-3">موردی نیست</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
  <div class="mt-3">{{ $invoices->links() }}</div>
</div>
@endsection
