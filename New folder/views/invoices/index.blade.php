@extends('layouts.app')

@section('title', 'فاکتورهای فروش')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/invoices-index.css') }}">
@endpush

@section('content')
<main class="invoice-live" id="invoiceLiveApp"
      data-endpoint="{{ route('invoices.data') }}"
      data-customers-endpoint="{{ route('invoices.customers.search') }}"
      data-index-url="{{ route('invoices.index') }}">
    <header class="invoice-live__header">
        <div>
            <h1>فاکتورهای فروش</h1>
            <p>جست‌وجو و پیگیری سریع فاکتورهای ثبت‌شده</p>
        </div>
        @if($canViewCancelled)
            <a class="btn btn-outline-danger" href="{{ route('invoices.cancelled') }}">بایگانی لغوشده‌ها</a>
        @endif
    </header>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    <section class="invoice-filters" aria-label="فیلتر فاکتورها">
        <div class="invoice-filter-field">
            <label for="invoiceOrderCode">کد سفارش</label>
            <input id="invoiceOrderCode" class="form-control" inputmode="numeric" maxlength="5" autocomplete="off" placeholder="مثلاً 00481">
            <small class="field-error" data-error-for="order_code"></small>
        </div>
        <div class="invoice-filter-field customer-picker" id="invoiceCustomerPicker">
            <label for="invoiceCustomerSearch">مشتری</label>
            <input id="invoiceCustomerSearch" class="form-control" autocomplete="off" placeholder="جست‌وجوی نام، موبایل یا کد مشتری" role="combobox" aria-expanded="false">
            <input id="invoiceCustomerId" type="hidden">
            <button type="button" class="customer-picker__clear" id="invoiceCustomerClear" aria-label="حذف مشتری انتخاب‌شده" hidden>×</button>
            <div class="customer-picker__results" role="listbox" hidden></div>
            <small class="field-error" data-error-for="customer_id"></small>
        </div>
        <div class="invoice-filter-field">
            <label for="invoiceDateFrom">از تاریخ</label>
            <input id="invoiceDateFrom" class="form-control" inputmode="numeric" autocomplete="off" placeholder="۱۴۰۵/۰۱/۰۱">
            <small class="field-error" data-error-for="date_from"></small>
        </div>
        <div class="invoice-filter-field">
            <label for="invoiceDateTo">تا تاریخ</label>
            <input id="invoiceDateTo" class="form-control" inputmode="numeric" autocomplete="off" placeholder="۱۴۰۵/۱۲/۲۹">
            <small class="field-error" data-error-for="date_to"></small>
        </div>
        <div class="invoice-filter-actions">
            <div class="quick-ranges" role="group" aria-label="بازه سریع">
                <button type="button" data-range="today">امروز</button>
                <button type="button" data-range="week">این هفته</button>
                <button type="button" data-range="month">این ماه</button>
            </div>
            <button type="button" class="btn btn-light" id="invoiceClearFilters">پاک کردن</button>
        </div>
    </section>

    <div class="invoice-live__error alert alert-danger" id="invoiceLiveError" hidden>
        <span>دریافت فاکتورها انجام نشد.</span>
        <button type="button" class="btn btn-sm btn-outline-danger" id="invoiceRetry">تلاش دوباره</button>
    </div>

    <section id="invoiceSummary" class="invoice-summary" aria-live="polite"></section>

    @if($canReassignSeller)
    <form id="bulkSellerForm" class="card card-body mb-3" method="POST" action="{{ route('invoices.bulk.reassign-seller') }}">
        @csrf
        <input type="hidden" name="operation_key" value="{{ (string) Str::uuid() }}">
        <div class="row g-2 align-items-end">
            <div class="col-md-3"><label class="form-label">فروشنده مقصد</label><select class="form-select" name="seller_id" required><option value="">انتخاب کنید</option>@foreach($sellers as $seller)<option value="{{ $seller->id }}">{{ $seller->name }} (#{{ $seller->id }})</option>@endforeach</select></div>
            <div class="col-md-5"><label class="form-label">دلیل تغییر</label><input class="form-control" name="reason" maxlength="1000" required></div>
            <div class="col-md-2"><label class="form-check"><input class="form-check-input" type="checkbox" name="sync_preinvoice" value="1" checked> همگام‌سازی پیش‌فاکتور</label></div>
            <div class="col-md-2"><button class="btn btn-warning w-100" onclick="return confirm('فروشنده فاکتورهای انتخاب‌شده تغییر کند؟')">تغییر گروهی</button></div>
        </div>
        <small class="text-muted mt-2">فاکتورها را از جدول انتخاب کنید؛ حداکثر ۱۰۰ مورد و عملیات اتمیک است.</small>
    </form>
    @endif

    <section class="invoice-results" aria-live="polite" aria-busy="true">
        <div class="invoice-desktop">
            <table class="table align-middle mb-0">
                <thead><tr><th>کد و تاریخ</th><th>مشتری</th><th>فروشنده</th><th>وضعیت</th><th>مبلغ و پرداخت</th><th class="text-end">عملیات</th></tr></thead>
                <tbody id="invoiceDesktopRows"></tbody>
            </table>
        </div>
        <div class="invoice-mobile" id="invoiceMobileCards"></div>
        <div class="invoice-skeleton" id="invoiceSkeleton" aria-label="در حال دریافت">
            @for($i = 0; $i < 5; $i++)<div class="invoice-skeleton__row"></div>@endfor
        </div>
        <div class="invoice-empty" id="invoiceEmpty" hidden><strong>فاکتوری با این مشخصات پیدا نشد.</strong><p>فیلترها را پاک کنید یا کد دیگری وارد کنید.</p><button type="button" class="btn btn-sm btn-outline-primary" data-clear-filters>پاک‌کردن فیلترها</button></div>
    </section>
    <div id="invoiceLoadSentinel" class="invoice-load-sentinel"></div>
    <div class="text-center text-muted small my-2" id="invoiceLoadStatus"></div>
    <button type="button" class="btn btn-outline-primary invoice-load-more" id="invoiceLoadMore" hidden>نمایش موارد بیشتر</button>

    <div class="modal fade" id="invoiceCancelModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <div class="modal-header"><h2 class="modal-title fs-5">لغو فاکتور <span data-cancel-number></span></h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST" id="invoiceCancelForm">@csrf
                <div class="modal-body">
                    <div class="alert alert-warning small">موجودی اقلام به انبار مرکزی بازمی‌گردد و بدهکاری این فاکتور از گردش حساب حذف می‌شود. پرداخت‌ها و چک‌های مشتری حذف نمی‌شوند.</div>
                    <div class="alert alert-danger small" data-shipped-warning hidden><div>این فاکتور ارسال شده است؛ بازگشت فیزیکی کالا باید تأیید شود.</div><label class="form-check mt-2"><input class="form-check-input" type="checkbox" name="physical_return_confirmed" value="1" id="invoicePhysicalReturn"> بازگشت فیزیکی کالا را تأیید می‌کنم.</label></div>
                    <label class="form-label" for="invoiceCancellationReason">دلیل لغو</label>
                    <textarea class="form-control" id="invoiceCancellationReason" name="cancellation_reason" rows="3" required></textarea>
                    <label class="form-label mt-3" for="invoiceCancelConfirmation">تأیید شماره فاکتور</label><input class="form-control" id="invoiceCancelConfirmation" name="confirm_invoice_uuid" required dir="ltr" autocomplete="off"><div class="form-text">شماره فاکتور را دقیق وارد کنید.</div>
                    <label class="form-label mt-3" for="invoiceCancellationNote">توضیحات</label><textarea class="form-control" id="invoiceCancellationNote" name="cancellation_note" rows="3" maxlength="1000"></textarea>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">انصراف</button><button class="btn btn-danger" id="invoiceCancelSubmit" disabled>لغو قطعی فاکتور</button></div>
            </form>
        </div></div>
    </div>

    <script type="application/json" id="invoiceInitialState">{!! json_encode(['filters' => $initialFilters, 'customer' => $initialCustomer], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
</main>
@endsection

@push('scripts')
    <script src="{{ asset('js/invoices-index.js') }}" defer></script>
@endpush
