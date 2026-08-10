<?php

use Illuminate\Support\Facades\File;

it('persists the full discount contract from create through finance', function () {
    $controller = File::get(app_path('Http/Controllers/PreinvoiceController.php'));
    $discountService = File::get(app_path('Services/PreinvoiceDiscountService.php'));
    $financeService = File::get(app_path('Services/FinancePreinvoiceEditorService.php'));
    $create = File::get(resource_path('views/preinvoice/create.blade.php'));

    expect($create)->toContain('name="discount_breakdown"')
        ->and($controller)->toContain("'discount_breakdown' => 'nullable|string'")
        ->and($controller)->toContain('preinvoiceDiscountService->applyToOrder')
        ->and($discountService)->toContain("'product_discount_amount'")
        ->and($discountService)->toContain("'invoice_discount_type'")
        ->and($discountService)->toContain("'invoice_discount_value'")
        ->and($discountService)->toContain("'invoice_discount_amount'")
        ->and($discountService)->toContain("'discount_breakdown'")
        ->and($discountService)->toContain("'discount_allocation_mode'")
        ->and($discountService)->toContain("'total_price'")
        ->and($financeService)->toContain("'status' => $".'oldStatus')
        ->and($financeService)->toContain('SalesDocumentTotals::calculate($lockedOrder->items, $invoiceDiscountAmount');
});
