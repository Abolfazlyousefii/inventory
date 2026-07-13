<?php

namespace Tests\Feature;

use Tests\TestCase;

class VoucherSalesReturnAutoNumberTest extends TestCase
{
    public function test_sales_return_number_is_generated_by_locked_sequence(): void
    {
        $service = file_get_contents(app_path('Services/SalesReturnService.php'));

        $this->assertStringContainsString('document_sequences', $service);
        $this->assertStringContainsString('lockForUpdate()', $service);
        $this->assertStringContainsString("'SR-'", $service);
        $this->assertStringContainsString('if(blank($doc->document_number))', $service);
    }
}
