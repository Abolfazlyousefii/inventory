<?php

namespace Tests\Feature;

use Tests\TestCase;

class VoucherSalesReturnCustomerLookupTest extends TestCase
{
    public function test_customer_lookup_is_portalled_and_keyboard_accessible(): void
    {
        $view = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/SalesReturnLookupController.php'));

        $this->assertStringContainsString("route('vouchers.return-from-sale.customers.search')", $view);
        $this->assertStringContainsString('document.body.appendChild(box)', $view);
        $this->assertStringContainsString("e.key==='ArrowDown'", $view);
        $this->assertStringContainsString("e.key==='Escape'", $view);
        $this->assertStringContainsString("orWhere('mobile'", $controller);
        $this->assertStringContainsString("orWhere('crm_customer_id'", $controller);
    }
}
