@extends('layouts.app')

@php
    use Morilog\Jalali\Jalalian;
@endphp

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0">🧾 نمایش فاکتور حواله فروش</h4>
        <div class="text-muted small">این صفحه فقط برای مشاهده اطلاعات ثبت‌شده است.</div>
    </div>
    <a href="{{ url()->previous() }}" class="btn btn-outline-secondary">بازگشت</a>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4"><strong>کد فاکتور:</strong> {{ $invoice->uuid }}</div>
            <div class="col-md-4"><strong>تاریخ:</strong> {{ \App\Support\JalaliDate::dateTime($invoice->display_document_date) }}</div>
            <div class="col-md-4"><strong>مبلغ کل:</strong> {{ \App\Support\Currency::formatRial($invoice->total) }}</div>
            <div class="col-md-4"><strong>مشتری:</strong> {{ $invoice->customer_name ?: '—' }}</div>
            <div class="col-md-4"><strong>شماره تماس:</strong> {{ $invoice->customer_mobile ?: '—' }}</div>
            <div class="col-md-12"><strong>آدرس:</strong> {{ $invoice->customer_address ?: '—' }}</div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">اقلام فاکتور</div>
    <div class="table-responsive">
        <table class="table table-striped align-middle mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>کالا</th>
                    <th>تنوع</th>
                    <th>تعداد</th>
                    <th>قیمت واحد</th>
                    <th>جمع</th>
                </tr>
            </thead>
            <tbody>
            @forelse($invoice->items as $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $item->product?->name ?? ('#' . $item->product_id) }}</td>
                    <td>{{ $item->variant?->variant_name ?: ($item->variant_name ?: '—') }}</td>
                    <td>{{ number_format((int) $item->quantity) }}</td>
                    <td>{{ number_format((int) $item->price) }}</td>
                    <td>{{ number_format((int) $item->line_total) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">آیتمی ثبت نشده است.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
