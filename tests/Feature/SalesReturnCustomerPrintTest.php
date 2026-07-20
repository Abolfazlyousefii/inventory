<?php

namespace Tests\Feature;

use Tests\TestCase;

class SalesReturnCustomerPrintTest extends TestCase
{
    public function test_customer_print_keeps_existing_document_rows(): void { $this->assertStringContainsString('شماره سند یا حواله', file_get_contents(resource_path('views/vouchers/return-from-sale/print-customers.blade.php'))); }
    public function test_customer_print_contains_applied_new_documents(): void { $this->assertStringContainsString('STATUS_APPLIED', file_get_contents(app_path('Services/SalesReturnReportService.php'))); }
    public function test_customer_print_contains_valid_legacy_returns(): void { $this->assertStringContainsString('TYPE_CUSTOMER_RETURN', file_get_contents(app_path('Services/SalesReturnReportService.php'))); }
    public function test_customer_print_excludes_drafts(): void { $this->assertStringContainsString("'official_only'", file_get_contents(app_path('Services/SalesReturnReportService.php'))); }
    public function test_customer_print_excludes_cancelled_documents(): void { $this->assertStringContainsString("where('d.status', SalesReturnDocument::STATUS_APPLIED)", file_get_contents(app_path('Services/SalesReturnReportService.php'))); }
    public function test_customer_print_respects_customer_filter(): void { $this->assertStringContainsString("where('d.customer_id'", file_get_contents(app_path('Services/SalesReturnReportService.php'))); }
    public function test_customer_print_respects_jalali_date_range(): void { $s=file_get_contents(app_path('Services/SalesReturnReportService.php')); $this->assertStringContainsString('startOfDay', $s); $this->assertStringContainsString('endOfDay', $s); }
    public function test_customer_print_respects_document_number(): void { $this->assertStringContainsString('document_number', file_get_contents(app_path('Services/SalesReturnReportService.php'))); }
    public function test_customer_print_has_print_button(): void { $this->assertStringContainsString('window.print()', file_get_contents(resource_path('views/vouchers/return-from-sale/partials/print-header.blade.php'))); }
    public function test_old_print_route_remains_compatible(): void { $this->assertStringContainsString("name('print-report')", file_get_contents(base_path('routes/web.php'))); }
    public function test_old_pdf_report_route_redirects_with_filters(): void { $this->assertStringContainsString("exportPdf(SalesReturnIndexRequest \$request){ return redirect()->route('vouchers.return-from-sale.print.customers', \$request->filters()); }", file_get_contents(app_path('Http/Controllers/VoucherSalesReturnController.php'))); }
}
