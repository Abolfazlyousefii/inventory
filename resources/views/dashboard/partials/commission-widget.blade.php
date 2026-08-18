@php
    use App\Support\Currency;
    use App\Support\JalaliDate;

    $commissionSeller = $commissionDashboard['seller_summary'] ?? null;
    $commissionTeam = $commissionDashboard['team_summary'] ?? null;
@endphp

<section class="seller-commission-widget" aria-labelledby="dashboard-commission-title">
    <div class="seller-commission-widget__head">
        <div>
            <span>دوره {{ $commissionDashboard ? JalaliDate::date($commissionDashboard['period']->start_at).' تا '.JalaliDate::date($commissionDashboard['period']->end_at) : 'جاری' }}</span>
            <h2 id="dashboard-commission-title">پورسانت دوره جاری</h2>
        </div>
        @if($commissionDashboard['is_stale'] ?? false)<span class="seller-commission-widget__warning">نیازمند بروزرسانی محاسبات</span>@endif
    </div>

    @if($commissionPeriodUnavailable)
        <div class="seller-commission-widget__empty"><strong>دوره جاری پورسانت قابل تشخیص نیست.</strong><span>لطفاً بعداً دوباره تلاش کنید یا با مدیر سامانه تماس بگیرید.</span></div>
    @elseif($canViewCommissionTeam && $commissionTeam)
        @if(($commissionTeam['sellers_with_calculation_count'] ?? 0) === 0)
            <div class="seller-commission-widget__empty"><strong>هنوز پورسانتی برای این دوره محاسبه نشده است.</strong><span>پس از اجرای محاسبات، خلاصه مدیریتی اینجا نمایش داده می‌شود.</span></div>
        @else
            <div class="seller-commission-widget__primary"><div><span>پورسانت محاسبه‌شده</span><strong>{{ Currency::formatToman($commissionTeam['total_calculated_commission']) }}</strong></div></div>
            <div class="seller-commission-widget__kpis">
                <div><span>تأییدشده مالی</span><strong>{{ Currency::formatToman($commissionDashboard['totals']['approved_commission']) }}</strong></div>
                <div><span>در انتظار بررسی</span><strong>{{ number_format($commissionDashboard['totals']['pending_review_count']) }}</strong></div>
                <div><span>برگشتی و اصلاحات</span><strong>{{ Currency::formatToman($commissionDashboard['totals']['returns_and_corrections']) }}</strong></div>
                <div><span>فروشنده دارای فعالیت</span><strong>{{ number_format($commissionTeam['sellers_with_calculation_count']) }}</strong></div>
            </div>
        @endif
        <div class="seller-commission-widget__foot"><span>{{ number_format(count($commissionDashboard['alerts'] ?? [])) }} گروه نیازمند توجه</span>@if($commissionPageUrl)<a href="{{ $commissionPageUrl }}">مشاهده سیستم پورسانت</a>@endif</div>
    @elseif($commissionSeller)
        <div class="seller-commission-widget__primary"><div><span>پورسانت محاسبه‌شده</span><strong>{{ Currency::formatToman($commissionSeller['calculated_commission']) }}</strong></div>@if($commissionDashboard['active_campaign'])<span class="seller-commission-widget__campaign">کمپین فعال +{{ rtrim(rtrim($commissionDashboard['active_campaign']->bonus_percentage, '0'), '.') }}٪</span>@endif</div>
        @if(!$commissionSeller['has_commission'])
            <div class="seller-commission-widget__empty"><strong>هنوز پورسانتی برای این دوره محاسبه نشده است.</strong><span>پس از ثبت فروش و به‌روزرسانی محاسبات، مبلغ این بخش نمایش داده می‌شود.</span></div>
        @endif
        @if($commissionTargetsEnabled && $commissionSeller['has_target'])
            <div class="seller-commission-widget__target"><span>از تارگت {{ Currency::formatToman($commissionSeller['target_amount']) }}</span><strong>{{ number_format($commissionSeller['progress_percent'], 1) }}٪</strong></div>
            <div class="seller-commission-progress"><span style="width: {{ $commissionSeller['progress_bar_percent'] }}%"></span></div>
            <div class="seller-commission-widget__kpis seller-commission-widget__kpis--seller">
                <div><span>{{ $commissionSeller['is_target_reached'] ? 'مازاد تارگت' : 'مانده تا تارگت' }}</span><strong>{{ Currency::formatToman($commissionSeller['is_target_reached'] ? $commissionSeller['exceeded_amount'] : $commissionSeller['remaining_amount']) }}</strong></div>
                <div><span>روز باقی‌مانده</span><strong>{{ number_format($commissionSeller['days_remaining']) }} روز</strong></div>
                <div><span>نیاز روزانه</span><strong>{{ $commissionSeller['required_daily_commission'] === null ? '—' : Currency::formatToman($commissionSeller['required_daily_commission']) }}</strong></div>
                <div><span>تأییدشده مالی</span><strong>{{ Currency::formatToman($commissionSeller['approved_commission']) }}</strong></div>
            </div>
            @if($commissionSeller['period_ended'])
                <div class="seller-commission-widget__empty"><strong>دوره پایان یافته است.</strong><span>نیاز روزانه دیگر محاسبه نمی‌شود و فاصله نهایی تا تارگت در بالا قابل مشاهده است.</span></div>
            @endif
        @elseif($commissionTargetsEnabled)
            <div class="seller-commission-widget__empty"><strong>برای این دوره تارگت پورسانت تعیین نشده است.</strong><span>{{ $commissionSeller['has_commission'] ? 'پورسانت محاسبه‌شده شما در بالا نمایش داده شده است.' : 'پس از شروع محاسبات، مبلغ پورسانت بدون درصد پیشرفت نمایش داده می‌شود.' }}</span></div>
        @endif
        <div class="seller-commission-widget__foot"><span>پاداش کمپین: {{ Currency::formatToman($commissionSeller['campaign_commission']) }}</span>@if($commissionPageUrl)<a href="{{ $commissionPageUrl }}">مشاهده جزئیات پورسانت</a>@endif</div>
    @else
        <div class="seller-commission-widget__empty"><strong>اطلاعات فروشندگی برای این حساب پیدا نشد.</strong><span>برای بررسی ارتباط این حساب با فروشنده، با مدیر سامانه تماس بگیرید.</span></div>
    @endif
</section>
