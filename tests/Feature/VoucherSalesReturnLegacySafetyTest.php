<?php

namespace Tests\Feature;

use Tests\TestCase;

class VoucherSalesReturnLegacySafetyTest extends TestCase
{
    public function test_patch_does_not_touch_legacy_transfer_paths(): void
    {
        $service = file_get_contents(app_path('Services/SalesReturnService.php'));
        $this->assertStringNotContainsString('warehouse_transfers', $service);
        $this->assertStringNotContainsString('WarehouseTransfer::', $service);
    }
}
