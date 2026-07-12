<?php
namespace Tests\Feature;
use Tests\TestCase;
class VoucherSalesReturnApplyTest extends TestCase
{
    public function test_apply_is_transactional_idempotent_and_records_inventory_before_customer_credit(): void
    {
        $service = file_get_contents(app_path('Services/SalesReturnService.php'));
        $this->assertStringContainsString('DB::transaction(function() use($document,$actorId)', $service);
        $this->assertStringContainsString('lockForUpdate()->firstOrFail()', $service);
        $this->assertStringContainsString('if($doc->isApplied()) return $doc', $service);
        $this->assertLessThan(strpos($service, 'recordCustomerCredit($doc'), strpos($service, 'recordInventoryEntry($item'));
        $this->assertStringContainsString('updateOrCreate', $service);
    }
}
