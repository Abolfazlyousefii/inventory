@if(\Illuminate\Support\Facades\Route::has('stock-thresholds.index') && \App\Support\PageAccessCatalog::userCanRoute(auth()->user(), 'stock-thresholds.index'))
<a class="threshold-dashboard-widget" href="{{ route('stock-thresholds.index') }}"><span>آستانه موجودی · کالاهای نیازمند بررسی تأمین</span><strong data-stock-threshold-count>—</strong></a>
@endif
