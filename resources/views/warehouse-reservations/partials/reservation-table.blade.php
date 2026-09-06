{{-- "Reservations" tab: filters, quick filters, table, and its detail/release modals. --}}
@php use App\Support\JalaliDate; @endphp
<form class="card filter-card mb-4" method="GET" action="{{ route('warehouse-reservations.index') }}">
    <div class="card-body">
        <div class="filter-title mb-3">جست‌وجو و فیلتر</div>
        <div class="row g-3 align-items-end">
            <div class="col-12 col-lg-4">
                <label class="form-label" for="reservation-search">نام کالا، کد تنوع یا فروشنده</label>
                <input class="form-control" id="reservation-search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="عبارت مورد نظر را وارد کنید">
            </div>
            <div class="col-12 col-md-4 col-lg-2">
                <label class="form-label" for="reservation-status">وضعیت</label>
                <select class="form-select" id="reservation-status" name="status">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="active" @selected(($filters['status'] ?? '') === 'active')>فعال</option>
                    <option value="preinvoice_active" @selected(($filters['status'] ?? '') === 'preinvoice_active')>پیش‌فاکتور فعال</option>
                    <option value="needs_review" @selected(($filters['status'] ?? '') === 'needs_review')>نیاز بررسی</option>
                    <option value="critical" @selected(($filters['status'] ?? '') === 'critical')>بحرانی</option>
                    <option value="releasable" @selected(($filters['status'] ?? '') === 'releasable')>قابل آزادسازی</option>
                </select>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label" for="reservation-date-from">از تاریخ</label>
                <input class="form-control" id="reservation-date-from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label" for="reservation-date-to">تا تاریخ</label>
                <input class="form-control" id="reservation-date-to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
            </div>
            <div class="col-12 col-lg-2">
                <div class="d-flex gap-2 filter-actions">
                    <button class="btn btn-primary" type="submit">اعمال فیلتر</button>
                    <a class="btn btn-outline-secondary" href="{{ route('warehouse-reservations.index') }}">پاک کردن</a>
                </div>
            </div>
        </div>

        @php
            $advancedFilterKeys = ['classification', 'lifecycle', 'age', 'user_id', 'product_id', 'variant_id', 'customer_id', 'customer_search'];
            $advancedFiltersActive = collect($advancedFilterKeys)->contains(fn ($key) => filled($filters[$key] ?? null));
        @endphp
        <button class="btn btn-sm btn-outline-secondary mt-3" type="button" data-bs-toggle="collapse" data-bs-target="#advanced-reservation-filters" aria-expanded="{{ $advancedFiltersActive ? 'true' : 'false' }}" aria-controls="advanced-reservation-filters">
            فیلترهای پیشرفته
            @if($advancedFiltersActive)<span class="badge rounded-pill text-bg-primary ms-1">فعال</span>@endif
        </button>

        <div class="collapse {{ $advancedFiltersActive ? 'show' : '' }} mt-3" id="advanced-reservation-filters">
            <hr>
            <div class="row g-3">
                <div class="col-12 col-md-4 col-lg-2">
                    <label class="form-label" for="reservation-classification">طبقه‌بندی</label>
                    <select class="form-select" id="reservation-classification" name="classification">
                        <option value="">همه طبقه‌بندی‌ها</option>
                        @foreach($classificationService->managementLabels() as $labelValue => $labelText)
                            <option value="{{ $labelValue }}" @selected(($filters['classification'] ?? '') === $labelValue)>{{ $labelText }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-2">
                    <label class="form-label" for="reservation-lifecycle">چرخه عمر</label>
                    <select class="form-select" id="reservation-lifecycle" name="lifecycle">
                        <option value="">همه</option>
                        <option value="active" @selected(($filters['lifecycle'] ?? '') === 'active')>فعال</option>
                        <option value="consumed" @selected(($filters['lifecycle'] ?? '') === 'consumed')>مصرف‌شده</option>
                        <option value="released" @selected(($filters['lifecycle'] ?? '') === 'released')>آزادشده</option>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-2">
                    <label class="form-label" for="reservation-age">سن رزرو</label>
                    <select class="form-select" id="reservation-age" name="age">
                        <option value="">هر سنی</option>
                        <option value="24h" @selected(($filters['age'] ?? '') === '24h')>بیش از ۲۴ ساعت</option>
                        <option value="72h" @selected(($filters['age'] ?? '') === '72h')>بیش از ۷۲ ساعت</option>
                        <option value="30d" @selected(($filters['age'] ?? '') === '30d')>بیش از ۳۰ روز</option>
                    </select>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label" for="reservation-user-id">شناسه فروشنده</label>
                    <input class="form-control" id="reservation-user-id" type="number" min="1" name="user_id" value="{{ $filters['user_id'] ?? '' }}">
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label" for="reservation-product-id">شناسه کالا</label>
                    <input class="form-control" id="reservation-product-id" type="number" min="1" name="product_id" value="{{ $filters['product_id'] ?? '' }}">
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label" for="reservation-variant-id">شناسه تنوع</label>
                    <input class="form-control" id="reservation-variant-id" type="number" min="1" name="variant_id" value="{{ $filters['variant_id'] ?? '' }}">
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <label class="form-label" for="reservation-customer-id">شناسه مشتری</label>
                    <input class="form-control" id="reservation-customer-id" type="number" min="1" name="customer_id" value="{{ $filters['customer_id'] ?? '' }}">
                </div>
                <div class="col-12 col-md-8 col-lg-4">
                    <label class="form-label" for="reservation-customer-search">نام یا موبایل مشتری</label>
                    <input class="form-control" id="reservation-customer-search" name="customer_search" value="{{ $filters['customer_search'] ?? '' }}" placeholder="جست‌وجوی مشتری پیش‌فاکتور">
                    <div class="form-text">فقط رزروهای متصل به پیش‌فاکتور دارای مشتری هستند؛ رزروهای موقت در این فیلتر نمایش داده نمی‌شوند.</div>
                </div>
            </div>
        </div>
    </div>
</form>

@php
    $quickFilter = $filters['quick'] ?? '';
    $quickBase = request()->except(['page', 'health_page', 'orphan_page', 'history_page', 'quick', 'status', 'tab']);
@endphp
<div class="d-flex flex-wrap align-items-center gap-2 mb-3" aria-label="فیلتر سریع رزروها">
    <span class="small fw-bold text-muted ms-1">فیلتر سریع:</span>
    <a class="quick-filter {{ $quickFilter === '' ? 'active' : '' }}" href="{{ route('warehouse-reservations.index', $quickBase) }}">همه</a>
    <a class="quick-filter {{ $quickFilter === 'actionable' ? 'active' : '' }}" href="{{ route('warehouse-reservations.index', array_merge($quickBase, ['quick' => 'actionable'])) }}">نیاز اقدام</a>
    <a class="quick-filter {{ $quickFilter === 'review' ? 'active' : '' }}" href="{{ route('warehouse-reservations.index', array_merge($quickBase, ['quick' => 'review'])) }}">بررسی</a>
    <a class="quick-filter {{ $quickFilter === 'active' ? 'active' : '' }}" href="{{ route('warehouse-reservations.index', array_merge($quickBase, ['quick' => 'active'])) }}">فعال</a>
</div>

{{-- Bulk selection/export is available to anyone who can view this page
     (warehouse_reservations.view — already required to reach this route);
     the release/legacy-cleanup buttons are separately gated below by their
     own permissions, both here and (authoritatively) on the server. --}}
@php $canBulkAny = true; @endphp

<div class="card bulk-toolbar-card mb-3" id="reservation-bulk-toolbar" hidden>
    <div class="card-body d-flex flex-wrap align-items-center gap-2 py-2 px-3">
        <div class="form-check mb-0 ms-1">
            <input class="form-check-input" type="checkbox" id="reservation-select-all-visible">
            <label class="form-check-label small fw-bold" for="reservation-select-all-visible">انتخاب همه صفحه</label>
        </div>
        <span class="small text-muted" id="reservation-bulk-count">۰ مورد انتخاب شده</span>
        <div class="d-flex flex-wrap gap-2 ms-auto">
            @canPermission('warehouse_reservations.release')
                <button class="btn btn-sm btn-outline-danger" type="button" id="reservation-bulk-release-btn" disabled data-bs-toggle="modal" data-bs-target="#bulk-release-modal">
                    آزادسازی رزرو
                </button>
            @endcanPermission
            @canPermission('inventory.reservation.legacy_cleanup')
                <button class="btn btn-sm btn-outline-warning" type="button" id="reservation-bulk-legacy-btn" disabled data-bs-toggle="modal" data-bs-target="#bulk-legacy-modal">
                    حذف Legacy
                </button>
            @endcanPermission
            <button class="btn btn-sm btn-outline-success" type="button" id="reservation-bulk-export-btn" disabled>
                خروجی CSV
            </button>
        </div>
    </div>
</div>

<div class="card table-card overflow-hidden">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    @if($canBulkAny)
                        <th style="width:2.5rem"></th>
                    @endif
                    <th>کالا</th>
                    <th>تنوع</th>
                    <th>تعداد</th>
                    <th>فروشنده</th>
                    <th>پیش‌فاکتور</th>
                    <th>وضعیت</th>
                    <th>نوع</th>
                    <th>سلامت</th>
                    <th>طبقه‌بندی</th>
                    <th>زمان رزرو</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
            @forelse($reservations as $reservation)
                @php
                    $businessStatus = $reservation->businessStatus();
                    $releasable = $reservation->isActionableForManagement();
                    $warning = $reservation->managementWarning();
                    $displayReason = $reservation->businessDisplayReason();
                    $variantName = $reservation->variant?->variant_name ?: $reservation->variant?->variety_name;
                    $variantCode = $reservation->variant?->variant_code ?: $reservation->variant?->variety_code;
                    $classification = $classificationService->classify($reservation);
                    $isLegacyCandidate = $classification['label'] === \App\Services\ReservationClassificationService::LABEL_LEGACY_CANDIDATE;
                @endphp
                <tr @class(['old-reservation-row' => $warning !== null])>
                    @if($canBulkAny)
                        <td>
                            <input
                                class="form-check-input reservation-select-row"
                                type="checkbox"
                                value="{{ $reservation->id }}"
                                data-releasable="{{ $releasable ? '1' : '0' }}"
                                data-legacy="{{ $isLegacyCandidate ? '1' : '0' }}"
                                aria-label="انتخاب رزرو {{ $reservation->id }}"
                            >
                        </td>
                    @endif
                    <td>
                        <div class="product-name">{{ $reservation->product?->name ?? 'کالای نامشخص' }}</div>
                        <div class="muted-line">{{ $reservation->product?->sku ?: $reservation->product?->code ?: 'بدون کد کالا' }}</div>
                    </td>
                    <td>
                        <div>{{ $variantName ?: 'بدون عنوان تنوع' }}</div>
                        <div class="muted-line" dir="ltr">{{ $variantCode ?: '—' }}</div>
                    </td>
                    <td class="fw-bold">{{ number_format($reservation->quantity) }}</td>
                    <td>{{ $reservation->user?->name ?? 'نامشخص' }}</td>
                    <td>
                        @if($reservation->order)
                            <span dir="ltr">{{ $reservation->order->uuid }}</span>
                            {{-- preinvoiceConnectedAt() falls back to the preinvoice order's own
                                 created_at when converted_at is missing; that timestamp is when the
                                 preinvoice was created, not when this reservation was connected to
                                 it. Label the two cases separately instead of implying they are the
                                 same event. --}}
                            @if($reservation->converted_at)
                                <div class="muted-line mt-1">اتصال رزرو: {{ JalaliDate::dateTime($reservation->converted_at) }}</div>
                            @else
                                <div class="muted-line mt-1">ایجاد پیش‌فاکتور مرتبط: {{ JalaliDate::dateTime($reservation->order->created_at) }}</div>
                            @endif
                        @else
                            <span class="text-muted">ثبت نشده</span>
                        @endif
                    </td>
                    <td>
                        @if($businessStatus === \App\Models\PreinvoiceDraftReservation::STATUS_CRITICAL)
                            <span class="status-badge status-critical">بحرانی</span>
                        @elseif($businessStatus === \App\Models\PreinvoiceDraftReservation::STATUS_NEEDS_REVIEW)
                            <span class="status-badge status-review">نیاز بررسی</span>
                        @elseif($businessStatus === \App\Models\PreinvoiceDraftReservation::STATUS_PREINVOICE_ACTIVE)
                            <span class="status-badge status-preinvoice">پیش‌فاکتور فعال</span>
                        @elseif($businessStatus === \App\Models\PreinvoiceDraftReservation::STATUS_ACTIVE)
                            <span class="status-badge status-active">فعال</span>
                        @else
                            <span class="status-badge status-neutral">نامشخص</span>
                        @endif
                        <div class="muted-line mt-1">{{ $displayReason }}</div>
                        @if($releasable)<div class="old-warning mt-1">قابل آزادسازی توسط مدیر انبار</div>@endif
                    </td>
                    <td>
                        <span class="status-badge status-neutral">{{ $classificationService->typeLabels()[$classification['type']] }}</span>
                    </td>
                    <td>
                        @php $healthClass = match($classification['health']) { 'critical' => 'status-critical', 'warning' => 'status-review', default => 'status-active' }; @endphp
                        <span class="status-badge {{ $healthClass }}">{{ $classificationService->healthLabels()[$classification['health']] }}</span>
                    </td>
                    <td>
                        @php $labelClass = match($classification['label']) { 'critical', 'legacy_candidate' => 'status-critical', 'temporary_orphan' => 'status-review', 'consumed' => 'status-neutral', default => 'status-active' }; @endphp
                        <span class="status-badge {{ $labelClass }}">{{ $classificationService->managementLabels()[$classification['label']] }}</span>
                    </td>
                    <td>
                        <div>{{ $reservation->managementAgeLabel() }}</div>
                        <div class="muted-line mt-1">{{ JalaliDate::dateTime($reservation->created_at) }}</div>
                        @if($warning)<div class="old-warning mt-1">{{ $warning }}</div>@endif
                    </td>
                    <td>
                        <div class="d-flex flex-wrap gap-2">
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('warehouse-reservations.show', $reservation) }}">
                                مشاهده
                            </a>
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#reservation-details-{{ $reservation->id }}">
                                جزئیات سریع
                            </button>
                            @if($releasable)
                                @canPermission('warehouse_reservations.release')
                                    <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#release-reservation-{{ $reservation->id }}">
                                        آزادسازی موجودی
                                    </button>
                                @endcanPermission
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $canBulkAny ? 12 : 11 }}"><div class="empty-state">رزروی با فیلترهای انتخاب‌شده پیدا نشد.</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($reservations->hasPages())
        <div class="card-footer bg-white border-0 d-flex justify-content-center pt-3">
            {{ $reservations->links() }}
        </div>
    @endif
