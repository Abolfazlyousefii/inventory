<?php
namespace Tests\Feature;
use Tests\TestCase;
class VoucherSalesReturnLegacySafetyTest extends TestCase
{
    public function test_legacy_customer_returns_are_read_only_and_only_used_for_previous_return_quantity(): void
    {
        $calc = file_get_contents(app_path('Services/SalesReturnCalculationService.php'));
        $service = file_get_contents(app_path('Services/SalesReturnService.php'));
        $this->assertStringContainsString('warehouse_transfer_items as wi', $calc);
        $this->assertStringContainsString('customer_return', file_get_contents(app_path('Models/WarehouseTransfer.php')));
        $this->assertStringNotContainsString('WarehouseTransfer::create', $service);
    }
}
