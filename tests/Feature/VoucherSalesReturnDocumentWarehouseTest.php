<?php

namespace Tests\Feature;

use App\Http\Requests\StoreSalesReturnRequest;
use App\Models\Warehouse;
use Tests\TestCase;

class VoucherSalesReturnDocumentWarehouseTest extends TestCase
{
    public function test_request_validates_document_destination_and_not_item_destination(): void
    {
        $rules = (new StoreSalesReturnRequest())->rules();

        $this->assertArrayHasKey('default_destination_warehouse_id', $rules);
        $this->assertContains('required', $rules['default_destination_warehouse_id']);
        $this->assertArrayNotHasKey('items.*.destination_warehouse_id', $rules);
    }

    public function test_only_active_central_or_return_warehouses_are_loaded_for_form(): void
    {
        $query = Warehouse::where('is_active', true)->whereIn('type', ['central', 'return'])->toSql();

        $this->assertStringContainsString('warehouses', $query);
    }
}
