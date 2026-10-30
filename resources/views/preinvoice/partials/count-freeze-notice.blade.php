@php
    $countFreeze = app(\App\Services\InventoryCountFreezeService::class);
    $countFreezeLabel = $countFreeze->scopeLabel();
    $initialCountWarning = $countFreezeLabel !== ''
        ? $countFreeze->salesWarning(collect(($order ?? null)?->items ?? [])->pluck('product_id')->all()) : null;
    $frozenOrderItems = collect($initialCountWarning['blocked_product_names'] ?? []);
@endphp
@if($countFreezeLabel !== '')
    <div class="alert alert-warning" role="status" data-count-freeze-notice>
        <strong>دسته‌های بسته برای شمارش: {{ $countFreezeLabel }}</strong>
        <div>ثبت پیش‌فاکتور و تأیید مالی برای سایر دسته‌ها عادی است. فقط انتخاب و فروش کالاهای این محدوده تا بازگشایی صریح مسئول انبار بسته است؛ نهایی‌شدن شمارش، فروش این دسته را خودکار باز نمی‌کند.</div>
        @if($frozenOrderItems->isNotEmpty())
            <div class="mt-1" data-count-freeze-order-items>اقلام بسته‌شدهٔ این سند: {{ $frozenOrderItems->implode('، ') }}. همهٔ اقلام به‌صورت پیش‌نویس حفظ می‌شوند. برای ارسال بقیهٔ کالاها به مالی، این ردیف‌ها را خودتان از سند حذف کنید؛ یا همهٔ اقلام را تا بازگشایی دسته به‌صورت پیش‌نویس نگه دارید. هیچ ردیفی خودکار حذف نمی‌شود.</div>
        @endif
    </div>
@endif
