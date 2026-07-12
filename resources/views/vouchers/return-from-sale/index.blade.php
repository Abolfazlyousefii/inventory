@extends('layouts.app')
@php
    use App\Models\SalesReturnDocument;

    $sourceLabels = SalesReturnDocument::sourceTypeLabels();
@endphp
@section('content')
<div class="container-fluid" dir="rtl" data-module="new-sales-return">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-1">برگشت از فروش</h4>
            <div class="text-muted small">فهرست ساده برگشتی‌های ثبت‌شده</div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @canPermission('sales_returns.create')
                <a class="btn btn-sm btn-primary" href="{{ route('vouchers.return-from-sale.create') }}">ثبت برگشت جدید</a>
            @endcanPermission
            @canPermission('sales_returns.export')
                <a class="btn btn-sm btn-outline-success" href="{{ route('vouchers.return-from-sale.export.excel', request()->query()) }}">خروجی Excel</a>
                <a class="btn btn-sm btn-outline-danger" href="{{ route('vouchers.return-from-sale.export.pdf', request()->query()) }}">خروجی PDF</a>
            @endcanPermission
            @canPermission('sales_returns.print')
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('vouchers.return-from-sale.print-report', request()->query()) }}">چاپ گزارش</a>
            @endcanPermission
            <a class="btn btn-sm btn-light" href="{{ route('vouchers.index') }}">بازگشت</a>
        </div>
    </div>

    <form class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small">شماره سند یا حواله</label>
                <input class="form-control form-control-sm" name="document_number" value="{{ $filters['document_number'] ?? '' }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small">مشتری</label>
                <input type="number" class="form-control form-control-sm" name="customer_id" value="{{ $filters['customer_id'] ?? '' }}" placeholder="شناسه مشتری">
            </div>
            <div class="col-md-2">
                <label class="form-label small">از تاریخ</label>
                <input class="form-control form-control-sm" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small">تا تاریخ</label>
                <input class="form-control form-control-sm" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small">نوع برگشت</label>
                <select class="form-select form-select-sm" name="source_type">
                    <option value="all">همه</option>
                    @foreach($sourceLabels as $key => $label)
                        <option value="{{ $key }}" @selected(($filters['source_type'] ?? 'all') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">انبار مقصد</label>
                <select class="form-select form-select-sm" name="destination_warehouse_id">
                    <option value="">همه</option>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected(($filters['destination_warehouse_id'] ?? null) == $warehouse->id)>{{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">کالا</label>
                <input type="number" class="form-control form-control-sm" name="product_id" value="{{ $filters['product_id'] ?? '' }}" placeholder="شناسه کالا">
            </div>
            <div class="col-md-2">
                <label class="form-label small">تنوع</label>
                <input type="number" class="form-control form-control-sm" name="product_variant_id" value="{{ $filters['product_variant_id'] ?? '' }}" placeholder="شناسه تنوع">
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button class="btn btn-sm btn-dark">اعمال فیلتر</button>
                <a class="btn btn-sm btn-light" href="{{ route('vouchers.return-from-sale.index') }}">حذف فیلتر</a>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>ردیف</th>
                    <th>شماره سند یا حواله</th>
                    <th>تاریخ</th>
                    <th>مشتری</th>
                    <th>کالا یا شرح سند</th>
                    <th>تعداد</th>
                    <th>نوع برگشت</th>
                    <th>انبار مقصد</th>
                    <th>مبلغ کل</th>
                    <th>ثبت‌کننده</th>
                    <th>عملیات</th>
                </tr>
                </thead>
                <tbody>
                @forelse($returnRows as $row)
                    <tr data-return-source="{{ $row['source'] }}" data-return-id="{{ $row['source_id'] }}">
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $row['document_number'] }}</td>
                        <td>{{ $row['returned_at_display'] }}</td>
                        <td>{{ $row['customer_name'] }}</td>
                        <td>{{ $row['items_summary'] }}</td>
                        <td>{{ number_format($row['quantity']) }}</td>
                        <td>{{ $row['return_type'] }}</td>
                        <td>{{ $row['destination_warehouse_name'] }}</td>
                        <td>{{ number_format($row['total_amount']) }}</td>
                        <td>{{ $row['created_by_name'] }}</td>
                        <td class="text-nowrap">
                            @if($row['show_url'])
                                <a class="btn btn-sm btn-outline-primary" href="{{ $row['show_url'] }}">مشاهده</a>
                            @endif
                            @if($row['print_url'])
                                <a class="btn btn-sm btn-outline-secondary" href="{{ $row['print_url'] }}">چاپ</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="text-center text-muted py-4">موردی برای نمایش وجود ندارد.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
