<?php

namespace Tests\Feature;

use Tests\TestCase;

class VoucherSalesReturnJalaliDateTest extends TestCase
{
    public function test_sales_return_ui_keeps_jalali_display_separate_from_backend_date_value(): void
    {
        $blade = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

        $this->assertStringContainsString('formatJalaliDate', $blade);
        $this->assertStringContainsString('normalizeJalaliDateInput', $blade);
        $this->assertStringContainsString('name="external_invoice_date"', $blade);
        $this->assertStringContainsString('id="externalInvoiceDateFa"', $blade);
    }
}
