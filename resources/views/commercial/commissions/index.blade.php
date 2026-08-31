@extends('layouts.app')

@section('title', 'پورسانت فروشندگان')
@section('page-title', 'بازرگانی / پورسانت')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/commissions.css') }}">
    <link rel="stylesheet" href="{{ asset('css/commissions-phase4.css') }}">
@endpush

@php
    use App\Models\CommissionDocument;
    use App\Models\CommissionPeriod;
    use App\Support\Currency;
    use App\Support\JalaliDate;

    $periodStatuses = CommissionPeriod::statusLabels();
    $campaignStatuses = ['active' => 'فعال', 'scheduled' => 'آینده', 'expired' => 'پایان‌یافته', 'archived' => 'بایگانی‌شده'];
    $sellerSummary = $dashboard['seller_summary'] ?? null;
    $teamSummary = $dashboard['team_summary'] ?? null;
@endphp

@section('content')
<div class="container-fluid commission-shell" id="commissionApp"
     data-tree-url="{{ route('commercial.commissions.tree') }}"
     data-history-url="{{ route('commercial.commissions.rates.history') }}"
     data-can-manage-rates="{{ $permissions['rates'] ? '1' : '0' }}"
     data-can-manage-campaigns="{{ $permissions['campaigns'] ? '1' : '0' }}">
    <header class="commission-page-head">
        <div>
            <span class="commission-eyebrow">مدیریت عملکرد فروش</span>
            <h1>پورسانت فروشندگان @if($pilotMode && $canViewTeam)<span class="badge text-bg-warning fs-6">حالت آزمایشی</span>@endif</h1>
            <p>وضعیت پورسانت و امور مالی تیم در یک نمای یکپارچه</p>
        </div>
        <div class="commission-head-actions">
            @if($periods->isNotEmpty())
                <form method="get" class="commission-period-picker">
                    <label for="commissionPeriodPicker">دوره نمایش</label>
                    <select id="commissionPeriodPicker" name="period" class="form-select" onchange="this.form.submit()">
                        @foreach($periods as $option)
                            <option value="{{ $option->id }}" @selected($period?->id === $option->id)>{{ $option->label }}</option>
                        @endforeach
                    </select>
                </form>
            @endif
            @if($permissions['periods'])
                <button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#commissionSettingsModal">⚙ تنظیمات پورسانت</button>
            @endif
        </div>
    </header>

    @if($pilotMode && $canViewTeam)
        <div class="alert alert-warning commission-feedback" role="status"><strong>سیستم پورسانت در مرحله آزمایش داخلی قرار دارد.</strong> اطلاعات این بخش در حال حاضر برای فروشندگان نمایش داده نمی‌شود.</div>
    @endif

    @if(session('success'))<div class="alert alert-success commission-feedback" role="status">{{ session('success') }}</div>@endif
    @if(session('warning'))<div class="alert alert-warning commission-feedback" role="status">{{ session('warning') }}</div>@endif
    @if($errors->any())
        <div class="alert alert-danger commission-feedback" role="alert"><strong>اطلاعات ذخیره نشد.</strong><ul class="mb-0 mt-2">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <nav class="commission-tabs" aria-label="بخش‌های پورسانت"><div class="nav nav-tabs" role="tablist">
        <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#commission-overview" type="button" role="tab">نمای کلی</button>
        <button class="nav-link" id="rates-tab" data-bs-toggle="tab" data-bs-target="#commission-rates" type="button" role="tab">نرخ‌ها و کمپین‌ها</button>
        <button class="nav-link" id="documents-tab" data-bs-toggle="tab" data-bs-target="#commission-documents" type="button" role="tab">اسناد و تسویه</button>
    </div></nav>

    <div class="tab-content">
        <section id="commission-overview" class="tab-pane fade show active commission-tab-panel" role="tabpanel">
            @if($periodResolutionFailed || ! $period)
                <div class="commission-empty commission-empty--warning"><strong>دوره جاری پورسانت قابل تشخیص نیست.</strong><p>لطفاً تنظیمات چرخه را بررسی کنید یا با مدیر سامانه تماس بگیرید.</p></div>
            @else
                <article class="commission-period-header">
                    <div><span class="commission-eyebrow">دوره جاری</span><h2>{{ JalaliDate::date($period->start_at) }} تا {{ JalaliDate::date($period->end_at) }}</h2></div>
                    <dl>
                        <div><dt>وضعیت</dt><dd><span class="commission-status commission-status--{{ $period->display_status }}">{{ $periodStatuses[$period->display_status] ?? $period->display_status }}</span></dd></div>
                        <div><dt>روزهای باقی‌مانده</dt><dd>{{ number_format($dashboard['days_remaining'] ?? 0) }} روز</dd></div>
                        <div><dt>آخرین محاسبه</dt><dd>{{ JalaliDate::dateTime($dashboard['last_calculated_at'] ?? null) }}</dd></div>
                    </dl>
                    @if($dashboard['is_stale'] ?? false)
                        <div class="commission-stale"><span>محاسبات این دوره نیازمند بروزرسانی است.</span>
                            @if($permissions['recalculate'] && in_array($period->status, [CommissionPeriod::STATUS_OPEN, CommissionPeriod::STATUS_REVIEW], true))
                                <form method="post" action="{{ route('commercial.commissions.periods.recalculate', $period) }}" data-loading-form>@csrf<button class="btn btn-warning" type="submit" data-loading-text="در حال بروزرسانی…">به‌روزرسانی محاسبات</button></form>
                            @endif
                        </div>
                    @endif
                </article>

                @if($canViewTeam && $teamSummary)
                    @if($targetsEnabled)
                    <div class="commission-section-heading"><div><h2>وضعیت پورسانت تیم فروش</h2><p>پیشرفت تیم بر اساس مجموع وزنی تارگت‌ها محاسبه شده است.</p></div>@if($permissions['targets'])<button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#targetManagementModal">مدیریت تارگت‌ها</button>@endif</div>
                    <div class="commission-kpi-grid">
                        <article class="commission-kpi commission-kpi--primary"><span>پورسانت فعلی تیم</span><strong>{{ Currency::formatToman($teamSummary['total_calculated_commission']) }}</strong><small>پورسانت محاسبه‌شده</small></article>
                        <article class="commission-kpi"><span>مجموع تارگت تیم</span><strong>{{ Currency::formatToman($teamSummary['total_target']) }}</strong><small>{{ number_format($teamSummary['targeted_seller_count']) }} فروشنده دارای تارگت</small></article>
                        <article class="commission-kpi"><span>پیشرفت تیم</span><strong>{{ $teamSummary['team_progress_percent'] === null ? '—' : number_format($teamSummary['team_progress_percent'], 1).'%' }}</strong><div class="commission-progress"><span style="width: {{ max(0, min(100, $teamSummary['team_progress_percent'] ?? 0)) }}%"></span></div></article>
                        <article class="commission-kpi commission-kpi--success"><span>رسیده به تارگت</span><strong>{{ number_format($teamSummary['reached_target_count']) }} از {{ number_format($teamSummary['targeted_seller_count']) }}</strong><small>فقط فروشندگان دارای تارگت</small></article>
                    </div>
                    <div class="commission-kpi-grid commission-kpi-grid--compact">
                        <article class="commission-kpi"><span>پورسانت محاسبه‌شده</span><strong>{{ Currency::formatToman($dashboard['totals']['calculated_commission']) }}</strong></article>
                        <article class="commission-kpi"><span>تأییدشده مالی</span><strong>{{ Currency::formatToman($dashboard['totals']['approved_commission']) }}</strong></article>
                        <article class="commission-kpi"><span>پاداش کمپین</span><strong>{{ Currency::formatToman($dashboard['totals']['campaign_commission']) }}</strong></article>
                        <article class="commission-kpi"><span>برگشتی و اصلاحات</span><strong>{{ Currency::formatToman($dashboard['totals']['return_and_corrections']) }}</strong></article>
                        <article class="commission-kpi"><span>پرداخت‌شده</span><strong>{{ Currency::formatToman($dashboard['totals']['settled_amount']) }}</strong></article>
                    </div>
                    <article class="commission-card"><div class="commission-card__head"><div><h3>پیشرفت فروشندگان</h3><p>پورسانت محاسبه‌شده با مبلغ تأییدشده مالی متفاوت است.</p></div></div><div class="table-responsive"><table class="commission-table"><thead><tr><th>فروشنده</th><th>تارگت</th><th>پورسانت فعلی</th><th>تأییدشده</th><th>پیشرفت</th><th>مانده / مازاد</th><th>عملیات</th></tr></thead><tbody>
                    @forelse($sellerSummaries as $row)
                        <tr><td><strong>{{ $row['seller_name'] }}</strong></td><td>@if($row['has_target']){{ Currency::formatToman($row['target_amount']) }}@else<span class="commission-status commission-status--neutral">فاقد تارگت</span>@endif</td><td>{{ Currency::formatToman($row['calculated_commission']) }}</td><td>{{ Currency::formatToman($row['approved_commission']) }}</td><td class="commission-progress-cell">@if($row['has_target'])<strong>{{ number_format($row['progress_percent'], 1) }}%</strong><div class="commission-progress"><span style="width: {{ $row['progress_bar_percent'] }}%"></span></div>@else—@endif</td><td>@if(!$row['has_target'])—@elseif($row['is_target_reached'])<span class="text-success">{{ Currency::formatToman($row['exceeded_amount']) }} مازاد</span>@else{{ Currency::formatToman($row['remaining_amount']) }} مانده@endif</td><td><a class="btn btn-sm btn-outline-primary" href="{{ route('commercial.commissions.sellers.show', [$period, $row['seller_id']]) }}">جزئیات</a></td></tr>
                    @empty<tr><td colspan="7"><div class="commission-empty">فروشنده فعالی برای نمایش وجود ندارد.</div></td></tr>@endforelse
                    </tbody></table></div></article>
                    @elseif(($teamSummary['sellers_with_calculation_count'] ?? 0) === 0)
                        <div class="commission-empty"><strong>هنوز پورسانتی برای این دوره محاسبه نشده است.</strong><p>پس از ثبت فعالیت فروش و اجرای محاسبات، شاخص‌های مدیریتی اینجا نمایش داده می‌شوند.</p></div>
                    @else
                        <div class="commission-section-heading"><div><h2>وضعیت پورسانت تیم فروش</h2><p>نمای عملیاتی دوره برای بررسی، تأیید و تسویه</p></div></div>
                        <div class="commission-kpi-grid commission-kpi-grid--compact">
                            <article class="commission-kpi commission-kpi--primary"><span>پورسانت محاسبه‌شده</span><strong>{{ Currency::formatToman($dashboard['totals']['calculated_commission']) }}</strong></article>
                            <article class="commission-kpi"><span>تأییدشده مالی</span><strong>{{ Currency::formatToman($dashboard['totals']['approved_commission']) }}</strong></article>
                            <article class="commission-kpi"><span>در انتظار بررسی</span><strong>{{ number_format($dashboard['totals']['pending_review_count']) }}</strong><small>قلم سند</small></article>
                            <article class="commission-kpi"><span>برگشتی و اصلاحات</span><strong>{{ Currency::formatToman($dashboard['totals']['returns_and_corrections']) }}</strong></article>
                            <article class="commission-kpi"><span>فروشنده دارای فعالیت</span><strong>{{ number_format($teamSummary['sellers_with_calculation_count']) }}</strong></article>
                        </div>
                        <article class="commission-card"><div class="commission-card__head"><div><h3>وضعیت فروشندگان</h3><p>مبالغ محاسبه‌شده و تأییدشده ممکن است تا پایان بررسی متفاوت باشند.</p></div></div><div class="table-responsive"><table class="commission-table"><thead><tr><th>فروشنده</th><th>محاسبه‌شده</th><th>تأییدشده</th><th>در انتظار بررسی</th><th>برگشتی / اصلاح</th><th>وضعیت سند</th><th>عملیات</th></tr></thead><tbody>
                        @foreach($sellerSummaries as $row)
                            <tr><td><strong>{{ $row['seller_name'] }}</strong></td><td>{{ Currency::formatToman($row['calculated_commission']) }}</td><td>{{ Currency::formatToman($row['approved_commission']) }}</td><td>{{ number_format($row['pending_document_items']) }}</td><td>{{ Currency::formatToman($row['returns_and_corrections']) }}</td><td>{{ $row['document_status'] === CommissionDocument::STATUS_FINALIZED ? 'نهایی‌شده' : ($row['has_document'] ? 'پیش‌نویس' : 'فاقد سند') }}</td><td><a class="btn btn-sm btn-outline-primary" href="{{ route('commercial.commissions.sellers.show', [$period, $row['seller_id']]) }}">جزئیات</a></td></tr>
                        @endforeach
                        </tbody></table></div></article>
                    @endif
                @elseif($sellerSummary)
                    <article class="commission-seller-hero">
                        <div class="commission-seller-hero__top"><div><span class="commission-eyebrow">پورسانت دوره جاری من</span><strong>{{ Currency::formatToman($sellerSummary['calculated_commission']) }}</strong><small>پورسانت محاسبه‌شده / تخمینی</small></div>@if($dashboard['active_campaign'])<span class="commission-campaign-chip">پاداش کمپین فعال +{{ rtrim(rtrim($dashboard['active_campaign']->bonus_percentage, '0'), '.') }}%</span>@endif</div>
                        @if($targetsEnabled && $sellerSummary['has_target'])
                            <div class="commission-target-line"><span>از تارگت {{ Currency::formatToman($sellerSummary['target_amount']) }}</span><strong>{{ number_format($sellerSummary['progress_percent'], 1) }}%</strong></div><div class="commission-progress commission-progress--large"><span style="width: {{ $sellerSummary['progress_bar_percent'] }}%"></span></div>
                            <div class="commission-seller-metrics"><div><span>{{ $sellerSummary['is_target_reached'] ? 'مازاد تارگت' : ($sellerSummary['period_ended'] ? 'فاصله نهایی تا تارگت' : 'مانده تا تارگت') }}</span><strong>{{ Currency::formatToman($sellerSummary['is_target_reached'] ? $sellerSummary['exceeded_amount'] : $sellerSummary['remaining_amount']) }}</strong></div><div><span>روز باقی‌مانده</span><strong>{{ number_format($sellerSummary['days_remaining']) }} روز</strong></div>@if($sellerSummary['required_daily_commission'] !== null)<div><span>نیاز روزانه تا تارگت</span><strong>{{ Currency::formatToman($sellerSummary['required_daily_commission']) }}</strong></div>@elseif($sellerSummary['period_ended'])<div><span>وضعیت دوره</span><strong>دوره پایان یافته است</strong></div>@endif</div>
                        @elseif($targetsEnabled)<div class="commission-empty commission-empty--inline"><strong>برای این دوره تارگت پورسانت تعیین نشده است.</strong><p>پورسانت فعلی شما همچنان بر اساس محاسبات دوره نمایش داده می‌شود.</p></div>@endif
                        <div class="commission-breakdown"><div><span>پورسانت پایه</span><strong>{{ Currency::formatToman($sellerSummary['base_commission']) }}</strong></div><div><span>پاداش کمپین</span><strong>{{ Currency::formatToman($sellerSummary['campaign_commission']) }}</strong></div><div><span>تأییدشده مالی</span><strong>{{ Currency::formatToman($sellerSummary['approved_commission']) }}</strong></div></div>
                        <a class="btn btn-outline-primary" href="{{ route('commercial.commissions.sellers.show', [$period, $sellerSummary['seller_id']]) }}">مشاهده جزئیات پورسانت</a>
                    </article>
                @else<div class="commission-empty"><strong>اطلاعات فروشندگی برای این حساب تعریف نشده است.</strong><p>در صورت نیاز، وضعیت فروشنده حساب را با مدیر سامانه بررسی کنید.</p></div>@endif

                @if($canViewTeam)
                    <article class="commission-card commission-attention">
                        <div class="commission-card__head"><div><h3>نیازمند توجه</h3><p>مواردی که برای تکمیل چرخه پورسانت باید بررسی شوند.</p></div></div>
                        @if(empty($dashboard['alerts']))
                            <div class="commission-all-clear"><span>✓</span><div><strong>همه موارد این دوره به‌روز هستند.</strong><p>در حال حاضر مورد معوقی وجود ندارد.</p></div></div>
                        @else
                            <div class="commission-alert-grid">
                                @foreach($dashboard['alerts'] as $alert)
                                    <div class="commission-alert commission-alert--{{ $alert['variant'] }}">
                                        <strong>{{ number_format($alert['count']) }}</strong>
                                        <span>{{ $alert['label'] }}</span>
                                        @if($alert['key'] === 'missing_rates')
                                            <a href="{{ route('commercial.commissions.index', ['period' => $period->id, 'tab' => 'rates']) }}">بررسی نرخ‌ها</a>
                                        @elseif(in_array($alert['key'], ['pending_documents', 'stale_documents', 'pending_corrections', 'pending_adjustments', 'settlement_issues'], true))
                                            <a href="{{ route('commercial.commissions.index', ['period' => $period->id, 'tab' => 'documents']) }}">بررسی اسناد</a>
                                        @elseif($alert['key'] === 'missing_targets' && $permissions['targets'])
                                            <button type="button" data-bs-toggle="modal" data-bs-target="#targetManagementModal">مدیریت تارگت‌ها</button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </article>
                @endif
            @endif
        </section>

        <section id="commission-rates" class="tab-pane fade commission-tab-panel" role="tabpanel">
            <div class="commission-section-heading"><div><h2>نرخ‌های پورسانت</h2><p>نرخ اختصاصی، ارث‌بری و نرخ مؤثر هر سطح از درخت کالا</p></div><div class="commission-heading-actions"><input id="commissionTreeSearch" class="form-control" type="search" placeholder="جستجو در دسته، کالا و تنوع"><button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#campaignModal" @disabled(!$permissions['campaigns'])>ایجاد کمپین</button></div></div>
            <div class="commission-rates-layout">
                <article class="commission-card commission-tree-card"><div class="commission-tree-legend"><span><i class="commission-legend--own"></i> اختصاصی</span><span><i class="commission-legend--inherited"></i> ارث‌بری</span><span><i class="commission-legend--zero"></i> بدون پورسانت</span><span><i class="commission-legend--missing"></i> فاقد نرخ</span></div><div id="commissionTree" class="commission-tree" aria-live="polite">@forelse($rootNodes as $node)@include('commercial.commissions.partials.node', ['node' => $node])@empty<div class="commission-empty">هنوز دسته‌ای برای مدیریت نرخ ثبت نشده است.</div>@endforelse</div></article>
                <aside class="commission-card commission-campaigns"><div class="commission-card__head"><div><h3>کمپین‌های پورسانت</h3><p>برنامه‌های تشویقی و اقلام کمپین فعال یا زمان‌بندی‌شده</p></div></div>
                    @php($activeCampaign = $campaigns->first(fn($campaign) => $campaign->derived_status === 'active'))
                    @if($activeCampaign)
                        <article class="commission-active-campaign">
                            <span class="commission-status commission-status--active">کمپین فعال</span>
                            <h4>{{ $activeCampaign->name }}</h4>
                            <strong>+{{ rtrim(rtrim($activeCampaign->bonus_percentage, '0'), '.') }}%</strong>
                            <dl><div><dt>بازه</dt><dd>{{ JalaliDate::date($activeCampaign->start_at) }} تا {{ JalaliDate::date($activeCampaign->end_at->copy()->subDay()) }}</dd></div><div><dt>اقلام کمپین</dt><dd>{{ number_format($activeCampaign->targets_count) }} قلم مستقیم/درختی</dd></div><div><dt>روز باقی‌مانده</dt><dd>{{ max(0, now()->startOfDay()->diffInDays($activeCampaign->end_at->copy()->startOfDay(), false)) }} روز</dd></div></dl>
                            <details class="commission-campaign-items"><summary>مشاهده اقلام کمپین</summary><ul>@foreach($activeCampaign->targets as $campaignTarget)<li>{{ $campaignTarget->display_name }}</li>@endforeach</ul></details>
                        </article>
                    @else<div class="commission-empty"><strong>در حال حاضر کمپین پورسانتی فعالی وجود ندارد.</strong>@if($permissions['campaigns'])<button class="btn btn-primary mt-3" type="button" data-bs-toggle="modal" data-bs-target="#campaignModal">ایجاد کمپین</button>@endif</div>@endif
                    <div class="commission-campaign-list">@foreach($campaigns as $campaign)<div><div><strong>{{ $campaign->name }}</strong><small>{{ number_format($campaign->targets_count) }} قلم کمپین</small></div><span class="commission-status commission-status--{{ $campaign->derived_status }}">{{ $campaignStatuses[$campaign->derived_status] ?? $campaign->derived_status }}</span>@if($permissions['campaigns'] && !$campaign->archived_at)<button type="button" class="btn btn-sm btn-link commission-edit-campaign" data-action="{{ route('commercial.commissions.campaigns.update', $campaign) }}" data-name="{{ $campaign->name }}" data-bonus="{{ $campaign->bonus_percentage }}" data-start="{{ JalaliDate::date($campaign->start_at) }}" data-end="{{ JalaliDate::date($campaign->end_at->copy()->subDay()) }}" data-notes="{{ $campaign->notes }}" data-targets='@json($campaign->targets->map(fn($target) => ["key" => $target->target_key, "label" => $target->display_name])->values())'>ویرایش</button>@endif</div>@endforeach</div>
                </aside>
            </div>
        </section>

        <section id="commission-documents" class="tab-pane fade commission-tab-panel" role="tabpanel">
            <div class="commission-section-heading"><div><h2>اسناد و تسویه</h2><p>وضعیت بررسی مالی، نهایی‌سازی و پرداخت پورسانت‌ها</p></div>@if($permissions['manage_documents'] && $period)<button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#documentCreateModal">+ ایجاد سند پورسانت</button>@endif</div>
            <div class="commission-kpi-grid commission-kpi-grid--compact"><article class="commission-kpi"><span>کل اسناد دوره</span><strong>{{ number_format($documentStats['total']) }}</strong></article><article class="commission-kpi"><span>در انتظار بررسی</span><strong>{{ number_format($documentStats['pending']) }}</strong></article><article class="commission-kpi"><span>نهایی‌شده</span><strong>{{ number_format($documentStats['finalized']) }}</strong></article><article class="commission-kpi"><span>تسویه‌شده</span><strong>{{ number_format($documentStats['settled']) }}</strong></article><article class="commission-kpi"><span>پورسانت تأییدشده</span><strong>{{ Currency::formatToman($dashboard['totals']['approved_commission'] ?? 0) }}</strong></article></div>
            <article class="commission-card">
                @if($documents->isEmpty())<div class="commission-empty"><strong>هنوز سند پورسانتی برای این دوره ثبت نشده است.</strong>@if($permissions['manage_documents'] && $period)<button class="btn btn-primary mt-3" type="button" data-bs-toggle="modal" data-bs-target="#documentCreateModal">ایجاد سند</button>@endif</div>
                @else<div class="table-responsive"><table class="commission-table"><thead><tr><th>شماره سند</th><th>فروشنده</th><th>دوره</th><th>پورسانت</th><th>در انتظار</th><th>تأیید</th><th>وضعیت</th><th>آخرین تغییر</th><th>عملیات</th></tr></thead><tbody>@foreach($documents as $document)@php($documentStatus = $document->settlement?->status === 'paid' ? 'paid' : ($document->status === CommissionDocument::STATUS_FINALIZED ? 'finalized' : ($document->pending_count > 0 ? 'review' : 'draft'))) @php($documentLabels = ['draft'=>'پیش‌نویس','review'=>'در حال بررسی','finalized'=>'نهایی‌شده','paid'=>'پرداخت‌شده'])<tr><td><strong>{{ $document->document_number }}</strong></td><td>{{ $document->seller->name }}</td><td>{{ $document->period->label }}</td><td>{{ Currency::formatToman($document->approved_commission ?? 0) }}</td><td>{{ number_format($document->pending_count) }}</td><td>{{ number_format($document->approved_count) }}</td><td><span class="commission-status commission-status--{{ $documentStatus }}">{{ $documentLabels[$documentStatus] }}</span></td><td>{{ JalaliDate::dateTime($document->updated_at) }}</td><td><div class="commission-row-actions"><a class="btn btn-sm btn-outline-primary" href="{{ route('commercial.commissions.documents.show', $document) }}">مشاهده</a>@if($permissions['print_documents'])<a class="btn btn-sm btn-outline-secondary" href="{{ route('commercial.commissions.documents.print', $document) }}">چاپ</a>@endif</div></td></tr>@endforeach</tbody></table></div>{{ $documents->links() }}@endif
            </article>
            @include('commercial.commissions.partials.phase5')
        </section>
    </div>
