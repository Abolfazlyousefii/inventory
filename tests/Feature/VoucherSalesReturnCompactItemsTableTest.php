<?php

namespace Tests\Feature;

use Tests\TestCase;

class VoucherSalesReturnCompactItemsTableTest extends TestCase
{
    public function test_return_items_table_has_no_per_item_destination_column_or_handler(): void
    {
        $blade = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

        $this->assertStringContainsString('name="default_destination_warehouse_id"', $blade);
        $this->assertStringNotContainsString('<th>انبار مقصد</th>', $blade);
        $this->assertStringNotContainsString('classList.contains(\'wh\')', $blade);
        $this->assertStringNotContainsString('[destination_warehouse_id]', $blade);
        $this->assertStringContainsString('انبار مقصد<br><strong data-sum="warehouses"', $blade);
    }
}
