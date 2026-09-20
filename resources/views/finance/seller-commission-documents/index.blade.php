@extends('layouts.app')
@section('title', 'مدیریت پورسانت فروشندگان')

@section('content')
@php
    use App\Support\Currency;
    use App\Support\JalaliDate;
    $issueUrl = $canIssueDocument
        ? route('finance.seller-sales.create', ['seller_id' => $filters['seller_id'], 'date_from' => $filters['date_from'], 'date_to' => $filters['date_to']])
        : null;
@endphp

<div class="container-fluid py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">مدیریت پورسانت فروشندگان</h1>
            <p class="text-muted mb-0">مشاهده فروش، بررسی پورسانت و مدیریت اسناد ثبت‌شده</p>
        </div>
    </div>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if(session('info'))<div class="alert alert-info">{{ session('info') }}</div>@endif
    @if($rangeError)<div class="alert alert-danger">{{ $rangeError }}</div>@endif

    <div class="mb-3 d-flex flex-wrap gap-2">
        <a href="{{ route('finance.commission-rates.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-gear"></i> تنظیمات نرخ پورسانت
        </a>
        <a href="{{ route('finance.org-commission.index') }}" class="btn btn-outline-info">
            پورسانت اداری
        </a>
    </div>

    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link @if($activeTab === 'report') active @endif" id="reportTabButton" data-bs-toggle="tab" data-bs-target="#reportTab" type="button" role="tab">گزارش و صدور سند</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link @if($activeTab === 'documents') active @endif" id="documentsTabButton" data-bs-toggle="tab" data-bs-target="#documentsTab" type="button" role="tab">اسناد ثبت‌شده</button>
        </li>
    </ul>

    <div class="tab-content">

        {{-- ================= گزارش و صدور سند ================= --}}
        <div class="tab-pane fade @if($activeTab === 'report') show active @endif" id="reportTab" role="tabpanel">

            <form method="GET" action="{{ route('finance.seller-sales.index') }}" class="card shadow-sm mb-4">
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label" for="reportSellerId">فروشنده <span class="text-danger">*</span></label>
                            <select class="form-select" id="reportSellerId" name="seller_id" required>
                                <option value="">انتخاب فروشنده</option>
                                @foreach($users as $user)
                                    <option value="{{ $user->id }}" @selected($filters['seller_id'] == $user->id)>{{ $user->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-6">
                            <label class="form-label" for="reportDateFrom">از تاریخ <span class="text-danger">*</span></label>
                            <input class="form-control" id="reportDateFrom" name="date_from" data-jdp autocomplete="off" value="{{ request('date_from') }}" required>
                        </div>
                        <div class="col-lg-2 col-md-6">
                            <label class="form-label" for="reportDateTo">تا تاریخ <span class="text-danger">*</span></label>
                            <input class="form-control" id="reportDateTo" name="date_to" data-jdp autocomplete="off" value="{{ request('date_to') }}" required>
                        </div>
                        <div class="col-lg-2 col-md-6">
                            <label class="form-label" for="reportInvoiceNumber">شماره فاکتور</label>
                            <input class="form-control" id="reportInvoiceNumber" name="invoice_number" maxlength="100" value="{{ $filters['invoice_number'] }}">
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label" for="reportCustomer">نام یا موبایل مشتری</label>
                            <input class="form-control" id="reportCustomer" name="customer" maxlength="100" value="{{ $filters['customer'] }}">
                        </div>
                        <div class="col-12 d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-primary">نمایش گزارش</button>
                            <a class="btn btn-outline-secondary" href="{{ route('finance.seller-sales.index') }}">پاک‌کردن فیلترها</a>
                        </div>
                    </div>
                </div>
            </form>

            @if(!$report)
                <div class="alert alert-info">برای مشاهده گزارش، فروشنده و بازه تاریخ را انتخاب کنید.</div>
            @else
                <div class="row g-3 mb-4">
                    <div class="col-xl-3 col-md-6"><div class="card card-body h-100"><span class="text-muted">پورسانت محاسبه‌شده</span><strong class="fs-5">{{ Currency::formatRial($report['summary']['calculated_commission_total']) }}</strong></div></div>
                    <div class="col-xl-3 col-md-6"><div class="card card-body h-100"><span class="text-muted">فاکتورهای دارای نرخ ناقص</span><strong class="fs-5">{{ number_format($report['summary']['missing_rate_invoice_count']) }}</strong></div></div>
                    <div class="col-xl-3 col-md-6"><div class="card card-body h-100"><span class="text-muted">ردیف‌های کالای بدون نرخ</span><strong class="fs-5">{{ number_format($report['summary']['missing_rate_item_count']) }}</strong></div></div>
                    <div class="col-xl-3 col-md-6"><div class="card card-body h-100"><span class="text-muted">مبنای بدون نرخ / وصول نقدی</span><strong class="fs-6">{{ Currency::formatRial($report['summary']['missing_rate_base_total']) }} / {{ Currency::formatRial($report['summary']['cash_collected']) }} ({{ number_format($report['summary']['cash_ratio'],1) }}٪)</strong></div></div>
                </div>

                @if($report['summary']['missing_rate_item_count'])
                    <div class="alert alert-warning">برای بخشی از کالاهای این گزارش نرخ پورسانت تعریف نشده است؛ مبلغ پورسانت آن‌ها در جمع محاسبه‌شده لحاظ نشده است.</div>
                @endif

                <div class="card shadow-sm mb-4">
                    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <strong>صدور سند از این گزارش</strong>
                            <div class="text-muted small">فروشنده و بازه تاریخ این گزارش به فرم ثبت سند منتقل می‌شود و تنها فاکتورهای آزاد همان فروشنده قابل انتخاب خواهند بود.</div>
                        </div>
                        @if($issueUrl)
                            <a class="btn btn-success" id="issueDocumentCta" href="{{ $issueUrl }}">صدور سند پورسانت از این گزارش</a>
                        @else
                            <button class="btn btn-success" id="issueDocumentCta" type="button" disabled>صدور سند پورسانت از این گزارش</button>
                        @endif
                    </div>
                </div>

                <div class="card shadow-sm overflow-hidden">
                    <div class="table-responsive">
                        <table class="table table-striped align-middle mb-0">
                            <thead class="table-light"><tr><th>فاکتور</th><th>مبلغ</th><th>پورسانت محاسبه‌شده</th><th>وضعیت نرخ</th><th>جزئیات</th></tr></thead>
                            <tbody>
                            @forelse($report['invoices'] as $invoice)
                                <tr>
                                    <td class="fw-semibold">{{ $invoice->uuid }}</td>
                                    <td>{{ Currency::formatRial($invoice->total) }}</td>
                                    <td>{{ $invoice->base_preview['missing_rate_item_count'] ? 'نیازمند تعیین نرخ' : Currency::formatRial($invoice->base_preview['calculated_commission_total']) }}</td>
                                    <td>{{ $invoice->base_preview['missing_rate_item_count'] ? 'نیازمند بررسی نرخ' : 'کامل' }}</td>
                                    <td><details><summary>آیتم‌ها</summary>@foreach($invoice->base_preview['items'] as $item)<div>{{ $item['product_name'] }} {{ $item['variant_name'] }} | مبنا {{ Currency::formatRial($item['commission_base']) }} | نرخ {{ $item['rate_percent'] }}٪ | منبع {{ $item['rule_source'] ?: '—' }} | پورسانت {{ $item['calculated_commission'] === null ? 'نیازمند تعیین نرخ' : Currency::formatRial($item['calculated_commission']) }} {{ $item['warning'] }}</div>@endforeach</details></td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center py-4 text-muted">فاکتور واجد شرایطی در این بازه پیدا نشد.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="mt-3">{{ $report['invoices']->links() }}</div>
            @endif
        </div>

        {{-- ================= اسناد ثبت‌شده ================= --}}
        <div class="tab-pane fade @if($activeTab === 'documents') show active @endif" id="documentsTab" role="tabpanel">

            <form method="GET" action="{{ route('finance.seller-sales.index') }}" class="card shadow-sm mb-4">
                <input type="hidden" name="tab" value="documents">
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label" for="documentUserId">فروشنده</label>
                            <select class="form-select" id="documentUserId" name="user_id">
                                <option value="">همه فروشندگان</option>
                                @foreach($users as $user)
                                    <option value="{{ $user->id }}" @selected($documentFilters['user_id'] == $user->id)>{{ $user->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-6">
                            <label class="form-label" for="documentDateFrom">از تاریخ</label>
                            <input class="form-control" id="documentDateFrom" name="date_from" data-jdp autocomplete="off" value="{{ request('date_from') }}">
                        </div>
                        <div class="col-lg-2 col-md-6">
                            <label class="form-label" for="documentDateTo">تا تاریخ</label>
                            <input class="form-control" id="documentDateTo" name="date_to" data-jdp autocomplete="off" value="{{ request('date_to') }}">
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label" for="documentNumber">شماره سند</label>
                            <input class="form-control" id="documentNumber" name="document_number" maxlength="50" value="{{ $documentFilters['document_number'] }}">
                        </div>
                        <div class="col-12 d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-primary">نمایش اسناد</button>
                            <a class="btn btn-outline-secondary" href="{{ route('finance.seller-sales.index', ['tab' => 'documents']) }}">پاک‌کردن فیلترها</a>
                            <a class="btn btn-success ms-auto" href="{{ route('finance.seller-sales.create') }}">ثبت سند جدید</a>
                        </div>
                    </div>
                </div>
            </form>

            <div class="card shadow-sm overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-striped align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>شماره سند</th><th>فروشنده</th><th>بازه</th><th>تعداد فاکتور</th>
                            <th>جمع فروش</th><th>مبلغ پورسانت</th><th>پورسانت خالص</th><th>وضعیت</th><th>ثبت‌کننده</th><th>تاریخ ثبت</th><th>عملیات</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($documents as $document)
                            <tr>
                                <td class="fw-semibold">{{ $document->document_number }}</td>
                                <td>{{ $document->seller?->name ?: '—' }}</td>
                                <td>{{ JalaliDate::date($document->period_from) }} تا {{ JalaliDate::date($document->period_to) }}</td>
                                <td>{{ number_format($document->invoice_count) }}</td>
                                <td>{{ Currency::formatRial($document->total_sales_amount) }}</td>
                                <td>{{ Currency::formatRial($document->total_commission_amount) }}</td>
                                <td class="text-success fw-bold">{{ Currency::formatRial($document->net_commission_amount) }}</td>
                                <td>
                                    @php
                                        $rowStatus = [
                                            'draft'     => ['label' => 'پیش‌نویس', 'class' => 'bg-warning text-dark'],
                                            'confirmed' => ['label' => 'تأیید‌شده', 'class' => 'bg-primary'],
                                            'finalized' => ['label' => 'نهایی‌شده', 'class' => 'bg-success'],
                                        ][$document->status] ?? ['label' => $document->status ?? '—', 'class' => 'bg-secondary'];
                                    @endphp
                                    <span class="badge {{ $rowStatus['class'] }}">{{ $rowStatus['label'] }}</span>
                                </td>
                                <td>{{ $document->creator?->name ?: '—' }}</td>
                                <td>{{ JalaliDate::date($document->created_at) }}</td>
                                <td class="text-nowrap">
                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('finance.seller-sales.show', $document) }}">مشاهده</a>
                                    <a class="btn btn-sm btn-outline-secondary" target="_blank" href="{{ route('finance.seller-sales.print', $document) }}">چاپ</a>
                                    @if($document->isDraft())
                                        <a class="btn btn-sm btn-outline-dark" href="{{ route('finance.seller-sales.edit', $document) }}">ویرایش</a>
                                        <form class="d-inline" method="POST" action="{{ route('finance.seller-sales.destroy', $document) }}" onsubmit="return confirm('آیا از حذف این سند پورسانت مطمئن هستید؟ فاکتورهای آن دوباره آزاد می‌شوند.');">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">حذف</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="11" class="text-center py-4 text-muted">هنوز هیچ سند پورسانتی ثبت نشده است.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="mt-3">{{ $documents->links() }}</div>
        </div>
    </div>
</div>
@endsection
