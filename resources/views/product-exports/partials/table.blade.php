@php
    $totalStock = $rows->sum('stock');
    $inStockCount = $rows->where('stock', '>', 0)->count();
    $outOfStockCount = $rows->where('stock', '<=', 0)->count();
@endphp

<style>
    .export-result-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #e8edf2;
    }
    .export-result-title { display: flex; align-items: center; gap: .7rem; min-width: 0; }
    .export-result-title__icon {
        display: grid;
        flex: 0 0 auto;
        width: 40px;
        height: 40px;
        place-items: center;
        color: #0f766e;
        background: #ccfbf1;
        border-radius: 12px;
    }
    .export-result-title__icon svg { width: 20px; height: 20px; }
    .export-result-title h2 { margin: 0; color: #0f172a; font-size: 1rem; font-weight: 900; }
    .export-result-title p { margin: .15rem 0 0; color: #64748b; font-size: .75rem; }
    .export-count { flex: 0 0 auto; padding: .45rem .75rem; color: #334155; background: #f1f5f9; border-radius: 999px; font-size: .78rem; font-weight: 900; }
    .export-summary {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: .75rem;
        padding: 1rem 1.25rem;
        background: #f8fafc;
        border-bottom: 1px solid #e8edf2;
    }
    .export-summary__item { min-width: 0; padding: .7rem .85rem; background: #fff; border: 1px solid #e8edf2; border-radius: 12px; }
    .export-summary__item span { display: block; color: #64748b; font-size: .72rem; }
    .export-summary__item strong { display: block; margin-top: .15rem; color: #0f172a; font-size: 1rem; }
    .export-table-wrap { width: 100%; overflow-x: auto; overscroll-behavior-inline: contain; }
    .export-products-table { min-width: 1080px; table-layout: auto; }
    .export-products-table thead th {
        top: 0;
        z-index: 2;
        padding: .85rem .75rem;
        white-space: nowrap;
        color: #475569;
        background: #f8fafc;
        font-size: .74rem;
        font-weight: 900;
    }
    .export-products-table tbody td { padding: .85rem .75rem; color: #334155; border-color: #eef2f6; }
    .export-products-table tbody tr:last-child td { border-bottom: 0; }
    .export-product { display: flex; align-items: center; gap: .75rem; min-width: 240px; }
    .export-product__image {
        width: 54px;
        height: 54px;
        flex: 0 0 54px;
        object-fit: cover;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
    }
    .export-product__name { display: block; max-width: 280px; overflow: hidden; color: #0f172a; font-weight: 900; text-overflow: ellipsis; white-space: nowrap; }
    .export-product__unit { display: block; margin-top: .2rem; color: #94a3b8; font-size: .7rem; }
    .export-code { direction: ltr; unicode-bidi: plaintext; display: inline-block; max-width: 180px; overflow: hidden; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; text-overflow: ellipsis; white-space: nowrap; }
    .export-number { direction: ltr; unicode-bidi: isolate; display: inline-block; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .export-stock { font-weight: 900; }
    .export-price strong { display: block; color: #0f172a; white-space: nowrap; }
    .export-price small { color: #94a3b8; }
    .export-status {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        padding: .4rem .65rem;
        border-radius: 999px;
        font-size: .72rem;
        font-weight: 900;
        white-space: nowrap;
    }
    .export-status::before { content: ""; width: 7px; height: 7px; background: currentColor; border-radius: 50%; }
    .export-status--success { color: #15803d; background: #dcfce7; }
    .export-status--warning { color: #a16207; background: #fef9c3; }
    .export-status--danger { color: #b91c1c; background: #fee2e2; }
    .export-status--secondary { color: #475569; background: #e2e8f0; }
    .export-date { color: #64748b; white-space: nowrap; font-size: .78rem; }
    .export-empty { padding: 3.5rem 1rem; text-align: center; }
    .export-empty__icon { display: grid; width: 72px; height: 72px; margin: 0 auto 1rem; place-items: center; color: #0f766e; background: #f0fdfa; border-radius: 22px; font-size: 2rem; }
    .export-empty h3 { margin: 0 0 .4rem; color: #0f172a; font-size: 1rem; font-weight: 900; }
    .export-empty p { margin: 0; color: #64748b; }
    @media (max-width: 767.98px) {
        .export-result-header { align-items: flex-start; padding: .9rem 1rem; }
        .export-summary { grid-template-columns: 1fr; padding: .8rem 1rem; }
        .export-table-wrap { overflow: visible; padding: .75rem; }
        .export-products-table,
        .export-products-table tbody,
        .export-products-table tr,
        .export-products-table td { display: block; width: 100%; min-width: 0; }
        .export-products-table thead { display: none; }
        .export-products-table tbody { display: grid; gap: .75rem; }
        .export-products-table tbody tr { padding: .9rem; background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 5px 16px rgba(15, 23, 42, .04); }
        .export-products-table tbody td {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: .48rem 0;
            border: 0;
            text-align: left;
        }
        .export-products-table tbody td::before { content: attr(data-label); flex: 0 0 auto; color: #64748b; font-size: .72rem; font-weight: 800; text-align: right; }
        .export-products-table tbody td:first-child { display: block; padding: 0 0 .75rem; border-bottom: 1px solid #eef2f6; }
        .export-products-table tbody td:first-child::before { display: none; }
        .export-product { min-width: 0; }
        .export-product__name { max-width: calc(100vw - 155px); }
    }
</style>

<div class="export-result-header">
    <div class="export-result-title">
        <span class="export-result-title__icon">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7.5 12 3l8 4.5M4 7.5V16l8 5m-8-13.5 8 5m8-5V16l-8 5m8-13.5-8 5m0 0V21" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
        </span>
        <span>
            <h2>لیست محصولات</h2>
            <p>نتیجه مطابق فیلترهای انتخاب‌شده</p>
        </span>
    </div>
    <span class="export-count">{{ number_format($rows->count()) }} محصول</span>
</div>

@if($rows->isEmpty())
    <div class="export-empty">
        <div class="export-empty__icon">📦</div>
        <h3>محصولی با این مشخصات پیدا نشد</h3>
        <p>فیلترها یا عبارت جستجو را تغییر دهید و دوباره تلاش کنید.</p>
    </div>
@else
    <div class="export-summary">
        <div class="export-summary__item"><span>مجموع موجودی</span><strong>{{ number_format($totalStock) }} عدد</strong></div>
        <div class="export-summary__item"><span>محصولات موجود</span><strong>{{ number_format($inStockCount) }} محصول</strong></div>
        <div class="export-summary__item"><span>محصولات ناموجود</span><strong>{{ number_format($outOfStockCount) }} محصول</strong></div>
    </div>

    <div class="export-table-wrap">
        <table class="table export-products-table">
            <thead>
                <tr>
                    <th>محصول</th>
                    <th>کد محصول / SKU</th>
                    <th>دسته‌بندی</th>
                    <th>مدل لیست</th>
                    <th>موجودی فعلی</th>
                    <th>قیمت فروش</th>
                    <th>وضعیت موجودی</th>
                    <th>آخرین بروزرسانی</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    @php
                        $statusClass = in_array($row['stock_status_class'], ['success', 'warning', 'danger', 'secondary'], true)
                            ? $row['stock_status_class']
                            : 'secondary';
                    @endphp
                    <tr>
                        <td data-label="محصول">
                            <div class="export-product">
                                <img class="export-product__image" src="{{ $row['image_url'] }}" alt="" loading="lazy"
                                     onerror="this.onerror=null;this.src='data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2256%22 height=%2256%22%3E%3Crect width=%2256%22 height=%2256%22 rx=%2212%22 fill=%22%23f0fdfa%22/%3E%3Ctext x=%2228%22 y=%2235%22 font-size=%2220%22 text-anchor=%22middle%22%3E📦%3C/text%3E%3C/svg%3E'">
                                <span>
                                    <span class="export-product__name" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                                    <span class="export-product__unit">واحد شمارش: {{ $row['unit'] }}</span>
                                </span>
                            </div>
                        </td>
                        <td data-label="کد / SKU"><span class="export-code" title="{{ $row['sku'] }}">{{ $row['sku'] }}</span></td>
                        <td data-label="دسته‌بندی">{{ $row['category'] }}</td>
                        <td data-label="مدل لیست">{{ $row['model_list'] ?? '—' }}</td>
                        <td data-label="موجودی"><span class="export-stock export-number">{{ number_format($row['stock']) }}</span> {{ $row['unit'] }}</td>
                        <td data-label="قیمت فروش" class="export-price"><span><strong class="export-number">{{ number_format($row['price']) }}</strong><small>تومان</small></span></td>
                        <td data-label="وضعیت"><span class="export-status export-status--{{ $statusClass }}">{{ $row['stock_status'] }}</span></td>
                        <td data-label="بروزرسانی"><span class="export-date">{{ $row['updated_at'] ?: '—' }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
