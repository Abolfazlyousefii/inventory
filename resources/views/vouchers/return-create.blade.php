@extends('layouts.app')

@section('content')
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">

<style>
    :root{
        --brd:#e8edf3;
        --muted:#6b7280;
        --soft:#f8fafc;
        --soft2:#f3f6fb;
        --blue:#2563eb;
        --blue-soft:#eff6ff;
        --ok:#16a34a;
        --danger:#dc2626;
        --warn:#f59e0b;
    }

    .card-soft{
        border:1px solid var(--brd);
        border-radius:18px;
        background:#fff;
        box-shadow:0 10px 28px rgba(15,23,42,.04);
    }

    .section-head{
        padding:12px 14px;
        border-bottom:1px solid var(--brd);
        background:linear-gradient(0deg,#fff,var(--soft2));
        border-top-left-radius:18px;
        border-top-right-radius:18px;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:10px;
        flex-wrap:wrap;
    }

    .section-title{
        font-weight:800;
        font-size:14px;
    }

    .muted{
        color:var(--muted);
        font-size:12px;
    }

    .hint-box{
        border:1px dashed #dbeafe;
        background:#f8fbff;
        border-radius:14px;
        padding:12px;
    }

    .selected-invoice-box{
        border:1px solid #dbeafe;
        background:#f8fbff;
        border-radius:14px;
        padding:12px;
    }

    .invoice-card{
        border:1px solid var(--brd);
        border-radius:14px;
        background:#fff;
        padding:12px;
        cursor:pointer;
        transition:.18s ease;
        height:100%;
    }

    .invoice-card:hover{
        border-color:#bfdbfe;
        box-shadow:0 6px 18px rgba(37,99,235,.08);
        transform:translateY(-1px);
    }

    .invoice-card.active{
        border-color:#93c5fd;
        background:#eff6ff;
    }

    .line-table th{
        white-space:nowrap;
        font-size:13px;
    }

    .line-table td{
        vertical-align:middle;
    }

    .empty-state{
        border:1px dashed var(--brd);
        border-radius:14px;
        background:#fff;
        padding:16px;
        color:var(--muted);
        text-align:center;
    }

    .sticky-submit{
        position:sticky;
        bottom:16px;
        z-index:5;
        background:rgba(255,255,255,.92);
        backdrop-filter:blur(6px);
        border:1px solid var(--brd);
        border-radius:16px;
        padding:12px;
    }

    .mono{
        font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;
        letter-spacing:.5px;
        direction:ltr;
        display:inline-block;
    }

    .qty-input{
        max-width:130px;
    }

    .select2-container{
        width:100% !important;
        direction:rtl;
    }

    .select2-container .select2-selection--single{
        height:38px;
        border:1px solid #ced4da;
        border-radius:.375rem;
        display:flex;
        align-items:center;
    }

    .select2-container--default .select2-selection--single .select2-selection__rendered{
        width:100%;
        padding-right:.75rem;
        padding-left:2rem;
        color:#212529;
        line-height:36px;
    }

    .select2-container--default .select2-selection--single .select2-selection__arrow{
        height:36px;
        left:6px;
        right:auto;
    }

    .select2-dropdown{
        direction:rtl;
        text-align:right;
        z-index:9999;
    }

    .customer-result-name{
        font-weight:800;
        font-size:13px;
    }

    .customer-result-phone{
        color:var(--muted);
        font-size:12px;
        margin-top:2px;
        direction:ltr;
        text-align:right;
    }

    .manual-modal-backdrop{
        position:fixed;
        inset:0;
        background:rgba(15,23,42,.45);
        z-index:1040;
    }
</style>

<div class="container py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">{{ isset($voucher) ? 'ویرایش برگشت از فروش' : 'ثبت برگشت از فروش' }}</h4>
        <a class="btn btn-outline-secondary" href="{{ route('vouchers.section.index', 'return-from-sale') }}">بازگشت</a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <div class="fw-bold mb-2">لطفاً خطاهای زیر را بررسی کن:</div>
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card-soft">
        <div class="section-head">
            <div>
                <div class="section-title">فرم ثبت برگشت از فروش</div>
                <div class="muted">
                    مشتری را جستجو کن، فاکتور را انتخاب کن؛ کالاهای خریداری‌شده همان فاکتور به‌صورت خودکار در جدول برگشتی لود می‌شوند.
                </div>
            </div>
        </div>

        <div class="p-3">
            <form method="POST" action="{{ isset($voucher) ? route('vouchers.update', $voucher) : route('vouchers.section.store', 'return-from-sale') }}" id="returnForm">
                @csrf
                @isset($voucher)
                    @method('PUT')
                @endisset

                <input type="hidden" name="related_invoice_uuid" id="relatedInvoiceUuid" value="{{ old('related_invoice_uuid', $voucher->relatedInvoice->uuid ?? '') }}">

                <div class="row g-3 mb-1">
                    <div class="col-lg-4">
                        <label class="form-label">نوع برگشت از فروش</label>
                        <select name="return_type" id="returnTypeSelect" class="form-select" required>
                            <option value="internal_invoice" @selected(old('return_type', $voucher->return_type ?? 'internal_invoice') === 'internal_invoice')>بر اساس فاکتور داخلی</option>
                            <option value="external_manual" @selected(old('return_type', $voucher->return_type ?? null) === 'external_manual')>بدون فاکتور داخلی / فاکتور سازه‌حساب</option>
                        </select>
                    </div>
                    <div class="col-lg-4 d-none" id="externalInvoiceWrap">
                        <label class="form-label">شماره فاکتور سازه‌حساب</label>
                        <input name="external_invoice_number" id="externalInvoiceNumber" class="form-control" value="{{ old('external_invoice_number', $voucher->external_invoice_number ?? '') }}" maxlength="100">
                        <div class="form-text">برای مرجوعی‌هایی که فاکتورشان در نرم‌افزار قبلی ثبت شده است.</div>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label">انبار مقصد</label>
                        <select class="form-select" name="to_warehouse_id" id="warehouseSelect" required>
                            @foreach($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}" @selected((int) old('to_warehouse_id', $voucher->to_warehouse_id ?? $returnsWarehouse->id) === (int) $warehouse->id)>
                                    {{ $warehouse->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">برای کالاهای سالم می‌توانید مستقیماً انبار مرکزی را انتخاب کنید.</div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-4">
                        <label class="form-label">مشتری</label>
                        <select name="customer_id" id="customerSelect" class="form-select" required>
                            <option value="">جستجو با نام، نام خانوادگی یا شماره تماس...</option>

                            @foreach($customers as $customer)
                                @php
                                    $customerFullName = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
                                    $customerTitle = $customerFullName !== '' ? $customerFullName : ('مشتری #' . $customer->id);
                                    $customerPhone = $customer->mobile
                                        ?? $customer->phone
                                        ?? $customer->tel
                                        ?? $customer->telephone
                                        ?? '';
                                    $customerSearch = trim($customerTitle . ' ' . $customerPhone . ' ' . $customer->id);
                                @endphp

                                <option
                                    value="{{ $customer->id }}"
                                    data-search="{{ $customerSearch }}"
                                    data-name="{{ $customerTitle }}"
                                    data-phone="{{ $customerPhone }}"
                                    @selected(old('customer_id', $voucher->customer_id ?? null) == $customer->id)
                                >
                                    {{ $customerTitle }}@if($customerPhone) | {{ $customerPhone }}@endif
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">Select2 فعال است؛ با اسم، فامیل، موبایل یا ID مشتری جستجو کن.</div>
                    </div>

                    <div class="col-lg-4" id="invoicePickerWrap">
                        <label class="form-label">فاکتور مشتری</label>
                        <button type="button" class="btn btn-outline-primary w-100" id="openInvoiceModalBtn" disabled>
                            انتخاب فاکتور
                        </button>
                        <div class="form-text">بعد از انتخاب مشتری، این دکمه فعال می‌شود.</div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">علت برگشت از فروش</label>
                        <select name="return_reason" id="returnReasonSelect" class="form-select" required>
                            <option value="">انتخاب علت...</option>
                            @foreach($returnReasons as $reasonKey => $reasonTitle)
                                <option value="{{ $reasonKey }}" @selected(old('return_reason', $voucher->return_reason ?? null) === $reasonKey)>
                                    {{ $reasonTitle }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-8">
                        <div class="selected-invoice-box h-100 d-none" id="selectedInvoiceBox">
                            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                <div>
                                    <div class="muted mb-1">فاکتور انتخاب‌شده</div>
                                    <div class="fw-bold mono" id="selectedInvoiceUuid">—</div>
                                </div>

                                <div>
                                    <div class="muted mb-1">تاریخ</div>
                                    <div class="fw-bold" id="selectedInvoiceDate">—</div>
                                </div>

                                <div>
                                    <div class="muted mb-1">مبلغ کل</div>
                                    <div class="fw-bold"><span id="selectedInvoiceTotal">—</span> ریال</div>
                                </div>

                                <button type="button" class="btn btn-sm btn-outline-primary" id="changeInvoiceBtn">
                                    تغییر فاکتور
                                </button>
                            </div>
                        </div>

                        <div class="hint-box h-100" id="invoiceEmptyBox">
                            <div class="fw-bold mb-1">هنوز فاکتوری انتخاب نشده است.</div>
                            <div class="muted">بعد از انتخاب مشتری، روی «انتخاب فاکتور» بزن؛ سپس کالاها خودکار در جدول پایین می‌آیند.</div>
                        </div>
                    </div>

                    <div class="col-12 d-none" id="itemsSection">
                        <div class="card-soft">
                            <div class="section-head">
                                <div>
                                    <div class="section-title">کالاهای برگشتی</div>
                                    <div class="muted">
                                        ردیف‌ها به‌صورت خودکار از کالاهای خریداری‌شده در فاکتور انتخاب‌شده ساخته می‌شوند. ردیف‌هایی که برگشت ندارند را حذف کن یا تعداد را تغییر بده.
                                    </div>
                                </div>

                                <button type="button" class="btn btn-sm btn-outline-secondary" id="reloadItemsBtn">
                                    بازخوانی کالاهای فاکتور
                                </button>
                            </div>

                            <div class="p-3">
                                <div class="alert alert-warning py-2 d-none" id="itemsWarningBox"></div>

                                <div class="table-responsive">
                                    <table class="table table-striped line-table" id="itemsTable">
                                        <thead>
                                            <tr>
                                                <th>محصول خریداری‌شده</th>
                                                <th>کد کالا</th>
                                                <th>تنوع / طرح</th>
                                                <th>کد تنوع</th>
                                                <th>تعداد فاکتور</th>
                                                <th>قبلاً برگشتی</th>
                                                <th>قابل برگشت</th>
                                                <th>قیمت واحد طبق فاکتور</th>
                                                <th style="width:170px;">تعداد برگشتی</th>
                                                <th>واحد</th>
                                                <th>مبلغ برگشتی</th>
                                                <th style="width:80px;"></th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>

                                <div class="empty-state d-none" id="itemsEmptyState">
                                    برای این فاکتور کالایی با مقدار قابل برگشت پیدا نشد.
                                </div>

                                <div class="alert alert-info mt-3 mb-0 d-none" id="internalReturnSummary">
                                    <div>جمع تعداد برگشتی: <strong id="internalReturnQtyTotal">۰</strong></div>
                                    <div>جمع برگشت از فروش: <strong id="internalReturnAmountTotal">۰</strong> ریال</div>
                                    <div class="small mt-1">این مبلغ در گردش حساب مشتری به عنوان بستانکاری ثبت می‌شود.</div>
                                </div>
                            </div>
                        </div>
                    </div>


                    <div class="col-12 d-none" id="manualItemsSection">
                        <div class="card-soft">
                            <div class="section-head">
                                <div>
                                    <div class="section-title">کالاهای مرجوعی دستی / سازه‌حساب</div>
                                    <div class="muted">کالا را انتخاب کن یا اگر تعریف نشده، گزینه «تعریف کالای جدید» را بزن؛ موجودی به انبار انتخاب‌شده اضافه می‌شود.</div>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary" id="addManualItemBtn">افزودن کالا</button>
                            </div>
                            <div class="p-3">
                                <div class="table-responsive">
                                    <table class="table table-striped line-table" id="manualItemsTable">
                                        <thead>
                                            <tr>
                                                <th>دسته‌بندی</th><th>کالا / تعریف سریع</th><th>تنوع</th><th>کد / بارکد</th><th>تعداد برگشتی</th><th>واحد</th><th>مبلغ فروش واحد</th><th>مبلغ کل</th><th></th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                                <div class="empty-state d-none" id="manualItemsEmptyState">حداقل یک کالای مرجوعی دستی باید اضافه شود.</div>
                                <div class="text-start fw-bold mt-2">جمع کل مرجوعی: <span id="manualGrandTotal">۰</span> ریال</div>
                            </div>
                        </div>
                    </div>


                    @unless(isset($voucher))
                    <div class="col-md-6">
                        <label class="form-label">شماره حواله / ارجاع اختیاری</label>
                        <input name="reference" class="form-control" value="{{ old('reference', $voucher->reference ?? '') }}" maxlength="100">
                    </div>
                    @endunless

                    <div class="col-md-6">
                        <label class="form-label">توضیحات اختیاری</label>
                        <input name="note" class="form-control" value="{{ old('note', $voucher->note ?? '') }}" maxlength="255">
                    </div>

                    <div class="col-12">
                        <div class="sticky-submit">
                            <button type="submit" class="btn btn-success w-100" id="submitBtn">
                                {{ isset($voucher) ? 'ذخیره ویرایش برگشت از فروش' : 'ثبت برگشت از فروش' }}
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="invoicePickerModal" tabindex="-1" aria-labelledby="invoicePickerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="invoicePickerModalLabel">انتخاب فاکتور مشتری</h5>
                <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="mb-3">
                    <input type="text" class="form-control" id="invoiceSearchInput" placeholder="جستجو در شناسه فاکتور، تاریخ، مبلغ یا نام مشتری...">
                </div>

                <div id="invoiceListWrap" class="row g-3">
                    <div class="col-12">
                        <div class="text-muted">ابتدا مشتری را انتخاب کن.</div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">بستن</button>
                <button type="button" class="btn btn-primary" id="confirmInvoiceBtn" disabled>
                    انتخاب این فاکتور و لود کالاها
                </button>
            </div>
        </div>
    </div>
</div>

@php
    $manualReturnProducts = $products->map(function ($product) {
        $variants = collect($product->variants ?? []);

        return [
            'id' => (int) $product->id,
            'name' => (string) ($product->name ?? ''),
            'code' => (string) ($product->code ?? $product->sku ?? $product->barcode ?? $product->short_barcode ?? ''),
            'barcode' => (string) ($product->barcode ?? $product->short_barcode ?? ''),
            'category_id' => (int) ($product->category_id ?? 0),
            'sale_price' => (int) ($product->price ?? $product->sale_retail ?? $product->sale_wholesale ?? 0),
            'price' => (int) ($product->price ?? $product->sale_retail ?? $product->sale_wholesale ?? 0),
            'unit' => (string) ($product->unit ?? ''),
            'variants' => $variants->map(function ($variant) {
                $variantName = $variant->variant_name
                    ?: ($variant->variety_name
                    ?: ($variant->name
                    ?: ($variant->title ?: 'تنوع عمومی')));
                $variantCode = $variant->variant_code
                    ?: ($variant->sku
                    ?: ($variant->barcode
                    ?: ($variant->variety_code ?: '')));
                $salePrice = (int) ($variant->sell_price ?? $variant->price ?? 0);

                return [
                    'id' => (int) $variant->id,
                    'product_id' => (int) $variant->product_id,
                    'name' => (string) $variantName,
                    'code' => (string) $variantCode,
                    'barcode' => (string) ($variant->barcode ?? ''),
                    'sale_price' => $salePrice,
                    'price' => $salePrice,
                ];
            })->values(),
        ];
    })->values();
@endphp

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const returnTypeSelect = document.getElementById('returnTypeSelect');
    const externalInvoiceWrap = document.getElementById('externalInvoiceWrap');
    const externalInvoiceNumber = document.getElementById('externalInvoiceNumber');
    const invoicePickerWrap = document.getElementById('invoicePickerWrap');
    const manualItemsSection = document.getElementById('manualItemsSection');
    const manualTbody = document.querySelector('#manualItemsTable tbody');
    const addManualItemBtn = document.getElementById('addManualItemBtn');
    const manualGrandTotal = document.getElementById('manualGrandTotal');
    const manualItemsEmptyState = document.getElementById('manualItemsEmptyState');
    const customerSelect = document.getElementById('customerSelect');
    const warehouseSelect = document.getElementById('warehouseSelect');
    const returnReasonSelect = document.getElementById('returnReasonSelect');
    const openInvoiceModalBtn = document.getElementById('openInvoiceModalBtn');
    const changeInvoiceBtn = document.getElementById('changeInvoiceBtn');
    const invoicePickerModalEl = document.getElementById('invoicePickerModal');
    const invoiceSearchInput = document.getElementById('invoiceSearchInput');
    const invoiceListWrap = document.getElementById('invoiceListWrap');
    const confirmInvoiceBtn = document.getElementById('confirmInvoiceBtn');
    const relatedInvoiceUuid = document.getElementById('relatedInvoiceUuid');
    const selectedInvoiceBox = document.getElementById('selectedInvoiceBox');
    const invoiceEmptyBox = document.getElementById('invoiceEmptyBox');
    const selectedInvoiceUuid = document.getElementById('selectedInvoiceUuid');
    const selectedInvoiceDate = document.getElementById('selectedInvoiceDate');
    const selectedInvoiceTotal = document.getElementById('selectedInvoiceTotal');
    const itemsSection = document.getElementById('itemsSection');
    const reloadItemsBtn = document.getElementById('reloadItemsBtn');
    const itemsEmptyState = document.getElementById('itemsEmptyState');
    const itemsWarningBox = document.getElementById('itemsWarningBox');
    const internalReturnSummary = document.getElementById('internalReturnSummary');
    const internalReturnQtyTotal = document.getElementById('internalReturnQtyTotal');
    const internalReturnAmountTotal = document.getElementById('internalReturnAmountTotal');
    const tbody = document.querySelector('#itemsTable tbody');
    const returnForm = document.getElementById('returnForm');
    const submitBtn = document.getElementById('submitBtn');

    const endpoints = {
        customerInvoicesBase: @json(url('/vouchers/return/customers')),
        invoiceProductsBase: @json(url('/vouchers/invoice')),
    };

    @php
        $persistedReturnItems = isset($voucher) ? $voucher->items->map(fn ($item) => [
            'invoice_item_id' => $item->invoice_item_id,
            'product_id' => $item->product_id,
            'variant_id' => $item->product_variant_id ?: $item->variant_id,
            'product_variant_id' => $item->product_variant_id ?: $item->variant_id,
            'quantity' => (int) $item->quantity,
            'unit_price' => (int) $item->unit_price,
            'category_id' => $item->product?->category_id,
            'unit' => $item->product?->unit,
            'product_name' => $item->product?->name,
            'product_code' => $item->product?->code ?: ($item->product?->sku ?: ''),
            'variant_name' => $item->variant?->variant_name ?: ($item->variant_name ?: '—'),
            'variant_code' => $item->variant?->variant_code ?: ($item->variant_code ?: ''),
        ])->values()->all() : [];

        $oldInputItems = old('items');
        $returnOldItems = is_array($oldInputItems)
            ? collect($oldInputItems)->values()->map(function ($row, $index) use ($persistedReturnItems) {
                $persisted = $persistedReturnItems[$index] ?? [];
                $row = is_array($row) ? $row : [];
                $quantity = (int) ($row['quantity'] ?? 0);

                if ($quantity <= 0) {
                    $quantity = (int) ($persisted['quantity'] ?? 1);
                }

                return array_replace($persisted, $row, ['quantity' => max($quantity, 1)]);
            })->all()
            : $persistedReturnItems;

        $returnExistingInvoice = isset($voucher) && $voucher->relatedInvoice ? [
            'uuid' => $voucher->relatedInvoice->uuid,
            'invoice_date' => optional($voucher->relatedInvoice->created_at)->format('Y-m-d H:i'),
            'created_at' => optional($voucher->relatedInvoice->created_at)->format('Y-m-d H:i'),
            'total' => (int) ($voucher->relatedInvoice->total ?? 0),
        ] : null;

        $returnOldRelatedInvoiceUuid = old('related_invoice_uuid', $voucher->relatedInvoice->uuid ?? '');
        $returnEditingVoucherId = $voucher->id ?? null;
        $returnCategories = $categories->map(fn ($category) => [
            'id' => (int) $category->id,
            'name' => (string) $category->name,
            'code' => (string) ($category->code ?? ''),
        ])->values();
    @endphp

    const oldRelatedInvoiceUuid = @json($returnOldRelatedInvoiceUuid);
    const rawOldItems = @json($returnOldItems);
    const oldItems = (Array.isArray(rawOldItems) ? rawOldItems : Object.values(rawOldItems || {})).map(function (row) {
        row = row || {};
        const quantity = returnItemQuantity(row);
        const variantId = row.variant_id || row.product_variant_id || row.variety_id || '';

        return Object.assign({}, row, {
            invoice_item_id: row.invoice_item_id || row.invoiceItemId || '',
            product_id: row.product_id || row.productId || '',
            variant_id: variantId,
            product_variant_id: row.product_variant_id || variantId,
            quantity: quantity > 0 ? quantity : row.quantity,
        });
    });
    const editingVoucherId = @json($returnEditingVoucherId);
    const existingInvoice = @json($returnExistingInvoice);
    const products = @json($manualReturnProducts);
    const categories = @json($returnCategories);

    let customerInvoices = [];
    let selectedInvoice = null;
    let invoiceItems = [];
    let manualBackdrop = null;
    let manualItemIndex = 0;

    function normalizeDigits(value) {
        const persian = '۰۱۲۳۴۵۶۷۸۹';
        const arabic = '٠١٢٣٤٥٦٧٨٩';

        return String(value || '')
            .replace(/[۰-۹]/g, function (d) { return String(persian.indexOf(d)); })
            .replace(/[٠-٩]/g, function (d) { return String(arabic.indexOf(d)); });
    }

    function toSafeNumber(value, fallback = 0) {
        if (value === null || value === undefined || value === '') return fallback;

        const normalized = normalizeDigits(String(value))
            .replace(/[٬,،\s]/g, '')
            .replace(/[^0-9.-]/g, '');
        const number = Number(normalized);

        return Number.isFinite(number) ? number : fallback;
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function toMoney(value) {
        const number = toSafeNumber(value, 0);
        return number.toLocaleString('fa-IR');
    }

    function makeModalController(modalEl) {
        if (window.bootstrap && window.bootstrap.Modal) {
            return window.bootstrap.Modal.getOrCreateInstance(modalEl);
        }

        let controller = {
            show: function () {
                modalEl.classList.add('show');
                modalEl.style.display = 'block';
                modalEl.removeAttribute('aria-hidden');
                modalEl.setAttribute('aria-modal', 'true');
                document.body.classList.add('modal-open');

                if (!manualBackdrop) {
                    manualBackdrop = document.createElement('div');
                    manualBackdrop.className = 'manual-modal-backdrop';
                    manualBackdrop.addEventListener('click', function () { controller.hide(); });
                    document.body.appendChild(manualBackdrop);
                }
            },
            hide: function () {
                modalEl.classList.remove('show');
                modalEl.style.display = 'none';
                modalEl.setAttribute('aria-hidden', 'true');
                modalEl.removeAttribute('aria-modal');
                document.body.classList.remove('modal-open');

                if (manualBackdrop) {
                    manualBackdrop.remove();
                    manualBackdrop = null;
                }
            }
        };

        modalEl.querySelectorAll('[data-bs-dismiss="modal"]').forEach(function (btn) {
            btn.addEventListener('click', function () { controller.hide(); });
        });

        return controller;
    }

    const modalController = makeModalController(invoicePickerModalEl);

    function showItemsWarning(message) {
        itemsWarningBox.textContent = message || '';
        itemsWarningBox.classList.toggle('d-none', !message);
    }

    function showLoadingInvoices() {
        invoiceListWrap.innerHTML = '<div class="col-12"><div class="text-muted">در حال بارگذاری فاکتورها...</div></div>';
        confirmInvoiceBtn.disabled = true;
    }

    function showInvoiceError(message) {
        invoiceListWrap.innerHTML = '<div class="col-12"><div class="text-danger">' + escapeHtml(message) + '</div></div>';
        confirmInvoiceBtn.disabled = true;
    }

    function resetInvoiceSelection() {
        selectedInvoice = null;
        customerInvoices = [];
        invoiceItems = [];

        relatedInvoiceUuid.value = '';
        selectedInvoiceUuid.textContent = '—';
        selectedInvoiceDate.textContent = '—';
        selectedInvoiceTotal.textContent = '—';

        selectedInvoiceBox.classList.add('d-none');
        invoiceEmptyBox.classList.remove('d-none');

        itemsSection.classList.add('d-none');
        tbody.innerHTML = '';
        recalcInternalReturnSummary();
        itemsEmptyState.classList.add('d-none');
        showItemsWarning('');
        confirmInvoiceBtn.disabled = true;
    }

    function applySelectedInvoice(invoice) {
        selectedInvoice = invoice;
        relatedInvoiceUuid.value = invoice.uuid || '';
        selectedInvoiceUuid.textContent = invoice.uuid || '—';
        selectedInvoiceDate.textContent = invoice.invoice_date || invoice.created_at || '—';
        selectedInvoiceTotal.textContent = toMoney(invoice.total || 0);

        selectedInvoiceBox.classList.remove('d-none');
        invoiceEmptyBox.classList.add('d-none');
    }

    function filteredInvoices(keyword) {
        const q = normalizeDigits(keyword).trim().toLowerCase();
        if (!q) return customerInvoices;

        return customerInvoices.filter(function (invoice) {
            const haystack = normalizeDigits([
                invoice.uuid || '',
                invoice.invoice_date || '',
                invoice.created_at || '',
                invoice.total || '',
                invoice.customer_name || '',
                invoice.customer_mobile || ''
            ].join(' ')).toLowerCase();

            return haystack.includes(q);
        });
    }

    function renderInvoiceCards(list) {
        invoiceListWrap.innerHTML = '';

        if (!list.length) {
            invoiceListWrap.innerHTML = '<div class="col-12"><div class="text-muted">فاکتوری برای این مشتری پیدا نشد.</div></div>';
            confirmInvoiceBtn.disabled = true;
            return;
        }

        list.forEach(function (invoice) {
            const col = document.createElement('div');
            col.className = 'col-md-6';

            const isActive = selectedInvoice && String(selectedInvoice.uuid) === String(invoice.uuid);

            col.innerHTML = `
                <div class="invoice-card ${isActive ? 'active' : ''}" data-uuid="${escapeHtml(invoice.uuid || '')}">
                    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                        <div class="fw-bold mono">${escapeHtml(invoice.uuid || '—')}</div>
                        <span class="badge text-bg-light">فاکتور فروش</span>
                    </div>

                    <div class="mt-3 d-flex justify-content-between align-items-center gap-2 flex-wrap">
                        <div>
                            <div class="muted">تاریخ فاکتور</div>
                            <div class="fw-bold">${escapeHtml(invoice.invoice_date || invoice.created_at || '—')}</div>
                        </div>

                        <div class="text-start">
                            <div class="muted">مبلغ کل</div>
                            <div class="fw-bold">${toMoney(invoice.total || 0)} ریال</div>
                        </div>

                        <div class="text-start">
                            <div class="muted">تعداد آیتم</div>
                            <div class="fw-bold">${toMoney(invoice.items_count || 0)}</div>
                        </div>
                    </div>
                </div>
            `;

            col.querySelector('.invoice-card').addEventListener('click', function () {
                selectedInvoice = invoice;
                confirmInvoiceBtn.disabled = false;
                renderInvoiceCards(filteredInvoices(invoiceSearchInput.value || ''));
            });

            invoiceListWrap.appendChild(col);
        });
    }

    async function loadCustomerInvoices(customerId) {
        showLoadingInvoices();
        selectedInvoice = null;

        try {
            const url = endpoints.customerInvoicesBase + '/' + encodeURIComponent(customerId) + '/invoices';
            const response = await fetch(url, {
                headers: { 'Accept': 'application/json' }
            });

            if (!response.ok) {
                showInvoiceError('خطا در دریافت فاکتورها. route مربوط به customerInvoices را بررسی کن.');
                return;
            }

            const payload = await response.json();
            customerInvoices = Array.isArray(payload) ? payload : (payload.invoices || []);
            renderInvoiceCards(customerInvoices);
        } catch (error) {
            showInvoiceError('ارتباط با سرور برای دریافت فاکتورها برقرار نشد.');
        }
    }

    function normalizeInvoiceProductsPayload(payload) {
        const raw = payload.products || payload.items || payload.variants || [];

        return raw.map(function (item) {
            const qty = toSafeNumber(item.qty ?? item.quantity ?? item.invoice_qty, 0);
            const returned = toSafeNumber(item.already_returned_qty ?? item.returned_qty, 0);
            const remaining = toSafeNumber(item.remaining_qty, Math.max(qty - returned, 0));

            return {
                invoice_item_id: toSafeNumber(item.invoice_item_id, 0),
                product_id: toSafeNumber(item.product_id, 0),
                variant_id: toSafeNumber(item.variant_id ?? item.product_variant_id, 0),
                name: String(item.name || item.product_name || 'بدون نام'),
                product_code: String(item.product_code || item.code || ''),
                variant_name: String(item.variant_name || 'بدون تنوع'),
                variant_code: String(item.variant_code || ''),
                variant_stock: toSafeNumber(item.variant_stock ?? item.stock, 0),
                qty: qty,
                already_returned_qty: returned,
                remaining_qty: remaining,
                unit_price: toSafeNumber(item.unit_price ?? item.price, 0),
                unit: String(item.unit || item.product_unit || 'عدد'),
            };
        }).filter(function (item) {
            return item.invoice_item_id > 0 && item.product_id > 0 && item.variant_id > 0;
        });
    }


    function returnItemQuantity(row) {
        if (!row || typeof row !== 'object') return 0;

        const value = row.quantity ?? row.qty ?? row.return_quantity ?? row.returned_quantity ?? 0;
        return toSafeNumber(value, 0);
    }

    function existingItemAsInvoiceRow(row) {
        const qty = returnItemQuantity(row);

        return {
            invoice_item_id: toSafeNumber(row.invoice_item_id, 0),
            product_id: toSafeNumber(row.product_id, 0),
            variant_id: toSafeNumber(row.variant_id ?? row.product_variant_id, 0),
            name: String(row.product_name || row.name || 'کالای ثبت‌شده'),
            product_code: String(row.product_code || row.code || ''),
            variant_name: String(row.variant_name || '—'),
            variant_code: String(row.variant_code || ''),
            variant_stock: 0,
            qty: toSafeNumber(row.invoice_qty, qty),
            already_returned_qty: toSafeNumber(row.already_returned_qty, 0),
            remaining_qty: Math.max(toSafeNumber(row.remaining_qty ?? row.returnable_quantity, 0), qty, 1),
            unit_price: toSafeNumber(row.unit_price ?? row.price, 0),
            unit: String(row.unit || 'عدد'),
            _fromExistingVoucher: true,
        };
    }

    function mergeExistingItemsForEdit(items) {
        if (!editingVoucherId || !Array.isArray(oldItems) || !oldItems.length) return items;

        const merged = items.slice();
        oldItems.forEach(function (row) {
            const exists = merged.some(function (item) {
                if (row.invoice_item_id && item.invoice_item_id && String(row.invoice_item_id) === String(item.invoice_item_id)) return true;

                const rowVariantId = row.variant_id ?? row.product_variant_id ?? '';

                return String(row.product_id || '') === String(item.product_id || '') &&
                       String(rowVariantId) === String(item.variant_id || item.product_variant_id || '');
            });

            if (!exists) {
                merged.push(existingItemAsInvoiceRow(row));
            }
        });

        return merged;
    }

    function oldQuantityFor(invoiceItemId, productId, variantId) {
        if (!Array.isArray(oldItems)) return null;

        const found = oldItems.find(function (row) {
            if (row.invoice_item_id && String(row.invoice_item_id) === String(invoiceItemId)) return true;

            const rowVariantId = row.variant_id ?? row.product_variant_id ?? '';

            return String(row.product_id || '') === String(productId) &&
                   String(rowVariantId) === String(variantId);
        });

        if (!found) return null;
        const qty = returnItemQuantity(found);
        return qty > 0 ? qty : null;
    }

    function itemRowTemplate(item, index, useOldQuantity) {
        const rawRemaining = toSafeNumber(item.remaining_qty, 0);
        const defaultQtyFromOld = useOldQuantity ? oldQuantityFor(item.invoice_item_id, item.product_id, item.variant_id) : null;
        const remaining = Math.max(defaultQtyFromOld !== null ? Math.max(rawRemaining, defaultQtyFromOld) : rawRemaining, 1);
        const defaultQty = Math.max(defaultQtyFromOld !== null ? defaultQtyFromOld : remaining, 1);

        return `
            <tr data-product-id="${escapeHtml(item.product_id)}" data-variant-id="${escapeHtml(item.variant_id)}" data-unit-price="${escapeHtml(item.unit_price || 0)}" data-default-quantity="${escapeHtml(defaultQty)}">
                <td>
                    <input type="hidden" name="items[${index}][invoice_item_id]" value="${escapeHtml(item.invoice_item_id)}">
                    <input type="hidden" name="items[${index}][product_id]" value="${escapeHtml(item.product_id)}">
                    <div class="fw-bold">${escapeHtml(item.name)}</div>
                </td>
                <td><span class="mono">${escapeHtml(item.product_code || '—')}</span></td>
                <td>
                    <input type="hidden" name="items[${index}][variant_id]" value="${escapeHtml(item.variant_id)}">
                    <div>${escapeHtml(item.variant_name || '—')}</div>
                </td>
                <td><span class="mono">${escapeHtml(item.variant_code || '—')}</span></td>
                <td><span class="badge text-bg-light">${toMoney(item.qty || 0)}</span></td>
                <td><span class="badge text-bg-light">${toMoney(item.already_returned_qty || 0)}</span></td>
                <td><span class="badge text-bg-primary">${toMoney(remaining)}</span></td>
                <td><span class="badge text-bg-light">${toMoney(item.unit_price || 0)} ریال</span></td>
                <td>
                    <div class="input-group input-group-sm">
                        <input
                        type="number"
                        min="1"
                        max="${escapeHtml(remaining)}"
                        name="items[${index}][quantity]"
                        class="form-control qty-input return-quantity-input"
                        value="${escapeHtml(defaultQty)}"
                        required
                    >
                        <span class="input-group-text">${escapeHtml(item.unit || 'عدد')}</span>
                    </div>
                </td>
                <td><span class="badge text-bg-light">${escapeHtml(item.unit || 'عدد')}</span></td>
                <td><span class="internal-line-total">۰ ریال</span></td>
                <td>
                    <button type="button" class="btn btn-sm btn-outline-danger remove-row-btn">حذف</button>
                </td>
            </tr>
        `;
    }

    function recalcInternalReturnSummary() {
        let qtyTotal = 0;
        let amountTotal = 0;

        tbody.querySelectorAll('tr').forEach(function (tr) {
            const qty = toSafeNumber(tr.querySelector('.qty-input')?.value, 0);
            const unitPrice = toSafeNumber(tr.dataset.unitPrice, 0);
            const lineTotal = Math.max(qty, 0) * Math.max(unitPrice, 0);
            const lineTotalEl = tr.querySelector('.internal-line-total');

            if (lineTotalEl) {
                lineTotalEl.textContent = toMoney(lineTotal) + ' ریال';
            }

            qtyTotal += Math.max(qty, 0);
            amountTotal += lineTotal;
        });

        internalReturnQtyTotal.textContent = toMoney(qtyTotal);
        internalReturnAmountTotal.textContent = toMoney(amountTotal);
        internalReturnSummary.classList.toggle('d-none', tbody.querySelectorAll('tr').length === 0);
    }

    function bindItemRows() {
        tbody.querySelectorAll('tr').forEach(function (tr) {
            const removeBtn = tr.querySelector('.remove-row-btn');
            const qtyInput = tr.querySelector('.qty-input');

            removeBtn.addEventListener('click', function () {
                tr.remove();
                refreshItemsStateAfterDelete();
                recalcInternalReturnSummary();
            });

            if (qtyInput && (!qtyInput.value || toSafeNumber(qtyInput.value, 0) <= 0)) {
                qtyInput.value = String(toSafeNumber(tr.dataset.defaultQuantity, 1));
            }

            if (!qtyInput) return;

            qtyInput.addEventListener('input', function () {
                const max = toSafeNumber(qtyInput.max, 0);
                let value = toSafeNumber(qtyInput.value, 0);

                if (max > 0 && value > max) {
                    qtyInput.value = String(max);
                }

                recalcInternalReturnSummary();
            });
        });
    }

    function refreshItemsStateAfterDelete() {
        const hasRows = tbody.querySelectorAll('tr').length > 0;
        itemsEmptyState.classList.toggle('d-none', hasRows);

        if (!hasRows) {
            showItemsWarning('همه ردیف‌ها حذف شده‌اند. برای ثبت، حداقل یک کالا باید در جدول باشد.');
        }
    }

    function renderInvoiceItems(useOldQuantity = false) {
        tbody.innerHTML = '';
        recalcInternalReturnSummary();
        showItemsWarning('');

        const sourceItems = useOldQuantity ? mergeExistingItemsForEdit(invoiceItems) : invoiceItems;
        const returnableItems = sourceItems.filter(function (item) {
            return Number(item.remaining_qty || 0) > 0;
        });

        itemsSection.classList.remove('d-none');

        if (!sourceItems.length) {
            itemsEmptyState.classList.remove('d-none');
            showItemsWarning('هیچ آیتمی از API فاکتور دریافت نشد. خروجی متد invoiceProducts را بررسی کن.');
            return;
        }

        if (!returnableItems.length) {
            itemsEmptyState.classList.remove('d-none');
            showItemsWarning('همه کالاهای این فاکتور قبلاً برگشت خورده‌اند یا مانده قابل برگشت ندارند.');
            return;
        }

        itemsEmptyState.classList.add('d-none');

        returnableItems.forEach(function (item, index) {
            tbody.insertAdjacentHTML('beforeend', itemRowTemplate(item, index, useOldQuantity));
        });

        bindItemRows();
        recalcInternalReturnSummary();
    }

    async function loadInvoiceProducts(uuid, useOldQuantity = false) {
        itemsSection.classList.remove('d-none');
        tbody.innerHTML = '';
        recalcInternalReturnSummary();
        itemsEmptyState.classList.add('d-none');
        showItemsWarning('در حال بارگذاری کالاهای خریداری‌شده فاکتور...');

        try {
            let url = endpoints.invoiceProductsBase + '/' + encodeURIComponent(uuid) + '/products';
            if (editingVoucherId) url += '?exclude_voucher_id=' + encodeURIComponent(editingVoucherId);
            const response = await fetch(url, {
                headers: { 'Accept': 'application/json' }
            });

            if (!response.ok) {
                invoiceItems = [];
                itemsEmptyState.classList.remove('d-none');
                showItemsWarning('خطا در دریافت کالاهای فاکتور. route مربوط به invoiceProducts را بررسی کن.');
                return;
            }

            const payload = await response.json();
            invoiceItems = normalizeInvoiceProductsPayload(payload);
            renderInvoiceItems(useOldQuantity);
        } catch (error) {
            if (useOldQuantity && Array.isArray(oldItems) && oldItems.length) {
                invoiceItems = oldItems.map(existingItemAsInvoiceRow);
                renderInvoiceItems(true);
                showItemsWarning('ارتباط با سرور برای دریافت کالاهای فاکتور برقرار نشد؛ اقلام ذخیره‌شده همین سند نمایش داده شد.');
                return;
            }

            invoiceItems = [];
            itemsEmptyState.classList.remove('d-none');
            showItemsWarning('ارتباط با سرور برای دریافت کالاهای فاکتور برقرار نشد.');
        }
    }

    function initCustomerSelect2() {
        if (!window.jQuery || !jQuery.fn || !jQuery.fn.select2) {
            return;
        }

        const $customer = jQuery('#customerSelect');

        $customer.select2({
            dir: 'rtl',
            width: '100%',
            placeholder: 'جستجو با نام، نام خانوادگی یا شماره تماس...',
            allowClear: true,
            language: {
                noResults: function () { return 'مشتری پیدا نشد'; },
                searching: function () { return 'در حال جستجو...'; },
                inputTooShort: function () { return 'برای جستجو تایپ کنید'; }
            },
            matcher: function (params, data) {
                const term = normalizeDigits(jQuery.trim(params.term || '')).toLowerCase();

                if (!term) return data;
                if (!data.element) return null;

                const option = data.element;
                const searchText = normalizeDigits((option.getAttribute('data-search') || '') + ' ' + (data.text || '')).toLowerCase();

                return searchText.includes(term) ? data : null;
            },
            templateResult: function (data) {
                if (!data.id || !data.element) return data.text;

                const option = data.element;
                const name = option.getAttribute('data-name') || data.text || '';
                const phone = option.getAttribute('data-phone') || '';

                const wrapper = document.createElement('div');
                wrapper.innerHTML =
                    '<div class="customer-result-name">' + escapeHtml(name) + '</div>' +
                    (phone ? '<div class="customer-result-phone">' + escapeHtml(phone) + '</div>' : '');

                return jQuery(wrapper);
            },
            templateSelection: function (data) {
                if (!data.id || !data.element) return data.text;

                const option = data.element;
                const name = option.getAttribute('data-name') || data.text || '';
                const phone = option.getAttribute('data-phone') || '';

                return phone ? (name + ' | ' + phone) : name;
            }
        });
    }


    function isManualReturn() {
        return returnTypeSelect.value === 'external_manual';
    }

    function selectSearchText(option, fallbackText) {
        return normalizeDigits(((option && option.getAttribute('data-search')) || '') + ' ' + (fallbackText || '')).toLowerCase();
    }

    function manualSelectMatcher(params, data) {
        const term = normalizeDigits(jQuery.trim(params.term || '')).toLowerCase();
        if (!term) return data;
        if (!data.element) return null;

        return selectSearchText(data.element, data.text).includes(term) ? data : null;
    }

    function productOptions(selected, categoryId = '') {
        const filteredProducts = categoryId ? products.filter(function (p) { return String(p.category_id || '') === String(categoryId); }) : products;
        return '<option value="">انتخاب کالا...</option><option value="__new__">➕ تعریف کالای جدید</option>' + filteredProducts.map(function (p) {
            const variantsSearch = (p.variants || []).map(function (v) {
                return [v.name || '', v.code || '', v.barcode || ''].join(' ');
            }).join(' ');
            const search = [p.name || '', p.code || '', p.barcode || '', variantsSearch].join(' ');
            return `<option value="${escapeHtml(p.id)}" data-search="${escapeHtml(search)}" ${String(selected || '') === String(p.id) ? 'selected' : ''}>${escapeHtml(p.name)}${p.code ? ' (' + escapeHtml(p.code) + ')' : ''}</option>`;
        }).join('');
    }

    function variantOptions(productId, selected) {
        const product = products.find(function (p) { return String(p.id) === String(productId); });
        if (!product) return '<option value="">ابتدا کالا...</option>';
        return '<option value="">انتخاب تنوع...</option>' + (product.variants || []).map(function (v) {
            const search = [v.name || '', v.code || '', v.barcode || '', product.name || '', product.code || ''].join(' ');
            return `<option value="${escapeHtml(v.id)}" data-search="${escapeHtml(search)}" data-code="${escapeHtml(v.code || v.barcode || product.code || product.barcode || '')}" data-price="${escapeHtml(v.price || product.price || 0)}" ${String(selected || '') === String(v.id) ? 'selected' : ''}>${escapeHtml(v.name)}${v.code ? ' [' + escapeHtml(v.code) + ']' : ''}</option>`;
        }).join('');
    }


    function categoryOptions(selected) {
        return '<option value="">دسته‌بندی...</option>' + categories.map(function (category) {
            return `<option value="${escapeHtml(category.id)}" ${String(selected || '') === String(category.id) ? 'selected' : ''}>${escapeHtml(category.name)}${category.code ? ' (' + escapeHtml(category.code) + ')' : ''}</option>`;
        }).join('');
    }

    function newProductFields(index, rowData = {}) {
        return `
            <div class="quick-new-product d-none mt-2 border rounded-3 p-2 bg-light">
                <div class="row g-2">
                    <div class="col-md-4"><input name="items[${index}][new_product_name]" class="form-control form-control-sm new-product-required" placeholder="نام کالای جدید" value="${escapeHtml(rowData.new_product_name || '')}"></div>
                    <div class="col-md-4"><select name="items[${index}][new_category_id]" class="form-select form-select-sm new-product-required">${categoryOptions(rowData.new_category_id)}</select></div>
                    <div class="col-md-4"><input name="items[${index}][new_variant_name]" class="form-control form-control-sm" placeholder="نام تنوع (اختیاری)" value="${escapeHtml(rowData.new_variant_name || '')}"></div>
                    <div class="col-md-6"><input name="items[${index}][new_buy_price]" inputmode="numeric" class="form-control form-control-sm quick-money" placeholder="قیمت خرید" value="${escapeHtml(rowData.new_buy_price || '')}"></div>
                    <div class="col-md-6"><input name="items[${index}][new_sell_price]" inputmode="numeric" class="form-control form-control-sm quick-money" placeholder="قیمت فروش" value="${escapeHtml(rowData.new_sell_price || '')}"></div>
                </div>
            </div>`;
    }


    function destroySelect2(select) {
        if (window.jQuery && jQuery.fn && jQuery(select).data('select2')) {
            jQuery(select).select2('destroy');
        }
    }

    function selectedProduct(productId) {
        return products.find(function (p) { return String(p.id) === String(productId); }) || null;
    }

    function fillVariantSelect(tr, productId, selectedVariantId = '') {
        const variantSelect = tr.querySelector('.manual-variant');
        const product = selectedProduct(productId);

        destroySelect2(variantSelect);
        variantSelect.innerHTML = '';
        variantSelect.disabled = false;

        if (!product) {
            variantSelect.innerHTML = '<option value="">ابتدا کالا...</option>';
            initManualSelect(variantSelect);
            return;
        }

        const variants = Array.isArray(product.variants) ? product.variants : [];

        if (!variants.length) {
            variantSelect.disabled = true;
            variantSelect.innerHTML = '<option value="">این کالا تنوعی ندارد</option>';
            initManualSelect(variantSelect);
            return;
        }

        variantSelect.innerHTML = variantOptions(product.id, selectedVariantId);

        if (variants.length === 1 && !selectedVariantId) {
            variantSelect.value = String(variants[0].id);
        }

        initManualSelect(variantSelect);
        if (window.jQuery && jQuery.fn) {
            jQuery(variantSelect).trigger('change.select2');
        }
    }

    function parseMoney(value) {
        return Number(normalizeDigits(value)
            .replace(/[٬,،\s]/g, '')
            .replace(/[^0-9]/g, '') || 0);
    }

    function syncMoneyRaw(row) {
        const displayInput = row.querySelector('.manual-price-display');
        const rawInput = row.querySelector('.manual-price');
        const raw = parseMoney(displayInput?.value || '');
        if (rawInput) rawInput.value = String(raw);
        return raw;
    }

    function initManualSelect(select) {
        if (!window.jQuery || !jQuery.fn || !jQuery.fn.select2) {
            return;
        }

        const $select = jQuery(select);
        if ($select.data('select2')) {
            $select.select2('destroy');
        }

        $select.select2({
            dir: 'rtl',
            width: '100%',
            matcher: manualSelectMatcher,
            placeholder: $select.hasClass('manual-product') ? 'جستجوی کالا...' : 'جستجوی تنوع...',
            language: {
                noResults: function () { return 'موردی پیدا نشد'; },
                searching: function () { return 'در حال جستجو...'; }
            }
        });
    }

    function initManualSelects(tr) {
        tr.querySelectorAll('.manual-product,.manual-variant').forEach(initManualSelect);
    }

    function recalcManualTotals() {
        let total = 0;
        manualTbody.querySelectorAll('tr').forEach(function (tr) {
            const qty = toSafeNumber(tr.querySelector('.manual-qty')?.value, 0);
            const price = syncMoneyRaw(tr);
            const line = Math.max(qty, 0) * Math.max(price, 0);
            tr.querySelector('.manual-line-total').textContent = toMoney(line) + ' ریال';
            total += line;
        });
        manualGrandTotal.textContent = toMoney(total);
        manualItemsEmptyState.classList.toggle('d-none', manualTbody.querySelectorAll('tr').length > 0);
    }

    function nextManualItemIndex() {
        manualItemIndex += 1;

        return manualItemIndex;
    }

    function addManualRow(rowData = {}) {
        const index = nextManualItemIndex();
        const unitPrice = toSafeNumber(rowData.unit_price, 0);
        const defaultQty = Math.max(returnItemQuantity(rowData), 1);
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><select class="form-select manual-category">${categoryOptions(rowData.category_id)}</select></td>
            <td><select name="items[${index}][product_id]" class="form-select manual-product" required>${productOptions(rowData.product_id, rowData.category_id)}</select>${newProductFields(index, rowData)}</td>
            <td><select name="items[${index}][variant_id]" class="form-select manual-variant" required>${variantOptions(rowData.product_id, rowData.variant_id)}</select></td>
            <td><span class="mono manual-code">—</span></td>
            <td><div class="input-group input-group-sm"><input name="items[${index}][quantity]" type="number" min="1" class="form-control manual-qty return-quantity-input" value="${escapeHtml(defaultQty)}" data-default-quantity="${escapeHtml(defaultQty)}" required><span class="input-group-text manual-unit-label">${escapeHtml(rowData.unit || 'عدد')}</span></div></td>
            <td><span class="badge text-bg-light manual-unit-cell">${escapeHtml(rowData.unit || 'عدد')}</span></td>
            <td>
                <input type="text" inputmode="numeric" autocomplete="off" class="form-control manual-price-display" value="${escapeHtml(unitPrice.toLocaleString('en-US'))}">
                <input type="hidden" name="items[${index}][unit_price]" class="manual-price" value="${escapeHtml(unitPrice)}">
            </td>
            <td><span class="manual-line-total">۰ ریال</span></td>
            <td><button type="button" class="btn btn-sm btn-outline-danger manual-remove">حذف</button></td>
        `;
        manualTbody.appendChild(tr);

        const categorySelect = tr.querySelector('.manual-category');
        const productSelect = tr.querySelector('.manual-product');
        const variantSelect = tr.querySelector('.manual-variant');
        const priceDisplayInput = tr.querySelector('.manual-price-display');
        const manualQtyInput = tr.querySelector('.manual-qty');
        const codeEl = tr.querySelector('.manual-code');
        const unitLabelEl = tr.querySelector('.manual-unit-label');
        const unitCellEl = tr.querySelector('.manual-unit-cell');

        function refreshVariantMeta(applySuggestedPrice) {
            if (manualQtyInput) {
                manualQtyInput.disabled = false;
                manualQtyInput.readOnly = false;
            }
            const isNewProduct = productSelect.value === '__new__';
            const quickFields = tr.querySelector('.quick-new-product');
            quickFields?.classList.toggle('d-none', !isNewProduct);
            tr.querySelectorAll('.new-product-required').forEach(function (el) { el.required = isNewProduct; });
            variantSelect.required = !isNewProduct;
            variantSelect.disabled = isNewProduct;
            if (isNewProduct) { codeEl.textContent = 'بعد از ثبت ساخته می‌شود'; unitLabelEl.textContent = 'عدد'; unitCellEl.textContent = 'عدد'; recalcManualTotals(); return; }
            const product = products.find(function (p) { return String(p.id) === String(productSelect.value); });
            const option = variantSelect.selectedOptions[0];
            codeEl.textContent = option?.dataset?.code || product?.code || product?.barcode || '—';
            const unit = product?.unit || 'عدد';
            unitLabelEl.textContent = unit;
            unitCellEl.textContent = unit;
            if (applySuggestedPrice && option?.dataset?.price !== undefined) {
                const suggested = Number(option.dataset.price || product?.price || 0);
                priceDisplayInput.value = suggested.toLocaleString('en-US');
            }
            recalcManualTotals();
        }

        function onCategoryChanged() {
            destroySelect2(productSelect);
            productSelect.innerHTML = productOptions('', categorySelect.value);
            initManualSelect(productSelect);
            fillVariantSelect(tr, '');
            refreshVariantMeta(true);
        }

        function onProductChanged() {
            if (productSelect.value !== '__new__') fillVariantSelect(tr, productSelect.value);
            refreshVariantMeta(true);
        }

        function onVariantChanged() {
            refreshVariantMeta(true);
        }

        if (window.jQuery && jQuery.fn) {
            jQuery(categorySelect).on('change', onCategoryChanged);
            jQuery(productSelect).on('change', onProductChanged);
            jQuery(variantSelect).on('change', onVariantChanged);
        } else {
            categorySelect.addEventListener('change', onCategoryChanged);
            productSelect.addEventListener('change', onProductChanged);
            variantSelect.addEventListener('change', onVariantChanged);
        }
        if (manualQtyInput && (!manualQtyInput.value || toSafeNumber(manualQtyInput.value, 0) <= 0)) {
            manualQtyInput.value = String(toSafeNumber(manualQtyInput.dataset.defaultQuantity, 1));
        }
        tr.querySelectorAll('.quick-money').forEach(function (input) { input.addEventListener('input', function () { const raw = parseMoney(input.value); input.value = input.value.trim() === '' ? '' : raw.toLocaleString('en-US'); }); });
        priceDisplayInput.addEventListener('input', function () {
            const raw = parseMoney(priceDisplayInput.value);
            priceDisplayInput.value = priceDisplayInput.value.trim() === '' ? '' : raw.toLocaleString('en-US');
            recalcManualTotals();
        });
        tr.querySelector('.manual-remove').addEventListener('click', function () {
            if (window.jQuery && jQuery.fn) {
                jQuery(tr).find('.manual-product,.manual-variant').each(function () {
                    if (jQuery(this).data('select2')) jQuery(this).select2('destroy');
                });
            }
            tr.remove();
            recalcManualTotals();
        });
        fillVariantSelect(tr, productSelect.value, rowData.variant_id || '');
        initManualSelects(tr);
        refreshVariantMeta(false);
    }

    function toggleReturnMode() {
        const manual = isManualReturn();
        externalInvoiceWrap.classList.toggle('d-none', !manual);
        externalInvoiceNumber.required = manual;
        invoicePickerWrap.classList.toggle('d-none', manual);
        selectedInvoiceBox.classList.toggle('d-none', manual || !relatedInvoiceUuid.value);
        invoiceEmptyBox.classList.toggle('d-none', manual || !!relatedInvoiceUuid.value);
        itemsSection.classList.toggle('d-none', manual || !relatedInvoiceUuid.value);
        manualItemsSection.classList.toggle('d-none', !manual);
        if (manual) {
            relatedInvoiceUuid.value = '';
            tbody.innerHTML = '';
            openInvoiceModalBtn.disabled = true;
            if (!manualTbody.children.length) addManualRow();
        } else {
            openInvoiceModalBtn.disabled = !customerSelect.value;
            manualTbody.innerHTML = '';
            recalcManualTotals();
        }
    }

    customerSelect.addEventListener('change', function () {
        if (!isManualReturn()) {
            resetInvoiceSelection();
            openInvoiceModalBtn.disabled = !customerSelect.value;
        }
    });

    returnTypeSelect.addEventListener('change', function () {
        resetInvoiceSelection();
        toggleReturnMode();
    });

    addManualItemBtn.addEventListener('click', function () { addManualRow(); });

    manualTbody.addEventListener('input', function (event) {
        if (event.target && event.target.classList.contains('return-quantity-input')) {
            event.target.disabled = false;
            event.target.readOnly = false;
            recalcManualTotals();
        }
    });

    openInvoiceModalBtn.addEventListener('click', function () {
        if (!customerSelect.value) {
            alert('اول مشتری را انتخاب کن.');
            return;
        }

        invoiceSearchInput.value = '';
        modalController.show();
        loadCustomerInvoices(customerSelect.value);
    });

    changeInvoiceBtn.addEventListener('click', function () {
        openInvoiceModalBtn.click();
    });

    invoiceSearchInput.addEventListener('input', function () {
        renderInvoiceCards(filteredInvoices(this.value || ''));
    });

    confirmInvoiceBtn.addEventListener('click', async function () {
        if (!selectedInvoice) {
            alert('یک فاکتور را انتخاب کن.');
            return;
        }

        applySelectedInvoice(selectedInvoice);
        modalController.hide();
        await loadInvoiceProducts(selectedInvoice.uuid, false);
    });

    reloadItemsBtn.addEventListener('click', function () {
        if (!relatedInvoiceUuid.value) {
            alert('ابتدا فاکتور را انتخاب کنید.');
            return;
        }

        loadInvoiceProducts(relatedInvoiceUuid.value, false);
    });

    returnForm.addEventListener('submit', function (event) {
        if (!customerSelect.value) {
            event.preventDefault();
            alert('لطفاً مشتری را انتخاب کنید.');
            return;
        }

        if (!isManualReturn() && !relatedInvoiceUuid.value) {
            event.preventDefault();
            alert('لطفاً فاکتور مشتری را انتخاب کنید.');
            return;
        }

        if (isManualReturn() && !externalInvoiceNumber.value.trim()) {
            event.preventDefault();
            alert('لطفاً شماره فاکتور سازه‌حساب را وارد کنید.');
            return;
        }

        if (!warehouseSelect.value) {
            event.preventDefault();
            alert('لطفاً انبار مقصد برگشت را انتخاب کنید.');
            return;
        }

        if (!returnReasonSelect.value) {
            event.preventDefault();
            alert('لطفاً علت برگشت از فروش را انتخاب کنید.');
            return;
        }

        if (isManualReturn()) {
            recalcManualTotals();
        }

        const rows = Array.from((isManualReturn() ? manualTbody : tbody).querySelectorAll('tr'));

        if (!rows.length) {
            event.preventDefault();
            alert('حداقل یک کالای برگشتی باید در جدول باشد.');
            return;
        }

        const seenVariants = new Set();

        for (const row of rows) {
            const productId = row.querySelector('[name$="[product_id]"]')?.value || '';
            const variantId = row.querySelector('[name$="[variant_id]"]')?.value || (productId === '__new__' ? '__new__' : '');
            const qtyInput = row.querySelector(isManualReturn() ? '.manual-qty' : '.qty-input');
            const qty = toSafeNumber(qtyInput?.value, 0);
            const maxQty = isManualReturn() ? 0 : toSafeNumber(qtyInput?.max, 0);
            const unitPrice = isManualReturn() ? toSafeNumber(row.querySelector('.manual-price')?.value, -1) : 0;
            const dupKey = productId + ':' + variantId;

            if (!productId || !variantId || qty <= 0 || (isManualReturn() && unitPrice < 0)) {
                event.preventDefault();
                alert('تعداد برگشتی همه ردیف‌ها باید حداقل ۱ باشد. اگر کالایی برگشت ندارد، ردیف آن را حذف کن.');
                return;
            }

            if (seenVariants.has(dupKey)) {
                event.preventDefault();
                alert('این محصول/تنوع تکراری است. هر تنوع فقط یک‌بار می‌تواند ثبت شود.');
                return;
            }

            seenVariants.add(dupKey);

            if (maxQty > 0 && qty > maxQty) {
                event.preventDefault();
                alert('تعداد برگشتی نمی‌تواند بیشتر از مقدار قابل برگشت باشد.');
                return;
            }
        }

        submitBtn.disabled = true;
        submitBtn.textContent = 'در حال ثبت...';
    });

    if (Array.isArray(oldItems) && oldItems.length && isManualReturn()) {
        manualTbody.innerHTML = '';
        oldItems.forEach(function (row) { addManualRow(row); });
    }

    toggleReturnMode();
    initCustomerSelect2();

    if (customerSelect.value && !isManualReturn()) {
        openInvoiceModalBtn.disabled = false;
    }

    if (oldRelatedInvoiceUuid && !isManualReturn()) {
        if (Array.isArray(oldItems) && oldItems.length) {
            invoiceItems = oldItems.map(existingItemAsInvoiceRow);
            renderInvoiceItems(true);
        }

        applySelectedInvoice(existingInvoice || {
            uuid: oldRelatedInvoiceUuid,
            invoice_date: '—',
            created_at: '—',
            total: 0
        });

        loadInvoiceProducts(oldRelatedInvoiceUuid, true);
    }
});
</script>
@endsection
