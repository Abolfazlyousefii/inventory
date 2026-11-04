<?php

it('uses the in-app modal for explicit sale-price approval and does not use browser prompts', function (): void {
    $view = file_get_contents(resource_path('views/purchases/create.blade.php'));

    expect($view)
        ->toContain('id="salePriceChangeModal"')
        ->toContain('id="salePriceChangeReasonInput"')
        ->toContain('aria-required="true"')
        ->toContain("salePriceChangeConfirmedInput.value = '1';")
        ->toContain('salePriceChangeReasonHidden.value = reason.slice(0, 500);')
        ->toContain('approvedSalePriceSignature')
        ->not->toContain("prompt('دلیل تغییر قیمت فروش برای ثبت در تاریخچه:')")
        ->not->toContain("confirm('تغییر قیمت فروش جاری");
});

it('restores the purchase price baseline from the persisted item after a validation redirect', function (): void {
    $view = file_get_contents(resource_path('views/purchases/create.blade.php'));

    expect($view)
        ->toContain("$item['original_sell_price'] = (int) $storedItem->sell_price;")
        ->toContain("row.dataset.originalPurchaseSellPrice = String(parseNumericInput(item.original_sell_price));")
        ->toContain('const baselinePrice = hasPersistedBaseline ? Number(original) : livePrice;');
});
