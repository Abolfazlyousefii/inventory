<?php

use Illuminate\Support\Facades\File;

it('uses SalesDocumentTotals for order totals finalize and reapproval sync', function () {
    $controller = File::get(app_path('Http/Controllers/PreinvoiceController.php'));

    expect($controller)->toContain('private function calculateOrderTotal')
        ->and($controller)->toContain('SalesDocumentTotals::calculate($order->items')
        ->and($controller)->not->toContain('max((int) $order->discount_amount, $itemDiscount)');
});

it('preserves proportional line discounts in both warehouse invoice mutation paths', function () {
    $warehouse = File::get(app_path('Services/WarehouseCollectionService.php'));
    $havaleh = File::get(app_path('Services/SalesHavalehService.php'));

    expect(substr_count($warehouse, 'SalesDocumentTotals::proportionalLineDiscount('))->toBeGreaterThanOrEqual(2)
        ->and(substr_count($havaleh, 'SalesDocumentTotals::proportionalLineDiscount('))->toBeGreaterThanOrEqual(2);
});
