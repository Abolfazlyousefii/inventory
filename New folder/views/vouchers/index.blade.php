@extends('layouts.app')

@section('content')
<div class="container py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h4 class="mb-0">ورودی و خروجی‌های انبار</h4>
    <a class="btn btn-primary" href="{{ route('vouchers.create') }}">+ ثبت حواله</a>
  </div>

  <form method="GET" class="card shadow-sm border-0 mb-3">
    <div class="card-body">
      <div class="row g-2 align-items-end">
        <div class="col-md-2">
          <label class="form-label">از تاریخ</label>
          <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] }}">
        </div>
        <div class="col-md-2">
          <label class="form-label">تا تاریخ</label>
          <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] }}">
        </div>
        <div class="col-md-2">
          <label class="form-label">ورودی / خروجی</label>
          <select name="direction" class="form-select">
            <option value="">همه</option>
            @foreach($directionOptions as $key => $label)
              <option value="{{ $key }}" @selected($filters['direction'] === $key)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">علت حواله</label>
          <select name="reason" class="form-select">
            <option value="">همه</option>
            @foreach($reasonLabels as $key => $label)
              <option value="{{ $key }}" @selected($filters['reason'] === $key)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">شماره حواله</label>
          <input type="text" class="form-control" name="voucher_no" value="{{ $filters['voucher_no'] }}" placeholder="TR-...">
        </div>
        <div class="col-md-2">
          <label class="form-label">ثبت‌کننده</label>
          <input type="text" class="form-control" name="user_q" value="{{ $filters['user_q'] }}" placeholder="نام کاربر">
        </div>
      </div>
      <div class="mt-2 d-flex gap-2">
        <button class="btn btn-primary">اعمال فیلتر</button>
        <a class="btn btn-outline-secondary" href="{{ route('vouchers.index') }}">حذف فیلتر</a>
      </div>
    </div>
  </form>

  <div class="row g-2 mb-3">
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">تعداد حواله‌ها</small><div class="fs-5 fw-bold">{{ number_format($summary['count']) }}</div></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">تعداد کل ردیف‌ها</small><div class="fs-5 fw-bold">{{ number_format($summary['items']) }}</div></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">جمع کل تعداد اقلام</small><div class="fs-5 fw-bold">{{ number_format($summary['qty']) }}</div></div></div></div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
        <tr>
          <th>شماره حواله</th>
          <th>تاریخ</th>
          <th>علت حواله</th>
          <th>نوع حرکت</th>
          <th>انبار مبدا</th>
          <th>انبار مقصد</th>
          <th>تعداد آیتم‌ها</th>
          <th>جمع کل تعداد</th>
          <th>ثبت‌کننده</th>
          <th class="text-end">عملیات</th>
        </tr>
        </thead>
        <tbody>
        @forelse($vouchers as $voucher)
          @php($direction = $voucher->voucher_type === 'customer_return' ? 'incoming' : 'outgoing')
          <tr>
            <td>{{ $voucher->reference ?: ('TR-' . $voucher->id) }}</td>
            <td>{{ optional($voucher->transferred_at)->format('Y/m/d H:i') }}</td>
            <td>{{ $reasonLabels[$voucher->voucher_type] ?? $voucher->voucher_type }}</td>
            <td>
              <span class="badge {{ $direction === 'incoming' ? 'bg-success-subtle text-success-emphasis border border-success-subtle' : 'bg-warning-subtle text-warning-emphasis border border-warning-subtle' }}">
                {{ $directionOptions[$direction] ?? '—' }}
              </span>
            </td>
            <td>{{ $voucher->fromWarehouse?->name ?? '—' }}</td>
            <td>{{ $voucher->toWarehouse?->name ?? '—' }}</td>
            <td>{{ number_format((int) ($voucher->items_count ?? 0)) }}</td>
            <td>{{ number_format((int) ($voucher->total_quantity ?? 0)) }}</td>
            <td>{{ $voucher->user?->name ?? '—' }}</td>
            <td class="text-end">
              <div class="dropdown">
                <button class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">عملیات</button>
                <ul class="dropdown-menu">
                  <li><a class="dropdown-item" href="{{ route('vouchers.show', $voucher) }}">مشاهده</a></li>
                  <li><a class="dropdown-item" href="{{ route('vouchers.show', $voucher) }}?print=1" target="_blank">چاپ</a></li>
                </ul>
              </div>
            </td>
          </tr>
        @empty
          <tr><td colspan="10" class="text-center py-4 text-muted">رکوردی یافت نشد.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="mt-3">
    {{ $vouchers->appends(request()->query())->links() }}
  </div>
</div>
@endsection
