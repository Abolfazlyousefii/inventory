@extends('layouts.app')
@section('title', 'کارتکس فروش کالا')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/product-sales-ledger.css') }}?v=1">
@endpush
@section('content')
@php
    $fa = fn($v) => strtr((string) $v, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    $number = fn($v) => $fa(number_format((int) $v));
@endphp
<div class="sales-ledger" dir="rtl">
    <header class="sl-header">
        <div class="sl-heading"><span class="sl-icon" aria-hidden="true">↗</span><div><span class="sl-eyebrow">سوابق فروش کالا</span><h1>کارتکس فروش</h1><p>{{ $product->name }} @if($selectedVariant)<span> / {{ $selectedVariant->variant_name }}</span>@endif</p></div></div>
        <div class="sl-actions sl-no-print"><button type="button" class="btn btn-outline-primary" onclick="window.print()">چاپ</button><a class="btn btn-outline-success" href="{{ request()->fullUrlWithQuery(['export'=>'csv', 'page'=>null]) }}">خروجی اکسل (CSV)</a><a class="btn btn-outline-secondary" href="{{ route('products.index') }}">بازگشت به کالاها</a></div>
    </header>
    <div class="sl-stats">
        <div class="sl-stat blue"><span class="sl-stat-icon" aria-hidden="true">▤</span><div><span>ردیف‌های فروش</span><strong>{{ $number($ledgerItems->total()) }} <small>ردیف</small></strong><p>مطابق فیلترهای انتخاب‌شده</p></div></div>
        <div class="sl-stat purple"><span class="sl-stat-icon" aria-hidden="true">▦</span><div><span>تعداد فروش کالا</span><strong>{{ $number($summary->total_quantity ?? 0) }} <small>عدد</small></strong><p>مجموع تعداد در همه نتایج</p></div></div>
        <div class="sl-stat green"><span class="sl-stat-icon" aria-hidden="true">↗</span><div><span>جمع مبلغ فروش</span><strong>{{ $number($summary->total_amount ?? 0) }} <small>ریال</small></strong><p>مجموع مبلغ ردیف‌های فروش</p></div></div>
    </div>
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <form method="get" action="{{ route('products.sales-ledger', $product) }}" class="sl-filters sl-no-print">
        <div class="sl-section-heading"><strong>جست‌وجو در فروش‌ها</strong><span>نام مشتری، شماره فاکتور یا بازهٔ فروش را مشخص کنید</span></div>
        <div class="sl-filter-grid">
            <div class="sl-customer-search"><label for="sl-customer-search">جست‌وجوی مشتری</label><input id="sl-customer-search" type="search" name="customer_search" value="{{ request('customer_search') }}" class="form-control" placeholder="نام، نام خانوادگی یا شماره موبایل" maxlength="150"></div>
            <div><label for="sl-invoice">شماره فاکتور</label><input id="sl-invoice" name="invoice_uuid" value="{{ request('invoice_uuid') }}" class="form-control" placeholder="مثلاً ۰۲۰۱۹"></div>
            <div><label for="sl-from">از تاریخ ثبت فروش</label><input id="sl-from" type="date" name="date_from" value="{{ request('date_from') }}" class="form-control"></div>
            <div><label for="sl-to">تا تاریخ ثبت فروش</label><input id="sl-to" type="date" name="date_to" value="{{ request('date_to') }}" class="form-control"></div>
            <div><label for="sl-customer">مشتری مشخص</label><select id="sl-customer" name="customer_id" class="form-select"><option value="">همه مشتریان</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((int) request('customer_id') === $customer->id)>{{ trim(($customer->first_name ?? '').' '.($customer->last_name ?? '')) ?: 'مشتری #'.$customer->id }}</option>@endforeach</select></div>
            @if($variants->isNotEmpty())<div><label for="sl-variant">تنوع / مدل</label><select id="sl-variant" name="variant_id" class="form-select"><option value="">همه تنوع‌های کالا</option>@foreach($variants as $variant)<option value="{{ $variant->id }}" @selected((int) request('variant_id') === $variant->id)>{{ $variant->variant_name }} ({{ $fa($variant->variant_code) }})</option>@endforeach</select></div>@endif
            <div><label for="sl-creator">ثبت‌کننده</label><select id="sl-creator" name="creator_id" class="form-select"><option value="">همه ثبت‌کنندگان</option>@foreach($creators as $creator)<option value="{{ $creator->id }}" @selected((int) request('creator_id') === $creator->id)>{{ $creator->name }}</option>@endforeach</select></div>
        </div>
        @if(request()->boolean('only_without_variant'))<input type="hidden" name="only_without_variant" value="1">@endif
        <div class="sl-filter-footer"><span>خلاصه و خروجی اکسل بر اساس همین فیلترها هستند.</span><div class="sl-actions"><button class="btn btn-primary">جست‌وجو</button><a class="btn btn-outline-secondary" href="{{ route('products.sales-ledger', $product) }}">پاک‌کردن فیلترها</a></div></div>
    </form>
    <section class="sl-list" aria-label="سوابق فروش">
        <div class="sl-section-heading"><strong>جزئیات فروش {{ $product->name }}</strong><span>{{ $number($ledgerItems->total()) }} ردیف @if($ledgerItems->total()) · نمایش {{ $number($ledgerItems->firstItem()) }} تا {{ $number($ledgerItems->lastItem()) }} @endif</span></div>
        @if($ledgerItems->isEmpty())<div class="sl-empty"><span aria-hidden="true">⌕</span><strong>فروشی با این مشخصات پیدا نشد</strong><p>نام مشتری یا فیلترهای انتخاب‌شده را بررسی کنید.</p></div>@else
        <div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>فاکتور / تاریخ فروش</th><th>مشتری</th><th>تنوع / مدل</th><th>تعداد</th><th>قیمت واحد <small>ریال</small></th><th>مبلغ ردیف <small>ریال</small></th><th>ثبت‌کننده</th><th class="sl-no-print">عملیات</th></tr></thead><tbody>
            @foreach($ledgerItems as $item)
            <tr>
                <td data-label="فاکتور / تاریخ"><strong class="sl-code"><bdi>{{ $fa($item->invoice?->uuid ?? '—') }}</bdi></strong><time class="sl-sub" dir="ltr">{{ $fa(\App\Support\JalaliDate::dateTime($item->invoice?->display_document_date)) }}</time></td>
                <td data-label="مشتری"><strong>{{ $item->invoice?->customer_name ?? '—' }}</strong><span class="sl-sub"><bdi>{{ $fa($item->invoice?->customer_mobile ?? '') }}</bdi></span></td>
                <td data-label="تنوع / مدل">{{ $item->variant?->variant_name ?? 'بدون تنوع' }}</td>
                <td data-label="تعداد"><span class="sl-quantity">{{ $number($item->quantity) }}</span></td>
                <td data-label="قیمت واحد (ریال)"><bdi>{{ $number($item->price) }}</bdi></td>
                <td data-label="مبلغ ردیف (ریال)"><strong class="sl-amount"><bdi>{{ $number($item->line_total) }}</bdi></strong></td>
                <td data-label="ثبت‌کننده">{{ $item->invoice?->preinvoiceOrder?->creator?->name ?? '—' }}</td>
                <td data-label="عملیات" class="sl-no-print">@if($item->invoice?->uuid && $item->can_view_invoice)<a class="btn btn-sm btn-outline-primary" href="{{ route('invoices.show', $item->invoice->uuid) }}">مشاهده فاکتور</a>@else<span class="text-muted">—</span>@endif</td>
            </tr>
            @endforeach
        </tbody></table></div>
        <div class="sl-pagination sl-no-print">{{ $ledgerItems->links() }}</div>
        @endif
    </section>
    <p class="sl-print-note">مبالغ به ریال · چاپ ردیف‌های صفحهٔ جاری؛ برای دریافت همهٔ نتایج از خروجی اکسل استفاده کنید.</p>
</div>
@endsection
