@extends('layouts.app')
@section('title', 'برگشت از خرید')
@push('styles')<link rel="stylesheet" href="{{ asset('css/purchase-returns.css') }}?v={{ substr(hash_file('sha256', public_path('css/purchase-returns.css')), 0, 12) }}">@endpush
@section('content')
@php
    $fa = fn ($value) => strtr((string) $value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    $money = fn ($value) => $fa(number_format((int) $value)).' ریال';
    $user = auth()->user();
    $canCreate = !$user || \App\Support\PageAccessCatalog::userCanRoute($user, 'purchase-returns.create');
    $canEdit = $user && \App\Support\PageAccessCatalog::userCanRoute($user, 'purchase-returns.edit');
    $canCancel = $user && \App\Support\PageAccessCatalog::userCanRoute($user, 'purchase-returns.destroy');
@endphp
<div class="pr-page">
    <header class="pr-heading">
        <div>
            <span class="pr-eyebrow">خرید کالا</span>
            <h1>برگشت از خرید</h1>
            <p>آرشیو اسناد، جست‌وجو و ثبت برگشت جدید از موجودی آزاد انبار مبدأ</p>
        </div>
        <div class="pr-actions">
            <a class="btn btn-outline-secondary" href="{{ route('purchases.index') }}">خریدها</a>
            @if($canCreate)<a class="btn btn-primary" href="{{ route('purchase-returns.create') }}">+ ثبت برگشت جدید</a>@endif
        </div>
    </header>
    @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif

    <form class="pr-filter" method="get" action="{{ route('purchase-returns.index') }}">
        <div class="pr-filter-search">
            <label for="pr-filter-query">جست‌وجو</label>
            <input id="pr-filter-query" class="form-control" name="q" maxlength="100" value="{{ request('q') }}" placeholder="شماره برگشت، تأمین‌کننده یا شماره خرید">
        </div>
        <div>
            <label for="pr-filter-supplier">تأمین‌کننده</label>
            <select id="pr-filter-supplier" class="form-select" name="supplier_id" data-pr-filter-select>
                <option value="">همهٔ تأمین‌کننده‌ها</option>
                @foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected(request('supplier_id') == $supplier->id)>{{ $supplier->name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="pr-filter-warehouse">انبار مبدأ</label>
            <select id="pr-filter-warehouse" class="form-select" name="warehouse_id" data-pr-filter-select>
                <option value="">همهٔ انبارها</option>
                @foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected(request('warehouse_id') == $warehouse->id)>{{ $warehouse->name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="pr-filter-from">از تاریخ شمسی</label>
            <input id="pr-filter-from" class="form-control" name="from_fa" data-jdp data-jdp-only-date dir="ltr" inputmode="numeric" autocomplete="off" placeholder="۱۴۰۵/۰۸/۰۱" value="{{ request('from_fa') }}">
        </div>
        <div>
            <label for="pr-filter-to">تا تاریخ شمسی</label>
            <input id="pr-filter-to" class="form-control" name="to_fa" data-jdp data-jdp-only-date dir="ltr" inputmode="numeric" autocomplete="off" placeholder="۱۴۰۵/۰۸/۳۰" value="{{ request('to_fa') }}">
        </div>
        <div>
            <label for="pr-filter-status">وضعیت</label>
            <select id="pr-filter-status" class="form-select" name="status">
                <option value="active" @selected($status === 'active')>فعال</option>
                <option value="cancelled" @selected($status === 'cancelled')>ابطال‌شده</option>
                <option value="all" @selected($status === 'all')>همه</option>
            </select>
        </div>
        <div class="pr-filter-actions">
            <button class="btn btn-primary" type="submit">اعمال فیلترها</button>
            <a class="btn btn-outline-secondary" href="{{ route('purchase-returns.index') }}">پاک‌کردن</a>
        </div>
    </form>

    <section class="pr-panel">
        <div class="pr-result-count" role="status">{{ $fa(number_format($documents->total())) }} سند یافت شد · جدیدترین ابتدا</div>
        <table class="pr-table pr-archive-table">
            <thead><tr><th>شماره برگشت</th><th>تاریخ</th><th>تأمین‌کننده</th><th>خرید مرجع</th><th>انبار مبدأ</th><th>تعداد کل</th><th>مبلغ برگشت (ریال)</th><th>وضعیت</th><th>عملیات</th></tr></thead>
            <tbody>
            @forelse($documents as $doc)
                <tr class="{{ $doc->trashed() ? 'pr-deleted' : '' }}">
                    <td data-label="شماره برگشت"><a href="{{ route('purchase-returns.show', $doc->id) }}">{{ $fa($doc->document_number) }}</a></td>
                    <td data-label="تاریخ">{{ $fa(\App\Support\JalaliDate::date($doc->returned_at)) }}</td>
                    <td data-label="تأمین‌کننده">{{ $doc->supplier_name }}</td>
                    <td data-label="خرید مرجع">@if($doc->purchase)<a href="{{ route('purchases.show', $doc->purchase_id) }}">{{ $fa($doc->purchase_id) }}</a>@else{{ $fa($doc->purchase_id) }}@endif</td>
                    <td data-label="انبار مبدأ">{{ $doc->warehouse?->name ?: 'انبار #'.$doc->warehouse_id }}</td>
                    <td data-label="تعداد کل">{{ $fa(number_format($doc->total_quantity)) }}</td>
                    <td data-label="مبلغ برگشت" dir="ltr">{{ $money($doc->total_amount) }}</td>
                    <td data-label="وضعیت"><span class="pr-status {{ $doc->trashed() ? 'pr-status-cancelled' : 'pr-status-active' }}">{{ $doc->trashed() ? 'ابطال‌شده' : 'فعال' }}</span></td>
                    <td data-label="عملیات">
                        <div class="pr-row-actions">
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('purchase-returns.show', $doc->id) }}">مشاهده</a>
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-pr-print-url="{{ route('purchase-returns.print', $doc->id) }}">چاپ</button>
                            @unless($doc->trashed())
                                @if($canEdit)<a class="btn btn-sm btn-outline-primary" href="{{ route('purchase-returns.edit', $doc) }}">ویرایش</a>@endif
                                @if($canCancel)
                                    <button class="btn btn-sm btn-outline-danger" type="button" data-pr-cancel-trigger
                                        data-action="{{ route('purchase-returns.destroy', $doc) }}"
                                        data-version="{{ $doc->version }}"
                                        data-number="{{ $doc->document_number }}"
                                        data-supplier="{{ $doc->supplier_name }}"
                                        data-purchase="{{ $doc->purchase_id }}"
                                        data-warehouse="{{ $doc->warehouse?->name ?: 'انبار #'.$doc->warehouse_id }}"
                                        data-items-count="{{ $doc->items_count }}"
                                        data-quantity="{{ $doc->total_quantity }}">ابطال</button>
                                @endif
                            @endunless
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td class="pr-empty" colspan="9">سندی مطابق فیلترهای انتخاب‌شده پیدا نشد.</td></tr>
            @endforelse
            </tbody>
        </table>
        <footer class="pr-footer">{{ $documents->links() }}</footer>
    </section>
    @if($canCancel)@include('purchase-returns._cancel-modal')@endif
</div>
@endsection
@push('scripts')<script src="{{ asset('js/purchase-returns.js') }}?v={{ substr(hash_file('sha256', public_path('js/purchase-returns.js')), 0, 12) }}" defer></script>@endpush
