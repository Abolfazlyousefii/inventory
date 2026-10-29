@extends('layouts.app')
@section('title', 'آرشیو انبارگردانی')
@push('styles')<link rel="stylesheet" href="{{ asset('css/stocktake-archive.css') }}?v=2">@endpush
@section('content')
@php
    $fa = fn($v) => strtr((string)$v, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    $number = fn($v) => $fa(number_format((int)$v));
    $statuses = ['draft'=>'پیش‌نویس','finalized'=>'اعمال‌شده','cancelled'=>'لغوشده'];
@endphp
<div class="stocktake-archive" dir="rtl">
    <header class="sa-header"><div class="sa-heading"><span class="sa-icon" aria-hidden="true">▤</span><div><span class="sa-eyebrow">کنترل و تطبیق موجودی انبار</span><h1>آرشیو انبارگردانی</h1><p>سوابق شمارش و تغییرات موجودی کالاها</p></div></div><div style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn btn-outline-primary" href="{{ route('stock-count-documents.category-workspace') }}">انبارگردانی دسته‌ای</a><a class="btn btn-outline-primary" href="{{ route('warehouse.stock-flow.index') }}">موجودی در جریان / چاپ گزارش</a><a class="btn btn-primary" href="{{ route('stock-count-documents.create') }}">+ ثبت سند انبارگردانی محصولی</a></div></header>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <div class="sa-stats">
        @foreach([['اسناد مطابق جست‌وجو',$summary['total'],'blue','▤'],['اسناد اعمال‌شده',$summary['finalized'],'purple','✓'],['جمع افزایش موجودی',$summary['increase'],'green','↗'],['جمع کاهش موجودی',$summary['decrease'],'red','↘']] as [$label,$value,$color,$icon])
        <div class="sa-stat {{ $color }}"><span class="sa-stat-icon" aria-hidden="true">{{ $icon }}</span><div><span>{{ $label }}</span><strong>{{ $number($value) }}</strong></div></div>
        @endforeach
    </div>
    <form method="get" action="{{ route('stocktake.index') }}" class="sa-filters">
        <div class="sa-search"><label for="sa-q">جست‌وجو در اسناد</label><input id="sa-q" name="q" type="search" class="form-control" value="{{ $filters['q'] ?? '' }}" placeholder="شماره سند، نام کالا، ثبت‌کننده یا توضیحات"></div>
        <div><label for="sa-warehouse">انبار</label><select id="sa-warehouse" name="warehouse_id" class="form-select"><option value="">همه انبارها</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected((string)($filters['warehouse_id'] ?? '') === (string)$warehouse->id)>{{ $warehouse->name }}</option>@endforeach</select></div>
        <div><label for="sa-status">وضعیت</label><select id="sa-status" name="status" class="form-select"><option value="">همه وضعیت‌ها</option>@foreach($statuses as $key=>$label)<option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="sa-from">از تاریخ سند</label><input id="sa-from" data-jdp data-jdp-only-date name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}" placeholder="۱۴۰۵/۰۷/۰۱" dir="ltr" inputmode="numeric" autocomplete="off"></div>
        <div><label for="sa-to">تا تاریخ سند</label><input id="sa-to" data-jdp data-jdp-only-date name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}" placeholder="۱۴۰۵/۰۷/۳۰" dir="ltr" inputmode="numeric" autocomplete="off"></div>
        @if(!empty($filters['document_number']))<input type="hidden" name="document_number" value="{{ $filters['document_number'] }}">@endif
        <div class="sa-filter-actions"><button class="btn btn-primary">جست‌وجو</button><a class="btn btn-outline-secondary" href="{{ route('stocktake.index') }}">پاک‌کردن فیلترها</a></div>
    </form>
    <section class="sa-list" aria-label="اسناد انبارگردانی"><div class="sa-list-title"><strong>سوابق شمارش موجودی</strong><span>{{ $number($documents->total()) }} سند مطابق جست‌وجو</span></div>
        @if($documents->isEmpty())<div class="sa-empty"><strong>سندی پیدا نشد</strong><p>فیلترها را تغییر دهید یا سند انبارگردانی جدید ثبت کنید.</p></div>@else
        <div class="sa-table-wrap"><table class="sa-table"><thead><tr><th>شماره / تاریخ سند</th><th>محصول / محدوده</th><th>انبار / ثبت‌کننده</th><th>شمارش</th><th>افزایش</th><th>کاهش</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
            @foreach($documents as $doc)
            <tr>
                <td data-label="سند"><a class="sa-code" href="{{ route('stock-count-documents.view',$doc) }}"><bdi>{{ $fa($doc->document_number) }}</bdi></a><time class="sa-subtitle" dir="ltr">{{ $fa(\App\Support\JalaliDate::date($doc->document_date)) }}</time></td>
                <td data-label="محصول / محدوده"><strong class="sa-product">{{ ($doc->type ?? 'product') === 'category' ? ($doc->category?->name ?? 'دسته‌بندی') : ($doc->product?->name ?? '—') }}</strong><span class="sa-subtitle">{{ ($doc->type ?? 'product') === 'category' ? 'انبارگردانی دسته‌ای' : (($doc->type ?? 'product') === 'product' ? 'انبارگردانی محصولی' : 'انبارگردانی نقشه‌ای') }}</span></td>
                <td data-label="انبار / ثبت‌کننده"><span>{{ $doc->warehouse?->name ?? '—' }}</span><span class="sa-subtitle">{{ $doc->creator?->name ?? '—' }}</span></td>
                <td data-label="شمارش"><strong>{{ $number($doc->variants_count ?: $doc->items_count) }} تنوع</strong><span class="sa-subtitle">{{ $number($doc->zeroed_count) }} صفرشده</span></td>
                <td data-label="افزایش"><span class="sa-delta positive">{{ $doc->total_increase > 0 ? '+' : '' }}{{ $number($doc->total_increase) }}</span></td>
                <td data-label="کاهش"><span class="sa-delta {{ $doc->total_decrease > 0 ? 'negative' : 'neutral' }}"><bdi>{{ $doc->total_decrease > 0 ? '−' : '' }}{{ $number($doc->total_decrease) }}</bdi></span></td>
                <td data-label="وضعیت"><span class="sa-status {{ $doc->status }}">{{ $statuses[$doc->status] ?? $doc->status }}</span></td>
                <td data-label="عملیات"><div class="sa-actions"><a class="btn btn-sm btn-outline-primary" href="{{ route('stock-count-documents.view',$doc) }}">مشاهده</a>@if($doc->status==='draft')<a class="btn btn-sm btn-outline-success" href="{{ route('stock-count-documents.edit',$doc) }}">ادامه شمارش</a><form method="post" action="{{ route('stock-count-documents.cancel',$doc) }}">@csrf @method('PATCH')<button class="btn btn-sm btn-outline-secondary">لغو پیش‌نویس</button></form>@endif @if($doc->status==='finalized')<button onclick="print()" class="btn btn-sm btn-outline-dark">چاپ</button>@endif</div></td>
            </tr>
            @endforeach
        </tbody></table></div><div class="sa-pagination">{{ $documents->links() }}</div>
        @endif
    </section>
</div>
@endsection
