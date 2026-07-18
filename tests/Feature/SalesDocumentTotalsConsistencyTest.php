<?php

use Illuminate\Support\Facades\File;

it('uses SalesDocumentTotals for order totals finalize and reapproval sync', function () {
    $controller = File::get(app_path('Http/Controllers/PreinvoiceController.php'));

    expect($controller)->toContain('private function calculateOrderTotal')
        ->and($controller)->toContain('SalesDocumentTotals::calculate($order->items')
        ->and($controller)->not->toContain('max((int) $order->discount_amount, $itemDiscount)');
});
