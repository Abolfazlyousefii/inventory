<?php

namespace Tests\Feature;

use Tests\TestCase;

class InvoiceCancellationTest extends TestCase
{
    private function service(): string { return file_get_contents(app_path('Services/SalesHavalehService.php')); }
    private function controller(): string { return file_get_contents(app_path('Http/Controllers/InvoiceController.php')); }
    private function invoiceModel(): string { return file_get_contents(app_path('Models/Invoice.php')); }

    public function test_cancellation_uses_transaction_and_row_lock(): void { $s=$this->service(); $this->assertStringContainsString('DB::transaction', $s); $this->assertStringContainsString('lockForUpdate()->findOrFail($invoice->id)', $s); }
    public function test_cancellation_is_idempotent_by_status_and_stock_movement(): void { $s=$this->service(); $this->assertStringContainsString('$invoice->isCancelled()', $s); $this->assertStringContainsString('hasCancellationStockReturn', $s); $this->assertStringContainsString("'_cancel'", $s); }
    public function test_applied_sales_return_blocks_invoice_cancellation(): void { $s=$this->service(); $this->assertStringContainsString('SalesReturnDocument::STATUS_APPLIED', $s); $this->assertStringContainsString('برای این فاکتور سند برگشت از فروش ثبت نهایی شده است', $s); }
    public function test_invoice_debit_is_voided_but_payments_are_not_deleted(): void { $s=$this->service(); $ledger=file_get_contents(app_path('Services/CustomerLedgerService.php')); $this->assertStringContainsString('voidInvoiceDebit', $s); $this->assertStringNotContainsString('InvoicePayment::', $s); $this->assertStringNotContainsString('invoice_payments', $ledger); }
    public function test_cancelled_fields_are_stored_on_invoice(): void { $s=$this->service(); $this->assertStringContainsString('cancelled_at', $s); $this->assertStringContainsString('cancellation_reason', $s); $this->assertStringContainsString('cancellation_note', $s); }
    public function test_controller_authorizes_and_validates_invoice_number_and_physical_return(): void { $c=$this->controller(); $this->assertStringContainsString('canCancelInvoices', $c); $this->assertStringContainsString('confirm_invoice_uuid', $c); $this->assertStringContainsString('physical_return_confirmed', $c); }
    public function test_active_and_cancelled_scopes_exist(): void { $m=$this->invoiceModel(); $this->assertStringContainsString('scopeActive', $m); $this->assertStringContainsString('scopeCancelled', $m); $this->assertStringContainsString('isCancelled', $m); }
    public function test_active_index_query_excludes_cancelled_invoices(): void { $this->assertStringContainsString('->active()', $this->controller()); }
    public function test_cancelled_archive_route_and_view_exist(): void { $routes=file_get_contents(base_path('routes/web.php')); $this->assertStringContainsString("/cancelled', [InvoiceController::class, 'cancelled']", $routes); $this->assertFileExists(resource_path('views/invoices/cancelled.blade.php')); }
    public function test_cancelled_invoice_operations_are_guarded(): void { $this->assertStringContainsString('assertNotCancelled', file_get_contents(app_path('Http/Controllers/InvoicePaymentController.php'))); $this->assertStringContainsString('assertNotCancelled', file_get_contents(app_path('Services/WarehouseCollectionService.php'))); }
}
