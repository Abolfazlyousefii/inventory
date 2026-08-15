@extends('layouts.app')

@section('title', 'داشبورد')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
@endpush

@push('scripts')
    <script src="{{ asset('js/dashboard.js') }}" defer></script>
@endpush

@php
    use Morilog\Jalali\Jalalian;

    $statusVariants = [
        \App\Models\PreinvoiceOrder::STATUS_DRAFT => 'muted',
        \App\Models\PreinvoiceOrder::STATUS_PENDING_FINANCE => 'info',
        \App\Models\PreinvoiceOrder::STATUS_FINANCE_REVIEWING => 'info-strong',
        \App\Models\PreinvoiceOrder::STATUS_RETURNED_TO_SALES => 'warning',
        \App\Models\PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE => 'success',
        \App\Models\PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE => 'danger',
    ];
@endphp

@section('content')
<div class="seller-dashboard">
    @if($sellerDashboardEnabled)
        <header class="seller-hero" aria-labelledby="seller-dashboard-title">
            <div class="seller-hero__intro">
                <div class="seller-hero__eyebrow">میز کار فروش</div>
                <h1 id="seller-dashboard-title">سلام {{ $userName }}، امروز چه سفارشی ثبت می‌کنیم؟</h1>
                <p>سریع پیش‌فاکتور بسازید، سفارش‌های قبلی را ادامه دهید و مشتری موردنظر را پیدا کنید.</p>
                <div class="seller-hero__meta" aria-label="اطلاعات امروز">
                    <span>{{ $todayDateLabel }}</span>
                    <span>آخرین بارگذاری {{ $todayDateTimeLabel }}</span>
                    <span>{{ $userRoleLabel }}</span>
                </div>
            </div>

            <div class="seller-hero__tools">
                @if($sellerCanSearch)
                    <form method="GET" action="{{ route('global-search') }}" class="seller-search" role="search">
                        <label class="visually-hidden" for="dashboard-global-search">جست‌وجوی سراسری</label>
                        <span class="seller-search__icon">@include('dashboard.partials.icon', ['name' => 'search'])</span>
                        <input id="dashboard-global-search" name="q" type="search" placeholder="نام مشتری، موبایل، کالا، فاکتور یا بارکد..." autocomplete="off">
                        <button type="submit">جست‌وجو</button>
                    </form>
                @endif

                @if($sellerCanCreate)
                    <a class="seller-primary-cta" href="{{ route('preinvoice.create') }}">
                        @include('dashboard.partials.icon', ['name' => 'plus'])
                        <span>ثبت پیش‌فاکتور جدید</span>
                    </a>
                @endif
            </div>
        </header>

        @if($commissionDashboard || $commissionPeriodUnavailable)
            @include('dashboard.partials.commission-widget')
        @endif

        @if($sellerQuickActions->isNotEmpty())
            <section class="seller-section" aria-labelledby="seller-quick-actions-title">
                <div class="seller-section__heading">
                    <div>
                        <h2 id="seller-quick-actions-title">دسترسی‌های اصلی فروش</h2>
                        <p>کار موردنظر را مستقیم شروع کنید.</p>
                    </div>
                </div>

                <div class="seller-quick-grid">
                    @foreach($sellerQuickActions as $action)
                        <a href="{{ $action['route'] }}" class="seller-quick-card {{ $action['emphasis'] ? 'seller-quick-card--primary' : '' }}">
                            <span class="seller-quick-card__icon">
                                @include('dashboard.partials.icon', ['name' => match($action['key']) {
                                    'create' => 'plus',
                                    'mine' => 'document',
                                    'customers' => 'customers',
                                    default => 'invoice',
                                }])
                            </span>
                            @if($action['emphasis'])
                                <span class="seller-quick-card__label">مهم‌ترین کار</span>
                            @endif
                            <strong>{{ $action['title'] }}</strong>
                            <span class="seller-quick-card__description">{{ $action['description'] }}</span>
                            @if($action['key'] === 'mine')
                                <span class="seller-quick-card__badges">
                                    <span>{{ number_format($sellerStatusCounts['drafts']) }} پیش‌نویس</span>
                                    <span>{{ number_format($sellerStatusCounts['returned_to_sales']) }} برگشتی</span>
                                </span>
                            @endif
                            <span class="seller-quick-card__action">{{ $action['key'] === 'create' ? 'ثبت سفارش' : 'باز کردن' }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        @if($sellerWorkItems->isNotEmpty())
            <div class="seller-main-grid">
                <section class="seller-panel" aria-labelledby="seller-work-title">
                    <div class="seller-section__heading">
                        <div>
                            <h2 id="seller-work-title">کارهای من</h2>
                            <p>سفارش‌هایی که امروز به رسیدگی شما نیاز دارند</p>
                        </div>
                    </div>

                    <div class="seller-work-list">
                        @foreach($sellerWorkItems as $item)
                            <article class="seller-work-item seller-work-item--{{ $item['variant'] }}">
                                <span class="seller-work-item__icon">@include('dashboard.partials.icon', ['name' => $item['icon']])</span>
                                <div class="seller-work-item__body">
                                    <h3>{{ $item['title'] }}</h3>
                                    <p>{{ $item['description'] }}</p>
                                </div>
                                <strong class="seller-work-item__count" aria-label="{{ number_format($item['count']) }} مورد">{{ number_format($item['count']) }}</strong>
                                <a href="{{ $item['route'] }}" class="seller-button seller-button--secondary">{{ $item['action_label'] }}</a>
                            </article>
                        @endforeach
                    </div>
                </section>

                <section class="seller-panel" aria-labelledby="seller-today-title">
                    <div class="seller-section__heading">
                        <div>
                            <h2 id="seller-today-title">خلاصه عملکرد امروز من</h2>
                            <p>فقط سفارش‌های ثبت‌شده توسط شما</p>
                        </div>
                    </div>

                    <div class="seller-summary-grid">
                        <div class="seller-summary-card">
                            <span>پیش‌فاکتور امروز من</span>
                            <strong>{{ number_format($sellerTodaySummary['preinvoices']) }}</strong>
                            <small>سفارش</small>
                        </div>
                        <div class="seller-summary-card">
                            <span>مبلغ سفارش‌های امروز من</span>
                            <strong>{{ number_format($sellerTodaySummary['amount']) }}</strong>
                            <small>ریال</small>
                        </div>
                        <div class="seller-summary-card">
                            <span>تأییدشده امروز</span>
                            <strong>{{ number_format($sellerTodaySummary['converted']) }}</strong>
                            <small>سفارش</small>
                        </div>
                        <div class="seller-summary-card">
                            <span>برگشتی برای اصلاح</span>
                            <strong>{{ number_format($sellerTodaySummary['returned']) }}</strong>
                            <small>سفارش</small>
                        </div>
                    </div>

                    <div class="seller-conversion">
                        <div>
                            <span>نسبت تبدیل سفارش‌های امروز</span>
                            <small>سهم سفارش‌های امروز که به فاکتور تبدیل شده‌اند</small>
                        </div>
                        <strong>{{ rtrim(rtrim(number_format($sellerConversionRate, 1, '.', ''), '0'), '.') }}٪</strong>
                    </div>
                </section>
            </div>

            <section class="seller-panel seller-recent" aria-labelledby="seller-recent-title">
                <div class="seller-section__heading seller-section__heading--action">
                    <div>
                        <h2 id="seller-recent-title">آخرین پیش‌فاکتورهای من</h2>
                        <p>پنج سفارش اخیر شما با تازه‌ترین وضعیت</p>
                    </div>
                    <a href="{{ route('preinvoice.my.index') }}" class="seller-text-link">مشاهده همه</a>
                </div>

                @if($sellerRecentPreinvoices->isEmpty())
                    <div class="seller-empty">
                        <span class="seller-empty__icon">@include('dashboard.partials.icon', ['name' => 'document'])</span>
                        <h3>هنوز پیش‌فاکتوری ثبت نکرده‌اید.</h3>
                        <p>اولین سفارش مشتری را از همین‌جا شروع کنید.</p>
                        @if($sellerCanCreate)
                            <a href="{{ route('preinvoice.create') }}" class="seller-button seller-button--primary">ثبت اولین پیش‌فاکتور</a>
                        @endif
                    </div>
                @else
                    <div class="seller-table-wrap">
                        <table class="seller-table">
                            <caption class="visually-hidden">پنج پیش‌فاکتور اخیر کاربر فعلی</caption>
                            <thead>
                                <tr>
                                    <th scope="col">شماره</th>
                                    <th scope="col">مشتری</th>
                                    <th scope="col">مبلغ</th>
                                    <th scope="col">زمان ثبت</th>
                                    <th scope="col">وضعیت</th>
                                    <th scope="col">عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($sellerRecentPreinvoices as $order)
                                    @php
                                        $statusLabel = $preinvoiceStatusLabels[$order->status] ?? 'وضعیت نامشخص';
                                        $statusVariant = $statusVariants[$order->status] ?? 'muted';
                                        $date = $order->document_date ?? $order->created_at;
                                        $dateLabel = $date?->isToday()
                                            ? 'امروز '.$date->format('H:i')
                                            : ($date ? Jalalian::fromDateTime($date)->format('Y/m/d') : '—');
                                    @endphp
                                    <tr>
                                        <td data-label="شماره"><span class="seller-document-number">{{ $order->uuid }}</span></td>
                                        <td data-label="مشتری">{{ $order->customer_name ?: 'بدون نام' }}</td>
                                        <td data-label="مبلغ"><strong>{{ number_format($order->total_price) }}</strong> <small>ریال</small></td>
                                        <td data-label="زمان ثبت">{{ $dateLabel }}</td>
                                        <td data-label="وضعیت"><span class="seller-status seller-status--{{ $statusVariant }}">{{ $statusLabel }}</span></td>
                                        <td data-label="عملیات"><a class="seller-button seller-button--secondary" href="{{ $order->dashboard_action_route }}">{{ $order->dashboard_action_label }}</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            @if($sellerSupplementaryActions->isNotEmpty())
                <section class="seller-section" aria-labelledby="seller-more-actions-title">
                    <div class="seller-section__heading">
                        <div>
                            <h2 id="seller-more-actions-title">دسترسی‌های تکمیلی فروش</h2>
                            <p>ابزارهای مرتبط فقط بر اساس دسترسی شما</p>
                        </div>
                    </div>
                    <div class="seller-small-links">
                        @foreach($sellerSupplementaryActions as $action)
                            <a href="{{ $action['route'] }}">
                                <span class="seller-small-links__icon">@include('dashboard.partials.icon', ['name' => 'document'])</span>
                                <span><strong>{{ $action['title'] }}</strong><small>{{ $action['description'] }}</small></span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            @if($sellerFollowUps->isNotEmpty())
                <section class="seller-panel seller-followups" aria-labelledby="seller-followups-title">
                    <div class="seller-section__heading">
                        <div>
                            <h2 id="seller-followups-title">پیگیری‌های مهم</h2>
                            <p>موارد واقعی که بهتر است از قلم نیفتند</p>
                        </div>
                    </div>
                    <div class="seller-followups__grid">
                        @foreach($sellerFollowUps as $item)
                            <a href="{{ $item['route'] }}" class="seller-followup seller-followup--{{ $item['variant'] }}">
                                <span>{{ $item['title'] }}</span>
                                <strong>{{ number_format($item['count']) }}</strong>
                                <small>{{ $item['description'] }}</small>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        @endif
    @else
        <header class="seller-hero seller-hero--compact">
            <div class="seller-hero__intro">
                <div class="seller-hero__eyebrow">داشبورد داخلی</div>
                <h1>سلام {{ $userName }}</h1>
                <p>گزارش‌ها و میانبرهای مجاز نقش {{ $userRoleLabel }} در ادامه نمایش داده شده‌اند.</p>
                <div class="seller-hero__meta"><span>{{ $todayDateLabel }}</span><span>آخرین بارگذاری {{ $todayDateTimeLabel }}</span></div>
            </div>
        </header>

        @if($commissionDashboard || $commissionPeriodUnavailable)
            @include('dashboard.partials.commission-widget')
        @endif
    @endif

    @if($canViewManagementReports || $canViewFinanceReports || $canViewWarehouseReports)
        <section class="seller-management" aria-labelledby="management-reports-title">
            <details>
                <summary>
                    <span>
                        <strong id="management-reports-title">{{ $canViewManagementReports ? 'گزارش‌های مدیریتی' : 'گزارش‌های عملیاتی مجاز' }}</strong>
                        <small>خلاصه‌های مالی، انبار و مدیریت متناسب با دسترسی شما</small>
                    </span>
                    <span class="seller-management__toggle">نمایش گزارش‌ها</span>
                </summary>

                <div class="seller-management__content">
                    <div class="seller-report-grid">
                        @if($salesSummary)
                            <article class="seller-report-card">
                                <h3>خلاصه فروش</h3>
                                <dl>
                                    <div><dt>پیش‌فاکتور این ماه</dt><dd>{{ number_format($salesSummary['preinvoicesThisMonth']) }}</dd></div>
                                    <div><dt>فاکتور این ماه</dt><dd>{{ number_format($salesSummary['invoicesThisMonth']) }}</dd></div>
                                    <div><dt>مبلغ فروش این ماه</dt><dd>{{ number_format($salesSummary['salesAmountThisMonth']) }} ریال</dd></div>
                                    <div><dt>برگشت از فروش</dt><dd>{{ number_format($salesSummary['returnFromSaleCount']) }}</dd></div>
                                </dl>
                            </article>
                        @endif

                        @if($warehouseSummary)
                            <article class="seller-report-card">
                                <h3>خلاصه انبار</h3>
                                <dl>
                                    <div><dt>حواله‌های امروز</dt><dd>{{ number_format($warehouseSummary['todayHavalehCount']) }}</dd></div>
                                    <div><dt>در انتظار انبار</dt><dd>{{ number_format($warehouseSummary['pendingWarehouse']) }}</dd></div>
                                    <div><dt>کالاهای کم‌موجود</dt><dd>{{ number_format($warehouseSummary['lowStock']) }}</dd></div>
                                    <div><dt>کالاهای ناموجود</dt><dd>{{ number_format($warehouseSummary['outOfStock']) }}</dd></div>
                                </dl>
                            </article>
                        @endif

                        @if($financeSummary)
                            <article class="seller-report-card">
                                <h3>خلاصه مالی</h3>
                                <dl>
                                    <div><dt>صف مالی</dt><dd>{{ number_format($financeSummary['financeQueue']) }}</dd></div>
                                    <div><dt>دریافت امروز</dt><dd>{{ number_format($financeSummary['todayReceipts']) }} ریال</dd></div>
                                    <div><dt>پرداخت نقدی امروز</dt><dd>{{ number_format($financeSummary['todayCashPayments']) }}</dd></div>
                                    <div><dt>پرداخت چکی امروز</dt><dd>{{ number_format($financeSummary['todayChequePayments']) }}</dd></div>
                                </dl>
                            </article>
                        @endif
                    </div>

                    @if($canViewManagementReports && $monthlyReport)
                        <section class="seller-report-card seller-monthly-report" id="monthlyReportsCard" data-endpoint="{{ route('dashboard.monthly-report') }}" aria-labelledby="monthly-report-title">
                            <div class="seller-monthly-report__head">
                                <div>
                                    <h3 id="monthly-report-title">گزارش ماهانه</h3>
                                    <p id="monthlyReportRange">بازه: {{ $monthlyReport['range_label'] }}</p>
                                </div>
                                <div class="seller-monthly-report__filters">
                                    <label for="reportMonthSelect">ماه
                                        <select id="reportMonthSelect">
                                            @foreach($reportMonths as $monthNumber => $monthLabel)
                                                <option value="{{ $monthNumber }}" @selected($selectedReportMonth === $monthNumber)>{{ $monthLabel }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label for="reportYearSelect">سال
                                        <select id="reportYearSelect">
                                            @foreach($reportYears as $year)
                                                <option value="{{ $year }}" @selected($selectedReportYear === $year)>{{ $year }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                </div>
                            </div>
                            <div id="monthlyReportError" class="seller-report-error" role="status" hidden></div>
                            <div id="monthlyHorizontalChart" class="seller-monthly-chart" aria-live="polite"></div>
                            <script type="application/json" id="monthlyReportInitialData">@json($monthlyReport, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
                        </section>
                    @endif

                    @if($warnings->isNotEmpty())
                        <section class="seller-report-card" aria-labelledby="dashboard-warnings-title">
                            <h3 id="dashboard-warnings-title">هشدارها</h3>
                            <div class="seller-warning-grid">
                                @foreach($warnings as $warning)
                                    <div class="seller-warning seller-warning--{{ $warning['variant'] }}">
                                        <strong>{{ $warning['title'] }}</strong>
                                        <span>{{ number_format($warning['count']) }}</span>
                                        <small>{{ $warning['description'] }}</small>
                                        @if($warning['route'])
                                            <a href="{{ $warning['route'] }}">مشاهده</a>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    <div class="seller-report-grid seller-report-grid--secondary">
                        @if($recentActivity)
                            <article class="seller-report-card">
                                <h3>فعالیت‌های اخیر</h3>
                                <div class="seller-activity-list">
                                    <div><span>آخرین پیش‌فاکتور</span><strong>{{ $recentActivity['latestPreinvoice']?->customer_name ?? '—' }}</strong></div>
                                    <div><span>آخرین حواله</span><strong>{{ $recentActivity['latestHavaleh']?->uuid ?? '—' }}</strong></div>
                                    <div><span>آخرین تغییر وضعیت</span><strong>{{ \App\Models\Invoice::statusLabels()[$recentActivity['latestStatusChange']?->new_value ?? ''] ?? '—' }}</strong></div>
                                    @foreach($recentActivity['latestUserActivities']->take(3) as $log)
                                        <div><span>{{ $log->user?->name ?? 'سیستم' }} — {{ $log->description }}</span><strong>{{ Jalalian::fromDateTime($log->occurred_at)->format('m/d H:i') }}</strong></div>
                                    @endforeach
                                </div>
                            </article>
                        @endif

                        @if($moduleShortcuts->isNotEmpty())
                            <article class="seller-report-card">
                                <h3>میانبرهای ماژول‌ها</h3>
                                <div class="seller-module-links">
                                    @foreach($moduleShortcuts as $module)
                                        <a href="{{ $module['route'] }}"><strong>{{ $module['title'] }}</strong><small>{{ $module['description'] }}</small></a>
                                    @endforeach
                                </div>
                            </article>
                        @endif
                    </div>
                </div>
            </details>
        </section>
    @endif
</div>
@endsection
