@extends('layouts.app')
@section('title',$document?'ویرایش برگشت از خرید':'برگشت از خرید جدید')
@push('styles')<link rel="stylesheet" href="{{ asset('css/purchase-returns.css') }}?v=1">@endpush
@section('content')
@php
$fa = fn($value) => strtr((string)$value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
$money = fn($value) => $fa(number_format((int)$value)).' ریال';
@endphp

<div class="pr-page">
    <header class="pr-heading"><div><span class="pr-eyebrow">انبار مرکزی</span><h1>{{ $document?'ویرایش سند '.$fa($document->document_number):'برگشت از خرید جدید' }}</h1><p>با ثبت، تعداد برگشت از موجودی آزاد کم می‌شود.</p></div><a class="btn btn-outline-secondary" href="{{ route('purchase-returns.index') }}">بازگشت</a></header>
    @if($errors->any())<div class="alert alert-danger" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
    @if(!$document)
    <form method="get" class="pr-select" id="pr-selection"><div><label for="pr-supplier">تأمین‌کننده</label><select id="pr-supplier" name="supplier_id" class="form-select" data-pr-supplier><option value="">انتخاب تأمین‌کننده</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected($supplierId==$supplier->id)>{{ $supplier->name }}</option>@endforeach</select></div><div><label for="pr-purchase">سند خرید</label><select id="pr-purchase" name="purchase_id" class="form-select" data-pr-purchase @disabled(!$supplierId)><option value="">انتخاب خرید</option>@foreach($purchases as $item)<option value="{{ $item->id }}" @selected($purchase?->id==$item->id)>خرید {{ $fa($item->id) }} · {{ $fa(\App\Support\JalaliDate::date($item->purchased_at)) }} · {{ $money($item->total_amount) }}</option>@endforeach</select></div><button class="btn btn-outline-primary" type="submit">نمایش کالاها</button></form>
    @else<div class="pr-select"><span>تأمین‌کننده <strong>{{ $document->supplier_name }}</strong></span><span>خرید مرجع <strong>{{ $fa($purchase->id) }}</strong></span></div>@endif
    @if($purchase)
    <form method="post" action="{{ $document?route('purchase-returns.update',$document):route('purchase-returns.store') }}" class="pr-entry" data-pr-entry>
        @csrf @if($document) @method('PUT') @endif
        <input type="hidden" name="supplier_id" value="{{ $supplierId }}"><input type="hidden" name="purchase_id" value="{{ $purchase->id }}"><input type="hidden" name="submission_token" value="{{ $token }}">@if($document)<input type="hidden" name="version" value="{{ $document->version }}">@endif
        <div class="pr-meta"><div><label for="pr-date">تاریخ برگشت</label><input id="pr-date" name="returned_at_fa" class="form-control" data-jdp data-jdp-only-date value="{{ old('returned_at_fa',\App\Support\JalaliDate::date($document?->returned_at??now())) }}" autocomplete="off" inputmode="numeric" required dir="ltr"></div><div><label for="pr-note">توضیحات <small>اختیاری</small></label><input id="pr-note" name="note" class="form-control" maxlength="2000" value="{{ old('note',$document?->note) }}" placeholder="علت برگشت یا توضیح سند"></div></div>
        <section class="pr-panel"><table class="pr-table pr-item-table"><thead><tr><th>کالا / تنوع</th><th>تعداد خرید</th><th>برگشت قبلی</th><th>موجودی آزاد</th><th>قیمت خرید · ریال</th><th>تعداد برگشت</th><th>جمع · ریال</th></tr></thead><tbody>
        @foreach($rows as $i=>$row)<tr data-pr-row data-stock-key="{{ $row->stockKey }}" data-capacity="{{ $row->capacity }}" data-price="{{ $row->price }}"><td data-label="کالا">{{ $row->item->product_name }}<small>{{ $row->item->variant_name ?: 'بدون تنوع' }} · {{ $fa($row->item->product_code) }}</small></td><td data-label="خرید">{{ $fa($row->item->quantity) }}</td><td data-label="برگشت قبلی">{{ $fa($row->returned) }}</td><td data-label="موجودی آزاد">{{ $fa($row->free) }}</td><td data-label="قیمت خرید">{{ $fa(number_format($row->price)) }}</td><td data-label="تعداد برگشت"><input type="hidden" name="items[{{ $i }}][purchase_item_id]" value="{{ $row->item->id }}"><input class="form-control pr-quantity" name="items[{{ $i }}][quantity]" type="text" inputmode="numeric" pattern="[0-9۰-۹٠-٩]+" value="{{ old('items.'.$i.'.quantity',$row->old) }}" aria-label="تعداد برگشت {{ $row->item->product_name }}" data-pr-quantity data-limit="{{ $row->limit }}"><small>حداکثر {{ $fa($row->limit) }}</small></td><td data-label="جمع" data-pr-line-total>۰</td></tr>@endforeach
        </tbody></table></section>
        <footer class="pr-save"><div><span>جمع مبلغ برگشت</span><strong data-pr-total>۰ ریال</strong><small>صرفاً مبلغ سند؛ بدون ثبت مالی</small></div><button class="btn btn-primary" type="submit">{{ $document?'ذخیرهٔ تغییرات':'ثبت برگشت' }}</button></footer>
    </form>
    @else<div class="pr-empty pr-panel">تأمین‌کننده و سند خرید را انتخاب کنید.</div>@endif
</div>
@endsection
@push('scripts')<script src="{{ asset('js/purchase-returns.js') }}?v=1" defer></script>@endpush
