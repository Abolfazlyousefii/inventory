@extends('layouts.app')

@section('title', 'مدیریت رزرو موجودی')
@section('page-title', 'مدیریت رزرو موجودی')

@php use App\Support\JalaliDate; @endphp

@push('styles')
<style>
    .warehouse-reservations-page { --reservation-primary: #0f766e; }
    .warehouse-reservations-page .page-intro { color: #64748b; max-width: 720px; }
    .warehouse-reservations-page .summary-card,
    .warehouse-reservations-page .filter-card,
    .warehouse-reservations-page .table-card { border: 0; border-radius: 16px; box-shadow: 0 8px 24px rgba(15, 23, 42, .06); }
    .warehouse-reservations-page .summary-card { border-right: 4px solid var(--summary-color); }
    .warehouse-reservations-page .summary-label { color: #64748b; font-size: .86rem; }
    .warehouse-reservations-page .summary-value { color: #0f172a; font-size: 1.55rem; font-weight: 800; }
    .warehouse-reservations-page .summary-meta { color: #64748b; font-size: .78rem; }
    .warehouse-reservations-page .filter-title { color: #334155; font-size: .92rem; font-weight: 700; }
    .warehouse-reservations-page .form-label { color: #475569; font-size: .82rem; font-weight: 600; }
    .warehouse-reservations-page .table { min-width: 980px; }
    .warehouse-reservations-page .table > :not(caption) > * > * { padding: .85rem .75rem; vertical-align: middle; }
    .warehouse-reservations-page .table thead th { color: #64748b; font-size: .79rem; font-weight: 700; white-space: nowrap; }
    .warehouse-reservations-page .product-name { color: #0f172a; font-weight: 700; }
    .warehouse-reservations-page .muted-line { color: #64748b; font-size: .78rem; }
    .warehouse-reservations-page .status-badge { border-radius: 999px; display: inline-flex; font-size: .76rem; font-weight: 700; padding: .38rem .65rem; }
    .warehouse-reservations-page .status-active { background: #dcfce7; color: #166534; }
    .warehouse-reservations-page .status-preinvoice { background: #dbeafe; color: #1d4ed8; }
    .warehouse-reservations-page .status-review { background: #fef3c7; color: #92400e; }
    .warehouse-reservations-page .status-critical { background: #fee2e2; color: #991b1b; }
    .warehouse-reservations-page .status-releasable { background: #fee2e2; color: #991b1b; }
    .warehouse-reservations-page .status-neutral { background: #e2e8f0; color: #475569; }
    .warehouse-reservations-page .quick-filter { background: #fff; border: 1px solid #e2e8f0; border-radius: 999px; color: #475569; font-size: .82rem; padding: .42rem .85rem; text-decoration: none; }
    .warehouse-reservations-page .quick-filter:hover,
    .warehouse-reservations-page .quick-filter.active { background: var(--reservation-primary); border-color: var(--reservation-primary); color: #fff; }
    .warehouse-reservations-page .section-tabs { border-bottom-color: #e2e8f0; }
    .warehouse-reservations-page .section-tabs .nav-link { border: 0; border-bottom: 2px solid transparent; color: #64748b; font-weight: 700; padding: .75rem 1rem; }
    .warehouse-reservations-page .section-tabs .nav-link.active { background: transparent; border-bottom-color: var(--reservation-primary); color: var(--reservation-primary); }
    .warehouse-reservations-page .old-warning { color: #b45309; font-size: .76rem; font-weight: 700; }
    .warehouse-reservations-page .old-reservation-row { background: #fffbeb; }
    .warehouse-reservations-page .empty-state { color: #64748b; padding: 3rem 1rem; text-align: center; }
    .warehouse-reservations-page .details-row td { background: #f8fafc; }
    @media (max-width: 767.98px) {
        .warehouse-reservations-page .summary-value { font-size: 1.3rem; }
        .warehouse-reservations-page .filter-actions .btn { flex: 1 1 auto; }
    }
</style>
@endpush

@section('content')
<div class="container-fluid py-3 warehouse-reservations-page" dir="rtl">
    <div class="mb-4">
        <h2 class="h4 fw-bold mb-2">مدیریت رزرو موجودی</h2>
        <p class="page-intro mb-0">رزروهای موجودی را بررسی کنید. آزادسازی فقط برای رزروهایی فعال است که سامانه آن‌ها را قابل آزادسازی تشخیص داده باشد.</p>
    </div>

    @if(session('success'))
        <div class="alert alert-success border-0 shadow-sm" role="alert">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger border-0 shadow-sm" role="alert">{{ $errors->first() }}</div>
    @endif

    @include('warehouse-reservations.partials.dashboard-cards')

    @php
        $activeTab = $filters['tab'] ?? 'reservations';
    @endphp
    <ul class="nav nav-tabs section-tabs mb-4" aria-label="بخش‌های مدیریت رزرو">
        <li class="nav-item">
            <a class="nav-link {{ $activeTab === 'reservations' ? 'active' : '' }}" href="{{ route('warehouse-reservations.index') }}">رزروها</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $activeTab === 'health' ? 'active' : '' }}" href="{{ route('warehouse-reservations.index', ['tab' => 'health']) }}">سلامت رزروها</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $activeTab === 'orphaned' ? 'active' : '' }}" href="{{ route('warehouse-reservations.index', ['tab' => 'orphaned']) }}">
                رزروهای رها شده
                <span class="badge rounded-pill text-bg-danger me-1">{{ number_format($orphanedCount) }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $activeTab === 'history' ? 'active' : '' }}" href="{{ route('warehouse-reservations.index', ['tab' => 'history']) }}">تاریخچه آزادسازی</a>
        </li>
    </ul>

    @if($activeTab === 'reservations')
        @include('warehouse-reservations.partials.reservation-table')

    @elseif($activeTab === 'health')
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-3">
            <div>
                <h3 class="h6 fw-bold mb-1">گزارش سلامت رزروها</h3>
                <p class="muted-line mb-0">این گزارش فقط‌خواندنی است و هیچ تغییری در رزرو یا موجودی ایجاد نمی‌کند.</p>
            </div>
            <a class="btn btn-sm btn-outline-success" href="{{ route('warehouse-reservations.health.export') }}">خروجی CSV</a>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3">
                <div class="card summary-card h-100" style="--summary-color:#16a34a">
                    <div class="card-body">
                        <div class="summary-label mb-1">رزرو سالم</div>
                        <div class="summary-value">{{ number_format($healthStats['healthy']) }}</div>
                        <div class="summary-meta">دارای مالک معتبر</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card summary-card h-100" style="--summary-color:#d97706">
                    <div class="card-body">
                        <div class="summary-label mb-1">رزرو قدیمی</div>
                        <div class="summary-value">{{ number_format($healthStats['old']) }}</div>
                        <div class="summary-meta">نیازمند بررسی زمانی</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card summary-card h-100" style="--summary-color:#dc2626">
                    <div class="card-body">
                        <div class="summary-label mb-1">رزرو رها شده</div>
                        <div class="summary-value">{{ number_format($healthStats['orphaned']) }}</div>
                        <div class="summary-meta">بدون مالک معتبر</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card summary-card h-100" style="--summary-color:#7c3aed">
                    <div class="card-body">
                        <div class="summary-label mb-1">اختلاف موجودی</div>
                        <div class="summary-value">{{ number_format($healthStats['cache_mismatch']) }}</div>
                        <div class="summary-meta">اختلاف cache تنوع</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card table-card overflow-hidden">
            <div class="card-header bg-white border-0 px-3 px-md-4 pt-4 pb-3">
                <h3 class="h6 fw-bold mb-1">جزئیات موارد نیازمند بررسی</h3>
                <p class="muted-line mb-0">رزروهای سالم در این جدول نمایش داده نمی‌شوند.</p>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>کالا</th>
                            <th>تنوع</th>
                            <th>تعداد</th>
                            <th>نوع مشکل</th>
                            <th>زمان</th>
                            <th>وضعیت</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($healthIssues as $healthIssue)
                        @php
                            $healthVariantName = $healthIssue->variant_name ?: $healthIssue->variety_name;
                            $healthVariantCode = $healthIssue->variant_code ?: $healthIssue->variety_code;
                            $healthTime = JalaliDate::dateTime($healthIssue->occurred_at, 'ثبت نشده');
                        @endphp
                        <tr>
                            <td><div class="product-name">{{ $healthIssue->product_name }}</div></td>
                            <td>
                                <div>{{ $healthVariantName ?: 'بدون عنوان تنوع' }}</div>
                                <div class="muted-line" dir="ltr">{{ $healthVariantCode ?: '—' }}</div>
                            </td>
                            <td>
                                <div class="fw-bold">{{ number_format($healthIssue->quantity) }}</div>
                                @if($healthIssue->cached_quantity !== null)
                                    <div class="muted-line">cache: {{ number_format($healthIssue->cached_quantity) }}</div>
                                @endif
                            </td>
                            <td>{{ $healthIssue->issue_label }}</td>
                            <td dir="ltr">{{ $healthTime }}</td>
                            <td>
                                <span class="status-badge {{ $healthIssue->issue_type === 'orphaned' ? 'status-releasable' : 'status-review' }}">{{ $healthIssue->status_label }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="empty-state">مورد ناسالمی در رزروها پیدا نشد.</div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($healthIssues->hasPages())
                <div class="card-footer bg-white border-0 d-flex justify-content-center pt-3">
                    {{ $healthIssues->links() }}
                </div>
            @endif
        </div>

    @elseif($activeTab === 'orphaned')
        <div class="card table-card overflow-hidden">
            <div class="card-header bg-white border-0 px-3 px-md-4 pt-4 pb-3">
                <h3 class="h6 fw-bold mb-1">رزروهای رها شده</h3>
                <p class="muted-line mb-0">رزروهای بدون پیش‌فاکتور، draft فعال و heartbeat معتبر در این بخش نمایش داده می‌شوند.</p>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>کالا</th>
                            <th>تنوع</th>
                            <th>تعداد</th>
                            <th>گروه token</th>
                            <th>سن رزرو</th>
                            <th>آخرین فعالیت</th>
                            <th>دلیل</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($orphanedReservations as $orphanedReservation)
                        @php
                            $orphanVariantName = $orphanedReservation->variant?->variant_name ?: $orphanedReservation->variant?->variety_name;
                            $orphanVariantCode = $orphanedReservation->variant?->variant_code ?: $orphanedReservation->variant?->variety_code;
                        @endphp
                        <tr>
                            <td>
                                <div class="product-name">{{ $orphanedReservation->product?->name ?? 'کالای نامشخص' }}</div>
                                <div class="muted-line">{{ $orphanedReservation->product?->sku ?: $orphanedReservation->product?->code ?: 'بدون کد کالا' }}</div>
                            </td>
                            <td>
                                <div>{{ $orphanVariantName ?: 'بدون عنوان تنوع' }}</div>
                                <div class="muted-line" dir="ltr">{{ $orphanVariantCode ?: '—' }}</div>
                            </td>
                            <td class="fw-bold">{{ number_format($orphanedReservation->quantity) }}</td>
                            <td>
                                <div class="text-break" dir="ltr">{{ $orphanedReservation->token }}</div>
                                <div class="muted-line mt-1">{{ number_format((int) $orphanedReservation->token_group_count) }} ردیف در این گروه</div>
                            </td>
                            <td>
                                <div>{{ $orphanedReservation->managementAgeLabel() }}</div>
                                <div class="muted-line mt-1" dir="ltr">{{ JalaliDate::dateTime($orphanedReservation->created_at, 'ثبت نشده') }}</div>
                            </td>
                            <td dir="ltr">{{ JalaliDate::dateTime($orphanedReservation->managementLastActivityAt(), 'ثبت نشده') }}</td>
                            <td><span class="status-badge status-releasable">رزرو رها شده</span></td>
                            <td>
                                <div class="d-flex flex-wrap gap-2">
                                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#orphan-details-{{ $orphanedReservation->id }}">مشاهده</button>
                                    @canPermission('warehouse_reservations.release')
                                        <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#release-orphan-{{ $orphanedReservation->id }}">آزادسازی</button>
                                    @endcanPermission
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><div class="empty-state">رزرو رهاشده‌ای برای بررسی وجود ندارد.</div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($orphanedReservations->hasPages())
                <div class="card-footer bg-white border-0 d-flex justify-content-center pt-3">
                    {{ $orphanedReservations->links() }}
                </div>
            @endif
        </div>

    @elseif($activeTab === 'history')
    <div class="card table-card overflow-hidden mt-4">
        <div class="card-header bg-white border-0 px-3 px-md-4 pt-4 pb-3">
            <h3 class="h6 fw-bold mb-1">تاریخچه آزادسازی رزروها</h3>
            <p class="muted-line mb-0">رزروهای آزادشده به‌ترتیب آخرین زمان آزادسازی نمایش داده می‌شوند.</p>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>کالا</th>
                        <th>تنوع</th>
                        <th>تعداد</th>
                        <th>زمان آزادسازی</th>
                        <th>آزادکننده</th>
                        <th>دلیل آزادسازی</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($releasedReservations as $releasedReservation)
                    @php
                        $releasedVariantName = $releasedReservation->variant?->variant_name ?: $releasedReservation->variant?->variety_name;
                        $releasedVariantCode = $releasedReservation->variant?->variant_code ?: $releasedReservation->variant?->variety_code;
                    @endphp
                    <tr>
                        <td>
                            <div class="product-name">{{ $releasedReservation->product?->name ?? 'کالای نامشخص' }}</div>
                            <div class="muted-line">{{ $releasedReservation->product?->sku ?: $releasedReservation->product?->code ?: 'بدون کد کالا' }}</div>
                        </td>
                        <td>
                            <div>{{ $releasedVariantName ?: 'بدون عنوان تنوع' }}</div>
                            <div class="muted-line" dir="ltr">{{ $releasedVariantCode ?: '—' }}</div>
                        </td>
                        <td class="fw-bold">{{ number_format($releasedReservation->quantity) }}</td>
                        <td dir="ltr">{{ JalaliDate::dateTime($releasedReservation->released_at, 'ثبت نشده') }}</td>
                        <td>{{ $releasedReservation->releasedBy?->name ?? 'سیستم' }}</td>
                        <td>
                            <div>{{ $releasedReservation->releaseReasonLabel() }}</div>
                            @if($releasedReservation->release_note)
                                <div class="muted-line mt-1">{{ $releasedReservation->release_note }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="empty-state">هنوز رزرو آزادشده‌ای ثبت نشده است.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($releasedReservations->hasPages())
            <div class="card-footer bg-white border-0 d-flex justify-content-center pt-3">
                {{ $releasedReservations->links() }}
            </div>
        @endif
    </div>
    @endif

    @if($activeTab === 'orphaned')
        @foreach($orphanedReservations as $orphanedReservation)
            <div class="modal fade" id="orphan-details-{{ $orphanedReservation->id }}" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content border-0">
                        <div class="modal-header">
                            <h3 class="modal-title fs-6">جزئیات رزرو رها شده</h3>
                            <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="بستن"></button>
                        </div>
                        <div class="modal-body">
                            <dl class="row g-2 mb-0 small">
                                <dt class="col-5 text-muted">کالا</dt>
                                <dd class="col-7 fw-bold">{{ $orphanedReservation->product?->name ?? 'کالای نامشخص' }}</dd>
                                <dt class="col-5 text-muted">تنوع</dt>
                                <dd class="col-7">{{ $orphanedReservation->variant?->variant_name ?: $orphanedReservation->variant?->variety_name ?: 'بدون عنوان تنوع' }}</dd>
                                <dt class="col-5 text-muted">تعداد</dt>
                                <dd class="col-7">{{ number_format($orphanedReservation->quantity) }}</dd>
                                <dt class="col-5 text-muted">گروه token</dt>
                                <dd class="col-7 text-break" dir="ltr">{{ $orphanedReservation->token }}</dd>
                                <dt class="col-5 text-muted">تعداد ردیف‌های گروه</dt>
                                <dd class="col-7">{{ number_format((int) $orphanedReservation->token_group_count) }}</dd>
                                <dt class="col-5 text-muted">سن رزرو</dt>
                                <dd class="col-7">{{ $orphanedReservation->managementAgeLabel() }}</dd>
                                <dt class="col-5 text-muted">زمان ایجاد</dt>
                                <dd class="col-7" dir="ltr">{{ JalaliDate::dateTime($orphanedReservation->created_at, 'ثبت نشده') }}</dd>
                                <dt class="col-5 text-muted">آخرین فعالیت</dt>
                                <dd class="col-7" dir="ltr">{{ JalaliDate::dateTime($orphanedReservation->managementLastActivityAt(), 'ثبت نشده') }}</dd>
                                <dt class="col-5 text-muted">دلیل</dt>
                                <dd class="col-7">رزرو رها شده</dd>
                            </dl>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">بستن</button>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach

        @canPermission('warehouse_reservations.release')
            @foreach($orphanedReservations as $orphanedReservation)
                <div class="modal fade" id="release-orphan-{{ $orphanedReservation->id }}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <form class="modal-content border-0" method="POST" action="{{ route('warehouse-reservations.release', $orphanedReservation) }}">
                            @csrf
                            <input type="hidden" name="release_reason" value="رزرو رها شده">
                            <div class="modal-header">
                                <h3 class="modal-title fs-6">آزادسازی رزرو رها شده</h3>
                                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="بستن"></button>
                            </div>
                            <div class="modal-body">
                                <p class="mb-3">آیا از آزادسازی این رزرو مطمئن هستید؟</p>
                                <dl class="row g-2 mb-0 small">
                                    <dt class="col-3 text-muted">کالا:</dt>
                                    <dd class="col-9 fw-bold">{{ $orphanedReservation->product?->name ?? 'کالای نامشخص' }}</dd>
                                    <dt class="col-3 text-muted">تعداد:</dt>
                                    <dd class="col-9 fw-bold">{{ number_format($orphanedReservation->quantity) }}</dd>
                                </dl>
                            </div>
                            <div class="modal-footer">
                                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">انصراف</button>
                                <button class="btn btn-danger" type="submit">تأیید آزادسازی</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endforeach
        @endcanPermission
    @endif
</div>
@endsection
