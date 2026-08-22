@extends('layouts.app')

@section('title', 'جزئیات پورسانت فاکتور')
@section('page-title', 'فاکتور '.$invoice->uuid)

@section('content')
<div class="container-fluid">
    <nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ route('commercial.commissions.index', ['period' => $period->id]) }}">پورسانت فروشندگان</a></li>
        <li class="breadcrumb-item"><a href="{{ route('commercial.commissions.sellers.show', [$period, $seller]) }}">{{ $seller->name }}</a></li>
        <li class="breadcrumb-item active">فاکتور {{ $invoice->uuid }}</li>
    </ol></nav>
    <div class="card mb-3"><div class="card-body"><div class="row g-3">
        <div class="col-md-3"><span class="text-muted">شماره فاکتور</span><div class="fw-bold">{{ $invoice->uuid }}</div></div><div class="col-md-3"><span class="text-muted">تاریخ</span><div>{{ \App\Support\JalaliDate::date($invoice->display_document_date) }}</div></div>
        <div class="col-md-3"><span class="text-muted">مشتری</span><div>{{ $invoice->customer_name ?: '—' }}</div></div><div class="col-md-3"><span class="text-muted">فروشنده</span><div>{{ $seller->name }}</div></div>
        <div class="col-md-3"><span class="text-muted">وضعیت</span><div>{{ $invoice->status }}</div></div><div class="col-md-3"><span class="text-muted">مبلغ فاکتور</span><div>{{ \App\Support\Currency::formatToman($invoice->total) }}</div></div>
        <div class="col-md-3"><span class="text-muted">فروش خالص مشمول</span><div>{{ \App\Support\Currency::formatToman($invoiceSummary['net_sales_amount']) }}</div></div><div class="col-md-3"><span class="text-muted">پورسانت فاکتور</span><div class="fw-bold">{{ \App\Support\Currency::formatToman($invoiceSummary['total_commission_amount']) }}</div></div>
        <div class="col-md-3"><span class="text-muted">اقلام فاقد نرخ</span><div>{{ number_format($invoiceSummary['missing_rate_count']) }}</div></div>
    </div></div></div>
    <div class="card"><div class="card-header"><strong>محاسبه آیتم‌های فاکتور از Snapshot دوره</strong></div><div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th>کالا</th><th>تنوع</th><th>تعداد</th><th>ناخالص</th><th>تخفیف</th><th>خالص</th><th>نرخ پایه</th><th>منبع پایه</th><th>کمپین</th><th>پایه</th><th>کمپین</th><th>نهایی</th></tr></thead>
        <tbody>@foreach($entries as $entry)<tr>
            <td>{{ $entry->product_name_snapshot }}</td><td>{{ $entry->variant_name_snapshot ?: '—' }}</td><td>{{ number_format($entry->quantity_snapshot) }}</td><td>{{ \App\Support\Currency::formatToman($entry->gross_amount_snapshot) }}</td><td>{{ \App\Support\Currency::formatToman($entry->discount_amount_snapshot) }}</td><td>{{ \App\Support\Currency::formatToman($entry->net_amount_snapshot) }}</td>
            <td>@if($entry->missing_rate)<span class="badge bg-warning text-dark">فاقد نرخ</span>@else {{ rtrim(rtrim($entry->base_rate_snapshot, '0'), '.') }}٪ @endif</td><td>{{ ['category'=>'دسته‌بندی','product'=>'کالا','variant'=>'تنوع'][$entry->rate_source_type] ?? 'تعیین‌نشده' }}@if($entry->rate_source_label) — {{ $entry->rate_source_label }}@elseif($entry->rate_source_id) #{{ $entry->rate_source_id }}@endif</td>
            <td>{{ rtrim(rtrim($entry->campaign_rate_snapshot, '0'), '.') }}٪ @if($entry->campaign_name_snapshot) — {{ $entry->campaign_name_snapshot }}@endif</td><td>{{ \App\Support\Currency::formatToman($entry->base_commission_amount) }}</td><td>{{ \App\Support\Currency::formatToman($entry->campaign_commission_amount) }}</td><td class="fw-bold">{{ \App\Support\Currency::formatToman($entry->total_commission_amount) }}</td>
        </tr>@endforeach</tbody><tfoot><tr class="fw-bold"><td colspan="9">جمع</td><td>{{ \App\Support\Currency::formatToman($invoiceSummary['base_commission_amount']) }}</td><td>{{ \App\Support\Currency::formatToman($invoiceSummary['campaign_commission_amount']) }}</td><td>{{ \App\Support\Currency::formatToman($invoiceSummary['total_commission_amount']) }}</td></tr></tfoot>
    </table></div></div>
</div>
@endsection
