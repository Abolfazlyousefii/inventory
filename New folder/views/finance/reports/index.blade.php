<x-app-layout>
    <x-slot name="title">گزارش‌های مالی</x-slot>

    @php $financeReportsCss = public_path('css/finance-reports.css'); @endphp
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/finance-reports.css') }}?v={{ is_file($financeReportsCss) ? filemtime($financeReportsCss) : 1 }}">
    @endpush

    <div class="finance-report-page">
        <div class="finance-report-directory-heading">
            <h1>گزارش‌های مالی</h1>
            <p>گزارش موردنظر را انتخاب کنید.</p>
        </div>
        <div class="finance-reports-grid">
            @foreach($reports as $report)
                <a class="finance-report-directory-card" href="{{ $report['url'] }}">
                    <div><h2>{{ $report['title'] }}</h2><p>{{ $report['description'] }}</p></div>
                    <span>مشاهده اسناد</span>
                </a>
            @endforeach
        </div>
    </div>
</x-app-layout>
