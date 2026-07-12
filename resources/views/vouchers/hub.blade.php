@extends('layouts.app')

@section('content')
<style>
    :root{
        --brd:#e8edf3;
        --soft:#f8fafc;
        --soft2:#f5f7fb;
        --text:#0f172a;
        --muted:#64748b;
        --blue:#2563eb;
        --shadow:0 8px 24px rgba(15,23,42,.05);
    }

    .voucher-page{
        padding:12px 0 24px;
    }

    .hero-box{
        border:1px solid var(--brd);
        border-radius:20px;
        background:#fff;
        box-shadow:var(--shadow);
        padding:22px;
        margin-bottom:16px;
    }

    .hero-title{
        font-size:26px;
        font-weight:900;
        color:var(--text);
        margin-bottom:8px;
    }

    .hero-sub{
        color:var(--muted);
        font-size:14px;
        line-height:1.9;
        margin-bottom:0;
        max-width:760px;
    }

    .hero-actions{
        margin-top:14px;
    }

    .section-box{
        border:1px solid var(--brd);
        border-radius:20px;
        background:#fff;
        box-shadow:var(--shadow);
        overflow:hidden;
        margin-bottom:16px;
    }

    .section-head{
        padding:14px 16px;
        border-bottom:1px solid var(--brd);
        background:var(--soft2);
    }

    .section-title{
        margin:0;
        font-size:15px;
        font-weight:900;
        color:var(--text);
    }

    .section-sub{
        margin:4px 0 0;
        font-size:12px;
        color:var(--muted);
    }

    .voucher-scroll{
        padding:14px;
        overflow-x:auto;
    }

    .voucher-row{
        display:flex;
        flex-wrap:nowrap;
        gap:12px;
        min-width:max-content;
    }

    .voucher-card{
        width:230px;
        min-width:230px;
        text-decoration:none;
        border:1px solid var(--brd);
        border-radius:16px;
        background:#fff;
        padding:14px;
        transition:.18s ease;
        display:block;
    }

    .voucher-card:hover{
        transform:translateY(-2px);
        border-color:#cfe0ff;
        box-shadow:0 10px 20px rgba(37,99,235,.08);
    }

    .voucher-icon{
        width:44px;
        height:44px;
        border-radius:12px;
        display:flex;
        align-items:center;
        justify-content:center;
        font-size:22px;
        background:#eff6ff;
        margin-bottom:10px;
    }

    .voucher-title{
        font-size:16px;
        font-weight:900;
        color:var(--text);
        margin-bottom:6px;
    }

    .voucher-desc{
        font-size:12px;
        color:var(--muted);
        line-height:1.9;
        margin-bottom:10px;
        min-height:44px;
    }

    .tags{
        display:flex;
        flex-wrap:wrap;
        gap:6px;
    }

    .tag{
        display:inline-flex;
        align-items:center;
        padding:5px 8px;
        border-radius:999px;
        background:var(--soft);
        border:1px solid var(--brd);
        font-size:11px;
        font-weight:700;
        color:#334155;
    }

    .bottom-box{
        border:1px solid var(--brd);
        border-radius:18px;
        background:#fff;
        box-shadow:var(--shadow);
        padding:16px;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        flex-wrap:wrap;
    }

    .bottom-title{
        font-size:16px;
        font-weight:900;
        color:var(--text);
        margin-bottom:4px;
    }

    .bottom-sub{
        font-size:13px;
        color:var(--muted);
        margin:0;
        line-height:1.8;
    }
</style>

<div class="container voucher-page">
    <div class="hero-box">
        <div class="hero-title">حواله‌های انبار</div>
        <p class="hero-sub">
            از این بخش نوع حواله انبار را انتخاب کن و سریع وارد همان فرآیند شو. برای جلوگیری از شلوغی سایدبار، عملیات‌ها به‌صورت کارت نمایش داده می‌شوند.
        </p>

        <div class="hero-actions">
            <a class="btn btn-primary rounded-4 px-4 fw-bold" href="{{ route('vouchers.index') }}">
                ورودی و خروجی‌های انبار
            </a>
        </div>
    </div>

    <div class="section-box">
        <div class="section-head">
            <h6 class="section-title">انواع حواله</h6>
            <div class="section-sub">همه حواله‌های مرتبط با انبار در یک ردیف قرار گرفته‌اند</div>
        </div>

        <div class="voucher-scroll">
            <div class="voucher-row">
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