</div>

@foreach($reservations as $reservation)
    <div class="modal fade" id="reservation-details-{{ $reservation->id }}" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0">
                <div class="modal-header">
                    <h3 class="modal-title fs-6">جزئیات رزرو موجودی</h3>
                    <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="بستن"></button>
                </div>
                <div class="modal-body">
                    <dl class="row g-2 mb-0 small">
                        <dt class="col-5 text-muted">سن رزرو</dt>
                        <dd class="col-7 fw-bold">{{ $reservation->managementAgeLabel() }}</dd>
                        <dt class="col-5 text-muted">زمان ایجاد رزرو</dt>
                        <dd class="col-7" dir="ltr">{{ JalaliDate::dateTime($reservation->created_at, 'ثبت نشده') }}</dd>
                        <dt class="col-5 text-muted">آخرین فعالیت</dt>
                        <dd class="col-7" dir="ltr">{{ JalaliDate::dateTime($reservation->managementLastActivityAt(), 'ثبت نشده') }}</dd>
                        @if($reservation->isPreinvoiceReservationWithoutInvoice())
                            {{-- Same distinction as the summary column above: only converted_at is
                                 a real reservation-connection timestamp. --}}
                            <dt class="col-5 text-muted">{{ $reservation->converted_at ? 'زمان اتصال به پیش‌فاکتور' : 'زمان ایجاد پیش‌فاکتور مرتبط' }}</dt>
                            <dd class="col-7" dir="ltr">{{ JalaliDate::dateTime($reservation->preinvoiceConnectedAt(), 'ثبت نشده') }}</dd>
                        @endif
                        <dt class="col-5 text-muted">دلیل نمایش</dt>
                        <dd class="col-7">{{ $reservation->businessDisplayReason() }}</dd>
                        <dt class="col-5 text-muted">سطح اهمیت</dt>
                        <dd class="col-7">{{ $reservation->managementImportanceLabel() }}</dd>
                        <dt class="col-5 text-muted">شناسه رزرو</dt>
                        <dd class="col-7">{{ $reservation->id }}</dd>
                        <dt class="col-5 text-muted">مرجع رزرو</dt>
                        <dd class="col-7 text-break" dir="ltr">{{ $reservation->token }}</dd>
                    </dl>
                    @if($reservation->managementWarning())
                        <div class="alert alert-warning py-2 px-3 small mt-3 mb-0">{{ $reservation->managementWarning() }}</div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">بستن</button>
                </div>
            </div>
        </div>
    </div>
