<?php
namespace Tests\Feature;
use Tests\TestCase;
class VoucherSalesReturnNewProductTest extends TestCase
{
    public function test_new_product_payload_is_saved_in_draft_and_materialized_only_during_apply(): void
    {
        $service = file_get_contents(app_path('Services/SalesReturnService.php'));
        $view = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));
        $this->assertStringContainsString('new_product_payload', $service);
        $this->assertStringContainsString('materializeNewProduct($item)', $service);
        $this->assertStringContainsString('created_product_id', $service);
        $this->assertStringContainsString('created_variant_id', $service);
        $this->assertStringContainsString('پیش‌نمایش کد کالا', $view);
        $this->assertStringContainsString('مدل لیست', $view);
    }
}
