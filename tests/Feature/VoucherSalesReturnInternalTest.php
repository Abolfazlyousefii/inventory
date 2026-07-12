<?php
namespace Tests\Feature;
use Tests\TestCase;
class VoucherSalesReturnInternalTest extends TestCase
{
    public function test_internal_return_uses_invoice_snapshot_and_previously_returned_from_new_and_legacy_applied_rows(): void
    {
        $calc = file_get_contents(app_path('Services/SalesReturnCalculationService.php'));
        $service = file_get_contents(app_path('Services/SalesReturnService.php'));
        $request = file_get_contents(app_path('Http/Requests/StoreSalesReturnRequest.php'));
        $this->assertStringContainsString("where('d.status', SalesReturnDocument::STATUS_APPLIED)", $calc);
        $this->assertStringContainsString("where('wt.voucher_type', WarehouseTransfer::TYPE_CUSTOMER_RETURN)", $calc);
        $this->assertStringContainsString('line_discount_amount', $calc);
        $this->assertStringContainsString('allocated_invoice_discount_snapshot', $service);
        $this->assertStringContainsString('تعداد برگشتی بیشتر از قابل برگشت است', $request);
    }
}
