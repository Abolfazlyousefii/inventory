<?php

it('submits purchase form normally after an authorized explicit price confirmation', function (): void {
    $view = file_get_contents(resource_path('views/purchases/create.blade.php'));

    expect($view)->toContain("if (saleChanges.length) {\n                    if (!CAN_CHANGE_SALE_PRICE)")
        ->toContain("document.getElementById('salePriceChangeConfirmed').value = '1';")
        ->toContain("document.getElementById('salePriceChangeReason').value = reason.trim().slice(0, 500);")
        ->toContain("                }\n\n                purchaseForm.dataset.submitting = '1';");

    // The successful branch must NOT call preventDefault: that would cancel
    // the native submit and silently discard the approved price change.
    $successBranch = explode("                }\n\n                purchaseForm.dataset.submitting = '1';",
        explode("document.getElementById('salePriceChangeReason').value = reason.trim().slice(0, 500);", $view)[1]
    )[0];

    expect($successBranch)->not->toContain('event.preventDefault()');
});
