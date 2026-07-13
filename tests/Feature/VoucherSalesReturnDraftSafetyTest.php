<?php

namespace Tests\Feature;

use Tests\TestCase;

class VoucherSalesReturnDraftSafetyTest extends TestCase
{
    public function test_draft_persistence_uses_sales_return_tables_only_and_document_destination(): void
    {
        $service = file_get_contents(app_path('Services/SalesReturnService.php'));

        $this->assertStringContainsString('default_destination_warehouse_id', $service);
        $this->assertStringContainsString('documentDestinationWarehouse($data)', $service);
        $this->assertStringContainsString('$doc->items()->delete()', $service);
        $this->assertStringNotContainsString('WarehouseStockService::change($destinationWarehouseId', substr($service, 0, strpos($service, 'public function apply')));
    }
}
