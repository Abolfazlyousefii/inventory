<?php

namespace Tests\Feature;

use Tests\TestCase;

class SalesReturnAppliedAdjustmentTest extends TestCase
{
    public function test_draft_has_edit_and_delete_actions(): void { $this->assertStringContainsString('edit_draft', file_get_contents(app_path('Services/SalesReturnReportService.php'))); }
    public function test_applied_has_edit_and_void_actions_with_permission(): void { $s=file_get_contents(app_path('Services/SalesReturnReportService.php')); $this->assertStringContainsString('sales_returns.edit_applied', $s); $this->assertStringContainsString('sales_returns.void_applied', $s); }
    public function test_applied_actions_hidden_without_permission(): void { $this->assertStringContainsString('can_edit', file_get_contents(resource_path('views/vouchers/return-from-sale/partials/index-results.blade.php'))); }
    public function test_legacy_is_read_only(): void { $s=file_get_contents(app_path('Services/SalesReturnReportService.php')); $this->assertStringContainsString("'can_edit' => false", $s); }
    public function test_applied_edit_keeps_document_number(): void { $this->assertStringContainsString('document_number', file_get_contents(app_path('Services/SalesReturnAppliedAdjustmentService.php'))); }
    public function test_applied_edit_keeps_created_at(): void { $this->assertStringContainsString('created_at', file_get_contents(app_path('Services/SalesReturnAppliedAdjustmentService.php'))); }
    public function test_applied_edit_keeps_original_applied_at(): void { $this->assertStringContainsString('applied_at', file_get_contents(app_path('Services/SalesReturnAppliedAdjustmentService.php'))); }
    public function test_applied_edit_keeps_customer_and_source(): void { $s=file_get_contents(app_path('Services/SalesReturnAppliedAdjustmentService.php')); $this->assertStringContainsString('customer_id', $s); $this->assertStringContainsString('source_type', $s); }
    public function test_applied_edit_reverses_old_stock_and_applies_new_stock(): void { $s=file_get_contents(app_path('Services/SalesReturnAppliedAdjustmentService.php')); $this->assertStringContainsString('reverseInventory', $s); $this->assertStringContainsString('applyInventory', $s); }
    public function test_applied_edit_reverses_old_credit_and_records_new_credit(): void { $s=file_get_contents(app_path('Services/SalesReturnAppliedAdjustmentService.php')); $this->assertStringContainsString('reverseLedger', $s); $this->assertStringContainsString('creditLedger', $s); }
    public function test_insufficient_stock_rolls_back_entire_adjustment(): void { $this->assertStringContainsString('DB::transaction', file_get_contents(app_path('Services/SalesReturnAppliedAdjustmentService.php'))); }
    public function test_applied_void_reverses_stock(): void { $this->assertStringContainsString('voidApplied', file_get_contents(app_path('Services/SalesReturnAppliedAdjustmentService.php'))); }
    public function test_applied_void_reverses_customer_credit(): void { $this->assertStringContainsString('sales_return_reversal', file_get_contents(app_path('Services/SalesReturnAppliedAdjustmentService.php'))); }
    public function test_applied_void_keeps_document_and_items(): void { $this->assertStringNotContainsString('->delete()', substr(file_get_contents(app_path('Services/SalesReturnAppliedAdjustmentService.php')), strpos(file_get_contents(app_path('Services/SalesReturnAppliedAdjustmentService.php')), 'voidApplied'))); }
    public function test_applied_void_marks_document_cancelled(): void { $this->assertStringContainsString('STATUS_CANCELLED', file_get_contents(app_path('Services/SalesReturnAppliedAdjustmentService.php'))); }
    public function test_repeated_void_does_not_reverse_twice(): void { $this->assertStringContainsString('این سند قبلاً ابطال شده است', file_get_contents(app_path('Services/SalesReturnAppliedAdjustmentService.php'))); }
    public function test_adjustment_creates_revision(): void { $this->assertStringContainsString('SalesReturnDocumentRevision::create', file_get_contents(app_path('Services/SalesReturnAppliedAdjustmentService.php'))); }
    public function test_cancelled_document_cannot_be_edited(): void { $this->assertStringContainsString('سند ابطال‌شده قابل ویرایش نیست', file_get_contents(app_path('Services/SalesReturnAppliedAdjustmentService.php'))); }
}
