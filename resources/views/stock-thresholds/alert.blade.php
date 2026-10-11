@if(auth()->check() && \Illuminate\Support\Facades\Route::has('stock-thresholds.daily') && \App\Support\PageAccessCatalog::userCanRoute(auth()->user(), 'stock-thresholds.index'))
<link rel="stylesheet" href="{{ asset('css/stock-thresholds.css') }}">
<dialog class="threshold-alert-dialog" id="stockThresholdDialog" dir="rtl" aria-labelledby="stockThresholdTitle" data-daily-url="{{ route('stock-thresholds.daily') }}" data-summary-url="{{ route('stock-thresholds.summary') }}" data-user-id="{{ auth()->id() }}">
    <h2 id="stockThresholdTitle">هشدار آستانه موجودی</h2><p id="stockThresholdMessage"></p>
    <div class="threshold-alert-actions"><a class="threshold-button" href="{{ route('stock-thresholds.index') }}">مشاهده لیست تأمین</a><button type="button" id="stockThresholdClose">متوجه شدم</button></div>
</dialog>
<script src="{{ asset('js/stock-threshold-alert.js') }}" defer></script>
@endif
