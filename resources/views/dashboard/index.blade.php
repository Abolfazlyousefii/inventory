@extends('layouts.app')

@section('title', 'داشبورد')

@php
use Morilog\Jalali\Jalalian;

$statusLabels = [
    'pending_warehouse_approval' => 'در انتظار تایید انبار',
    'collecting' => 'در حال جمع‌آوری',
    'checking_discrepancy' => 'بررسی مغایرت',
    'final_check' => 'بازبینی نهایی',
    'packing' => 'بسته‌بندی',
    'shipped' => 'ارسال‌شده',
    'not_shipped' => 'ارسال‌نشده',
];
@endphp

@section('content')
<style>
    .dashboard-shell{background:#f6f8fb;border-radius:20px;padding:14px;max-width:100%;overflow-x:hidden}.dash-surface{background:#fff;border:1px solid #e2e8f0;border-radius:18px;box-shadow:0 8px 24px rgba(15,39,69,.04)}.dash-title{color:#102a43;font-weight:800}.dash-muted{color:#64748b}.dash-section-title{color:#102a43;font-size:1.05rem;font-weight:800}.quick-card{display:block;height:100%;padding:18px;text-decoration:none;color:#102a43;border:1px solid #e2e8f0;border-radius:18px;background:linear-gradient(180deg,#fff,#f8fbff);transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease}.quick-card:hover{transform:translateY(-2px);box-shadow:0 14px 32px rgba(37,99,235,.1);border-color:#bfdbfe;color:#0f172a}.quick-icon{width:44px;height:44px;border-radius:14px;background:#eef4ff;color:#2563eb;display:inline-flex;align-items:center;justify-content:center;font-size:1.25rem}.quick-card-desc{font-size:.86rem;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.search-box{background:linear-gradient(135deg,#eff6ff,#f8fafc)}.task-row{border-bottom:1px solid #edf2f7;padding:.75rem 0}.task-row:last-child{border-bottom:0}.task-row.is-zero{opacity:.58}.summary-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:14px;height:100%}.summary-value{font-size:1.1rem;font-weight:800;color:#0f172a}.admin-panel details>summary{cursor:pointer;list-style:none}.admin-panel details>summary::-webkit-details-marker{display:none}.dash-link-row{border-bottom:1px solid #edf2f7;padding-top:.65rem!important;padding-bottom:.65rem!important;border-radius:10px}.dash-link-row:last-child{border-bottom:0}.dash-link-row:hover{background:#f8fbff}.dash-progress-bg{background:#e7eef8!important}@media(max-width:575.98px){.dashboard-shell{padding:10px}.quick-card{padding:14px}.quick-icon{width:38px;height:38px}.summary-value{font-size:1rem}}
</style>

<div class="dashboard-shell">
    <div class="dash-surface p-3 mb-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h4 class="mb-1 dash-title">داشبورد</h4>
            <div class="dash-muted">امروز چه کاری می‌خواهید انجام دهید؟</div>
        </div>
        <div class="text-md-end small dash-muted">
            <div>{{ $todayDateLabel }}</div>
            <div>{{ $todayDateTimeLabel }} @if($userName) | {{ $userName }} @endif</div>
        </div>
    </div>

    <section class="dash-surface p-3 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="dash-section-title">شروع سریع</div>
            <span class="small dash-muted">میانبرهای اصلی</span>
        </div>
        <div class="row g-3">
            @forelse($quickActions as $action)
                <div class="col-12 col-sm-6 col-lg-4 col-xxl-3">
                    <a class="quick-card" href="{{ $action['route'] }}">
                        <span class="quick-icon mb-3"><i class="bi bi-{{ $action['icon'] }}"></i></span>
                        <h6 class="fw-bold mb-1">{{ $action['title'] }}</h6>
                        <div class="quick-card-desc">{{ $action['description'] }}</div>
                    </a>
                </div>
            @empty
                <div class="col-12"><div class="alert alert-light border mb-0">برای نقش شما میانبر فعالی تعریف نشده است.</div></div>
            @endforelse
        </div>
    </section>

    <section class="dash-surface search-box p-3 mb-4">
        <div class="dash-section-title mb-2">جستجوی سریع</div>
        <form method="GET" action="{{ route('global-search') }}" class="row g-2 align-items-center">
            <div class="col-12 col-lg-10">
                <input name="q" class="form-control form-control-lg" placeholder="جستجوی کالا، مشتری، فاکتور یا بارکد..." autocomplete="off">
            </div>
            <div class="col-12 col-lg-2 d-grid">
                <button class="btn btn-primary btn-lg">جستجو</button>
            </div>
        </form>
        <div class="small dash-muted mt-2">نام کالا، کد، SKU، بارکد، شماره فاکتور/پیش‌فاکتور، نام یا موبایل مشتری را وارد کنید.</div>
    </section>

    <div class="row g-3 mb-4">
        <div class="col-xl-7">
            <section class="dash-surface p-3 h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="dash-section-title">کارهای امروز</div>
                    <span class="small dash-muted">موارد نیازمند رسیدگی</span>
                </div>
                @foreach($actionItems as $item)
                    <div class="task-row d-flex flex-wrap justify-content-between align-items-center gap-2 {{ (int) $item['count'] === 0 ? 'is-zero' : '' }}">
                        <div class="fw-semibold text-dark">{{ $item['title'] }}</div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge text-bg-light border text-dark">{{ number_format($item['count']) }}</span>
                            <a href="{{ $item['route'] }}" class="btn btn-sm btn-outline-primary">مشاهده</a>
                        </div>
                    </div>
                @endforeach
            </section>
        </div>
        <div class="col-xl-5">
            <section class="dash-surface p-3 h-100">
                <div class="dash-section-title mb-3">خلاصه امروز</div>
                <div class="row g-2">
                    @foreach($todaySummary as $summary)
                        <div class="col-6">
                            <div class="summary-card">
                                <div class="small dash-muted mb-1">{{ $summary['title'] }}</div>
                                <div class="summary-value">{{ number_format($summary['value']) }}</div>
                                <div class="small dash-muted">{{ $summary['suffix'] }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        </div>
    </div>

    <section class="dash-surface p-3 admin-panel">
        <details>
            <summary class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <div class="dash-section-title">گزارش‌های بیشتر</div>
                    <div class="small dash-muted">داشبورد مدیریتی، خلاصه ماهانه و آخرین فعالیت‌ها</div>
                </div>
                <span class="btn btn-outline-secondary btn-sm">نمایش داشبورد مدیریتی</span>
            </summary>

            <div class="pt-3 mt-3 border-top">
                <div class="row g-3 mb-3">
                    <div class="col-xl-4"><div class="summary-card"><div class="fw-bold mb-2">خلاصه فروش</div><div class="small d-flex justify-content-between mb-2"><span class="dash-muted">پیش‌فاکتور این ماه</span><strong>{{ number_format($salesSummary['preinvoicesThisMonth']) }}</strong></div><div class="small d-flex justify-content-between mb-2"><span class="dash-muted">فاکتور این ماه</span><strong>{{ number_format($salesSummary['invoicesThisMonth']) }}</strong></div><div class="small d-flex justify-content-between"><span class="dash-muted">مبلغ فروش این ماه</span><strong>{{ number_format($salesSummary['salesAmountThisMonth']) }}</strong></div></div></div>
                    <div class="col-xl-4"><div class="summary-card"><div class="fw-bold mb-2">خلاصه انبارداری</div><div class="small d-flex justify-content-between mb-2"><span class="dash-muted">حواله‌های امروز</span><strong>{{ number_format($warehouseSummary['todayHavalehCount']) }}</strong></div><div class="small d-flex justify-content-between mb-2"><span class="dash-muted">در انتظار انبار</span><strong>{{ number_format($warehouseSummary['pendingWarehouse']) }}</strong></div><div class="small d-flex justify-content-between"><span class="dash-muted">کالاهای ناموجود</span><strong>{{ number_format($warehouseSummary['outOfStock']) }}</strong></div></div></div>
                    <div class="col-xl-4"><div class="summary-card"><div class="fw-bold mb-2">خلاصه مالی</div><div class="small d-flex justify-content-between mb-2"><span class="dash-muted">صف مالی</span><strong>{{ number_format($financeSummary['financeQueue']) }}</strong></div><div class="small d-flex justify-content-between mb-2"><span class="dash-muted">پرداخت نقدی</span><strong>{{ number_format($financeSummary['todayCashPayments']) }}</strong></div><div class="small d-flex justify-content-between"><span class="dash-muted">پرداخت چکی</span><strong>{{ number_format($financeSummary['todayChequePayments']) }}</strong></div></div></div>
                </div>

                <div class="dash-surface p-3 mb-3" id="monthlyReportsCard" data-endpoint="{{ route('dashboard.monthly-report') }}" data-initial='@json($monthlyReport)'>
                    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
                        <div><div class="dash-section-title mb-1">خلاصه ماهانه</div><div id="monthlyReportRange" class="dash-muted small">بازه: {{ $monthlyReport['range_label'] }}</div></div>
                        <div class="d-flex gap-2 align-items-end"><div><label for="reportMonthSelect" class="form-label small dash-muted mb-1">ماه</label><select id="reportMonthSelect" class="form-select form-select-sm">@foreach($reportMonths as $monthNumber => $monthLabel)<option value="{{ $monthNumber }}" @selected($selectedReportMonth==$monthNumber)>{{ $monthLabel }}</option>@endforeach</select></div><div><label for="reportYearSelect" class="form-label small dash-muted mb-1">سال</label><select id="reportYearSelect" class="form-select form-select-sm">@foreach($reportYears as $yearOption)<option value="{{ $yearOption }}" @selected($selectedReportYear==$yearOption)>{{ $yearOption }}</option>@endforeach</select></div></div>
                    </div>
                    <div id="monthlyHorizontalChart" class="d-grid gap-2"></div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-7"><div class="summary-card"><div class="fw-bold mb-2">آخرین فعالیت‌ها</div><div class="dash-link-row py-2 d-flex justify-content-between"><span>آخرین پیش‌فاکتور</span><span class="dash-muted small">{{ $recentActivity['latestPreinvoice']?->customer_name ?? '---' }}</span></div><div class="dash-link-row py-2 d-flex justify-content-between"><span>آخرین حواله</span><span class="dash-muted small">{{ $recentActivity['latestHavaleh']?->uuid ?? '---' }}</span></div><div class="dash-link-row py-2 d-flex justify-content-between"><span>آخرین تغییر وضعیت</span><span class="dash-muted small">{{ $statusLabels[$recentActivity['latestStatusChange']?->new_value ?? ''] ?? '---' }}</span></div>@foreach($recentActivity['latestUserActivities']->take(2) as $log)<div class="dash-link-row py-2 d-flex justify-content-between"><span class="small">{{ $log->user?->name ?? 'سیستم' }} - {{ $log->description }}</span><span class="dash-muted small">{{ Jalalian::fromDateTime($log->occurred_at)->format('m/d H:i') }}</span></div>@endforeach</div></div>
                    <div class="col-lg-5"><div class="summary-card"><div class="fw-bold mb-2">بخش‌های نرم‌افزار</div>@foreach($moduleShortcuts as $module)<a href="{{ $module['route'] }}" class="dash-link-row py-2 text-decoration-none d-flex justify-content-between align-items-center"><div><div class="fw-semibold text-dark">{{ $module['title'] }}</div><div class="dash-muted small">{{ $module['description'] }}</div></div><i class="bi bi-{{ $module['icon'] }} text-primary"></i></a>@endforeach</div></div>
                </div>
            </div>
        </details>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded',function(){const card=document.getElementById('monthlyReportsCard');if(!card)return;const endpoint=card.dataset.endpoint,monthSelect=document.getElementById('reportMonthSelect'),yearSelect=document.getElementById('reportYearSelect'),rangeLabelEl=document.getElementById('monthlyReportRange'),chartEl=document.getElementById('monthlyHorizontalChart'),formatNumber=(v)=>new Intl.NumberFormat('fa-IR').format(Number(v||0));function renderChart(report){chartEl.innerHTML='';(report.metrics||[]).slice(0,5).forEach((metric)=>{const row=document.createElement('div');row.innerHTML=`<div class="d-flex justify-content-between mb-1 small"><span class="fw-semibold">${metric.label}</span><span class="dash-muted">${formatNumber(metric.value)} ${metric.unit}</span></div><div class="progress dash-progress-bg" style="height:10px;"><div class="progress-bar bg-${metric.color}" style="width:${metric.percent}%"></div></div>`;chartEl.appendChild(row);});}function renderReport(report){rangeLabelEl.textContent=`بازه: ${report.range_label}`;renderChart(report);}let initialReport=null;try{initialReport=JSON.parse(card.dataset.initial||'{}')}catch(e){initialReport=null}if(initialReport&&initialReport.metrics)renderReport(initialReport);async function fetchReport(){const url=`${endpoint}?report_month=${encodeURIComponent(monthSelect.value)}&report_year=${encodeURIComponent(yearSelect.value)}`;card.classList.add('opacity-75');try{const response=await fetch(url,{headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});if(!response.ok)throw new Error('failed');renderReport(await response.json())}catch(err){console.error(err)}finally{card.classList.remove('opacity-75')}}monthSelect?.addEventListener('change',fetchReport);yearSelect?.addEventListener('change',fetchReport);});
</script>
@endsection
