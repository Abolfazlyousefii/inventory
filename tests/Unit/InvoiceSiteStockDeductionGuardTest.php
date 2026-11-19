<?php

use App\Models\Invoice;
use Illuminate\Validation\ValidationException;

it('rejects an explicitly non-deducted site invoice', function (): void {
    $invoice = new Invoice();
    $invoice->forceFill(['external_order_id' => 89, 'stock_deduction_status' => 'not_deducted']);
    expect(fn () => $invoice->assertStockDeducted())->toThrow(ValidationException::class);
});

it('rejects an unknown site invoice status until reconciled', function (): void {
    $invoice = new Invoice();
    $invoice->forceFill(['external_order_id' => 45, 'stock_deduction_status' => null]);
    expect(fn () => $invoice->assertStockDeducted())->toThrow(ValidationException::class);
});

it('rejects any unrecognized site invoice status', function (): void {
    $invoice = new Invoice();
    $invoice->forceFill(['external_order_id' => 94, 'stock_deduction_status' => 'unknown']);
    expect(fn () => $invoice->assertStockDeducted())->toThrow(ValidationException::class);
});

it('allows site invoices only after a verified deduction is recorded', function (): void {
    $invoice = new Invoice();
    $invoice->forceFill(['external_order_id' => 88, 'stock_deduction_status' => 'deducted']);
    $invoice->assertStockDeducted();
    expect(true)->toBeTrue();
});

it('preserves the existing behavior for non-site legacy invoices with null status', function (): void {
    $invoice = new Invoice();
    $invoice->forceFill(['external_order_id' => null, 'stock_deduction_status' => null]);
    $invoice->assertStockDeducted();
    expect(true)->toBeTrue();
});

it('identifies only an explicit non-deduction as safe to skip a cancellation stock return', function (): void {
    $invoice = new Invoice();
    $invoice->forceFill(['stock_deduction_status' => Invoice::STOCK_NOT_DEDUCTED]);
    expect($invoice->stockWasNotDeducted())->toBeTrue();

    foreach ([Invoice::STOCK_DEDUCTED, null, 'unknown'] as $status) {
        $invoice->forceFill(['stock_deduction_status' => $status]);
        expect($invoice->stockWasNotDeducted())->toBeFalse();
    }
});