</div>

@if($targetsEnabled && $permissions['targets'] && $period)
<div class="modal fade" id="targetManagementModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><h2 class="modal-title h5">مدیریت تارگت‌ها</h2><small class="text-muted">مبالغ این فرم به تومان هستند.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="d-flex justify-content-between gap-2 align-items-center mb-3 flex-wrap"><span>دوره: <strong>{{ $period->label }}</strong></span><form method="post" action="{{ route('commercial.commissions.targets.copy-previous', $period) }}" data-loading-form>@csrf<button class="btn btn-outline-primary" type="submit" data-loading-text="در حال کپی…">کپی تارگت‌های دوره قبل</button></form></div><div class="table-responsive"><table class="commission-table"><thead><tr><th>فروشنده</th><th>تارگت این دوره</th><th>تارگت دوره قبل</th><th>یادداشت</th><th>عملیات</th></tr></thead><tbody>@foreach($targetRows as $targetRow)@php($targetFormId = 'commissionTargetForm'.$targetRow['seller']->id)<tr><td><strong>{{ $targetRow['seller']->name }}</strong></td><td><div class="input-group"><input form="{{ $targetFormId }}" name="target_amount" class="form-control commission-money-input" inputmode="numeric" value="{{ $targetRow['current'] ? Currency::formatTomanNumber($targetRow['current']->target_amount) : '' }}" placeholder="مثلاً 20,000,000" required><span class="input-group-text">تومان</span></div></td><td>{{ $targetRow['previous'] ? Currency::formatToman($targetRow['previous']->target_amount) : 'فاقد تارگت' }}</td><td><input form="{{ $targetFormId }}" name="notes" class="form-control" maxlength="3000" value="{{ $targetRow['current']?->notes }}" placeholder="اختیاری"></td><td><form id="{{ $targetFormId }}" method="post" action="{{ route('commercial.commissions.targets.update', [$period, $targetRow['seller']]) }}" data-loading-form>@csrf @method('PUT')<button class="btn btn-sm btn-primary" type="submit" data-loading-text="در حال ذخیره…">{{ $targetRow['current'] ? 'ذخیره تغییرات' : 'تعیین تارگت' }}</button></form></td></tr>@endforeach</tbody></table></div></div></div></div></div>
@endif

