@extends('layouts.app')
@section('title', $document ? 'ویرایش برگشت از خرید' : 'برگشت از خرید جدید')
@push('styles')<link rel="stylesheet" href="{{ asset('css/purchase-returns.css') }}?v={{ substr(hash_file('sha256', public_path('css/purchase-returns.css')), 0, 12) }}">@endpush
@section('content')
@php
    $fa = fn ($value) => strtr((string) $value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    $money = fn ($value) => $fa(number_format((int) $value)).' ریال';
@endphp
<div class="pr-page pr-form-page">
    <header class="pr-heading">
        <div class="pr-title">
            <span class="pr-title-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M9 4H5v4 M5 4l5 5 M5 14a7 7 0 1 0 7-9 M9 13l3 2 4-3"/></svg></span>
            <div><span class="pr-eyebrow">انبار مبدأ: {{ $warehouse->name }}</span><h1>{{ $document ? 'ویرایش سند '.$fa($document->document_number) : 'برگشت از خرید جدید' }}</h1><p>ثبت سند، موجودی آزاد همین انبار را بلافاصله کاهش می‌دهد.</p></div>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('purchase-returns.index') }}">بازگشت</a>
    </header>
    @if($errors->any())<div class="alert alert-danger" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif

    @unless($document)
        <form method="get" class="pr-select" id="pr-selection" action="{{ route('purchase-returns.create') }}">
            <div><label for="pr-warehouse">۱. انبار مبدأ</label>
                <select id="pr-warehouse" name="warehouse_id" class="form-select" data-pr-warehouse required>
                    @foreach($warehouses as $source)<option value="{{ $source->id }}" @selected($warehouseId == $source->id)>{{ $source->name }}{{ $source->personnel_name ? ' · '.$source->personnel_name : '' }}</option>@endforeach
                </select>
            </div>
            <div><label for="pr-supplier">۲. تأمین‌کننده</label>
                <select id="pr-supplier" name="supplier_id" class="form-select" data-pr-supplier>
                    <option value="">انتخاب تأمین‌کننده</option>
                    @foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected($supplierId == $supplier->id)>{{ $supplier->name }}</option>@endforeach
                </select>
            </div>
            <div><label for="pr-purchase">۳. سند خرید (جست‌وجوی شماره)</label>
                <select id="pr-purchase" name="purchase_id" class="form-select" data-pr-purchase
                    data-pr-lookup-url="{{ route('purchase-returns.purchases') }}" @disabled(!$supplierId)>
                    <option value="">انتخاب سند خرید</option>
                    @if($purchase && !$purchases->contains('id', $purchase->id))
                        <option value="{{ $purchase->id }}" selected>خرید {{ $fa($purchase->id) }} · {{ $fa(\App\Support\JalaliDate::date($purchase->purchased_at)) }} · {{ $money($purchase->total_amount) }}</option>
                    @endif
                    @foreach($purchases as $item)
                        <option value="{{ $item->id }}" @selected($purchase?->id == $item->id)>خرید {{ $fa($item->id) }} · {{ $fa(\App\Support\JalaliDate::date($item->purchased_at)) }} · {{ $money($item->total_amount) }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn btn-outline-primary" type="submit">نمایش کالاها</button>
        </form>
    @else
        <div class="pr-select pr-fixed-reference">
            <div><span>تأمین‌کننده</span><strong>{{ $document->supplier_name }}</strong></div>
            <div><span>خرید مرجع</span><strong>{{ $fa($purchase->id) }}</strong></div>
            <div><span>انبار مبدأ</span><strong>{{ $warehouse->name }}</strong><small>این مشخصات پس از ثبت ثابت‌اند.</small></div>
        </div>
    @endunless

    @if($purchase)
        <form method="post" action="{{ $document ? route('purchase-returns.update', $document) : route('purchase-returns.store') }}" class="pr-entry" data-pr-entry>
            @csrf
            @if($document)@method('PUT')@endif
            <input type="hidden" name="warehouse_id" value="{{ $warehouseId }}">
            <input type="hidden" name="supplier_id" value="{{ $supplierId }}">
            <input type="hidden" name="purchase_id" value="{{ $purchase->id }}">
            <input type="hidden" name="submission_token" value="{{ $token }}">
            @if($document)<input type="hidden" name="version" value="{{ $document->version }}">@endif

            <div class="pr-meta">
                <div><label for="pr-date">تاریخ برگشت</label><input id="pr-date" name="returned_at_fa" class="form-control" data-jdp data-jdp-only-date value="{{ old('returned_at_fa', \App\Support\JalaliDate::date($document?->returned_at ?? now())) }}" autocomplete="off" inputmode="numeric" required dir="ltr"></div>
                <div><label for="pr-note">توضیحات <small>اختیاری</small></label><input id="pr-note" name="note" class="form-control" maxlength="2000" value="{{ old('note', $document?->note) }}" placeholder="علت برگشت یا توضیح سند"></div>
            </div>
            <section class="pr-panel">
                <div class="pr-section-title">
                    <div><strong>اقلام برگشت از خرید</strong><small>برگشت فقط از اقلام همین خرید و موجودی آزاد انبار مبدأ امکان‌پذیر است.</small></div>
                    <div class="pr-item-tools">
                        <input type="search" class="form-control" data-pr-item-search placeholder="جست‌وجوی کالا، کد یا تنوع" aria-label="جست‌وجو در اقلام">
                        <label class="pr-only-selected"><input type="checkbox" data-pr-only-selected> فقط اقلام انتخاب‌شده</label>
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-pr-zero-all>صفرکردن همه</button>
                    </div>
                </div>
                <table class="pr-table pr-item-table">
                    <thead><tr><th>کالا / تنوع</th><th>تعداد خرید</th><th>برگشت قبلی</th><th>موجودی آزاد مبدأ</th><th>حداکثر برگشت</th><th>قیمت واحد خرید (ریال)</th><th>تعداد جدید</th><th>مبلغ خالص برگشت (ریال)</th></tr></thead>
                    <tbody>
                    @foreach($rows as $i => $row)
                        <tr data-pr-row class="{{ $row->limit > 0 ? 'pr-row-available' : 'pr-row-unavailable' }}"
                            data-stock-key="{{ $row->stockKey }}"
                            data-capacity="{{ $row->capacity }}"
                            data-net-total="{{ $row->netTotal }}"
                            data-purchased-quantity="{{ $row->item->quantity }}"
                            data-previous-quantity="{{ $row->returned }}"
                            data-previous-amount="{{ $row->previousAmount }}">
                            <td data-label="کالا">{{ $row->item->product_name }}<small>{{ $row->item->variant_name ?: 'بدون تنوع' }} · {{ $fa($row->item->product_code) }}</small></td>
                            <td data-label="تعداد خرید">{{ $fa($row->item->quantity) }}</td>
                            <td data-label="برگشت قبلی">{{ $fa($row->returned) }}</td>
                            <td data-label="موجودی آزاد">{{ $fa($row->free) }}</td>
                            <td data-label="حداکثر برگشت">{{ $fa($row->limit) }}</td>
                            <td data-label="قیمت واحد خرید">{{ $fa(number_format($row->price)) }}</td>
                            <td data-label="تعداد جدید">
                                <input type="hidden" name="items[{{ $i }}][purchase_item_id]" value="{{ $row->item->id }}">
                                <input class="form-control pr-quantity" name="items[{{ $i }}][quantity]" type="text"
                                    inputmode="numeric" pattern="[0-9۰-۹٠-٩]+" required
                                    value="{{ old('items.'.$i.'.quantity', $row->old) }}"
                                    aria-label="تعداد برگشت {{ $row->item->product_name }}" data-pr-quantity data-limit="{{ $row->limit }}">
                                <small class="pr-row-error" data-pr-error role="status"></small>
                            </td>
                            <td data-label="مبلغ خالص" data-pr-line-total>۰ ریال</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <p class="pr-no-results" data-pr-no-results hidden>کالایی مطابق جست‌وجو یا فیلتر یافت نشد.</p>
            </section>
            <footer class="pr-save">
                <div class="pr-save-metrics">
                    <div><span>تعداد کل برگشت</span><strong data-pr-total-quantity>۰</strong></div>
                    <div><span>اقلام انتخاب‌شده</span><strong data-pr-selected-count>۰</strong></div>
                    <div><span>مبلغ خالص پس از تخفیف‌ها</span><strong data-pr-total>۰ ریال</strong></div>
                </div>
                <button class="btn btn-primary" type="submit" data-pr-save>{{ $document ? 'بررسی و ذخیره تغییرات' : 'ثبت نهایی برگشت' }}</button>
            </footer>
            <small class="pr-price-note">مبلغ نمایشی برآورد است؛ محاسبه قطعی فقط توسط سرور و بر اساس خرید مرجع انجام می‌شود.</small>

            <dialog class="pr-review-dialog" data-pr-review-dialog aria-labelledby="pr-review-title">
                <h2 id="pr-review-title">بررسی نهایی برگشت از خرید</h2>
                <dl class="pr-cancel-details">
                    <div><dt>تأمین‌کننده</dt><dd>{{ $purchase->supplier?->name ?: $document?->supplier_name }}</dd></div>
                    <div><dt>انبار مبدأ</dt><dd>{{ $warehouse->name }}</dd></div>
                    <div><dt>شماره خرید</dt><dd>{{ $fa($purchase->id) }}</dd></div>
                    <div><dt>تعداد کل برگشت</dt><dd data-pr-review-quantity>۰</dd></div>
                    <div><dt>تعداد اقلام</dt><dd data-pr-review-count>۰</dd></div>
                    <div><dt>مبلغ خالص (ریال)</dt><dd data-pr-review-total>۰</dd></div>
                </dl>
                <p class="pr-review-warning">پس از تأیید، اختلاف تعداد کالاها بلافاصله در موجودی آزاد انبار مبدأ اعمال می‌شود و مبلغ خالص برگشت در حساب تأمین‌کننده ثبت یا اصلاح خواهد شد. مبالغ قطعی را سرور محاسبه می‌کند.</p>
                <div class="pr-cancel-actions"><button type="button" class="btn btn-outline-secondary" data-pr-review-close>بازگشت به فرم</button><button type="button" class="btn btn-primary" data-pr-review-confirm>تأیید و {{ $document ? 'ذخیره' : 'ثبت سند' }}</button></div>
            </dialog>
        </form>
    @else
        <div class="pr-empty pr-panel">انبار مبدأ، تأمین‌کننده و سند خرید را انتخاب کنید تا اقلام قابل برگشت نمایش داده شوند.</div>
    @endif
</div>
@endsection
@push('scripts')<script src="{{ asset('js/purchase-returns.js') }}?v={{ substr(hash_file('sha256', public_path('js/purchase-returns.js')), 0, 12) }}" defer></script>@endpush
