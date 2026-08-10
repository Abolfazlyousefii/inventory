<div class="actions no-print">
    <button class="btn" type="button" onclick="window.print()">چاپ</button>
    <a class="btn" href="{{ route('vouchers.return-from-sale.index', request()->query()) }}">بازگشت</a>
</div>
<header class="print-header">
    <div class="brand">
        <img src="{{ asset('logo.png') }}" alt="آریا">
        <div><div class="system-name">{{ config('app.name', 'نرم افزار داخلی آریا گستر') }}</div><h1>{{ $title }}</h1></div>
    </div>
    <div class="generated">تاریخ تولید: {{ $generatedAt }}</div>
</header>
@if(!empty($activeFilters))
    <div class="filters">
        @foreach($activeFilters as $label => $value)
            <span>{{ $label }}: <strong>{{ $value }}</strong></span>
        @endforeach
    </div>
@endif
