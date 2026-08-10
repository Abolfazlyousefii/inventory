@extends('layouts.app')

@section('content')
@php
    $titles = [
        'return-from-sale' => 'برگشت از فروش',
        'scrap' => 'انبار ضایعات',
        'personnel' => 'حواله پرسنل',
        'transfer' => 'حواله بین انباری',
    ];

    $toRial = fn($rial) => \App\Support\Currency::formatRial($rial);
    $jalaliDateTime = fn($date) => \App\Support\JalaliDate::dateTime($date);

    $isCustomerReturn = $voucherType === \App\Models\WarehouseTransfer::TYPE_CUSTOMER_RETURN;

    $pageItems = method_exists($vouchers, 'getCollection') ? $vouchers->getCollection() : collect($vouchers);
    $pageCount = $pageItems->count();
    $pageTotalAmount = (int) $pageItems->sum(fn ($voucher) => $voucher->returned_items_total_amount ?? $voucher->total_amount ?? 0);

    $returnerName = function ($voucher): string {
        $customerFullName = trim(implode(' ', array_filter([
            $voucher->customer?->first_name,
            $voucher->customer?->last_name,
        ])));

        return $customerFullName !== ''
            ? $customerFullName
            : ($voucher->beneficiary_name ?: ($voucher->customer?->display_name ?: '—'));
    };

    $variantLabel = function ($item): string {
        $variant = $item->variant;
        $parts = collect([
            $variant?->variant_name,
            $variant?->modelList?->model_name,
            $variant?->color?->name,
            $variant?->variety_name,
            $item->variant_name,
        ])->filter(fn ($value) => filled($value) && $value !== '—')->unique()->values();

        return $parts->isNotEmpty() ? $parts->implode(' / ') : '—';
    };

    $returnItemRows = function ($voucher) use ($variantLabel) {
        return $voucher->items->map(function ($item) use ($variantLabel) {
            $product = $item->product?->name ?? ('#' . $item->product_id);
            $variant = $variantLabel($item);
            $code = $item->variant?->variant_code ?: ($item->variant_code ?: null);
            $unit = $item->product?->unit ?: 'عدد';

            return [
                'product' => $product,
                'variant' => $variant,
                'code' => $code,
                'quantity' => (int) $item->quantity,
                'unit' => $unit,
                'kind' => $item->returnKindLabel(),
                'warehouse' => $item->destinationWarehouse?->name ?: ($item->transfer?->toWarehouse?->name ?: 'نامشخص'),
                'line_total' => (int) $item->line_total,
                'summary' => trim($product . ' / ' . $variant . ($code ? ' (' . $code . ')' : '') . ' - تعداد: ' . number_format((int) $item->quantity) . ' ' . $unit . ' - نوع برگشت: ' . $item->returnKindLabel() . ' - انبار مقصد: ' . ($item->destinationWarehouse?->name ?: 'نامشخص')),
            ];
        })->filter(fn ($row) => filled($row['summary']))->values();
    };

    $returnedItemsSummary = function ($voucher) use ($returnItemRows): string {
        $items = $returnItemRows($voucher)->pluck('summary')->filter()->values();

        return $items->isNotEmpty() ? $items->implode('، ') : '—';
    };
@endphp

