@extends('layouts.app')

@php
    $statusTabs = [
        \App\Models\SalesReturnDocument::STATUS_DRAFT => 'پیش‌نویس‌ها',
        \App\Models\SalesReturnDocument::STATUS_APPLIED => 'اعمال‌شده‌ها',
        \App\Models\SalesReturnDocument::STATUS_CANCELLED => 'لغوشده‌ها',
    ];
    $activeStatus = $filters['status'] ?? \App\Models\SalesReturnDocument::STATUS_DRAFT;
    $filterUrl = fn(array $extra = []) => route('sales-returns.index', array_merge(request()->query(), $extra));
    $customerTitle = fn($customer) => $customer ? (trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')) ?: ($customer->mobile ?: ('#'.$customer->id))) : '—';
    $documentReference = fn($document) => $document->isInternal() ? ($document->invoice?->uuid ?: '—') : ($document->external_invoice_number ?: '—');
    $destinations = fn($document) => $document->items->pluck('destinationWarehouse.name')->filter()->unique()->implode('، ') ?: '—';
@endphp

@section('content')
<style>
.sales-return-page{--navy:#1e3a5f;--muted:#64748b;--line:#e2e8f0;--soft:#f8fafc;--green:#147a5c;--orange:#f59e0b;--red:#dc2626;direction:rtl;font-size:13px}.sr-hero{background:linear-gradient(135deg,#f8fafc,#eef6ff);border:1px solid var(--line);border-radius:14px;padding:18px}.sr-title{font-size:23px;font-weight:900;color:var(--navy)}.sr-sub{color:var(--muted);margin-top:4px}.sr-actions{display:flex;gap:7px;flex-wrap:wrap}.sr-actions .btn{font-size:12px;border-radius:9px}.btn-sr-primary{background:var(--green);color:#fff;border-color:var(--green)}.btn-sr-primary:hover{background:#0f684e;color:#fff}.sr-tabs{display:flex;gap:8px;flex-wrap:wrap}.sr-tab{border:1px solid var(--line);background:#fff;color:#334155;padding:9px 14px;border-radius:999px;text-decoration:none;font-weight:800}.sr-tab.active{background:var(--navy);color:#fff;border-color:var(--navy)}.filter-card,.sr-card{border:1px solid var(--line);border-radius:14px;background:#fff}.filter-card .form-label{font-size:12px;color:#475569;margin-bottom:4px}.filter-card .form-control,.filter-card .form-select{font-size:12px;border-radius:9px}.sr-table{margin:0}.sr-table th{background:#f8fafc;color:#475569;font-size:12px;white-space:nowrap}.sr-table td{vertical-align:middle}.badge-draft{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}.badge-applied{background:#ecfdf5;color:#047857;border:1px solid #bbf7d0}.badge-cancelled{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}.mobile-doc-card{display:none;border:1px solid var(--line);border-radius:14px;padding:12px;margin-bottom:10px;background:#fff}.mobile-doc-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.mobile-doc-grid small{display:block;color:var(--muted)}@media(max-width:991.98px){.desktop-list{display:none}.mobile-doc-card{display:block}.sr-actions{width:100%}.sr-actions .btn{flex:1 1 auto}.filter-card .row>[class*=col-]{margin-bottom:6px}}
</style>
<div class="container-fluid sales-return-page">
    <div class="sr-hero mb-3">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div><div class="sr-title">برگشت از فروش</div><div class="sr-sub">مدیریت اسناد برگشت داخلی و سازه‌حساب</div></div>
            <div class="sr-actions">
                @canPermission('sales_returns.create')<a href="{{ route('sales-returns.create') }}" class="btn btn-sr-primary">ثبت برگشت جدید</a>@endcanPermission
                @canPermission('sales_returns.export')<a href="{{ route('sales-returns.export.excel', request()->query()) }}" class="btn btn-outline-success">خروجی Excel</a><a href="{{ route('sales-returns.export.pdf', request()->query()) }}" class="btn btn-outline-danger">خروجی PDF</a><a href="{{ route('sales-returns.export.pdf', request()->query()) }}" class="btn btn-outline-secondary">چاپ گزارش</a>@endcanPermission
                <a href="{{ url()->previous() }}" class="btn btn-outline-secondary">بازگشت</a>
            </div>
        </div>
    </div>

    @include('sales-returns.partials.flash')

    <div class="sr-tabs mb-3">
        @foreach($statusTabs as $status => $label)
            <a class="sr-tab {{ $activeStatus === $status ? 'active' : '' }}" href="{{ $filterUrl(['status' => $status, 'page' => null]) }}">{{ $label }} ({{ number_format($statusCounts[$status] ?? 0) }})</a>
        @endforeach
    </div>

    <div class="filter-card p-3 mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="status" value="{{ $activeStatus }}">
            <div class="col-lg-2 col-md-3"><label class="form-label">شماره سند برگشت</label><input name="document_number" value="{{ $filters['document_number'] ?? '' }}" class="form-control"></div>
            <div class="col-lg-2 col-md-3"><label class="form-label">نوع برگشت</label><select name="source_type" class="form-select"><option value="">همه</option>@foreach($sourceTypeLabels as $key=>$label)<option value="{{ $key }}" @selected(($filters['source_type']??'')===$key)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-lg-2 col-md-3"><label class="form-label">مشتری</label><select name="customer_id" class="form-select searchable-select"><option value="">همه</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" selected>{{ $customerTitle($customer) }}</option>@endforeach</select></div>
            <div class="col-lg-2 col-md-3"><label class="form-label">شماره فاکتور داخلی</label><input name="invoice_number" value="{{ $filters['invoice_number'] ?? '' }}" class="form-control"></div>
            <div class="col-lg-2 col-md-3"><label class="form-label">شماره سازه‌حساب</label><input name="external_invoice_number" value="{{ $filters['external_invoice_number'] ?? '' }}" class="form-control"></div>
            <div class="col-lg-2 col-md-3"><label class="form-label">انبار مقصد</label><select name="destination_warehouse_id" class="form-select"><option value="">همه</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected(($filters['destination_warehouse_id']??null)==$warehouse->id)>{{ $warehouse->name }}</option>@endforeach</select></div>
            <div class="col-lg-2 col-md-3"><label class="form-label">وضعیت کالا</label><select name="item_condition" class="form-select"><option value="">همه</option><option value="healthy" @selected(($filters['item_condition']??'')==='healthy')>سالم</option><option value="damaged" @selected(($filters['item_condition']??'')==='damaged')>معیوب</option></select></div>
            <div class="col-lg-2 col-md-3"><label class="form-label">علت برگشت</label><select name="return_reason" class="form-select"><option value="">همه</option>@foreach($returnReasons as $key=>$label)<option value="{{ $key }}" @selected(($filters['return_reason']??'')===$key)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-lg-2 col-md-3"><label class="form-label">محصول</label><select name="product_id" class="form-select searchable-select"><option value="">همه</option>@foreach($products as $product)<option value="{{ $product->id }}" selected>{{ $product->name }}</option>@endforeach</select></div>
            <div class="col-lg-2 col-md-3"><label class="form-label">تنوع</label><select name="product_variant_id" class="form-select searchable-select"><option value="">همه</option>@foreach($variants as $variant)<option value="{{ $variant->id }}" selected>{{ $variant->variant_name ?: $variant->variant_code }}</option>@endforeach</select></div>
            <div class="col-lg-2 col-md-3"><label class="form-label">ثبت‌کننده</label><select name="created_by" class="form-select"><option value="">همه</option>@foreach($creators as $creator)<option value="{{ $creator->id }}" selected>{{ $creator->name }}</option>@endforeach</select></div>
            <div class="col-lg-1 col-md-3"><label class="form-label">از تاریخ</label><input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="form-control"></div>
            <div class="col-lg-1 col-md-3"><label class="form-label">تا تاریخ</label><input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="form-control"></div>
            <div class="col-lg-2 col-md-12 d-flex gap-2"><button class="btn btn-sm btn-primary flex-fill">اعمال فیلتر</button><a class="btn btn-sm btn-outline-secondary flex-fill" href="{{ route('sales-returns.index', ['status'=>$activeStatus]) }}">پاک‌کردن</a></div>
        </form>
    </div>

    <div class="sr-card desktop-list">
        <div class="table-responsive">
            <table class="table table-hover sr-table align-middle">
                <thead><tr><th>ردیف</th><th>شماره سند</th><th>تاریخ</th><th>نوع برگشت</th><th>مشتری</th><th>شماره فاکتور مرجع</th><th>تعداد اقلام</th><th>مبلغ بستانکاری</th><th>انبار مقصد</th><th>ثبت‌کننده</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                <tbody>
                @forelse($documents as $document)
                    <tr>
                        <td>{{ $documents->firstItem() + $loop->index }}</td><td class="fw-bold">{{ $document->document_number }}</td><td>{{ optional($document->created_at)->format('Y/m/d H:i') }}</td><td>{{ $document->isInternal() ? 'داخلی' : 'سازه‌حساب' }}</td><td>{{ $customerTitle($document->customer) }}</td><td>{{ $documentReference($document) }}</td><td>{{ $document->items_count }}</td><td>{{ number_format((int)$document->refund_total) }} ریال</td><td>{{ $destinations($document) }}</td><td>{{ $document->creator?->name ?: '—' }}</td><td><span class="badge {{ $document->isDraft() ? 'badge-draft' : ($document->isApplied() ? 'badge-applied' : 'badge-cancelled') }}">{{ $document->statusLabel() }}</span></td>
                        <td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="{{ route('sales-returns.show',$document) }}">مشاهده</a>@if($document->isDraft()) <a class="btn btn-sm btn-outline-primary" href="{{ route('sales-returns.edit',$document) }}">ادامه ویرایش</a><form class="d-inline" method="POST" action="{{ route('sales-returns.cancel',$document) }}" onsubmit="return confirm('پیش‌نویس لغو شود؟')">@csrf<button class="btn btn-sm btn-outline-danger">لغو</button></form><a class="btn btn-sm btn-outline-dark" href="{{ route('sales-returns.print',$document) }}">چاپ پیش‌نمایش</a>@elseif($document->isApplied()) <a class="btn btn-sm btn-outline-dark" href="{{ route('sales-returns.print',$document) }}">چاپ سند</a><a class="btn btn-sm btn-outline-danger" href="{{ route('sales-returns.pdf',$document) }}">PDF</a>@endif</td>
                    </tr>
                @empty<tr><td colspan="12" class="text-center text-muted py-5">سندی یافت نشد.</td></tr>@endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mobile-list">
        @foreach($documents as $document)
            <div class="mobile-doc-card"><div class="d-flex justify-content-between"><strong>{{ $document->document_number }}</strong><span class="badge {{ $document->isDraft() ? 'badge-draft' : ($document->isApplied() ? 'badge-applied' : 'badge-cancelled') }}">{{ $document->statusLabel() }}</span></div><div class="mobile-doc-grid mt-2"><div><small>نوع</small><div>{{ $document->isInternal() ? 'داخلی' : 'سازه‌حساب' }}</div></div><div><small>مشتری</small><div>{{ $customerTitle($document->customer) }}</div></div><div><small>مرجع</small><div>{{ $documentReference($document) }}</div></div><div><small>مبلغ</small><div>{{ number_format($document->refund_total) }}</div></div></div><div class="mt-2"><a class="btn btn-sm btn-outline-secondary" href="{{ route('sales-returns.show',$document) }}">مشاهده</a>@if($document->isDraft()) <a class="btn btn-sm btn-outline-primary" href="{{ route('sales-returns.edit',$document) }}">ویرایش</a>@endif</div></div>
        @endforeach
    </div>

    <div class="mt-3">{{ $documents->links() }}</div>
</div>
@endsection
