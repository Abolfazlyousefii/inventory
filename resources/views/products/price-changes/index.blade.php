@extends('layouts.app')
@section('title', 'آرشیو تغییرات قیمت')
@push('styles')<link rel="stylesheet" href="{{ asset('css/price-change-archive.css') }}?v=1">@endpush
@section('content')
@php($fa = fn($v) => strtr((string)$v, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']))
<div class="price-archive" dir="rtl">
    <header class="pa-header"><div class="pa-heading"><span class="pa-icon" aria-hidden="true">↗</span><div><span class="pa-eyebrow">مدیریت قیمت فروش</span><h1>آرشیو تغییرات قیمت</h1><p>سوابق قیمت‌گذاری کالاها و تنوع‌ها، در یک نگاه</p></div></div><a class="btn btn-primary" href="{{ route('products.price-changes.create') }}">+ ثبت سند جدید</a></header>
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('status'))<div class="alert alert-info">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <div class="pa-stats">
        @foreach([['کل نتایج',$stats['total'],'blue','▤'],['اعمال‌شده',$stats['applied'],'green','✓'],['پیش‌نویس',$stats['draft'],'amber','✎'],['ثبت امروز',$stats['today'],'purple','◷']] as [$label,$value,$color,$icon])
        <div class="pa-stat {{ $color }}"><span class="pa-stat-icon" aria-hidden="true">{{ $icon }}</span><div><span>{{ $label }}</span><strong>{{ $fa(number_format($value)) }}</strong></div></div>
        @endforeach
    </div>
    <form method="get" action="{{ route('products.price-changes.index') }}" class="pa-filters">
        <div class="pa-search"><label for="pa-q">جست‌وجوی اسناد</label><input id="pa-q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="کد سند، عنوان، کالا، دسته‌بندی یا ثبت‌کننده" class="form-control"></div>
        <div><label for="pa-category">دسته‌بندی</label><select id="pa-category" name="category_id" class="form-select"><option value="">همه دسته‌ها</option>@foreach($categoryOptions as $category)<option value="{{ $category['id'] }}" @selected((string)($filters['category_id'] ?? '') === (string)$category['id'])>{{ $category['name'] }}</option>@endforeach</select></div>
        <div><label for="pa-status">وضعیت سند</label><select id="pa-status" name="status" class="form-select"><option value="">همه وضعیت‌ها</option>@foreach(\App\Models\PriceChangeDocument::statusLabels() as $key=>$label)<option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="pa-from">از تاریخ ثبت</label><input id="pa-from" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}" placeholder="۱۴۰۵/۰۷/۰۱" inputmode="numeric" dir="ltr" autocomplete="off"></div>
        <div><label for="pa-to">تا تاریخ ثبت</label><input id="pa-to" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}" placeholder="۱۴۰۵/۰۷/۳۰" inputmode="numeric" dir="ltr" autocomplete="off"></div>
        <div class="pa-filter-actions"><button class="btn btn-primary">جست‌وجو</button><a class="btn btn-outline-secondary" href="{{ route('products.price-changes.index') }}">پاک‌کردن</a></div>
    </form>
    <section class="pa-list" aria-label="اسناد تغییر قیمت">
        <div class="pa-list-title"><strong>سوابق تغییر قیمت</strong><span>{{ $fa(number_format($documents->total())) }} سند مطابق جست‌وجو</span></div>
        @if($documents->isEmpty())
            <div class="pa-empty"><strong>سندی پیدا نشد</strong><p>عبارت جست‌وجو یا فیلترها را تغییر دهید.</p><a href="{{ route('products.price-changes.index') }}">نمایش همه اسناد</a></div>
        @else
        <div class="pa-table-wrap"><table class="pa-table"><thead><tr><th>سند / عنوان</th><th>دسته‌بندی / کالا</th><th>تغییر قیمت</th><th>وضعیت / تعداد تنوع</th><th>ثبت سند</th><th>تاریخ اعمال</th><th>عملیات</th></tr></thead><tbody>
            @foreach($documents as $document)
            <tr>
                <td data-label="سند"><a class="pa-code" href="{{ route('products.price-changes.show',$document) }}"><bdi>{{ $fa($document->code ?? $document->id) }}</bdi></a><span class="pa-subtitle">{{ $document->title ?: 'بدون عنوان' }}</span></td>
                <td data-label="دسته‌بندی / کالا"><strong class="pa-category">{{ $document->archive_category }}</strong><span class="pa-subtitle">{{ $document->archive_product ?: $document->scopeLabel() }}</span></td>
                <td data-label="تغییر قیمت"><span class="pa-change {{ str_starts_with($document->change_type,'decrease') ? 'down' : 'up' }}">{{ $document->changeTypeLabel() }}</span><strong class="pa-change-value">{{ $fa(number_format((float)$document->change_value, str_contains($document->change_type,'percent') ? 2 : 0)) }} {{ str_contains($document->change_type,'percent') ? '٪' : 'ریال' }}</strong></td>
                <td data-label="وضعیت"><span class="pa-status {{ $document->status }}">{{ $document->statusLabel() }}</span><span class="pa-subtitle">{{ $fa(number_format($document->items_count)) }} تنوع</span></td>
                <td data-label="ثبت سند"><time dir="ltr">{{ $fa(\App\Support\JalaliDate::dateTime($document->created_at)) }}</time><span class="pa-subtitle">{{ $document->createdBy?->name ?: '—' }}</span></td>
                <td data-label="اعمال"><time dir="ltr">{{ $fa(\App\Support\JalaliDate::dateTime($document->applied_at)) }}</time></td>
                <td data-label="عملیات"><div class="pa-actions"><a class="btn btn-sm btn-outline-primary" href="{{ route('products.price-changes.show',$document) }}">مشاهده</a>@if($document->status === 'draft')<form method="post" action="{{ route('products.price-changes.apply',$document) }}">@csrf<button class="btn btn-sm btn-success">اعمال</button></form><form method="post" action="{{ route('products.price-changes.cancel',$document) }}">@csrf<button class="btn btn-sm btn-outline-danger">لغو</button></form>@endif</div></td>
            </tr>
            @endforeach
        </tbody></table></div><div class="pa-pagination">{{ $documents->links() }}</div>
        @endif
    </section>
</div>
@endsection