@if($permissions['periods'])
<div class="modal fade" id="commissionSettingsModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title h5">تنظیمات پورسانت</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="post" action="{{ route('commercial.commissions.settings.update') }}" data-loading-form>@csrf @method('PUT')
        <div class="modal-body"><label class="form-label" for="cycleDay">روز شروع چرخه</label><input id="cycleDay" name="cycle_day" class="form-control" value="{{ $setting->cycle_day }}" required><p class="form-text">این تغییر فقط برای دوره‌های آینده است و دوره‌های قبلی را تغییر نمی‌دهد.</p></div>
        <div class="modal-footer"><button class="btn btn-primary" type="submit" data-loading-text="در حال ذخیره…">ذخیره چرخه</button></div>
    </form>
    <form method="post" action="{{ route('commercial.commissions.settings.features.update') }}" data-loading-form class="border-top">@csrf @method('PUT')
        <div class="modal-body"><h3 class="h6">فعال‌سازی آزمایشی</h3>
            <input type="hidden" name="pilot_mode" value="0"><div class="form-check form-switch mb-3"><input class="form-check-input" id="pilotMode" type="checkbox" name="pilot_mode" value="1" @checked($pilotMode)><label class="form-check-label" for="pilotMode">حالت Pilot</label><div class="form-text">هشدارهای بازبینی آزمایشی را برای مدیران نمایش می‌دهد.</div></div>
            <input type="hidden" name="seller_visibility_enabled" value="0"><div class="form-check form-switch mb-3"><input class="form-check-input" id="sellerVisibility" type="checkbox" name="seller_visibility_enabled" value="1" @checked($sellerVisibilityEnabled)><label class="form-check-label" for="sellerVisibility">نمایش پورسانت به فروشندگان</label><div class="form-text">داشبورد و مسیرهای مستقیم اطلاعات پورسانت خود فروشنده را فعال می‌کند.</div></div>
            <input type="hidden" name="targets_enabled" value="0"><div class="form-check form-switch"><input class="form-check-input" id="targetsEnabled" type="checkbox" name="targets_enabled" value="1" @checked($targetsEnabled)><label class="form-check-label" for="targetsEnabled">نمایش تارگت‌های پورسانت</label><div class="form-text">رابط کاربری تارگت دوره را فعال می‌کند؛ تارگت اقلام کمپین مستقل است.</div></div>
        </div>
        <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">بستن</button><button class="btn btn-primary" type="submit" data-loading-text="در حال ذخیره…">ذخیره فعال‌سازی</button></div>
    </form>
