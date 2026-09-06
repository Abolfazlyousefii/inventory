{{-- Reservation dashboard summary cards. All numbers come from ReservationQueryService::dashboardStatistics() — no calculation happens in this view. --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card summary-card h-100" style="--summary-color:#16a34a">
            <div class="card-body">
                <div class="summary-label mb-1">رزرو فعال</div>
                <div class="summary-value">{{ number_format($stats['active']['count']) }}</div>
                <div class="summary-meta">{{ number_format($stats['active']['quantity']) }} واحد کالا</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card summary-card h-100" style="--summary-color:#1d4ed8">
            <div class="card-body">
                <div class="summary-label mb-1">پیش‌فاکتور رسمی</div>
                <div class="summary-value">{{ number_format($stats['official']['count']) }}</div>
                <div class="summary-meta">{{ number_format($stats['official']['quantity']) }} واحد کالا</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card summary-card h-100" style="--summary-color:#dc2626">
            <div class="card-body">
                <div class="summary-label mb-1">رزرو بحرانی</div>
                <div class="summary-value">{{ number_format($stats['critical']['count']) }}</div>
                <div class="summary-meta">{{ number_format($stats['critical']['quantity']) }} واحد کالا</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card summary-card h-100" style="--summary-color:#7c3aed">
            <div class="card-body">
                <div class="summary-label mb-1">کاندید Legacy</div>
                <div class="summary-value">{{ number_format($stats['legacy_candidates']['count']) }}</div>
                <div class="summary-meta">{{ number_format($stats['legacy_candidates']['quantity']) }} واحد کالا نیازمند بررسی</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card summary-card h-100" style="--summary-color:#0891b2">
            <div class="card-body">
                <div class="summary-label mb-1">رزرو موقت (بدون پیش‌فاکتور)</div>
                <div class="summary-value">{{ number_format($stats['temporary']['count']) }}</div>
                <div class="summary-meta">{{ number_format($stats['temporary']['quantity']) }} واحد کالا</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card summary-card h-100" style="--summary-color:#d97706">
            <div class="card-body">
                <div class="summary-label mb-1">رزرو نیاز بررسی</div>
                <div class="summary-value">{{ number_format($stats['needs_review']['count']) }}</div>
                <div class="summary-meta">{{ number_format($stats['needs_review']['quantity']) }} واحد کالا</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card summary-card h-100" style="--summary-color:#991b1b">
            <div class="card-body">
                <div class="summary-label mb-1">رزرو قابل آزادسازی</div>
                <div class="summary-value">{{ number_format($stats['releasable']['count']) }}</div>
                <div class="summary-meta">{{ number_format($stats['releasable']['quantity']) }} واحد کالا</div>
            </div>
        </div>
    </div>
</div>