@endforeach

@canPermission('warehouse_reservations.release')
    @foreach($reservations as $reservation)
        @if($reservation->canBeManuallyReleased())
            <div class="modal fade" id="release-reservation-{{ $reservation->id }}" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <form class="modal-content border-0" method="POST" action="{{ route('warehouse-reservations.release', $reservation) }}">
                        @csrf
                        <div class="modal-header">
                            <h3 class="modal-title fs-6">آزادسازی رزرو موجودی</h3>
                            <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="بستن"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-3">رزرو <strong>{{ $reservation->product?->name ?? 'کالا' }}</strong> به تعداد <strong>{{ number_format($reservation->quantity) }}</strong> آزاد شود؟</p>
                            <label class="form-label" for="release-reason-{{ $reservation->id }}">دلیل آزادسازی</label>
                            <select class="form-select" id="release-reason-{{ $reservation->id }}" name="release_reason" required>
                                <option value="">انتخاب کنید</option>
                                <option value="عدم تکمیل پیش‌فاکتور">عدم تکمیل پیش‌فاکتور</option>
                                <option value="انصراف مشتری">انصراف مشتری</option>
                                <option value="رزرو اشتباه">رزرو اشتباه</option>
                                <option value="سایر">سایر</option>
                            </select>
                            <label class="form-label mt-3" for="release-note-{{ $reservation->id }}">توضیحات اختیاری</label>
                            <textarea class="form-control" id="release-note-{{ $reservation->id }}" name="release_note" rows="3" maxlength="2000"></textarea>
                            <div class="form-text">این عملیات ثبت و در گزارش فعالیت‌ها نگهداری می‌شود.</div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">انصراف</button>
                            <button class="btn btn-danger" type="submit">تأیید آزادسازی</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endforeach
