<?php

namespace Tests\Feature;

use Tests\TestCase;

class VoucherSalesReturnSazehDateTest extends TestCase
{
    public function test_sazeh_date_has_visible_jalali_input_hidden_gregorian_value_and_idempotent_datepicker(): void
    {
        $blade = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

        $this->assertStringContainsString('name="external_invoice_date_fa"', $blade);
        $this->assertStringContainsString('name="external_invoice_date"', $blade);
        $this->assertStringContainsString('function initSazehInvoiceDatePicker()', $blade);
        $this->assertStringContainsString("dataset.datepickerInitialized==='1'", $blade);
        $this->assertStringContainsString('syncExternalDateFromVisible(true)', $blade);
    }
}
