@extends('layouts.app')
@section('title','رزروهای پیش‌فاکتور')
@push('styles')<link rel="stylesheet" href="{{ asset('css/preinvoice-reservation-report.css') }}?v=20261011">@endpush
@section('content')
<div class="prr">
    <header><div><span>انبارداری</span><h1>رزروهای پیش‌فاکتور</h1><p>کالاهایی که برای پیش‌فاکتورهای جاری کنار گذاشته شده‌اند.</p></div><a href="{{ route('warehouse-reservations.index',$filters) }}" class="prr-button">تازه‌سازی</a></header>
    <div class="prr-stats"><div><span>پیش‌فاکتور</span><strong>{{ number_format($summary->orders_count) }}</strong></div><div><span>کالا / تنوع در سفارش‌ها</span><strong>{{ number_format($summary->rows_count) }}</strong></div><div><span>تعداد رزرو</span><strong>{{ number_format($summary->quantity) }} <small>عدد</small></strong></div></div>
    <form method="GET" action="{{ route('warehouse-reservations.index') }}" class="prr-filters">
        <label>جست‌وجو<input name="q" value="{{ $filters['q']??'' }}" placeholder="پیش‌فاکتور، مشتری، کالا یا مدل" type="search"></label>
        <label>فروشنده<select name="seller_id"><option value="">همه فروشندگان</option>@foreach($sellers as $seller)<option value="{{ $seller->seller_id }}" @selected(($filters['seller_id']??'')==$seller->seller_id)>{{ $seller->seller_name ?: 'کاربر #'.$seller->seller_id }}</option>@endforeach</select></label>
        <label>دسته‌بندی<select name="category_id"><option value="">همه دسته‌ها</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(($filters['category_id']??'')==$category->id)>{{ $category->parent_id?'↳ ':'' }}{{ $category->name }}</option>@endforeach</select></label>
        <button type="submit" class="prr-button prr-primary">نمایش</button><a href="{{ route('warehouse-reservations.index') }}" class="prr-clear">پاک‌کردن فیلتر</a>
    </form>
    <p class="prr-caption">دسته‌بندی انتخاب‌شده، زیر‌دسته‌هایش را هم شامل می‌شود. این صفحه فقط گزارش است؛ آزادسازی طبق روند خودکار نرم‌افزار انجام می‌شود.</p>
    <section class="prr-book"><table><thead><tr><th>پیش‌فاکتور / مشتری</th><th>فروشنده</th><th>کالا / تنوع</th><th>تعداد رزرو</th><th>زمان رزرو</th><th>مهلت باقی‌مانده</th></tr></thead><tbody>
    @forelse($rows as $row)
        @php
            $deadline=$row->stock_frozen_until ?: $row->expires_at;
            $until=$deadline?\Carbon\Carbon::parse($deadline):null;
            $minutes=$until?(int)$evaluatedAt->diffInMinutes($until,false):null;
        @endphp
        <tr><td data-label="پیش‌فاکتور / مشتری"><a href="{{ route('preinvoice.my.show',$row->uuid) }}">{{ $row->uuid }}</a><span>{{ $row->customer_name ?: 'مشتری نامشخص' }}</span><small dir="ltr">{{ $row->customer_mobile }}</small></td>
            <td data-label="فروشنده">{{ $row->seller_name ?: 'نامشخص' }}</td>
            <td data-label="کالا / تنوع"><strong>{{ $row->product_name ?: 'کالای حذف‌شده #'.$row->product_id }}</strong><span>{{ $row->variant_name ?: 'تنوع #'.$row->variant_id }}</span>@if($row->variant_code)<small>{{ $row->variant_code }}</small>@endif</td>
            <td data-label="تعداد رزرو"><b class="prr-quantity">{{ number_format($row->quantity) }}</b></td>
            <td data-label="زمان رزرو">{{ \App\Support\JalaliDate::dateTime($row->reserved_at) }}</td>
            <td data-label="مهلت باقی‌مانده">@if($minutes===null)<span class="prr-badge prr-warning">مهلت ثبت نشده</span>@elseif($minutes<=0)<span class="prr-badge prr-warning">در انتظار آزادسازی خودکار</span>@else<span class="prr-badge">{{ intdiv($minutes,60) }} ساعت و {{ $minutes%60 }} دقیقه</span>@endif @if($until)<small>انقضا: {{ \App\Support\JalaliDate::dateTime($until) }}</small>@endif</td>
        </tr>
    @empty<tr><td colspan="6" class="prr-empty">رزروی با این شرایط پیدا نشد.</td></tr>@endforelse
    </tbody></table><footer><span>{{ $rows->firstItem()?:0 }} تا {{ $rows->lastItem()?:0 }} از {{ number_format($rows->total()) }} ردیف</span>{{ $rows->links() }}</footer></section>
</div>
@endsection
