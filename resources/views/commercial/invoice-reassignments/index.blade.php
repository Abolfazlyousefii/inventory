@extends('layouts.app')

@section('title', 'انتقال فروشنده فاکتور')

@php
    $pageCssPath = public_path('css/commercial-invoice-reassignments.css');
    $pageJsPath = public_path('js/commercial-invoice-reassignments.js');
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/commercial-invoice-reassignments.css') }}?v={{ is_file($pageCssPath) ? filemtime($pageCssPath) : 1 }}">
@endpush

@section('content')
<div class="invoice-reassignment-page"
     id="invoiceReassignmentApp"
     data-search-url="{{ route('commercial.invoice-reassignments.search') }}"
     data-preview-url="{{ route('commercial.invoice-reassignments.preview') }}">

    <div class="invoice-reassignment-header">
        <div>
            <span class="invoice-reassignment-eyebrow">بازرگانی و فروش</span>
            <h1>انتقال فروشنده فاکتور</h1>
            <p>انتقال مالک فروش با آزادسازی خودکار فاکتور از اسناد پورسانت فروشنده قبلی و حفظ کامل تاریخچه.</p>
        </div>
        <div class="invoice-reassignment-header__badge">دسترسی مستقل حسابداری</div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">{{ collect($errors->all())->unique()->join(' ') }}</div>
    @endif

    <div class="invoice-reassignment-notice">
        <strong>رفتار سیستم پس از انتقال</strong>
        <span>اگر فاکتور در سند پورسانت فروشنده قبلی باشد، ردیف تاریخی حذف نمی‌شود؛ از حالت فعال خارج و فاکتور برای فروشنده جدید آزاد می‌شود. سپس می‌توان آن را در سند جدید یا ویرایش سند قبلی فروشنده مقصد اضافه کرد.</span>
    </div>

    <section class="invoice-reassignment-card">
        <div class="invoice-reassignment-card__head">
            <div>
                <h2>۱. انتخاب فاکتورها</h2>
                <p>با شماره فاکتور، نام یا موبایل مشتری و نام فروشنده جست‌وجو کنید.</p>
            </div>
            <span class="selection-badge"><strong id="selectedInvoiceCount">۰</strong> فاکتور انتخاب شده</span>
        </div>

        <div class="invoice-search-grid">
            <div>
                <label for="invoiceReassignmentSearch">جست‌وجو</label>
                <input type="search" class="form-control" id="invoiceReassignmentSearch" autocomplete="off" placeholder="مثلاً 00657، نام مشتری یا فروشنده">
            </div>
            <div>
                <label for="invoiceCurrentSellerFilter">فروشنده فعلی</label>
                <select class="form-select" id="invoiceCurrentSellerFilter">
                    <option value="">همه فروشندگان</option>
                    @foreach($sellers as $seller)
                        <option value="{{ $seller->id }}">{{ $seller->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="invoice-search-actions">
                <button type="button" class="btn btn-primary" id="invoiceSearchButton">جست‌وجو</button>
                <button type="button" class="btn btn-light" id="invoiceSearchReset">پاک کردن</button>
            </div>
        </div>

        <div class="invoice-search-state" id="invoiceSearchState">در حال دریافت آخرین فاکتورها…</div>
        <div class="invoice-search-error alert alert-danger" id="invoiceSearchError" hidden></div>

        <div class="table-responsive invoice-search-table-wrap">
            <table class="table align-middle invoice-search-table mb-0">
                <thead>
                    <tr>
                        <th class="invoice-select-col"><input type="checkbox" class="form-check-input" id="invoiceSelectVisible" aria-label="انتخاب همه موارد نمایش داده‌شده"></th>
                        <th>فاکتور</th>
                        <th>مشتری</th>
                        <th>فروشنده فعلی</th>
                        <th>وضعیت پورسانت</th>
                        <th>مبلغ</th>
                    </tr>
                </thead>
                <tbody id="invoiceSearchRows"></tbody>
            </table>
        </div>

        <div class="invoice-search-empty" id="invoiceSearchEmpty" hidden>فاکتوری با این مشخصات پیدا نشد.</div>
    </section>

    <form method="POST" action="{{ route('commercial.invoice-reassignments.store') }}" id="invoiceTransferForm" class="invoice-reassignment-card">
        @csrf
        <div id="invoiceSelectedInputs"></div>
        <input type="hidden" name="preview_token" id="invoicePreviewToken" value="">
        <input type="hidden" name="sync_preinvoice" value="0">

        <div class="invoice-reassignment-card__head">
            <div>
                <h2>۲. مقصد و دلیل انتقال</h2>
                <p>انتقال فقط از همین فرآیند مرکزی انجام می‌شود تا مالک فروش و پورسانت همزمان اصلاح شوند.</p>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="clearSelectedInvoices">لغو انتخاب‌ها</button>
        </div>

        <div class="invoice-transfer-grid">
            <div>
                <label for="destinationSeller">فروشنده مقصد <span class="text-danger">*</span></label>
                <select class="form-select" id="destinationSeller" name="seller_id" required>
                    <option value="">انتخاب فروشنده</option>
                    @foreach($sellers as $seller)
                        <option value="{{ $seller->id }}" @selected((string) old('seller_id') === (string) $seller->id)>{{ $seller->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="invoice-transfer-reason">
                <label for="transferReason">دلیل انتقال <span class="text-danger">*</span></label>
                <textarea class="form-control" id="transferReason" name="reason" rows="2" maxlength="1000" required placeholder="مثلاً اصلاح مالک فروش / انتقال مشتری">{{ old('reason') }}</textarea>
            </div>
            <div class="invoice-transfer-sync">
                <label class="form-check">
                    <input class="form-check-input" type="checkbox" id="syncPreinvoice" name="sync_preinvoice" value="1" checked>
                    <span class="form-check-label">فروشنده پیش‌فاکتور مرتبط هم همگام شود</span>
                </label>
                <small>ثبت‌کننده اصلی پیش‌فاکتور تغییر نمی‌کند.</small>
            </div>
        </div>

        <div class="invoice-transfer-actions">
            <button type="button" class="btn btn-outline-primary" id="previewTransferButton" disabled>بررسی قبل از انتقال</button>
            <button type="submit" class="btn btn-warning" id="confirmTransferButton" disabled>تأیید و انتقال</button>
        </div>
    </form>

    <section class="invoice-reassignment-card invoice-preview-card" id="invoiceTransferPreview" hidden>
        <div class="invoice-reassignment-card__head">
            <div>
                <h2>پیش‌نمایش اثر انتقال</h2>
                <p>این بخش فقط پیش‌نمایش است و هیچ تغییری در دیتابیس ایجاد نمی‌کند.</p>
            </div>
            <span class="preview-ready-badge">آماده تأیید</span>
        </div>

        <div class="invoice-preview-stats" id="invoicePreviewStats"></div>
        <div class="table-responsive">
            <table class="table align-middle invoice-preview-table mb-0">
                <thead><tr><th>فاکتور</th><th>فروشنده فعلی</th><th>فروشنده مقصد</th><th>اثر روی پورسانت</th><th>مبلغ</th></tr></thead>
                <tbody id="invoicePreviewRows"></tbody>
            </table>
        </div>
        <div class="invoice-preview-warning">با تأیید نهایی، اتصال فعال فاکتور به سند پورسانت فروشنده قبلی آزاد می‌شود؛ ردیف تاریخی آن سند حذف نخواهد شد.</div>
    </section>

    <section class="invoice-reassignment-card">
        <div class="invoice-reassignment-card__head">
            <div>
                <h2>تاریخچه انتقال فروشنده</h2>
                <p>انتقال‌های واقعی همراه با انجام‌دهنده، دلیل و تعداد اتصالات پورسانتی آزادشده.</p>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table align-middle invoice-history-table mb-0">
                <thead><tr><th>فاکتور</th><th>از فروشنده</th><th>به فروشنده</th><th>تاریخ</th><th>انجام‌دهنده</th><th>پورسانت آزادشده</th><th>دلیل</th></tr></thead>
                <tbody>
                @forelse($history as $audit)
                    <tr>
                        <td><strong>{{ $audit->invoice?->uuid ?: '#'.$audit->invoice_id }}</strong></td>
                        <td>{{ $audit->oldSeller?->name ?: 'بدون فروشنده' }}</td>
                        <td><strong>{{ $audit->newSeller?->name ?: '—' }}</strong></td>
                        <td>{{ App\Support\JalaliDate::dateTime($audit->changed_at) }}</td>
                        <td>{{ $audit->changedByUser?->name ?: '—' }}</td>
                        <td>
                            @if($audit->released_commission_items_count > 0)
                                <span class="history-released">{{ number_format($audit->released_commission_items_count) }} مورد</span>
                            @else
                                <span class="text-muted">نداشت</span>
                            @endif
                        </td>
                        <td class="history-reason">{{ $audit->reason ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="invoice-history-empty">هنوز انتقالی از مسیر جدید ثبت نشده است.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $history->links() }}</div>
    </section>
</div>
@endsection

@push('scripts')
    <script src="{{ asset('js/commercial-invoice-reassignments.js') }}?v={{ is_file($pageJsPath) ? filemtime($pageJsPath) : 1 }}" defer></script>
@endpush
