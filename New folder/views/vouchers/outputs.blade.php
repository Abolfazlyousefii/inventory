@extends('layouts.app')

@section('content')
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="mb-1">خروجی‌های انبار</h4>
            <p class="text-muted mb-0 small">نمایش کامل حواله‌های خروجی با مبدا، مقصد، علت و ثبت‌کننده</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('vouchers.index') }}">بازگشت به لیست حواله‌ها</a>
            <a class="btn btn-primary" href="{{ route('vouchers.create') }}">+ ثبت حواله جدید</a>
        </div>
    </div>

    <form method="GET" class="card border-0 shadow-sm mb-3">
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
                    <label class="form-label">علت خروج</label>
                    <select name="reason" class="form-select">
                        <option value="">همه</option>
                        @foreach($reasonLabels as $key => $label)
                            <option value="{{ $key }}" @selected($filters['reason'] === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">انبار مبدا</label>
                    <select name="from_warehouse_id" class="form-select">
                        <option value="0">همه</option>
                        @foreach($fromWarehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected($filters['from_warehouse_id'] === (int) $warehouse->id)>
                                {{ $warehouse->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">مقصد</label>
                    <input type="text" class="form-control" name="destination" value="{{ $filters['destination'] }}" placeholder="انبار/مشتری/پرسنل">
                </div>
                <div class="col-md-2">
                    <label class="form-label">ثبت‌کننده</label>
                    <input type="text" class="form-control" name="user_q" value="{{ $filters['user_q'] }}" placeholder="نام کاربر">
                </div>
            </div>

            <div class="row g-2 align-items-end mt-1">
                <div class="col-md-3">
                    <label class="form-label">شماره حواله</label>
                    <input type="text" class="form-control" name="voucher_no" value="{{ $filters['voucher_no'] }}" placeholder="TR-...">
                </div>
                <div class="col-md-9 d-flex gap-2">
                    <button class="btn btn-primary">اعمال فیلتر</button>
                    <a class="btn btn-outline-secondary" href="{{ route('warehouse.outputs') }}">حذف فیلتر</a>
                </div>
            </div>
        </div>
    </form>

    <div class="row g-2 mb-3">
        <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">تعداد خروجی‌ها</small><div class="fs-5 fw-bold">{{ number_format($summary['count']) }}</div></div></div></div>
        <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">تعداد آیتم‌های حواله</small><div class="fs-5 fw-bold">{{ number_format($summary['items']) }}</div></div></div></div>
        <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><small class="text-muted">جمع کل تعداد کالاها</small><div class="fs-5 fw-bold">{{ number_format($summary['qty']) }}</div></div></div></div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>شماره حواله</th>
                    <th>تاریخ ثبت</th>
                    <th>تاریخ خروج</th>
                    <th>نوع/علت خروج</th>
                    <th>انبار مبدا</th>
                    <th>مقصد</th>
                    <th>ثبت‌کننده</th>
                    <th>تعداد آیتم‌ها</th>
                    <th>جمع کل تعداد</th>
                    <th>وضعیت</th>
                    <th class="text-end">عملیات</th>
                </tr>
                </thead>
                <tbody>
                @forelse($outputs as $voucher)
                    <tr>
                        <td class="fw-semibold">{{ $voucher->voucher_no }}</td>
                        <td>{{ optional($voucher->created_at)->format('Y/m/d H:i') ?: '—' }}</td>
                        <td>{{ optional($voucher->transferred_at)->format('Y/m/d H:i') ?: '—' }}</td>
                        <td>
                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                {{ $voucher->reason_label }}
                            </span>
                        </td>
                        <td>{{ $voucher->fromWarehouse?->name ?? '—' }}</td>
                        <td>{{ $voucher->destination_label }}</td>
                        <td>{{ $voucher->user?->name ?? '—' }}</td>
                        <td>{{ number_format((int) ($voucher->items_count ?? 0)) }}</td>
                        <td>{{ number_format((int) ($voucher->total_quantity ?? 0)) }}</td>
                        <td>{{ $voucher->status_label }}</td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('vouchers.show', $voucher) }}">مشاهده</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="text-center py-4 text-muted">خروجی‌ای مطابق فیلترها پیدا نشد.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $outputs->appends(request()->query())->links() }}
    </div>
</div>
@endsection
