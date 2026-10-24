<?php

namespace Tests\Unit;

use App\Http\Controllers\InvoicePaymentController;
use App\Models\Customer;
use App\Support\PageAccessCatalog;
use Illuminate\Http\Request;
use Tests\TestCase;

class AccountPaymentManagementAccessTest extends TestCase
{
    public function test_all_payment_management_routes_belong_to_account_statements(): void
    {
        foreach (['edit', 'update', 'destroy'] as $action) {
            $this->assertSame(['page.finance.accounts'], PageAccessCatalog::permissionsForRoute('account-statements.payments.'.$action));
        }
    }

    public function test_account_statement_origin_returns_to_the_same_customer(): void
    {
        $this->app->instance('request', Request::create('/', 'POST', ['return_to' => 'account-statement']));
        $customer = new Customer;
        $customer->id = 42;
        $controller = app(InvoicePaymentController::class);
        $method = new \ReflectionMethod($controller, 'paymentReturn');
        $response = $method->invoke($controller, $customer, '01949');
        $this->assertSame(route('account-statements.show', 42), $response->getTargetUrl());
    }
}
