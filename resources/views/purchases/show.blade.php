@extends('layouts.app')

@section('content')
@php
    $toRial = fn ($rial) => \App\Support\Currency::formatRial($rial);
    $num = fn ($value) => number_format((int) $value);

    $items = $purchase->items;
    $rowsCount = $items->count();
    $totalQuantity = (int) $items->sum(fn ($item) => (int) $item->quantity);
    $subtotal = (int) ($purchase->subtotal_amount ?? $items->sum(fn ($item) => (int) ($item->line_subtotal ?? ((int) $item->quantity * (int) $item->buy_price))));
    $totalDiscount = (int) ($purchase->total_discount ?? 0);
    $lineDiscounts = (int) $items->sum(fn ($item) => (int) ($item->discount_amount ?? 0));
    $payable = (int) $purchase->total_amount;
    $sellValue = (int) $items->sum(fn ($item) => (int) $item->quantity * (int) $item->sell_price);
    $zeroBuyPriceRows = $items->filter(fn ($item) => (int) $item->buy_price <= 0)->count();
@endphp

<style>
    .purchase-show {
        --ps-navy: #083d50; --ps-brand: #0c5367; --ps-accent: #dd991b; --ps-card: #fffdf9;
        --ps-border: #dde6e3; --ps-text: #173543; --ps-muted: #6d8087; --ps-soft: #f8fafc;
        --ps-shadow: 0 4px 14px rgba(8, 61, 80, .06);
        max-width: 1100px; color: var(--ps-text);
    }
    .purchase-show .ps-card { background: var(--ps-card); border: 1px solid var(--ps-border); border-radius: 16px; box-shadow: var(--ps-shadow); position: relative; overflow: hidden; margin-bottom: 14px; }
    .purchase-show .ps-card::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 3px; background: var(--ps-brand); }
    .purchase-show .ps-card-body { padding: 14px 16px; }
    .purchase-show .ps-title { font-size: 1.15rem; font-weight: 900; color: var(--ps-navy); margin: 0; }
    .purchase-show .ps-section-title { font-size: .95rem; font-weight: 900; color: var(--ps-navy); margin: 0; }
    .purchase-show .ps-hint { color: var(--ps-muted); font-size: .8rem; }
    .purchase-show .ps-label { font-size: .75rem; font-weight: 800; color: var(--ps-muted); margin-bottom: 3px; }
    .purchase-show .ps-value { font-weight: 800; color: var(--ps-text); }
    .purchase-show .ps-pill { display: inline-flex; align-items: center; gap: 5px; border-radius: 999px; padding: 3px 10px; font-size: .72rem; font-weight: 800; border: 1px solid rgba(12, 83, 103, .12); background: #fff; color: var(--ps-muted); white-space: nowrap; }
    .purchase-show .ps-pill.is-warn { color: #b7791f; border-color: rgba(241, 171, 39, .35); background: rgba(241, 171, 39, .1); }

    .purchase-show .ps-stats { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; }
    .purchase-show .ps-stat { background: var(--ps-soft); border: 1px solid rgba(12, 83, 103, .1); border-radius: 12px; padding: 10px 12px; }
    .purchase-show .ps-stat .s-label { font-size: .74rem; color: var(--ps-muted); font-weight: 700; }
    .purchase-show .ps-stat .s-value { font-size: 1.05rem; font-weight: 900; color: var(--ps-navy); margin-top: 2px; white-space: nowrap; }
    .purchase-show .ps-stat.is-primary { background: linear-gradient(135deg, var(--ps-navy), var(--ps-brand)); border-color: transparent; }
    .purchase-show .ps-stat.is-primary .s-label { color: rgba(255, 255, 255, .8); }
    .purchase-show .ps-stat.is-primary .s-value { color: #fff; }

    .purchase-show .ps-table { width: 100%; min-width: 760px; margin: 0; border-collapse: separate; border-spacing: 0; font-size: .85rem; }
    .purchase-show .ps-table thead th { background: #f3f6f8; color: var(--ps-muted); font-size: .74rem; font-weight: 800; padding: 10px; border-bottom: 1px solid var(--ps-border); white-space: nowrap; text-align: right; }
    .purchase-show .ps-table tbody td { padding: 10px; border-bottom: 1px solid rgba(12, 83, 103, .07); vertical-align: middle; background: #fff; }
    .purchase-show .ps-table tbody tr:hover td { background: #f7fbfb; }
    .purchase-show .ps-table .num { text-align: left; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .purchase-show .ps-table .row-index { color: var(--ps-muted); font-size: .75rem; width: 36px; }
    .purchase-show .ps-table .product-name { font-weight: 800; color: var(--ps-navy); }
    .purchase-show .ps-table .code { display: inline-block; margin-top: 3px; padding: 1px 8px; border-radius: 999px; background: #f7f5ef; border: 1px solid rgba(12, 83, 103, .1); font-size: .7rem; font-weight: 700; color: var(--ps-muted); direction: ltr; }
    .purchase-show .ps-table .qty { font-weight: 900; color: var(--ps-navy); }
    .purchase-show .ps-table .line-total { font-weight: 900; color: var(--ps-accent); }
    .purchase-show .ps-table .zero { color: #b7791f; }
    .purchase-show .ps-table .disc-meta { font-size: .72rem; color: var(--ps-muted); }
    .purchase-show .ps-table tfoot td { padding: 11px 10px; background: #f3f6f8; font-weight: 900; color: var(--ps-navy); border-top: 2px solid var(--ps-border); }

    .purchase-show .ps-summary { background: var(--ps-soft); border: 1px solid rgba(12, 83, 103, .1); border-radius: 12px; padding: 10px 14px; }
    .purchase-show .ps-summary .row-line { display: flex; justify-content: space-between; gap: 10px; padding: 5px 0; font-size: .86rem; }
    .purchase-show .ps-summary .row-line.is-total { border-top: 1px dashed rgba(12, 83, 103, .2); margin-top: 4px; padding-top: 9px; font-weight: 900; font-size: 1rem; color: var(--ps-navy); }

    @media (max-width: 991.98px) { .purchase-show .ps-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    @media (max-width: 575.98px) { .purchase-show .ps-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media print {
        .purchase-show .no-print { display: none !important; }
        .purchase-show .ps-card { box-shadow: none; }
    }
</style>

<div class="container-fluid py-3 purchase-show">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h1 class="ps-title">سند خرید #{{ $purchase->id }}</h1>
            <div class="ps-hint mt-1">
                {{ \App\Support\JalaliDate::dateTime($purchase->purchased_at) }}
                @if($purchase->user) · ثبت‌کننده: {{ $purchase->user->name }} @endif
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap no-print">
            <button type="button" class="btn btn-sm btn-outline-secondary rounded-3" onclick="window.print()">چاپ</button>
            @canPermission('stock_in.edit')
                <a class="btn btn-sm btn-primary rounded-3 fw-bold" href="{{ route('purchases.edit', $purchase) }}">ویرایش سند</a>
            @endcanPermission
            <a class="btn btn-sm btn-outline-secondary rounded-3" href="{{ route('purchases.index') }}">بازگشت</a>
        </div>
    </div>

    <div class="ps-card">
        <div class="ps-card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="ps-label">تأمین‌کننده</div>
                    <div class="ps-value">{{ $purchase->supplier?->name ?: '—' }}</div>
                </div>
                <div class="col-md-3">
                    <div class="ps-label">شماره تماس</div>
                    <div class="ps-value" dir="ltr" style="text-align:right">{{ $purchase->supplier?->phone ?: '—' }}</div>
                </div>
                <div class="col-md-5">
                    <div class="ps-label">آدرس تأمین‌کننده</div>
                    <div class="ps-value fw-normal">{{ $purchase->supplier?->address ?: '—' }}</div>
                </div>
                @if($purchase->note)
                    <div class="col-12">
                        <div class="ps-label">توضیحات</div>
                        <div class="ps-value fw-normal" style="white-space:pre-wrap">{{ $purchase->note }}</div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="ps-stats mb-3">
        <div class="ps-stat">
            <div class="s-label">تعداد ردیف کالا</div>
            <div class="s-value">{{ $num($rowsCount) }}</div>
        </div>
        <div class="ps-stat">
            <div class="s-label">جمع تعداد اقلام</div>
            <div class="s-value">{{ $num($totalQuantity) }} عدد</div>
        </div>
        <div class="ps-stat">
            <div class="s-label">جمع قبل از تخفیف</div>
            <div class="s-value">{{ $toRial($subtotal) }}</div>
        </div>
        <div class="ps-stat">
            <div class="s-label">ارزش فروش اقلام</div>
            <div class="s-value">{{ $toRial($sellValue) }}</div>
        </div>
        <div class="ps-stat is-primary">
            <div class="s-label">قابل پرداخت</div>
            <div class="s-value">{{ $toRial($payable) }}</div>
        </div>
    </div>

    <div class="ps-card">
        <div class="ps-card-body pb-2">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <h2 class="ps-section-title">اقلام سند</h2>
                <div class="d-flex gap-2 flex-wrap">
                    <span class="ps-pill">{{ $num($rowsCount) }} ردیف · {{ $num($totalQuantity) }} عدد</span>
                    @if($zeroBuyPriceRows > 0)
                        <span class="ps-pill is-warn">{{ $num($zeroBuyPriceRows) }} ردیف بدون قیمت خرید</span>
                    @endif
                </div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="ps-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>محصول</th>
                        <th>مدل / تنوع</th>
                        <th class="num">تعداد</th>
                        <th class="num">قیمت خرید</th>
                        <th class="num">قیمت فروش</th>
                        <th class="num">تخفیف</th>
                        <th class="num">جمع ردیف</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $item)
                        @php
                            $variantCode = $item->variant?->variant_code;
                            $discountAmount = (int) ($item->discount_amount ?? 0);
                        @endphp
                        <tr>
                            <td class="row-index">{{ $loop->iteration }}</td>
                            <td>
                                <div class="product-name">{{ $item->product_name ?: ($item->product?->name ?? '—') }}</div>
                                @if($item->product_code)<span class="code">{{ $item->product_code }}</span>@endif
                            </td>
                            <td>
                                <div>{{ $item->variant_name ?: ($item->variant?->variant_name ?? '—') }}</div>
                                @if($variantCode)<span class="code">{{ $variantCode }}</span>@endif
                            </td>
                            <td class="num qty">{{ $num($item->quantity) }}</td>
                            <td class="num {{ (int) $item->buy_price <= 0 ? 'zero' : '' }}">{{ $toRial($item->buy_price) }}</td>
                            <td class="num">{{ $toRial($item->sell_price) }}</td>
                            <td class="num">
                                @if($item->discount_type === 'percent' && (int) $item->discount_value > 0)
                                    <div>{{ $item->discount_value }}٪</div>
                                    <div class="disc-meta">{{ $toRial($discountAmount) }}</div>
                                @elseif($discountAmount > 0)
                                    {{ $toRial($discountAmount) }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="num line-total">{{ $toRial($item->line_total) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">این سند کالایی ندارد.</td></tr>
                    @endforelse
                </tbody>
                @if($rowsCount > 0)
                    <tfoot>
                        <tr>
                            <td colspan="3">جمع کل ({{ $num($rowsCount) }} ردیف)</td>
                            <td class="num">{{ $num($totalQuantity) }}</td>
                            <td></td>
                            <td></td>
                            <td class="num">{{ $lineDiscounts > 0 ? $toRial($lineDiscounts) : '—' }}</td>
                            <td class="num">{{ $toRial($items->sum(fn ($item) => (int) $item->line_total)) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        <div class="ps-card-body">
            <div class="row g-3 justify-content-end">
                <div class="col-md-5">
                    <div class="ps-summary">
                        <div class="row-line"><span>جمع قبل از تخفیف</span><strong>{{ $toRial($subtotal) }}</strong></div>
                        <div class="row-line"><span>تخفیف کل</span><strong>{{ $toRial($totalDiscount) }}</strong></div>
                        <div class="row-line is-total"><span>قابل پرداخت</span><span>{{ $toRial($payable) }}</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