</div></div></div>
@endif

<div class="modal fade" id="rateEditModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5">تعیین یا ویرایش نرخ</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><dl class="commission-rate-summary"><div><dt>نام</dt><dd id="rateTargetLabel">—</dd></div><div><dt>نوع</dt><dd id="rateTargetKind">—</dd></div><div><dt>نرخ ارث‌بری</dt><dd id="rateInherited">—</dd></div><div><dt>نرخ اختصاصی فعلی</dt><dd id="rateOwn">—</dd></div><div><dt>نرخ مؤثر</dt><dd id="rateEffective">—</dd></div><div><dt>منبع نرخ</dt><dd id="rateSource">—</dd></div></dl>@if($permissions['rates'])<form method="post" action="{{ route('commercial.commissions.rates.store') }}" id="commissionRateForm" data-loading-form>@csrf<input type="hidden" name="target_type" id="rateTargetType"><input type="hidden" name="target_id" id="rateTargetId"><input type="hidden" name="period_id" value="{{ $period?->id }}"><label class="form-label" for="ratePercentage">درصد جدید</label><div class="input-group"><input id="ratePercentage" name="percentage" class="form-control" inputmode="decimal" required><span class="input-group-text">٪</span></div><fieldset class="mt-3"><legend class="form-label fs-6">اعمال نرخ از</legend>@if($period && in_array($period->status, [CommissionPeriod::STATUS_OPEN, CommissionPeriod::STATUS_REVIEW], true))<label class="d-block mb-2"><input type="radio" name="effective_mode" value="period_start" checked> ابتدای دوره انتخاب‌شده ({{ JalaliDate::date($period->start_at) }})</label>@endif<label class="d-block mb-2"><input type="radio" name="effective_mode" value="today" @checked(!$period || !in_array($period->status, [CommissionPeriod::STATUS_OPEN, CommissionPeriod::STATUS_REVIEW], true))> از امروز</label><label class="d-block"><input type="radio" name="effective_mode" value="custom"> تاریخ مشخص</label><input name="effective_from" class="form-control mt-2" data-jdp placeholder="تاریخ شمسی، مثال ۱۴۰۵/۰۶/۱۰"></fieldset><div class="d-flex gap-2 flex-wrap mt-3"><button class="btn btn-primary" type="submit" data-loading-text="در حال ذخیره…">ذخیره نرخ</button><button class="btn btn-outline-secondary" type="button" id="commissionExplicitZero">تعیین 0٪</button></div></form><form method="post" action="{{ route('commercial.commissions.rates.destroy') }}" id="commissionRemoveRateForm" class="mt-2" data-loading-form>@csrf @method('DELETE')<input type="hidden" name="target_type" id="removeRateTargetType"><input type="hidden" name="target_id" id="removeRateTargetId"><button class="btn btn-link text-danger p-0" type="submit">حذف نرخ اختصاصی و استفاده از ارث‌بری</button></form>@endif</div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" id="rateHistoryButton">مشاهده تاریخچه تغییرات</button><button class="btn btn-light" type="button" data-bs-dismiss="modal">بستن</button></div></div></div></div>
<div class="modal fade" id="commissionHistoryModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5">تاریخچه تغییرات نرخ</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" id="commissionHistoryBody">در حال دریافت…</div></div></div></div>

