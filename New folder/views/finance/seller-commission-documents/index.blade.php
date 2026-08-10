@extends('layouts.app')

@section('title', 'اسناد فروش و پورسانت فروشندگان')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/seller-commission-documents.css') }}?v={{ filemtime(public_path('css/seller-commission-documents.css')) }}">
@endpush

@section('content')
<div class="seller-commission-page">
    <div class="seller-commission-header">
        <div>
            <h1>اسناد فروش و پورسانت فروشندگان</h1>
            <p>ثبت و مدیریت فاکتورهای منتخب هر کاربر برای محاسبات واحد مالی؛ بدون محاسبه مبلغ پورسانت</p>
        </div>
        <a class="btn btn-primary" href="{{ route('finance.seller-sales.create') }}">ثبت سند جدید</a>
    </div>

    <form method="GET" class="seller-commission-card seller-commission-filters">
        <div class="row g-3 align-items-end">
            <div class="col-lg-3 col-md-6">
                <label class="form-label" for="document_number">شماره سند</label>
                <input class="form-control" id="document_number" name="document_number" value="{{ request('document_number') }}" placeholder="SC-000001">
            </div>
            <div class="col-lg-3 col-md-6">
                <label class="form-label" for="user_id">فروشنده</label>
                <select class="form-select" id="user_id" name="user_id">
                    <option value="">همه کاربران</option>
                    @foreach($users as $user)
                        <option value="{{ $user->id }}" @selected((string) request('user_id') === (string) $user->id)>{{ $user->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-lg-2 col-md-6">
                <label class="form-label" for="date_from">از تاریخ</label>
                <input class="form-control" id="date_from" name="date_from" data-jdp value="{{ request('date_from') }}" autocomplete="off">
            </div>
            <div class="col-lg-2 col-md-6">
                <label class="form-label" for="date_to">تا تاریخ</label>
                <input class="form-control" id="date_to" name="date_to" data-jdp value="{{ request('date_to') }}" autocomplete="off">
            </div>
            <div class="col-lg-2 d-flex gap-2">
                <button class="btn btn-outline-primary flex-grow-1">اعمال فیلتر</button>
                <a class="btn btn-light" href="{{ route('finance.seller-sales.index') }}">پاک‌کردن</a>
            </div>
        </div>
    </form>

    <div class="seller-commission-card overflow-hidden">
        <div class="table-responsive">
            <table class="table seller-commission-table mb-0">
                <thead><tr><th>شماره سند</th><th>فروشنده</th><th>از تاریخ</th><th>تا تاریخ</th><th>تعداد فاکتور</th><th>جمع کل فروش</th><th>تاریخ ثبت</th><th>ثبت‌کننده</th><th>عملیات</th></tr></thead>
                <tbody>
                @forelse($documents as $document)
                    <tr>
                        <td class="fw-bold">{{ $document->document_number }}</td>
                        <td>{{ $document->seller?->name ?: '—' }}</td>
                        <td>{{ App\Support\JalaliDate::date($document->period_from) }}</td>
                        <td>{{ App\Support\JalaliDate::date($document->period_to) }}</td>
                        <td>{{ number_format($document->invoice_count) }}</td>
                        <td>{{ App\Support\Currency::formatRial($document->total_sales_amount) }}</td>
                        <td>{{ App\Support\JalaliDate::dateTime($document->created_at) }}</td>
                        <td>{{ $document->creator?->name ?: '—' }}</td>
                        <td><div class="seller-commission-actions"><a href="{{ route('finance.seller-sales.show', $document) }}">مشاهده</a><a href="{{ route('finance.seller-sales.edit', $document) }}">ویرایش</a><a href="{{ route('finance.seller-sales.print', $document) }}" target="_blank">چاپ</a></div></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="seller-commission-empty">هنوز سندی ثبت نشده است.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $documents->links() }}</div>
</div>
@endsection
