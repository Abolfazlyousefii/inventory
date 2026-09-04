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

<div class="card table-card overflow-hidden">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
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
                @endphp
                <tr @class(['old-reservation-row' => $warning !== null])>
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
                            <div class="muted-line mt-1">اتصال: {{ JalaliDate::dateTime($reservation->preinvoiceConnectedAt()) }}</div>
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
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#reservation-details-{{ $reservation->id }}">
                                مشاهده
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
                <tr><td colspan="11"><div class="empty-state">رزروی با فیلترهای انتخاب‌شده پیدا نشد.</div></td></tr>
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
                            <dt class="col-5 text-muted">زمان اتصال به پیش‌فاکتور</dt>
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
