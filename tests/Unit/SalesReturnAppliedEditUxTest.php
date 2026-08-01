<?php

use App\Http\Requests\StoreSalesReturnRequest;
use App\Http\Requests\UpdateAppliedSalesReturnRequest;

it('requires a Persian adjustment reason only for applied edits', function () {
    $draftRules = (new StoreSalesReturnRequest)->rules();
    $appliedRequest = new UpdateAppliedSalesReturnRequest;

    expect($draftRules)->not->toHaveKey('adjustment_reason')
        ->and($appliedRequest->rules()['adjustment_reason'])->toContain('required', 'min:3', 'max:1000')
        ->and($appliedRequest->messages()['adjustment_reason.required'])->toContain('دلیل اصلاح سند');
});

it('keeps stable temporary ids and exposes editing for draft new products', function () {
    $view = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

    expect($view)->toContain(
        'editingProductUuid',
        'function npEditGroup(uuid)',
        "np.editingProductUuid||(crypto.randomUUID",
        'temporary_variant_uuid:oldVariants.get',
        'ویرایش تعریف کالا',
        'if(isAppliedEdit)return'
    );
});

it('submits compact json asynchronously and renders validation errors in place', function () {
    $view = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));
    $controller = file_get_contents(app_path('Http/Controllers/VoucherSalesReturnController.php'));

    expect($view)->toContain(
        "'Content-Type':'application/json'",
        "response.status===422",
        'showServerErrors(result.errors)',
        'syncCompactItemsPayload(true)',
        'window.location.assign(result.redirect_url)'
    )->and($controller)->toContain(
        '$request->expectsJson()',
        "'redirect_url'=>\$url"
    );
});
