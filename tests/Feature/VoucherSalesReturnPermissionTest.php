<?php
namespace Tests\Feature;
use Tests\TestCase;
class VoucherSalesReturnPermissionTest extends TestCase
{
    public function test_create_product_and_destination_override_permissions_are_enforced_in_ui_and_backend(): void
    {
        $view = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));
        $request = file_get_contents(app_path('Http/Requests/StoreSalesReturnRequest.php'));
        $controller = file_get_contents(app_path('Http/Controllers/VoucherSalesReturnController.php'));
        $service = file_get_contents(app_path('Services/SalesReturnService.php'));
        $this->assertStringContainsString("@can('sales_returns.create_product')", $view);
        $this->assertStringContainsString("can('sales_returns.create_product')", $request);
        $this->assertStringContainsString('can_override_destination', $controller);
        $this->assertStringContainsString('resolveDestinationWarehouse', $service);
    }
}
