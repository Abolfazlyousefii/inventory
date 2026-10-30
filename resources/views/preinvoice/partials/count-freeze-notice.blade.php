@php
    $countFreeze = app(\App\Services\InventoryCountFreezeService::class);
    $countFreezeLabel = $countFreeze->scopeLabel();
    $frozenOrderItems = collect($countFreezeLabel !== '' ? (($order ?? null)?->items ?? []) : [])
        ->filter(fn ($item) => $countFreeze->blocked((int) $item->product_id))
        ->map(fn ($item) => $item->product?->name ?: ('کالای شماره '.$item->product_id))->unique();
@endphp
@if($countFreezeLabel !== '')
    <div class="alert alert-warning" role="status" data-count-freeze-notice>
        <strong>توقف فروش برای شمارش: {{ $countFreezeLabel }}</strong>
        <div>انتخاب و فروش کالاهای این محدوده تا بازگشایی صریح مسئول انبار بسته است. فروش سایر دسته‌ها ادامه دارد؛ نهایی‌شدن شمارش، فروش این دسته را خودکار باز نمی‌کند.</div>
        @if($frozenOrderItems->isNotEmpty())
            <div class="mt-1">اقلام بسته‌شدهٔ این سند: {{ $frozenOrderItems->implode('، ') }}. سند با همهٔ اقلام به‌صورت پیش‌نویس حفظ می‌شود و تا رفع توقف به مالی ارسال نمی‌شود.</div>
        @endif
    </div>
@endif
