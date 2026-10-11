@extends('layouts.app')
@section('title','برگشت از خرید')
@push('styles')<link rel="stylesheet" href="{{ asset('css/purchase-returns.css') }}?v=1">@endpush
@section('content')
@php
$fa = fn($value) => strtr((string)$value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
$money = fn($value) => $fa(number_format((int)$value)).' ریال';
@endphp

<div class="pr-page">
    <header class="pr-heading"><div><span class="pr-eyebrow">خرید کالا</span><h1>برگشت از خرید</h1><p>خروج کالا به تأمین‌کننده · انبار مرکزی</p></div><div class="pr-actions"><a class="btn btn-outline-secondary" href="{{ route('purchases.index') }}">خریدها</a><a class="btn btn-primary" href="{{ route('purchase-returns.create') }}">+ برگشت جدید</a></div></header>
    @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <form class="pr-filter" method="get"><input class="form-control" name="q" value="{{ request('q') }}" placeholder="شماره سند یا نام تأمین‌کننده" aria-label="جستجوی سند"><select class="form-select" name="supplier_id" aria-label="تأمین‌کننده"><option value="">همهٔ تأمین‌کننده‌ها</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected(request('supplier_id')==$supplier->id)>{{ $supplier->name }}</option>@endforeach</select><label class="pr-check"><input type="checkbox" name="show_deleted" value="1" @checked(request('show_deleted'))> سوابق حذف‌شده</label><button class="btn btn-primary">جستجو</button><a class="btn btn-outline-secondary" href="{{ route('purchase-returns.index') }}">پاک کردن</a></form>
    <section class="pr-panel"><table class="pr-table"><thead><tr><th>سند</th><th>تاریخ</th><th>تأمین‌کننده</th><th>خرید مرجع</th><th>تعداد</th><th>مبلغ برگشت</th><th>عملیات</th></tr></thead><tbody>
    @forelse($documents as $doc)<tr class="{{ $doc->trashed()?'pr-deleted':'' }}"><td data-label="سند"><a href="{{ route('purchase-returns.show',$doc->id) }}">{{ $fa($doc->document_number) }}</a>@if($doc->trashed())<small class="pr-negative">حذف‌شده</small>@endif</td><td data-label="تاریخ">{{ $fa(\App\Support\JalaliDate::date($doc->returned_at)) }}</td><td data-label="تأمین‌کننده">{{ $doc->supplier_name }}</td><td data-label="خرید مرجع">{{ $fa($doc->purchase_id) }}</td><td data-label="تعداد">{{ $fa(number_format($doc->total_quantity)) }}</td><td data-label="مبلغ">{{ $money($doc->total_amount) }}</td><td data-label="عملیات"><a class="btn btn-sm btn-outline-primary" href="{{ route('purchase-returns.show',$doc->id) }}">مشاهده</a></td></tr>
    @empty<tr><td class="pr-empty" colspan="7">هنوز سند برگشت از خرید ثبت نشده است.</td></tr>@endforelse
    </tbody></table><footer class="pr-footer">{{ $documents->links() }}</footer></section>
</div>
@endsection
