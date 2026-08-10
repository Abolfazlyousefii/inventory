@extends('layouts.app')

@section('content')
@php
    $toRial = fn($rial) => \App\Support\Currency::formatRial($rial);
@endphp

<style>
    :root{
        --ink: #0b1220;
        --navy: #071a3a;
        --blue: #0d6efd;
        --blue2:#0a58ca;
        --soft: #f6f9ff;
        --soft2:#eef4ff;
        --border: #dbe6ff;
        --shadow: 0 10px 28px rgba(7, 26, 58, .10);
        --shadow2:0 6px 14px rgba(7, 26, 58, .08);
    }

    .purchase-page-wrap{
        background: #fff;
        border-radius: 18px;
        padding: 14px;
    }

    .purchase-topbar{
        background: linear-gradient(90deg, var(--navy), var(--blue2));
        border-radius: 16px;
        padding: 12px 14px;
        box-shadow: var(--shadow2);
        color: #fff;
        margin-bottom: 14px;
    }
    .purchase-topbar .page-title{ color:#fff; margin:0; }
    .purchase-topbar .btn{ border-radius: 12px; }
    .purchase-topbar .btn-outline-light{
        border-color: rgba(255,255,255,.55);
        color:#fff;
    }
    .purchase-topbar .btn-outline-light:hover{
        background: rgba(255,255,255,.12);
        color:#fff;
    }
    .purchase-topbar .btn-light{
        background: rgba(255,255,255,.92);
        border: none;
        color: var(--navy);
        font-weight: 800;
    }

    /* Info card */
    .info-card{
        border: 1px solid var(--border);
        border-radius: 18px;
        box-shadow: var(--shadow);
        background: linear-gradient(180deg, var(--soft), #fff);
        overflow: hidden;
        position: relative;
    }
    .info-card::before{
        content:"";
        position:absolute;
        inset:0 auto 0 0;
        width:7px;
        background: linear-gradient(180deg, var(--navy), var(--blue));
    }
    .info-card .card-body{
        padding: 14px;
        color: var(--ink);
    }

    .kv{
        padding: 10px 12px;
        border: 1px solid rgba(7,26,58,.08);
        border-radius: 14px;
        background: rgba(255,255,255,.70);
        box-shadow: var(--shadow2);
        height: 100%;
    }
    .kv .k{
        font-size: .78rem;
        color: rgba(11,18,32,.62);
        margin-bottom: .25rem;
    }
    .kv .v{
        font-weight: 900;
        color: var(--ink);
    }
    .kv .sub{
        font-size: .82rem;
        color: rgba(11,18,32,.65);
        margin-top: .2rem;
    }

    .total-pill{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        padding: 10px 12px;
        border-radius: 14px;
        background: linear-gradient(90deg, var(--navy), var(--blue2));
        color:#fff;
        box-shadow: 0 12px 22px rgba(7,26,58,.12);
    }
    .total-pill .k{ font-size: .82rem; opacity: .9; }
    .total-pill .v{ font-size: 1.05rem; font-weight: 900; }

    /* Table card */
    .table-card{
        border: 1px solid var(--border);
        border-radius: 18px;
        box-shadow: var(--shadow);
        overflow: hidden;
        background: #fff;
    }
    .table-card .card-body{ padding: 0; }

    .table-card thead th{
        background: linear-gradient(90deg, rgba(7,26,58,.95), rgba(10,88,202,.95));
        color:#fff;
        border:none;
        font-weight: 900;
        font-size: .9rem;
        padding: 12px 10px;
        white-space: nowrap;
    }
    .table-card tbody td{
        padding: 12px 10px;
        vertical-align: middle;
        color: rgba(11,18,32,.88);
    }
    .table-card tbody tr:hover{
        background: rgba(13,110,253,.06);
    }

    .product-code{
        display:inline-block;
        margin-top: .25rem;
        padding: .2rem .45rem;
        border-radius: 999px;
        background: rgba(7,26,58,.06);
        border: 1px solid rgba(7,26,58,.08);
        color: rgba(7,26,58,.85);
        font-size: .75rem;
        font-weight: 700;
    }

    .disc-badge{
        display:inline-flex;
        align-items:center;
        gap:8px;
        padding: .25rem .55rem;
        border-radius: 999px;
        font-weight: 900;
        font-size: .78rem;
        border: 1px solid rgba(13,110,253,.20);
        background: rgba(13,110,253,.06);
        color: var(--blue2);
        white-space: nowrap;
    }
    .disc-badge.is-amount{
        border-color: rgba(7,26,58,.16);
        background: rgba(7,26,58,.06);
        color: var(--navy);
    }
    .disc-meta{
        font-size: .78rem;
        color: rgba(11,18,32,.55);
        margin-top: .25rem;
    }

    .line-total{
        font-weight: 900;
        color: var(--ink);
    }

    /* Summary box */
    .summary-card{
        border: 1px solid rgba(7,26,58,.10);
        border-radius: 18px;
        background: linear-gradient(180deg, var(--soft2), #fff);
        box-shadow: var(--shadow2);
        overflow: hidden;
    }
    .summary-card .head{
        background: linear-gradient(90deg, rgba(7,26,58,.92), rgba(10,88,202,.90));
        color:#fff;
        padding: 10px 12px;
        font-weight: 900;
    }
    .summary-card .list-group-item{
        border: none;
        border-top: 1px solid rgba(7,26,58,.08);
        padding: 12px 12px;
        background: transparent;
    }
    .summary-card .list-group-item:first-child{
        border-top: none;
    }
    .summary-card strong{
        color: var(--ink);
        font-weight: 900;
    }
    .summary-card .payable strong{
        color: var(--blue2);
        font-size: 1.05rem;
    }
</style>

<div class="purchase-page-wrap">

    <div class="purchase-topbar d-flex justify-content-between align-items-center">
        <h4 class="page-title mb-0">مشاهده سند خرید #{{ $purchase->id }}</h4>
        <div class="d-flex gap-2">
            @canPermission('stock_in.edit')<a class="btn btn-light" href="{{ route('purchases.edit', $purchase) }}">ویرایش سند</a>@endcanPermission
            <a class="btn btn-outline-light" href="{{ route('purchases.index') }}">بازگشت</a>
        </div>
    </div>

    <div class="card info-card mb-3">
        <div class="card-body">
            <div class="row g-3">

                <div class="col-md-4">
                    <div class="kv">
                        <div class="k">تامین‌کننده</div>
                        <div class="v">{{ $purchase->supplier?->name ?: '-' }}</div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="kv">
                        <div class="k">شماره تماس</div>
                        <div class="v">{{ $purchase->supplier?->phone ?: '-' }}</div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="kv">
                        <div class="k">تاریخ</div>
                        <div class="v">{{ \App\Support\JalaliDate::dateTime($purchase->purchased_at) }}</div>
                    </div>
                </div>

                <div class="col-md-8">
                    <div class="kv">
                        <div class="k">آدرس تامین‌کننده</div>
                        <div class="sub">{{ $purchase->supplier?->address ?: '-' }}</div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="total-pill">
                        <div class="k">مبلغ کل</div>
                        <div class="v">{{ $toRial($purchase->total_amount) }}</div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="kv">
                        <div class="k">توضیحات</div>
                        <div class="sub">{{ $purchase->note ?: '-' }}</div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <div class="card table-card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th>محصول</th>
                            <th>مدل</th>
                            <th>تعداد</th>
                            <th>قیمت خرید</th>
                            <th>قیمت فروش</th>
                            <th>تخفیف</th>
                            <th>جمع نهایی</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($purchase->items as $item)
                        <tr>
                            <td>
                                <div class="fw-bold">{{ $item->product_name }}</div>
                                <span class="product-code">{{ $item->product_code }}</span>
                            </td>

                            <td>{{ $item->variant_name ?: ($item->variant?->variant_name ?? '-') }}</td>

                            <td class="fw-bold">{{ $item->quantity }}</td>

                            <td>{{ $toRial($item->buy_price) }}</td>

                            <td>{{ $toRial($item->sell_price) }}</td>

                            <td>
                                @if($item->discount_type === 'percent')
                                    <span class="disc-badge">{{ $item->discount_value }}٪</span>
                                @elseif($item->discount_type === 'amount')
                                    <span class="disc-badge is-amount">{{ $toRial($item->discount_value) }}</span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif

                                <div class="disc-meta">
                                    مبلغ تخفیف: {{ $toRial($item->discount_amount ?? 0) }}
                                </div>
                            </td>

                            <td class="line-total">{{ $toRial($item->line_total) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="p-3">
                <div class="row g-3">
                    <div class="col-md-5 ms-auto">
                        <div class="summary-card">
                            <div class="head">جمع‌بندی سند</div>
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item d-flex justify-content-between">
                                    <span>جمع قبل تخفیف</span>
                                    <strong>{{ $toRial($purchase->subtotal_amount ?? 0) }}</strong>
                                </li>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span>تخفیف کل</span>
                                    <strong>{{ $toRial($purchase->total_discount ?? 0) }}</strong>
                                </li>
                                <li class="list-group-item d-flex justify-content-between payable">
                                    <span>قابل پرداخت</span>
                                    <strong>{{ $toRial($purchase->total_amount) }}</strong>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

</div>
@endsection
