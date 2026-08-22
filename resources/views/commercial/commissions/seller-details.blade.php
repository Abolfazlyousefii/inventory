@extends('layouts.app')

@section('title', 'فاکتورهای پورسانت فروشنده')
@section('page-title', 'پورسانت '.$seller->name)

@section('content')
<div class="container-fluid">
    <nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ route('commercial.commissions.index', ['period' => $period->id]) }}">پورسانت فروشندگان</a></li>
        <li class="breadcrumb-item active">{{ $seller->name }}</li>
    </ol></nav>
    <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
        <div><h1 class="h4 mb-1">فاکتورهای {{ $seller->name }}</h1><span class="text-muted">دوره {{ $period->label }}</span></div>
        <a class="btn btn-outline-secondary" href="{{ route('commercial.commissions.index', ['period'=>$period->id]) }}">بازگشت به نمای کلی</a>
    </div>
    @if($conflictingInvoiceIds !== [])
        <div class="alert alert-danger">هشدار یکپارچگی داده: {{ count($conflictingInvoiceIds) }} فاکتور دارای Ledger فعال برای بیش از یک فروشنده است. شناسه‌ها: {{ implode('، ', $conflictingInvoiceIds) }}</div>
    @endif
    <div class="row g-3 mb-3">
        @foreach([
            'فاکتورهای مشمول' => number_format($summary['eligible_invoice_count']),
            'فروش خالص مشمول' => \App\Support\Currency::formatToman($summary['net_sales_amount']),
            'پورسانت فروش فاکتورها' => \App\Support\Currency::formatToman($summary['total_commission_amount']),
            'برگشت از فروش' => \App\Support\Currency::formatToman($summary['return_reversal_amount']),
            'اصلاح انتقال فروشنده' => \App\Support\Currency::formatToman($summary['seller_correction_amount']),
            'تعدیلات دستی تأییدشده' => \App\Support\Currency::formatToman($summary['manual_adjustment_amount']),
            'پورسانت نهایی' => \App\Support\Currency::formatToman($summary['net_commission_amount']),
            'اقلام فاقد نرخ' => number_format($summary['missing_rate_item_count']),
        ] as $label => $value)
            <div class="col-12 col-sm-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="text-muted small">{{ $label }}</div><div class="fw-bold mt-1">{{ $value }}</div></div></div></div>
        @endforeach
    </div>
    <div class="card mb-3"><div class="card-body"><form method="get" class="row g-2 align-items-end">
        <div class="col-12 col-md-5"><label class="form-label">شماره فاکتور یا مشتری</label><input name="q" class="form-control" value="{{ $filters['q'] ?? '' }}"></div>
        <div class="col-12 col-md-3"><label class="form-label">وضعیت</label><input name="status" class="form-control" value="{{ $filters['status'] ?? '' }}" placeholder="مثلاً shipped"></div>
        <div class="col-12 col-md-2 form-check ms-2"><input class="form-check-input" type="checkbox" name="missing_rate" value="1" id="missing-rate" @checked(!empty($filters['missing_rate']))><label class="form-check-label" for="missing-rate">فقط فاقد نرخ</label></div>
        <div class="col-12 col-md-auto"><button class="btn btn-primary">اعمال فیلتر</button></div>
    </form></div></div>
    <div class="card"><div class="card-header"><strong>فاکتورهای مشمول دوره</strong></div><div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th>فاکتور</th><th>تاریخ</th><th>مشتری</th><th>وضعیت</th><th>اقلام</th><th>فروش خالص</th><th>فاقد نرخ</th><th>پایه</th><th>کمپین</th><th>پورسانت فاکتور</th><th></th></tr></thead>
        <tbody>@forelse($invoices as $row)<tr>
            <td>{{ $row->invoice_number_snapshot }}</td><td>{{ \App\Support\JalaliDate::date($row->invoice_date_snapshot) }}</td><td>{{ $row->customer_name ?: '—' }}</td><td>{{ $row->invoice_status ?: '—' }}</td><td>{{ number_format($row->items_count) }}</td>
            <td>{{ \App\Support\Currency::formatToman($row->net_sales_amount) }}</td><td>@if($row->missing_rate_count)<span class="badge bg-warning text-dark">{{ number_format($row->missing_rate_count) }} قلم فاقد نرخ</span>@else ۰ @endif</td>
            <td>{{ \App\Support\Currency::formatToman($row->base_commission_amount) }}</td><td>{{ \App\Support\Currency::formatToman($row->campaign_commission_amount) }}</td><td class="fw-bold">{{ \App\Support\Currency::formatToman($row->total_commission_amount) }}</td>
            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('commercial.commissions.sellers.invoices.show', [$period, $seller, $row->invoice_id]) }}">جزئیات</a></td>
        </tr>@empty<tr><td colspan="11" class="text-center text-muted py-4">فاکتور مشمولی برای این فروشنده یافت نشد.</td></tr>@endforelse</tbody>
    </table></div><div class="card-footer">{{ $invoices->links() }}</div></div>
    <div class="card mt-3"><div class="card-header"><strong>اثر برگشت از فروش</strong></div><div class="table-responsive"><table class="table"><thead><tr><th>سند برگشت</th><th>تاریخ</th><th>فاکتور اصلی</th><th>تعداد</th><th>فروش برگشتی</th><th>پورسانت برگشتی</th></tr></thead><tbody>
        @forelse($returns as $row)<tr><td>{{ $row->salesReturn?->document_number }}</td><td>{{ \App\Support\JalaliDate::dateTime($row->salesReturn?->applied_at) }}</td><td>{{ $row->invoice?->uuid }}</td><td>{{ number_format($row->quantity_delta) }}</td><td>{{ \App\Support\Currency::formatToman($row->net_amount) }}</td><td>{{ \App\Support\Currency::formatToman($row->total_commission_amount) }}</td></tr>
        @empty<tr><td colspan="6" class="text-center text-muted py-4">برگشت از فروشی در این دوره ثبت نشده است.</td></tr>@endforelse
    </tbody></table></div><div class="card-footer">{{ $returns->links() }}</div></div>
    <div class="card mt-3"><div class="card-header"><strong>اصلاحات انتقال فروشنده</strong></div><div class="table-responsive"><table class="table"><thead><tr><th>فاکتور</th><th>دوره مرتبط</th><th>مبلغ</th><th>دلیل</th></tr></thead><tbody>
        @forelse($reassignments as $row)<tr><td>{{ $row->invoice?->uuid }}</td><td>{{ $row->sourcePeriod?->label }}</td><td>{{ \App\Support\Currency::formatToman($row->total_commission_amount) }}</td><td>{{ $row->reason }}</td></tr>
        @empty<tr><td colspan="4" class="text-center text-muted py-4">اصلاح انتقال فروشنده‌ای وجود ندارد.</td></tr>@endforelse
    </tbody></table></div><div class="card-footer">{{ $reassignments->links() }}</div></div>
    <div class="card mt-3"><div class="card-header"><strong>تعدیلات دستی</strong></div><div class="table-responsive"><table class="table"><thead><tr><th>مبلغ</th><th>وضعیت</th><th>توضیح</th><th>تاریخ</th></tr></thead><tbody>
        @forelse($adjustments as $row)<tr><td>{{ \App\Support\Currency::formatToman($row->amount) }}</td><td>{{ $row->status }}</td><td>{{ $row->reason ?? $row->notes ?? '—' }}</td><td>{{ \App\Support\JalaliDate::dateTime($row->created_at) }}</td></tr>
        @empty<tr><td colspan="4" class="text-center text-muted py-4">تعدیل دستی وجود ندارد.</td></tr>@endforelse
    </tbody></table></div><div class="card-footer">{{ $adjustments->links() }}</div></div>
</div>
@endsection