@if($permissions['campaigns'])<div class="modal fade" id="campaignModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><h2 class="modal-title h5" id="campaignModalTitle">ایجاد کمپین</h2><small class="text-muted">انتخاب دسته، تمام زیرمجموعه‌های آن را طبق قواعد فعلی پوشش می‌دهد.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><form method="post" action="{{ route('commercial.commissions.campaigns.store') }}" id="commissionCampaignForm" data-loading-form>@csrf<input type="hidden" name="_method" id="campaignMethod" value="POST"><div class="modal-body"><div class="commission-form-grid"><div><label class="form-label">نام کمپین</label><input name="name" id="campaignName" class="form-control" required></div><div><label class="form-label">درصد اضافه</label><div class="input-group"><input name="bonus_percentage" id="campaignBonus" class="form-control" required><span class="input-group-text">٪</span></div></div><div><label class="form-label">شروع</label><input name="start_date" id="campaignStart" class="form-control" data-jdp required></div><div><label class="form-label">پایان</label><input name="end_date" id="campaignEnd" class="form-control" data-jdp required></div><div class="commission-form-grid__full"><label class="form-label">اقلام کمپین</label><div id="campaignTargets" class="commission-selected-items"><p class="text-muted mb-0" data-empty-targets>از درخت نرخ، گزینه «افزودن به اقلام کمپین» را انتخاب کنید.</p></div></div><div class="commission-form-grid__full"><label class="form-label">یادداشت</label><textarea name="notes" id="campaignNotes" class="form-control" rows="3"></textarea></div></div></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">انصراف</button><button class="btn btn-primary" id="campaignSubmit" type="submit" data-loading-text="در حال ذخیره…">ثبت کمپین</button></div></form></div></div></div>@endif

@if($permissions['manage_documents'] && $period)<div class="modal fade" id="documentCreateModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5">ایجاد سند پورسانت</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><form method="post" action="{{ route('commercial.commissions.documents.store') }}" data-loading-form>@csrf<div class="modal-body"><div class="mb-3"><label class="form-label">فروشنده</label><select name="seller_id" class="form-select" required><option value="">انتخاب کنید</option>@foreach($documentSellers as $seller)<option value="{{ $seller->id }}">{{ $seller->name }}</option>@endforeach</select></div><div class="mb-3"><label class="form-label">دوره</label><select name="period_id" class="form-select" required>@foreach($periods as $option)<option value="{{ $option->id }}" @selected($period->id === $option->id)>{{ $option->label }}</option>@endforeach</select></div><div><label class="form-label">یادداشت</label><textarea name="notes" class="form-control" rows="3"></textarea></div></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">انصراف</button><button class="btn btn-primary" type="submit" data-loading-text="در حال ایجاد…">ایجاد سند</button></div></form></div></div></div>@endif
@endsection

@push('scripts')
    <script src="{{ asset('js/commissions.js') }}" defer></script>
    <script src="{{ asset('js/commissions-phase4.js') }}" defer></script>
@endpush
