<?php

namespace Tests\Feature;

use Tests\TestCase;

class VoucherSalesReturnCustomerInvoicesTest extends TestCase
{
    public function test_create_form_has_central_customer_selection_and_abortable_invoice_lookup(): void
    {
        $blade = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/SalesReturnLookupController.php'));

        $this->assertStringContainsString('function setSelectedCustomer(customer)', $blade);
        $this->assertStringContainsString('async function loadCustomerInvoices(customerId)', $blade);
        $this->assertStringContainsString('invoiceAbort.abort()', $blade);
        $this->assertStringContainsString('routes.customerInvoices.replace', $blade);
        $this->assertStringContainsString("'date_fa'", $controller);
        $this->assertStringContainsString("'amount_fa'", $controller);
    }
}