@endcanPermission

{{-- Phase 5 — Bulk management: selection toolbar wiring, confirmation modals,
     and result feedback. Each mutation action posts only to its own dedicated
     endpoint (bulk-release vs bulk-legacy-cleanup) — never a shared/generic
     one — so the two different stock semantics can never be conflated on the
     client side either. The server independently re-validates every ID
     regardless of what this page shows. --}}
@canPermission('warehouse_reservations.release')
    <div class="modal fade" id="bulk-release-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0">
                <div class="modal-header bg-danger-subtle">
                    <h3 class="modal-title fs-6">آزادسازی رزرو و برگشت موجودی به انبار مرکزی</h3>
                    <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="بستن"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2"><strong id="bulk-release-count-text">۰ رزرو</strong> انتخاب‌شده آزاد خواهند شد.</p>
                    <ul class="small text-muted mb-3">
                        <li>مقدار رزروشده آزاد و از cache رزرو کسر می‌شود.</li>
                        <li>موجودی فیزیکی به انبار مرکزی برمی‌گردد.</li>
                        <li>برای هر رزرو ممکن است سند گردش موجودی (stock movement) ایجاد شود.</li>
                    </ul>
                    <label class="form-label" for="bulk-release-reason">دلیل آزادسازی</label>
                    <select class="form-select" id="bulk-release-reason" required>
                        <option value="">انتخاب کنید</option>
                        <option value="عدم تکمیل پیش‌فاکتور">عدم تکمیل پیش‌فاکتور</option>
                        <option value="انصراف مشتری">انصراف مشتری</option>
                        <option value="رزرو اشتباه">رزرو اشتباه</option>
                        <option value="سایر">سایر</option>
                    </select>
                    <label class="form-label mt-3" for="bulk-release-note">توضیحات اختیاری</label>
                    <textarea class="form-control" id="bulk-release-note" rows="3" maxlength="2000"></textarea>
                    <div class="form-text">رزروهایی که دیگر قابل آزادسازی نیستند، رد (skip) می‌شوند و آزاد نمی‌شوند.</div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">انصراف</button>
                    <button class="btn btn-danger" type="button" id="bulk-release-confirm-btn">تأیید آزادسازی</button>
                </div>
            </div>
        </div>
    </div>
