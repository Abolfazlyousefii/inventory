@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('css/warehouse-vouchers.css') }}?v=20261024">

<div class="container voucher-page">
    <div class="hero-box">
        <div class="hero-title">حواله‌های انبار</div>
        <p class="hero-sub">
            نوع عملیات را انتخاب کنید؛ سوابق تحویل، مرجوعی و انتقال کالا در دسترس شماست.
        </p>

        <div class="hero-actions">
            <a class="btn btn-primary rounded-4 px-4 fw-bold" href="{{ route('warehouse.outputs') }}">
                ورودی و خروجی‌های انبار
            </a>
        </div>
    </div>

    <div class="section-box">
        <div class="section-head">
            <h6 class="section-title">انواع حواله</h6>
            <div class="section-sub">برای ثبت یا پیگیری، بخش موردنظر را انتخاب کنید</div>
        </div>

        <div class="voucher-scroll">
            <div class="voucher-row">
                @if(\App\Support\PageAccessCatalog::userCanRoute(auth()->user(), 'vouchers.sales.index'))
                <a class="voucher-card" href="{{ route('vouchers.sales.index') }}">
                    <div class="voucher-icon">🧾</div>
                    <div class="voucher-title">حواله فروش</div>
                    <div class="voucher-desc">
                        مدیریت حواله‌های فروش و فاکتورهای تاییدشده.
                    </div>
                    <div class="tags">
                        <span class="tag">فروش</span>
                        <span class="tag">فاکتور</span>
                        <span class="tag">ارسال</span>
                    </div>
                </a>

                @endif
                <a class="voucher-card" href="{{ route('vouchers.return-from-sale.index') }}">
                    <div class="voucher-icon">🔁</div>
                    <div class="voucher-title">برگشت از فروش</div>
                    <div class="voucher-desc">
                        ثبت و پیگیری مرجوعی مشتری بر اساس فاکتور فروش.
                    </div>
                    <div class="tags">
                        <span class="tag">مرجوعی</span>
                        <span class="tag">مشتری</span>
                        <span class="tag">فاکتور</span>
                    </div>
                </a>

                <a class="voucher-card" href="{{ route('vouchers.section.index', 'scrap') }}">
                    <div class="voucher-icon">🗑️</div>
                    <div class="voucher-title">ضایعات</div>
                    <div class="voucher-desc">
                        انتقال کالاهای معیوب یا خارج از مصرف به انبار ضایعات.
                    </div>
                    <div class="tags">
                        <span class="tag">ضایعات</span>
                        <span class="tag">موجودی</span>
                        <span class="tag">خروج</span>
                    </div>
                </a>

                <a class="voucher-card" href="{{ route('vouchers.section.index', 'personnel') }}">
                    <div class="voucher-icon">👤</div>
                    <div class="voucher-title">تحویل به پرسنل</div>
                    <div class="voucher-desc">
                        ثبت تحویل کالا و تجهیزات به پرسنل.
                    </div>
                    <div class="tags">
                        <span class="tag">پرسنل</span>
                        <span class="tag">اموال</span>
                        <span class="tag">تحویل</span>
                    </div>
                </a>

                <a class="voucher-card" href="{{ route('vouchers.section.index', 'transfer') }}">
                    <div class="voucher-icon">🚚</div>
                    <div class="voucher-title">انتقال بین انبارها</div>
                    <div class="voucher-desc">
                        انتقال کالا بین انبارها با ثبت مبدا و مقصد.
                    </div>
                    <div class="tags">
                        <span class="tag">انتقال</span>
                        <span class="tag">مبدا</span>
                        <span class="tag">مقصد</span>
                    </div>
                </a>
                @unless(\App\Support\WarehouseVoucherAccess::restricted(auth()->user()))
                <a class="voucher-card" href="{{ route('vouchers.section.index','showroom') }}">
                    <div class="voucher-icon">🏬</div><div class="voucher-title">حواله شوروم</div>
                    <div class="voucher-desc">انتقال کالا از انبار مرکزی به انبار شوروم، با موجودی و سابقهٔ مستقل.</div>
                    <div class="tags"><span class="tag">شوروم</span><span class="tag">انتقال</span><span class="tag">موجودی مستقل</span></div>
                </a>
                @endunless
            </div>
        </div>
    </div>

    <div class="bottom-box">
        <div>
            <div class="bottom-title">خروجی‌های انبار</div>
            <p class="bottom-sub">
                برای دیدن تاریخچه خروجی‌ها و پیگیری حواله‌ها از این بخش استفاده کن.
            </p>
        </div>

        <a class="btn btn-outline-dark rounded-4 px-4 fw-bold" href="{{ route('warehouse.outputs') }}">
            ورود به خروجی‌های انبار
        </a>
    </div>
</div>
@endsection
