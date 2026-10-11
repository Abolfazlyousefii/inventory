<x-app-layout>
    <x-slot name="title">گزارش‌های مالی</x-slot>

    @php $financeReportsCss = public_path('css/finance-reports.css'); @endphp
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/finance-reports.css') }}?v={{ is_file($financeReportsCss) ? filemtime($financeReportsCss) : 1 }}">
        <link rel="stylesheet" href="{{ asset('css/finance-report-directory.css') }}?v={{ filemtime(public_path('css/finance-report-directory.css')) }}">
    @endpush

    <div class="finance-report-page finance-directory">
        <div class="finance-report-directory-heading">
            <h1>گزارش‌های مالی</h1>
            <p>گزارش موردنظر را انتخاب کنید.</p>
        </div>
        <div class="finance-reports-grid">
            @foreach($reports as $report)
                @php $isReceipts = str_contains($report['url'], '/receipts'); $isSalesChart = $isReceipts || str_contains($report['url'], '/top-products'); @endphp
                <a class="finance-report-directory-card {{ $isReceipts ? 'is-receipts' : ($isSalesChart ? 'is-sales' : 'is-commission') }}" href="{{ $report['url'] }}">
                    <div class="finance-directory-card-top">
                        <span class="finance-directory-icon" aria-hidden="true">
                            @if($isReceipts)
                                <svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="7" width="24" height="18" rx="4"/><path d="M4 12h24M20 19h3"/><circle cx="12" cy="19" r="3"/></svg>
                            @elseif($isSalesChart)
                                <svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M6 5v22h22"/><rect x="10" y="17" width="4" height="6" rx="1"/><rect x="17" y="11" width="4" height="12" rx="1"/><rect x="24" y="6" width="4" height="17" rx="1"/></svg>
                            @else
                                <svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M10 5h13l4 4v15a3 3 0 0 1-3 3H10a3 3 0 0 1-3-3V8a3 3 0 0 1 3-3Z"/><path d="M22 5v5h5M12 13h8M12 18h5M12 23h3"/><circle cx="23" cy="22" r="4"/><path d="m21.5 22 1 1 2-2"/></svg>
                            @endif
                        </span>
                        <span class="finance-directory-tag">{{ $isReceipts ? 'تحلیل دریافت‌ها' : ($isSalesChart ? 'تحلیل فروش' : 'اسناد پورسانت') }}</span>
                    </div>
                    <div><h2>{{ $report['title'] }}</h2><p>{{ $report['description'] }}</p></div>
                    <span class="finance-directory-card-link">{{ $isSalesChart ? 'مشاهده گزارش' : 'مشاهده اسناد' }} <svg aria-hidden="true" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 10H5m5-5-5 5 5 5"/></svg></span>
                </a>
            @endforeach
        </div>
    </div>
</x-app-layout>
