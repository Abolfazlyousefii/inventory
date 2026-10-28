@extends('layouts.app')
@section('title', 'حواله‌های انبار')
@section('content')
<link rel="stylesheet" href="{{ asset('css/voucher-hub.css') }}?v=20261028a">
@php
    $entries = [
        ['sale', 'حواله فروش', 'پیگیری حواله‌های فروش تأییدشده', route('vouchers.sales.index'), 'M8 3h8l3 3v15l-3-2-4 2-4-2-3 2V3h3 M9 8h6 M9 12h6'],
        ['return', 'برگشت از فروش', 'ثبت و پیگیری مرجوعی مشتری', route('vouchers.return-from-sale.index'), 'M9 4 4 9l5 5 M4 9h10a6 6 0 0 1 0 12'],
        ['personnel', 'تحویل به پرسنل', 'حواله کالا و تجهیزات به همکاران', route('vouchers.section.index', 'personnel'), 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M20 8v6 M17 11h6'],
        ['transfer', 'انتقال بین انبارها', 'جابه‌جایی کالا با تعیین مبدأ و مقصد', route('vouchers.section.index', 'transfer'), 'M4 7h16 M16 3l4 4-4 4 M20 17H4 M8 13l-4 4 4 4'],
        ['showroom', 'حواله شوروم', 'انتقال از انبار مرکزی به شوروم', route('vouchers.section.index', 'showroom'), 'M3 10h18 M5 10v11h14V10 M3 10l2-7h14l2 7 M9 21v-7h6v7'],
        ['scrap', 'ضایعات', 'انتقال کالای معیوب به انبار ضایعات', route('vouchers.section.index', 'scrap'), 'M3 6h18 M9 6V3h6v3 M5 6l1 15h12l1-15 M10 10v7 M14 10v7'],
    ];
@endphp
<div class="voucher-hub">
    <header class="voucher-hub__header">
        <div>
            <p class="voucher-hub__eyebrow">عملیات انبار</p>
            <h1>حواله‌های انبار</h1>
            <p class="voucher-hub__intro">چه کاری می‌خواهید انجام دهید؟</p>
        </div>
        <a class="voucher-hub__history" href="{{ route('warehouse.outputs') }}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7 M3 3v6h6 M12 7v5l3 2"/></svg>
            سوابق ورود و خروج
        </a>
    </header>
    <nav class="voucher-hub__grid" aria-label="انتخاب نوع حواله">
        @foreach($entries as [$key, $title, $description, $url, $icon])
            @continue($key === 'sale' && !\App\Support\PageAccessCatalog::userCanRoute(auth()->user(), 'vouchers.sales.index'))
            @continue($key === 'showroom' && \App\Support\WarehouseVoucherAccess::restricted(auth()->user()))
            <a class="voucher-hub__item voucher-hub__item--{{ $key }}" href="{{ $url }}">
                <span class="voucher-hub__icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $icon }}"/></svg></span>
                <span class="voucher-hub__text"><strong>{{ $title }}</strong><span>{{ $description }}</span></span>
                <svg class="voucher-hub__arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="m14 7-5 5 5 5"/></svg>
            </a>
        @endforeach
    </nav>
    <p class="voucher-hub__hint">برای ثبت حواله جدید یا دیدن سوابق آن، بخش موردنظر را انتخاب کنید.</p>
</div>
@endsection
