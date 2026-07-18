<?php

use Illuminate\Support\Facades\File;

it('keeps finance persistence atomic and logs query exception details', function () {
    $controller = File::get(app_path('Http/Controllers/PreinvoiceController.php'));
    $service = File::get(app_path('Services/FinancePreinvoiceEditorService.php'));
    $migration = File::get(database_path('migrations/2026_07_18_000001_add_line_total_to_preinvoice_order_items.php'));

    expect($service)->toContain('DB::transaction')
        ->and($service)->toContain("'quantity' =>")
        ->and($service)->toContain("'price' =>")
        ->and($service)->toContain("'line_discount_amount' =>")
        ->and($service)->toContain("'invoice_discount_amount'")
        ->and($service)->toContain("reviews()->create")
        ->and($service)->toContain("ActivityLogger::log('finance_edited'")
        ->and($service)->toContain("'status' => $".'oldStatus')
        ->and($controller)->toContain('financeQueryExceptionContext')
        ->and($controller)->toContain("'query' =>")
        ->and($controller)->toContain("'driver_error_code' =>")
        ->and($migration)->toContain("'line_total'");
});