<style>
    :root{
        --brd:#e8edf3;
        --soft:#f8fafc;
        --soft2:#f3f6fb;
        --text:#0f172a;
        --muted:#64748b;
        --blue:#2563eb;
        --blue-soft:#eff6ff;
        --green-soft:#ecfdf5;
        --shadow:0 12px 28px rgba(15,23,42,.06);
    }

    .page-wrap{
        padding: 6px 0 24px;
    }

    .hero-box{
        border:1px solid var(--brd);
        border-radius:22px;
        background:linear-gradient(135deg,#ffffff,#f8fbff 55%,#eef6ff);
        box-shadow:var(--shadow);
        overflow:hidden;
    }

    .hero-title{
        font-size:28px;
        font-weight:900;
        color:var(--text);
        margin-bottom:6px;
    }

    .hero-sub{
        color:var(--muted);
        font-size:14px;
        line-height:1.9;
        margin-bottom:0;
    }

    .soft-chip{
        display:inline-flex;
        align-items:center;
        gap:8px;
        padding:8px 12px;
        border-radius:999px;
        border:1px solid var(--brd);
        background:#fff;
        font-size:12px;
        font-weight:700;
        color:var(--text);
    }

    .stat-card{
        border:none;
        border-radius:18px;
        box-shadow:var(--shadow);
        height:100%;
    }

    .stat-card .card-body{
        padding:18px;
    }

    .stat-label{
        color:var(--muted);
        font-size:12px;
        margin-bottom:8px;
    }

    .stat-value{
        color:var(--text);
        font-size:26px;
        font-weight:900;
        line-height:1.2;
    }

    .filter-card,
    .table-card{
        border:none;
        border-radius:20px;
        box-shadow:var(--shadow);
        overflow:hidden;
    }

    .section-head{
        padding:14px 18px;
        border-bottom:1px solid var(--brd);
        background:linear-gradient(0deg,#fff,var(--soft2));
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        flex-wrap:wrap;
    }

    .section-title{
        font-size:15px;
        font-weight:900;
        margin:0;
        color:var(--text);
    }

    .section-sub{
        color:var(--muted);
        font-size:12px;
        margin:4px 0 0;
    }

    .table-clean{
        margin-bottom:0;
    }

    .table-clean thead th{
        white-space:nowrap;
        font-size:12px;
        color:var(--muted);
        font-weight:800;
        background:#fbfcfe;
        border-bottom-width:1px;
    }

    .table-clean tbody td{
        vertical-align:middle;
        font-size:13px;
    }

    .customer-box .name{
        font-weight:800;
        color:var(--text);
    }

    .customer-box .meta{
        font-size:12px;
        color:var(--muted);
        margin-top:2px;
    }

    .money-strong{
        font-weight:900;
        color:#0f172a;
        white-space:nowrap;
    }

    .code-pill{
        display:inline-flex;
        align-items:center;
        padding:6px 10px;
        border-radius:999px;
        background:#f8fafc;
        border:1px solid var(--brd);
        font-size:12px;
        font-weight:700;
    }

    .reason-pill{
        display:inline-flex;
        align-items:center;
        padding:6px 10px;
        border-radius:999px;
        background:var(--blue-soft);
        border:1px solid #dbeafe;
        color:#1d4ed8;
        font-size:12px;
        font-weight:700;
    }

    .action-group{
        display:flex;
        gap:8px;
        justify-content:flex-end;
        flex-wrap:wrap;
    }

    .empty-state{
        text-align:center;
        padding:56px 20px;
        color:var(--muted);
    }


    .return-table-wrap{
        overflow-x:visible;
    }

    .return-table{
        table-layout:fixed;
        width:100%;
        direction:rtl;
    }

    .return-table th,
    .return-table td{
        padding:.72rem .65rem;
        text-align:right;
        white-space:normal;
        overflow-wrap:anywhere;
        word-break:normal;
    }

    .return-table thead th{
        color:var(--muted);
        font-size:12px;
        font-weight:900;
        background:#fbfcfe;
        border-bottom:1px solid var(--brd);
    }

    .return-table tbody td{
        font-size:13px;
        line-height:1.65;
    }

    .return-col-row{width:48px}.return-col-ref{width:110px}.return-col-date{width:124px}.return-col-customer{width:140px}.return-col-items{width:22%}.return-col-item-count{width:96px}.return-col-amount{width:122px}.return-col-reason{width:112px}.return-col-status{width:100px}.return-col-user{width:110px}.return-col-actions{width:110px}

    .cell-muted{color:var(--muted);font-size:12px}.cell-strong{font-weight:900;color:var(--text)}
    .return-items-cell{max-width:100%}
    .return-item-line{display:block;min-width:0;margin-bottom:4px}
    .return-item-line:last-child{margin-bottom:0}
    .return-item-name{display:block;font-weight:800;color:var(--text);overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical}
    .return-item-meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:2px;color:var(--muted);font-size:11px}
    .mini-badge{display:inline-flex;align-items:center;max-width:100%;border:1px solid var(--brd);border-radius:999px;background:#f8fafc;padding:2px 7px;font-size:11px;font-weight:700;line-height:1.6}
    .more-items{display:inline-flex;margin-top:4px;color:#1d4ed8;background:#eff6ff;border:1px solid #dbeafe;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:800}
    .return-actions{display:flex;flex-direction:column;gap:6px;align-items:stretch}.return-actions .btn{width:100%;font-size:12px;font-weight:800;padding:.32rem .5rem}

    @media (max-width: 1199.98px){
        .return-table-wrap{overflow-x:auto}.return-table{min-width:1120px}
    }

    .mobile-cards{
        display:none;
    }

    .return-mobile-card{
        border:1px solid var(--brd);
        border-radius:18px;
        background:#fff;
        padding:14px;
        box-shadow:0 8px 20px rgba(15,23,42,.04);
        margin-bottom:12px;
    }

    .return-mobile-card .title{
        font-weight:900;
        color:var(--text);
        margin-bottom:10px;
    }

    .return-grid{
        display:grid;
        grid-template-columns:repeat(2,minmax(0,1fr));
        gap:10px;
    }

    .return-grid .item small{
        display:block;
        color:var(--muted);
        font-size:11px;
        margin-bottom:2px;
    }

    .return-grid .item div{
        font-size:13px;
        font-weight:700;
        color:var(--text);
    }

    @media (max-width: 991.98px){
        .desktop-table{
            display:none;
        }
        .mobile-cards{
            display:block;
        }
    }
</style>

<div class="container page-wrap">
    <div class="hero-box mb-4">
        <div class="p-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <div class="hero-title">{{ $titles[$type] ?? 'حواله' }}</div>
                    <p class="hero-sub">
                        @if($isCustomerReturn)
                            در این بخش می‌توانی برگشت‌های ثبت‌شده از فروش را با فیلتر مشتری، علت برگشت و بازه تاریخی بررسی، ویرایش یا حذف کنی.
                        @else
                            لیست حواله‌های ثبت‌شده این بخش را ببین و آن‌ها را مدیریت کن.
                        @endif
                    </p>

                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <span class="soft-chip">نوع: {{ $titles[$type] ?? 'حواله' }}</span>
                        <span class="soft-chip">تعداد در این صفحه: {{ number_format($pageCount) }}</span>
                        @if($isCustomerReturn)
                            <span class="soft-chip">جمع مبلغ این صفحه: {{ $toRial($pageTotalAmount) }}</span>
                        @endif
                    </div>
                </div>

                <div class="d-flex gap-2 flex-wrap">
                    <a class="btn btn-outline-secondary" href="{{ route('vouchers.index') }}">بازگشت</a>
                    @if($isCustomerReturn)
                        <a class="btn btn-success" href="{{ route('vouchers.section.return-from-sale.export', request()->query()) }}">خروجی اکسل</a>
                        <a class="btn btn-outline-danger" href="{{ route('vouchers.section.return-from-sale.pdf', request()->query()) }}">خروجی PDF</a>
                    @endif
                    <a class="btn btn-primary" href="{{ route('vouchers.section.create', $type) }}">+ ثبت جدید</a>
                </div>
            </div>
        </div>
    </div>

    @if($voucherType === \App\Models\WarehouseTransfer::TYPE_CUSTOMER_RETURN)
        <div class="modal fade" id="salesReturnExportModal" tabindex="-1" aria-labelledby="salesReturnExportModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <form method="GET" action="{{ route('vouchers.section.return-from-sale.export') }}" id="salesReturnExportForm">
                        <div class="modal-header">
                            <h5 class="modal-title" id="salesReturnExportModalLabel">خروجی اکسل برگشت از فروش</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="exportCustomerId">مشتری</label>
                                    <select name="customer_id" id="exportCustomerId" class="form-select" data-placeholder="جستجوی نام، موبایل یا کد مشتری"></select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="exportReturnReason">علت برگشت</label>
                                    <select name="return_reason" id="exportReturnReason" class="form-select">
                                        <option value="">همه علت‌ها</option>
                                        @foreach($returnReasons as $reasonKey => $reasonTitle)
                                            <option value="{{ $reasonKey }}">{{ $reasonTitle }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="exportDateFrom">تاریخ شروع</label>
                                    <input type="date" name="date_from" id="exportDateFrom" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="exportDateTo">تاریخ پایان</label>
                                    <input type="date" name="date_to" id="exportDateTo" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="exportCategoryId">دسته‌بندی</label>
                                    <select name="category_id" id="exportCategoryId" class="form-select">
                                        <option value="">انتخاب دسته‌بندی</option>
                                        @foreach($categories as $category)
                                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="exportSubcategoryId">زیر‌دسته‌بندی</label>
                                    <select name="subcategory_id" id="exportSubcategoryId" class="form-select" disabled>
                                        <option value="">ابتدا دسته‌بندی را انتخاب کنید</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="exportProductId">کالا</label>
                                    <select name="product_id" id="exportProductId" class="form-select" disabled data-placeholder="ابتدا زیر‌دسته‌بندی و سپس کالا را جستجو کنید"></select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="exportVariantId">تنوع کالا</label>
                                    <select name="variant_id" id="exportVariantId" class="form-select" disabled>
                                        <option value="">ابتدا کالا را انتخاب کنید</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">انصراف</button>
                            <button type="submit" class="btn btn-success">دریافت خروجی اکسل</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @if($voucherType === \App\Models\WarehouseTransfer::TYPE_CUSTOMER_RETURN)

        <div class="card table-card mb-3">
            <div class="p-3">
                <form method="GET" action="{{ route('vouchers.section.index', 'return-from-sale') }}" class="row g-3 align-items-end">
                    <div class="col-md-3"><label class="form-label">جستجو براساس نام مشتری</label><input class="form-control" name="customer_name" value="{{ $customerName }}" placeholder="مثلاً محمد"></div>
                    <div class="col-md-3"><label class="form-label">شماره حواله یا سند برگشت</label><input class="form-control" name="document_number" value="{{ $documentNumber }}" placeholder="شماره سند"></div>
                    <div class="col-md-2"><label class="form-label">تاریخ از</label><input type="date" class="form-control" name="date_from" value="{{ $dateFrom }}"></div>
                    <div class="col-md-2"><label class="form-label">تاریخ تا</label><input type="date" class="form-control" name="date_to" value="{{ $dateTo }}"></div>
                    <div class="col-md-2"><label class="form-label">نوع برگشت</label><select class="form-select" name="return_kind"><option value="">همه</option><option value="healthy" @selected($returnKind === 'healthy')>سالم</option><option value="damaged" @selected($returnKind === 'damaged')>مرجوعی</option><option value="mixed" @selected($returnKind === 'mixed')>سالم و مرجوعی</option></select></div>
                    <div class="col-md-2"><label class="form-label">انبار مقصد</label><select class="form-select" name="warehouse_id"><option value="">همه انبارها</option>@foreach($filterWarehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected((int) $warehouseId === (int) $warehouse->id)>{{ $warehouse->name }}</option>@endforeach</select></div>
                    <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary w-100" type="submit">جستجو</button><a class="btn btn-outline-secondary w-100" href="{{ route('vouchers.section.index', 'return-from-sale') }}">پاک کردن فیلترها</a></div>
                </form>
            </div>
        </div>
        <div class="card table-card">
            <div class="section-head">
                <div>
                    <h2 class="section-title">جدول خلاصه برگشت از فروش</h2>
                    <p class="section-sub">ستون‌های طولانی مثل کالاها و توضیحات خلاصه شده‌اند؛ جزئیات کامل در صفحه ویرایش/نمایش حواله باقی می‌ماند.</p>
                </div>
            </div>
            <div class="return-table-wrap">
                <table class="table table-hover align-middle mb-0 return-table">
                    <colgroup>
                        <col class="return-col-row"><col class="return-col-ref"><col class="return-col-date"><col class="return-col-customer"><col class="return-col-items"><col class="return-col-item-count"><col class="return-col-amount"><col><col class="return-col-reason"><col class="return-col-status"><col class="return-col-user"><col class="return-col-actions">
                    </colgroup>
                    <thead>
                    <tr>
                        <th>ردیف</th>
                        <th>شماره حواله</th>
                        <th>تاریخ شمسی</th>
                        <th>مشتری</th>
                        <th>کالا / تنوع</th>
                        <th>تعداد آیتم برگشتی</th>
                        <th>مبلغ کل</th>
                        <th>نوع برگشت</th>
                        <th>انبار مقصد</th>
                        <th>علت برگشت</th>
                        <th>وضعیت</th>
                        <th>ثبت‌کننده</th>
                        <th>عملیات</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($vouchers as $voucher)
                        @php
                            $itemRows = $returnItemRows($voucher);
                            $visibleItems = $itemRows->take(2);
                            $hiddenItemsCount = max($itemRows->count() - $visibleItems->count(), 0);
                            $itemSummary = $returnedItemsSummary($voucher);
                            $returnedItemsCount = (int) ($voucher->returned_items_count ?? $voucher->items->count());
                            $returnedTotalAmount = (int) ($voucher->returned_items_total_amount ?? $voucher->total_amount ?? $voucher->items->sum('line_total'));
                            $healthyAmount = (int) $voucher->items->filter(fn ($item) => $item->effectiveReturnKind() === 'healthy')->sum('line_total');
                            $damagedAmount = (int) $voucher->items->filter(fn ($item) => $item->effectiveReturnKind() === 'damaged')->sum('line_total');
                        @endphp
                        <tr>
                            <td class="cell-muted">{{ $vouchers->firstItem() ? $vouchers->firstItem() + $loop->index : $loop->iteration }}</td>
                            <td><span class="code-pill">{{ $voucher->reference ?: ('TR-'.$voucher->id) }}</span></td>
                            <td class="cell-strong" dir="ltr">{{ $jalaliDateTime($voucher->transferred_at) }}</td>
                            <td>
                                <div class="customer-box">
                                    <div class="name">{{ $returnerName($voucher) }}</div>
                                    <div class="meta">{{ $voucher->customer?->mobile ?: '—' }}</div>
                                </div>
                            </td>
                            <td class="return-items-cell" title="{{ $itemSummary }}">
                                @forelse($visibleItems as $itemRow)
                                    <span class="return-item-line">
                                        <span class="return-item-name">{{ $itemRow['product'] }}</span>
                                        <span class="return-item-meta">
                                            @if($itemRow['variant'] !== '—')<span class="mini-badge">{{ $itemRow['variant'] }}</span>@endif
                                            @if($itemRow['code'])<span class="mini-badge" dir="ltr">{{ $itemRow['code'] }}</span>@endif
                                            <span>تعداد: {{ number_format($itemRow['quantity']) }} {{ $itemRow['unit'] }}</span><span class="mini-badge">{{ $itemRow['kind'] }}</span><span class="mini-badge">{{ $itemRow['warehouse'] }}</span>
                                        </span>
                                    </span>
                                @empty
                                    <span class="cell-muted">—</span>
                                @endforelse
                                @if($hiddenItemsCount > 0)
                                    <span class="more-items" title="{{ $itemSummary }}">+ {{ number_format($hiddenItemsCount) }} قلم دیگر</span>
                                @endif
                            </td>
                            <td class="cell-strong">{{ number_format($returnedItemsCount) }} آیتم</td>
                            <td class="cell-strong"><div>{{ $toRial($returnedTotalAmount) }}</div><div class="small text-muted">سالم: {{ $toRial($healthyAmount) }} / مرجوعی: {{ $toRial($damagedAmount) }}</div></td>
                            <td><span class="reason-pill">{{ $voucher->returnKindLabel() }}</span></td>
                            <td>{{ $voucher->items->pluck('destinationWarehouse.name')->filter()->unique()->implode('، ') ?: ($voucher->toWarehouse?->name ?: 'نامشخص') }}</td>
                            <td><span class="reason-pill">{{ \App\Models\WarehouseTransfer::returnReasonOptions()[$voucher->return_reason] ?? '—' }}</span></td>
                            <td>{{ \App\Models\WarehouseTransfer::returnSourceLabel($voucher->return_type ?? null) }}</td>
                            <td>{{ $voucher->user?->name ?: '—' }}</td>
                            <td>
                                <div class="return-actions">
                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('vouchers.edit', $voucher) }}">ویرایش</a>
                                    <form method="POST" action="{{ route('vouchers.destroy', $voucher) }}" onsubmit="return confirm('از حذف برگشت از فروش مطمئن هستید؟')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">حذف</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12" class="text-center py-4 text-muted">موردی ثبت نشده است.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
            <div class="table-responsive">
                <table class="table table-clean table-hover mb-0">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>شماره</th>
                        <th>تاریخ</th>
                        <th>مبدا</th>
                        <th>مقصد</th>
                        <th>کاربر</th>
                        <th>فاکتور مرجع</th>
                        <th class="text-end">عملیات</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($vouchers as $voucher)
                        <tr>
                            <td>{{ $voucher->id }}</td>
                            <td>{{ $voucher->reference ?: ('TR-'.$voucher->id) }}</td>
                            <td>{{ $jalaliDateTime($voucher->transferred_at) }}</td>
                            <td>{{ $voucher->fromWarehouse?->name ?: '—' }}</td>
                            <td>{{ $voucher->toWarehouse?->name ?: '—' }}</td>
                            <td>{{ $voucher->user?->name ?: '—' }}</td>
                            <td>{{ $voucher->relatedInvoice?->uuid ?: '—' }}</td>
                            <td>
                                <div class="action-group">
                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('vouchers.edit', $voucher) }}">ویرایش</a>
                                    <form method="POST" action="{{ route('vouchers.destroy', $voucher) }}" class="d-inline" onsubmit="return confirm('از حذف حواله مطمئن هستید؟')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">حذف</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <div class="empty-state">موردی ثبت نشده است.</div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
    @endif

    <div class="mt-3">
        {{ $vouchers->links() }}
    </div>
</div>

@push('scripts')
@if($voucherType === \App\Models\WarehouseTransfer::TYPE_CUSTOMER_RETURN)
<script>
(function () {
    const hasSelect2 = window.jQuery && jQuery.fn && jQuery.fn.select2;
    const modal = document.getElementById('salesReturnExportModal');
    if (!modal) return;

    const $customer = jQuery('#exportCustomerId');
    const $product = jQuery('#exportProductId');
    const subcategory = document.getElementById('exportSubcategoryId');
    const category = document.getElementById('exportCategoryId');
    const variant = document.getElementById('exportVariantId');

    function resetSelect(select, placeholder, disabled = true) {
        select.innerHTML = `<option value="">${placeholder}</option>`;
        select.disabled = disabled;
    }

    async function loadJson(url) {
        const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
        if (!response.ok) {
            console.error('Sales return export ajax failed', { url, status: response.status });
            throw new Error(`Ajax request failed: ${response.status}`);
        }

        const data = await response.json();
        console.debug('Sales return export ajax response', { url, data });
        return data;
    }

    async function loadCategories() {
        resetSelect(category, 'در حال دریافت دسته‌بندی‌ها...', true);
        resetSelect(subcategory, 'ابتدا دسته‌بندی را انتخاب کنید', true);
        resetProduct();
        try {
            const rows = await loadJson(`{{ route('vouchers.section.return-from-sale.ajax.categories') }}`);
            resetSelect(category, 'انتخاب دسته‌بندی', false);
            rows.forEach(row => category.add(new Option(row.name, row.id)));
        } catch (error) {
            console.error('Unable to load main categories for sales return export', error);
            resetSelect(category, 'خطا در دریافت دسته‌بندی‌ها', true);
        }
    }

    function resetProduct() {
        if (hasSelect2 && $product.data('select2')) {
            $product.val(null).trigger('change');
        }
        $product.empty().prop('disabled', true);
        resetSelect(variant, 'ابتدا کالا را انتخاب کنید', true);
    }

    if (hasSelect2) {
        $customer.select2({
            dropdownParent: jQuery(modal),
            width: '100%',
            allowClear: true,
            minimumInputLength: 2,
            placeholder: $customer.data('placeholder'),
            ajax: {
                url: '{{ route('vouchers.section.return-from-sale.ajax.customers') }}',
                dataType: 'json',
                delay: 300,
                data: params => ({ q: params.term || '' }),
                processResults: data => ({ results: data.results || [] })
            }
        });

        $product.select2({
            dropdownParent: jQuery(modal),
            width: '100%',
            allowClear: true,
            minimumInputLength: 2,
            placeholder: $product.data('placeholder'),
            ajax: {
                url: '{{ route('vouchers.section.return-from-sale.ajax.products') }}',
                dataType: 'json',
                delay: 300,
                data: params => ({ q: params.term || '', subcategory_id: subcategory.value || '' }),
                processResults: data => ({ results: data.results || [] })
            }
        });
    }

    category.addEventListener('change', async function () {
        resetSelect(subcategory, this.value ? 'در حال دریافت...' : 'ابتدا دسته‌بندی را انتخاب کنید', true);
        resetProduct();
        if (!this.value) return;
        try {
            const rows = await loadJson(`{{ route('vouchers.section.return-from-sale.ajax.subcategories') }}?category_id=${encodeURIComponent(this.value)}`);
            resetSelect(subcategory, rows.length ? 'انتخاب زیر‌دسته‌بندی' : 'زیر‌دسته‌بندی ندارد', !rows.length);
            rows.forEach(row => subcategory.add(new Option(row.name, row.id)));
        } catch (error) {
            console.error('Unable to load subcategories for sales return export', error);
            resetSelect(subcategory, 'خطا در دریافت زیر‌دسته‌بندی‌ها', true);
        }
    });

    subcategory.addEventListener('change', function () {
        resetProduct();
        if (this.value) $product.prop('disabled', false);
    });

    $product.on('change', async function () {
        resetSelect(variant, this.value ? 'در حال دریافت...' : 'ابتدا کالا را انتخاب کنید', true);
        if (!this.value) return;
        const url = `{{ url('/vouchers/section/return-from-sale/ajax/products') }}/${encodeURIComponent(this.value)}/variants`;
        try {
            const rows = await loadJson(url);
            resetSelect(variant, rows.length ? 'همه تنوع‌ها' : 'تنوعی برای این کالا ثبت نشده است', !rows.length);
            rows.forEach(row => variant.add(new Option(row.text, row.id)));
        } catch (error) {
            console.error('Unable to load variants for sales return export', error);
            resetSelect(variant, 'خطا در دریافت تنوع‌ها', true);
        }
    });

    modal.addEventListener('show.bs.modal', loadCategories);
})();
</script>
@endif
@endpush

@endsection
