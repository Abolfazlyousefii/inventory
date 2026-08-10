<?php

use Illuminate\Support\Facades\File;

it('finance editor service persists item and invoice discount fields without creating invoices', function () {
    $service = File::get(app_path('Services/FinancePreinvoiceEditorService.php'));

    expect($service)->toContain("'quantity' =>")
        ->and($service)->toContain("'price' =>")
        ->and($service)->toContain("'line_discount_amount' =>")
        ->and($service)->toContain("'invoice_discount_type'")
        ->and($service)->toContain("'invoice_discount_value'")
        ->and($service)->toContain("'invoice_discount_amount'")
        ->and($service)->toContain("'product_discount_amount'")
        ->and($service)->toContain("'status' =>")
        ->and($service)->not->toContain('Invoice::create')
        ->and($service)->not->toContain('InvoiceItem::create')
        ->and($service)->not->toContain('consumeOfficialReservationsForOrder');
});
