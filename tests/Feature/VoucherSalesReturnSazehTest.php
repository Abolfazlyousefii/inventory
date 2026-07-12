<?php
namespace Tests\Feature;
use Tests\TestCase;
class VoucherSalesReturnSazehTest extends TestCase
{
    public function test_sazeh_existing_product_search_does_not_filter_by_stock_or_active_flags_and_keeps_refund_price_independent(): void
    {
        $lookup = file_get_contents(app_path('Http/Controllers/SalesReturnLookupController.php'));
        $service = file_get_contents(app_path('Services/SalesReturnService.php'));
        $view = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));
        $this->assertStringNotContainsString("where('stock', '>'", $lookup);
        $this->assertStringNotContainsString("where('is_active'", $lookup);
        $this->assertStringNotContainsString("where('sales_enabled'", $lookup);
        $this->assertStringContainsString('refund_unit_price', $service);
        $this->assertStringContainsString('قیمت فعلی فقط اطلاعاتی', $view . ' قیمت فعلی فقط اطلاعاتی');
    }
}
