@extends('layouts.app')

@section('content')
<div class="container-fluid" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="mb-1">برگشت از فروش</h3>
            <div class="text-muted small">ماژول مستقل فاز اول: پیش‌نویس و پیش‌نمایش بدون اثر مالی یا انباری</div>
        </div>
        @canPermission('sales_returns.create')
        <a href="{{ route('sales-returns.create') }}" class="btn btn-primary">ثبت پیش‌نویس جدید</a>
        @endcanPermission
    </div>

    @include('sales-returns.partials.flash')

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-2"><label class="form-label">وضعیت</label><select name="status" class="form-select"><option value="">همه</option>@foreach(\App\Models\SalesReturnDocument::statusLabels() as $key=>$label)<option value="{{ $key }}" @selected(($filters['status'] ?? '')===$key)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label">نوع</label><select name="source_type" class="form-select"><option value="">همه</option>@foreach(\App\Models\SalesReturnDocument::sourceTypeLabels() as $key=>$label)<option value="{{ $key }}" @selected(($filters['source_type'] ?? '')===$key)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label">شماره سند</label><input name="document_number" value="{{ $filters['document_number'] ?? '' }}" class="form-control"></div>
            <div class="col-md-2"><label class="form-label">شماره سازه‌حساب</label><input name="external_invoice_number" value="{{ $filters['external_invoice_number'] ?? '' }}" class="form-control"></div>
            <div class="col-md-2"><label class="form-label">از تاریخ</label><input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="form-control"></div>
            <div class="col-md-2"><button class="btn btn-outline-primary w-100">فیلتر</button></div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead><tr><th>شماره</th><th>نوع</th><th>وضعیت</th><th>مشتری</th><th>فاکتور/سازه‌حساب</th><th>مبلغ</th><th>تعداد ردیف</th><th>تاریخ</th><th></th></tr></thead>
                <tbody>
                @forelse($documents as $document)
                    <tr>
                        <td>{{ $document->document_number }}</td>
                        <td>{{ $document->sourceTypeLabel() }}</td>
                        <td><span class="badge bg-secondary">{{ $document->statusLabel() }}</span></td>
                        <td>{{ $document->customer?->display_name ?: trim(($document->customer?->first_name ?? '').' '.($document->customer?->last_name ?? '')) }}</td>
                        <td>{{ $document->invoice?->uuid ?: $document->external_invoice_number ?: '—' }}</td>
                        <td>{{ number_format((int)$document->refund_total) }}</td>
                        <td>{{ $document->items_count }}</td>
                        <td>{{ optional($document->created_at)->format('Y-m-d H:i') }}</td>
                        <td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="{{ route('sales-returns.show', $document) }}">نمایش</a>@if($document->isDraft()) <a class="btn btn-sm btn-outline-primary" href="{{ route('sales-returns.edit', $document) }}">ویرایش</a>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">سندی یافت نشد.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $documents->links() }}</div>
    </div>
</div>
@endsection