@endcanPermission

@canPermission('inventory.reservation.legacy_cleanup')
    <div class="modal fade" id="bulk-legacy-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0">
                <div class="modal-header bg-warning-subtle">
                    <h3 class="modal-title fs-6">حذف رزرو قدیمی بدون برگشت موجودی</h3>
                    <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="بستن"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2"><strong id="bulk-legacy-count-text">۰ رزرو</strong> انتخاب‌شده بررسی خواهند شد.</p>
                    <ul class="small text-muted mb-0">
                        <li>فقط ردیف‌هایی که «کاندید Legacy» هستند پردازش می‌شوند؛ بقیه رد (skip) می‌شوند.</li>
                        <li>چرخه عمر رزرو بسته و cache رزرو ترمیم می‌شود.</li>
                        <li>موجودی فیزیکی انبار مرکزی افزایش <strong>پیدا نمی‌کند</strong> و هیچ سند گردش موجودی ایجاد نمی‌شود.</li>
                    </ul>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">انصراف</button>
                    <button class="btn btn-warning" type="button" id="bulk-legacy-confirm-btn">تأیید حذف Legacy</button>
                </div>
            </div>
        </div>
    </div>
@endcanPermission

<div class="modal fade" id="bulk-result-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0">
            <div class="modal-header">
                <h3 class="modal-title fs-6" id="bulk-result-title">نتیجه عملیات</h3>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="بستن"></button>
            </div>
            <div class="modal-body">
                <div id="bulk-result-summary" class="mb-3"></div>
                <ul class="list-unstyled small mb-0" id="bulk-result-details"></ul>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" type="button" data-bs-dismiss="modal" onclick="window.location.reload()">بستن و بروزرسانی</button>
            </div>
        </div>
    </div>
