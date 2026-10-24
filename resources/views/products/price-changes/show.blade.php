@extends('layouts.app')
@section('title', 'جزئیات سند تغییر قیمت')
@push('styles')<link rel="stylesheet" href="{{ asset('css/price-change-detail.css') }}?v=1">@endpush
@section('content')
@php
    $isDraft = $document->status === \App\Models\PriceChangeDocument::STATUS_DRAFT;
    $isLegacyDraft = $isDraft && empty($document->scope_payload['uniform_pricing']);
    $validItemsCount = $document->items->whereIn('status', ['valid', 'applied'])->count();
    $invalidItemsCount = $document->items->where('status', 'invalid')->count();
    $fa = fn($value) => strtr((string)$value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    $money = fn($value) => $fa(number_format((float)$value));
    $isPercent = str_contains($document->change_type, 'percent');
@endphp
<div class="price-detail" dir="rtl">
    <header class="pd-header"><div class="pd-heading"><span class="pd-icon" aria-hidden="true">▤</span><div><span class="pd-eyebrow">جزئیات تغییر قیمت فروش</span><h1>{{ $document->title ?: 'سند تغییر قیمت' }}</h1><bdi class="pd-code">{{ $fa($document->code) }}</bdi></div></div><div class="pd-actions"><a class="btn btn-outline-primary" href="{{ route('products.price-changes.index') }}">بازگشت به آرشیو</a>@if($isDraft)<form method="post" action="{{ route('products.price-changes.apply',$document) }}">@csrf<button class="btn btn-success" @disabled($isLegacyDraft)>اعمال سند</button></form><form method="post" action="{{ route('products.price-changes.cancel',$document) }}">@csrf<button class="btn btn-outline-danger">لغو سند</button></form>@endif</div></header>
    @if($isLegacyDraft)<div class="alert alert-warning">این پیش‌نویس با روش قدیمی قیمت‌گذاری تنوع‌ها ثبت شده است و قابل اعمال نیست. برای تنظیم قیمت یکسان، سند جدیدی با پیش‌نمایش به‌روز ایجاد کنید.</div>@endif
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    @if($document->status === 'applied')<div class="pd-notice success"><span aria-hidden="true">✓</span> این سند اعمال شده است؛ قیمت فروش آینده بر اساس آن تغییر کرده است.</div>@elseif($isDraft)<div class="pd-notice warning"><span aria-hidden="true">◷</span> این سند پیش‌نویس است؛ قیمت کالاها هنوز تغییر نکرده است.</div>@endif
    <div class="pd-stats">
        <div class="pd-stat blue"><span>تنوع‌های بررسی‌شده</span><strong>{{ $money($document->items->count()) }}</strong><small>کل اقلام سند</small></div>
        <div class="pd-stat green"><span>اقلام معتبر</span><strong>{{ $money($validItemsCount) }}</strong><small>معتبر یا اعمال‌شده</small></div>
        <div class="pd-stat {{ $invalidItemsCount ? 'red' : 'purple' }}"><span>نیازمند بررسی</span><strong>{{ $money($invalidItemsCount) }}</strong><small>{{ $invalidItemsCount ? 'جزئیات خطا در جدول' : 'بدون خطای اعتبارسنجی' }}</small></div>
    </div>
    <section class="pd-info"><div class="pd-section-title"><strong>مشخصات سند</strong><span class="pd-badge {{ $document->status }}">{{ $document->statusLabel() }}</span></div><dl class="pd-info-grid">
        <div><dt>نوع تغییر</dt><dd>{{ $document->changeTypeLabel() }}</dd></div>
        <div class="pd-amount"><dt>{{ $isPercent ? 'درصد تغییر' : 'مبلغ تغییر / قیمت ثابت' }}</dt><dd>{{ $fa(number_format((float)$document->change_value, $isPercent ? 2 : 0)) }} <small>{{ $isPercent ? '٪' : 'ریال' }}</small></dd></div>
        <div><dt>محدوده</dt><dd>{{ $document->scopeLabel() }}</dd></div><div><dt>گرد کردن</dt><dd>{{ $document->roundingLabel() }}</dd></div>
        <div><dt>ثبت‌کننده</dt><dd>{{ $document->createdBy?->name ?: '—' }}</dd></div><div><dt>تاریخ ثبت</dt><dd><time dir="ltr">{{ $fa(\App\Support\JalaliDate::dateTime($document->created_at)) }}</time></dd></div>
        <div><dt>اعمال‌کننده</dt><dd>{{ $document->appliedBy?->name ?: '—' }}</dd></div><div><dt>تاریخ اعمال</dt><dd><time dir="ltr">{{ $fa(\App\Support\JalaliDate::dateTime($document->applied_at)) }}</time></dd></div>
        @if($document->note)<div class="pd-note"><dt>یادداشت سند</dt><dd>{{ $document->note }}</dd></div>@endif
    </dl></section>
    <section class="pd-items"><div class="pd-section-title"><div><strong>جزئیات قیمت تنوع‌ها</strong><p>مقایسهٔ قیمت قبل و بعد هر قلم</p></div><span>تمام مبالغ به ریال</span></div>
        <div class="pd-table-wrap"><table class="pd-table"><thead><tr><th>کالا / تنوع</th><th>کد کالا</th><th>قیمت قبل</th><th>قیمت بعد</th><th>تغییر مبلغ / درصد</th><th>وضعیت</th>@if($invalidItemsCount)<th>دلیل خطا</th>@endif<th>تاریخ اعمال</th></tr></thead><tbody>
            @forelse($document->items as $item)
            @php
                $diff = $item->new_price - $item->old_price;
                $percent = $item->old_price > 0 ? round(($diff / $item->old_price) * 100, 2) : null;
                $isInvalid = $item->status === 'invalid';
            @endphp
            <tr @class(['pd-invalid'=>$isInvalid])>
                <td class="pd-product" data-label="کالا / تنوع"><strong>{{ $item->variant_name_snapshot ?: $item->product_name_snapshot }}</strong><span>{{ $item->product_name_snapshot }}</span></td>
                <td data-label="کد کالا"><bdi class="pd-sku">{{ $fa($item->sku_snapshot ?: '—') }}</bdi></td>
                <td data-label="قیمت قبل" class="pd-old">{{ $money($item->old_price) }}</td>
                <td data-label="قیمت بعد"><strong class="pd-new">{{ $money($item->new_price) }}</strong></td>
                <td data-label="تغییر مبلغ / درصد"><span class="pd-difference {{ $diff > 0 ? 'increase' : ($diff < 0 ? 'decrease' : 'unchanged') }}"><bdi>{{ $diff > 0 ? '+' : '' }}{{ $money($diff) }}</bdi></span><small class="pd-percent">{{ $percent === null ? 'درصد قابل محاسبه نیست' : $fa($percent).'٪' }}</small></td>
                <td data-label="وضعیت"><span class="pd-badge {{ $item->status }}">{{ $isInvalid ? 'نیازمند بررسی' : ($item->status === 'applied' ? 'اعمال‌شده' : 'معتبر') }}</span></td>
                @if($invalidItemsCount)<td data-label="دلیل خطا" class="pd-error">{{ $item->error_message ?: '—' }}</td>@endif
                <td data-label="تاریخ اعمال"><time dir="ltr">{{ $fa(\App\Support\JalaliDate::dateTime($item->applied_at)) }}</time></td>
            </tr>
            @empty<tr><td colspan="8" class="pd-empty">قلمی در این سند ثبت نشده است.</td></tr>@endforelse
        </tbody></table></div>
        <footer class="pd-footer">{{ $money($document->items->count()) }} قلم در این سند</footer>
    </section>
</div>
@endsection
