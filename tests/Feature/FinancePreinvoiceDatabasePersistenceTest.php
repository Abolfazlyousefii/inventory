<?php

use Illuminate\Support\Facades\File;

it('keeps finance persistence atomic and logs query exception details', function () {
    $controller = File::get(app_path('Http/Controllers/PreinvoiceController.php'));
    $service = File::get(app_path('Services/FinancePreinvoiceEditorService.php'));
    $reservationScope = File::get(app_path('Support/ReservationSideEffects.php'));
    $itemModel = File::get(app_path('Models/PreinvoiceOrderItem.php'));

    expect($service)->toContain('ReservationSideEffects::transaction')
        ->and($reservationScope)->toContain('DB::transaction')
        ->and($reservationScope)->toContain('ReservationProjectionService::class')
        ->and($service)->toContain("'quantity' =>")
        ->and($service)->toContain("'price' =>")
        ->and($service)->toContain("'line_total' =>")
        ->and($service)->toContain("'line_discount_amount' =>")
        ->and($service)->toContain("'invoice_discount_amount'")
        ->and($service)->toContain("reviews()->create")
        ->and($service)->toContain("ActivityLogger::log('finance_edited'")
        ->and($service)->toContain("'status' => $".'oldStatus')
        ->and($controller)->toContain('financeQueryExceptionContext')
        ->and($controller)->toContain("'query' =>")
        ->and($controller)->toContain("'driver_error_code' =>")
        ->and($itemModel)->toContain("'line_total'");
});
