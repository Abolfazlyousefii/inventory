<?php

use Illuminate\Support\Facades\File;

it('previews percent product discounts with backend allocation contract', function () {
    $view = File::get(resource_path('views/preinvoice/finance-edit.blade.php'));
    $script = File::get(public_path('js/finance-review.js'));

    expect($script)->toContain('function recalculate()')
        ->and($script)->toContain('math.amount(groupGross,')
        ->and($script)->toContain('math.allocate(weights, amount)')
        ->and($script)->toContain('shares[index]')
        ->and($script)->toContain('math.amount(gross-discount, invoiceType, invoiceValue.value)')
        ->and($view)->toContain('js/finance-review.js')
        ->and($view)->toContain('data-product-discount-group')
        ->and($view)->toContain('data-product-discount-type')
        ->and($view)->toContain('data-product-discount-value')
        ->and($view)->toContain('data-summary-product-discount')
        ->and($view)->toContain('data-summary-invoice-discount')
        ->and($view)->toContain('data-summary-total-discount')
        ->and($view)->toContain('data-summary-grand-total');
});

it('persists percent product discounts and reloads discount breakdown fields', function () {
    $service = File::get(app_path('Services/FinancePreinvoiceEditorService.php'));
    $allocator = File::get(app_path('Services/ProductDiscountAllocator.php'));
    $hydrator = File::get(app_path('Services/PreinvoiceDiscountHydrator.php'));

    expect($service)->toContain('productDiscountAllocator->allocate')
        ->and($service)->toContain("'type' => (string) ($".'group' . "['discount_type'] ?? 'amount')")
        ->and($service)->toContain("'value' => $".'group' . "['discount_value'] ?? 0")
        ->and($service)->toContain("'discount_amount'")
        ->and($allocator)->toContain("'raw_subtotal' => $".'gross')
        ->and($allocator)->toContain("'final_amount' => max($".'gross' . " - $".'actual' . ", 0)")
        ->and($service)->toContain("'product_discount_amount'")
        ->and($service)->toContain("'line_discount_amount' => $".'lineDiscount')
        ->and($service)->toContain("'line_total' => max(((int) $".'item' . '->quantity * (int) $' . 'item' . '->price) - $' . 'lineDiscount' . ', 0)')
        ->and($hydrator)->toContain("'discount_type' => in_array($".'type')
        ->and($hydrator)->toContain("'discount_value' => $".'value')
        ->and($hydrator)->toContain("'discount_amount' => $".'amount');
});

it('validates product discount percent maximum without limiting amount discounts to one hundred', function () {
    $request = File::get(app_path('Http/Requests/FinanceUpdatePreinvoiceRequest.php'));

    expect($request)->toContain("($".'row' . "['type'] ?? null) === 'percent'")
        ->and($request)->toContain("درصد تخفیف محصول نمی‌تواند بیشتر از ۱۰۰ باشد.")
        ->and($request)->not->toContain("product_discounts.*.value' => ['required_with:product_discounts', 'numeric', 'min:0', 'max:100']");
});