</div>

@if($canBulkAny)
@push('scripts')
<script>
(function () {
    'use strict';

    var toolbar = document.getElementById('reservation-bulk-toolbar');
    var selectAll = document.getElementById('reservation-select-all-visible');
    var countLabel = document.getElementById('reservation-bulk-count');
    var releaseBtn = document.getElementById('reservation-bulk-release-btn');
    var legacyBtn = document.getElementById('reservation-bulk-legacy-btn');
    var exportBtn = document.getElementById('reservation-bulk-export-btn');
    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    function rowCheckboxes() {
        return Array.prototype.slice.call(document.querySelectorAll('.reservation-select-row'));
    }

    function selectedIds() {
        return rowCheckboxes().filter(function (cb) { return cb.checked; }).map(function (cb) { return cb.value; });
    }

    function toPersianDigits(value) {
        var digits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        return String(value).replace(/[0-9]/g, function (d) { return digits[d]; });
    }

    function refreshToolbar() {
        var ids = selectedIds();
        var count = ids.length;
        toolbar.hidden = rowCheckboxes().length === 0;
        countLabel.textContent = toPersianDigits(count) + ' مورد انتخاب شده';

        if (releaseBtn) releaseBtn.disabled = count === 0;
        if (legacyBtn) legacyBtn.disabled = count === 0;
        if (exportBtn) exportBtn.disabled = count === 0;

        var allChecked = rowCheckboxes().length > 0 && rowCheckboxes().every(function (cb) { return cb.checked; });
        selectAll.checked = allChecked;

        var releaseCountText = document.getElementById('bulk-release-count-text');
        if (releaseCountText) releaseCountText.textContent = toPersianDigits(count) + ' رزرو';
        var legacyCountText = document.getElementById('bulk-legacy-count-text');
        if (legacyCountText) legacyCountText.textContent = toPersianDigits(count) + ' رزرو';
    }

    selectAll?.addEventListener('change', function () {
        rowCheckboxes().forEach(function (cb) { cb.checked = selectAll.checked; });
        refreshToolbar();
    });

    document.addEventListener('change', function (event) {
        if (event.target && event.target.classList.contains('reservation-select-row')) {
            refreshToolbar();
        }
    });

    function showResult(title, summaryHtml, items, itemLabels) {
        document.getElementById('bulk-result-title').textContent = title;
        document.getElementById('bulk-result-summary').innerHTML = summaryHtml;

        var list = document.getElementById('bulk-result-details');
        list.innerHTML = '';
        (items || []).forEach(function (item) {
            if (!item.reason) { return; }
            var li = document.createElement('li');
            li.className = 'border-bottom py-1';
            li.textContent = '#' + item.id + ' — ' + item.reason;
            list.appendChild(li);
        });

        var resultModalEl = document.getElementById('bulk-result-modal');
        var resultModal = bootstrap.Modal.getOrCreateInstance(resultModalEl);
        resultModal.show();
    }

    function postBulk(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken || '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body),
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) {
                    var message = (data && data.message) || 'خطا در پردازش درخواست.';
                    throw new Error(message);
                }
                return data;
            });
        });
    }

    function hideModal(id) {
        var el = document.getElementById(id);
        var instance = bootstrap.Modal.getInstance(el);
        instance?.hide();
    }

    document.getElementById('bulk-release-confirm-btn')?.addEventListener('click', function () {
        var ids = selectedIds();
        var reasonField = document.getElementById('bulk-release-reason');
        var reason = reasonField.value;
        if (!reason) {
            reasonField.reportValidity();
            return;
        }
        var note = document.getElementById('bulk-release-note').value;

        postBulk('{{ route('warehouse-reservations.bulk-release') }}', {
            reservation_ids: ids,
            release_reason: reason,
            release_note: note || null,
        }).then(function (data) {
            hideModal('bulk-release-modal');
            showResult(
                'آزادسازی انجام شد',
                'موفق: ' + toPersianDigits(data.released) + ' — رد شده: ' + toPersianDigits(data.skipped) + ' — ناموفق: ' + toPersianDigits(data.failed),
                data.items
            );
        }).catch(function (error) {
            hideModal('bulk-release-modal');
            showResult('خطا در آزادسازی', error.message, []);
        });
    });

    document.getElementById('bulk-legacy-confirm-btn')?.addEventListener('click', function () {
        var ids = selectedIds();

        postBulk('{{ route('warehouse-reservations.bulk-legacy-cleanup') }}', {
            reservation_ids: ids,
        }).then(function (data) {
            hideModal('bulk-legacy-modal');
            showResult(
                'حذف Legacy انجام شد',
                'بسته‌شده: ' + toPersianDigits(data.closed) + ' — رد شده: ' + toPersianDigits(data.skipped),
                data.items
            );
        }).catch(function (error) {
            hideModal('bulk-legacy-modal');
            showResult('خطا در حذف Legacy', error.message, []);
        });
    });

    exportBtn?.addEventListener('click', function () {
        var ids = selectedIds();
        if (ids.length === 0) { return; }

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '{{ route('warehouse-reservations.bulk-export') }}';
        form.style.display = 'none';

        var tokenInput = document.createElement('input');
        tokenInput.type = 'hidden';
        tokenInput.name = '_token';
        tokenInput.value = csrfToken || '';
        form.appendChild(tokenInput);

        ids.forEach(function (id) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'reservation_ids[]';
            input.value = id;
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    });

    refreshToolbar();
})();
</script>
@endpush
@endif
