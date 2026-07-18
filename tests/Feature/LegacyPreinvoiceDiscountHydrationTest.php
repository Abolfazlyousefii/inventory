<?php

use Illuminate\Support\Facades\File;

it('centralizes legacy preinvoice discount hydration for finance edit', function () {
    $service = File::get(app_path('Services/PreinvoiceDiscountHydrator.php'));
    $controller = File::get(app_path('Http/Controllers/PreinvoiceController.php'));
    $view = File::get(resource_path('views/preinvoice/finance-edit.blade.php'));

    expect($service)->toContain('hydrateForEditing')
        ->and($service)->toContain('legacyInvoiceDiscount')
        ->and($service)->toContain('discount_amount')
        ->and($service)->toContain('line_discount_amount')
        ->and($controller)->toContain('PreinvoiceDiscountHydrator')
        ->and($controller)->toContain('discountEditorState')
        ->and($view)->toContain("$".'discountEditorState')
        ->and($view)->not->toContain("$".'breakdownGroups = collect($order->discount_breakdown');
});
