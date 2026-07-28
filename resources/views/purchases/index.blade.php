@extends('layouts.app')

@section('title', 'خرید کالا')

@section('content')
@php
    $toRial = fn($rial) => \App\Support\Currency::formatRial($rial);
@endphp




<div class="purchase-page-wrap">

    <div class="purchase-topbar d-flex justify-content-between align-items-center">
        <h4 class="page-title mb-0">خرید کالا</h4>
        <div class="d-flex gap-2 flex-wrap justify-content-end">
            @canPermission('stock_in.export')<a class="btn btn-outline-light" href="{{ route('purchases.export', request()->query()) }}">خروجی اکسل همه خریدها</a>@endcanPermission
            @canPermission('stock_in.create')<a class="btn btn-light" href="{{ route('purchases.create') }}">+ ثبت خرید جدید</a>@endcanPermission
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="card stat-card h-100">
                <div class="card-body d-flex flex-wrap gap-4 justify-content-between align-items-center">
                    <div>
                        <div class="label">جمع کل خریدها تا الان (قیمت خرید)</div>
                        <div class="value">{{ $toRial($totalAllAmount) }}</div>
                    </div>
                    <div>
                        <div class="label">تعداد لیست خریدها</div>
                        <div class="value">{{ number_format($totalAllCount) }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <form method="GET" class="card filter-card mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">از تاریخ</label>
                    <input type="date" name="date_from" class="form-control" value="{{ request('date_from') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">تا تاریخ</label>
                    <input type="date" name="date_to" class="form-control" value="{{ request('date_to') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">تامین‌کننده</label>
                    <select name="supplier_id" class="form-select">
                        <option value="">همه</option>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected((string) request('supplier_id') === (string) $supplier->id)>
                                {{ $supplier->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3 d-flex gap-2 filter-actions">
                    <button class="btn btn-primary">جستجو</button>
                    <a href="{{ route('purchases.index') }}" class="btn btn-outline-secondary">حذف فیلتر</a>
                </div>
            </div>
        </div>
    </form>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>تاریخ</th>
                            <th>تامین‌کننده</th>
                            <th>توضیحات</th>
                            <th>تخفیف سند</th>
                            <th>مبلغ کل فاکتور</th>
                            <th>تعداد آیتم</th>
                            <th class="text-end">عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($purchases as $purchase)
                        <tr>
                            <td class="fw-bold">
                                {{ $loop->iteration + (($purchases->currentPage() - 1) * $purchases->perPage()) }}
                            </td>
                            <td>{{ $purchase->purchased_at?->format('Y/m/d H:i') }}</td>
                            <td>{{ $purchase->supplier?->name }}</td>
                            <td class="text-muted" style="min-width: 180px; max-width: 320px; white-space: normal;">{{ $purchase->note ?: '-' }}</td>
                            <td>{{ $toRial($purchase->total_discount ?? 0) }}</td>
                            <td class="amount-strong">{{ $toRial($purchase->total_amount) }}</td>
                            <td><span class="badge-items">{{ $purchase->items_count }}</span></td>
                            <td class="text-end action-btns">
                                <a href="{{ route('purchases.show', $purchase) }}" class="btn btn-sm btn-outline-secondary">مشاهده</a>
                                @canPermission('stock_in.edit')<a href="{{ route('purchases.edit', $purchase) }}" class="btn btn-sm btn-outline-primary">ویرایش</a>@endcanPermission
                                @canPermission('stock_in.delete')<form method="POST" action="{{ route('purchases.destroy', $purchase) }}" class="d-inline"
                                      onsubmit="return confirm('از حذف این سند خرید مطمئن هستید؟')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger">حذف</button>
                                </form>@endcanPermission
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-5">
                                هیچ سند خریدی با این فیلتر ثبت نشده است.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="p-3">
                {{-- Pagination FIX: حفظ فیلترها --}}
                {{ $purchases->appends(request()->except('page'))->links() }}
            </div>
        </div>
    </div>

</div>
@endsection
