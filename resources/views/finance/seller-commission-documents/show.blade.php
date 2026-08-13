@extends('layouts.app')

@section('title', 'سند '.$document->document_number)

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/seller-commission-documents.css') }}?v={{ filemtime(public_path('css/seller-commission-documents.css')) }}">
@endpush

@section('content')
<div class="seller-commission-page">
    <div class="seller-commission-header">
        <div><h1>{{ $document->document_number }}</h1><p>سند فروش و پورسانت فروشنده؛ بدون محاسبه مبلغ پورسانت</p></div>
        <div class="d-flex flex-wrap gap-2"><a class="btn btn-light" href="{{ route('finance.seller-sales.index') }}">بازگشت</a><a class="btn btn-outline-primary" href="{{ route('finance.seller-sales.edit', $document) }}">ویرایش</a><a class="btn btn-primary" href="{{ route('finance.seller-sales.print', $document) }}" target="_blank">چاپ</a></div>
    </div>

    <div class="seller-commission-card p-3 mb-3">
        <div class="seller-commission-meta">
            <div><span>نام فروشنده</span><strong>{{ $document->seller?->name ?: '—' }}</strong></div>
            <div><span>بازه گزارش</span><strong>{{ App\Support\JalaliDate::date($document->period_from) }} تا {{ App\Support\JalaliDate::date($document->period_to) }}</strong></div>
            <div><span>تعداد فاکتورها</span><strong>{{ number_format($document->invoice_count) }}</strong></div>
            <div><span>انتقال‌یافته</span><strong>{{ number_format($document->items->where('status', App\Models\SellerSalesDocumentItem::STATUS_REASSIGNED)->count()) }}</strong></div>
            <div><span>جمع کل فروش</span><strong>{{ App\Support\Currency::formatRial($document->total_sales_amount) }}</strong></div>
            <div><span>ثبت‌کننده سند</span><strong>{{ $document->creator?->name ?: '—' }}</strong></div>
            <div><span>تاریخ ثبت سند</span><strong>{{ App\Support\JalaliDate::dateTime($document->created_at) }}</strong></div>
            <div><span>آخرین ویرایش‌کننده</span><strong>{{ $document->updater?->name ?: '—' }}</strong></div>
            <div><span>توضیحات</span><strong>{{ $document->notes ?: '—' }}</strong></div>
        </div>
    </div>

    <div class="seller-commission-card overflow-hidden">
        <div class="table-responsive">
            <table class="table seller-commission-table mb-0">
                <thead><tr><th>ردیف</th><th>شماره فاکتور</th><th>تاریخ فاکتور</th><th>نام مشتری</th><th>مبلغ نهایی</th><th>وضعیت</th></tr></thead>
                <tbody>
                @foreach($document->items as $item)
                    <tr @class(['seller-commission-row--reassigned' => $item->status === App\Models\SellerSalesDocumentItem::STATUS_REASSIGNED])>
                        <td>{{ $loop->iteration }}</td><td class="fw-bold">{{ $item->invoice_number_snapshot }}</td><td>{{ App\Support\JalaliDate::date($item->invoice_date_snapshot) }}</td><td>{{ $item->customer_name_snapshot }}</td><td>{{ App\Support\Currency::formatRial($item->invoice_total_snapshot) }}</td>
                        <td>
                            @if($item->status === App\Models\SellerSalesDocumentItem::STATUS_REASSIGNED)
                                <span class="badge text-bg-danger">انتقال‌یافته</span>
                                <div class="small mt-1">انتقال به: {{ $item->reassignedToSeller?->name ?: 'نامشخص' }}</div>
                                @if($item->reassigned_at)<div class="small">{{ App\Support\JalaliDate::dateTime($item->reassigned_at) }}</div>@endif
                            @else
                                <span class="badge text-bg-success">موثر</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr class="fw-bold"><td colspan="4">جمع کل موثر</td><td>{{ App\Support\Currency::formatRial($document->total_sales_amount) }}</td><td></td></tr></tfoot>
            </table>
        </div>
    </div>
</div>
@endsection
