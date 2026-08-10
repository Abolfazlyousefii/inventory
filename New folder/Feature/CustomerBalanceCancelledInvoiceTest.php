<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\RoutePermissionMiddleware;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Auth\Middleware\Authenticate;
use Tests\TestCase;

class CustomerBalanceCancelledInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelled_status_helper_only_returns_not_shipped(): void
    {
        $this->assertSame([Invoice::STATUS_NOT_SHIPPED], Invoice::cancelledStatuses());
    }

    public function test_customer_balance_ignores_not_shipped_and_counts_pending_collection(): void
    {
        $customer = Customer::query()->create([
            'first_name' => 'علی',
            'last_name' => 'رضایی',
            'mobile' => '09120000000',
            'opening_balance' => 0,
        ]);

        $cancelled = Invoice::query()->create([
            'uuid' => 'INV-CANCELLED-BALANCE',
            'customer_id' => $customer->id,
            'customer_name' => $customer->display_name,
            'total' => 80_000_000,
            'status' => Invoice::STATUS_NOT_SHIPPED,
        ]);

        $active = Invoice::query()->create([
            'uuid' => 'INV-PENDING-BALANCE',
            'customer_id' => $customer->id,
            'customer_name' => $customer->display_name,
            'total' => 25_000_000,
            'status' => Invoice::STATUS_PENDING_COLLECTION,
        ]);

        CustomerLedger::query()->create([
            'customer_id' => $customer->id,
            'type' => 'debit',
            'amount' => $cancelled->total,
            'reference_type' => Invoice::class,
            'reference_id' => $cancelled->id,
        ]);

        CustomerLedger::query()->create([
            'customer_id' => $customer->id,
            'type' => 'debit',
            'amount' => $active->total,
            'reference_type' => Invoice::class,
            'reference_id' => $active->id,
        ]);

        $balanced = Customer::query()->withBalance()->findOrFail($customer->id);

        $this->assertSame(25_000_000, $balanced->balance);
        $this->assertSame(25_000_000, $balanced->debt);
        $this->assertSame(0, $balanced->credit);
    }

    public function test_customers_page_and_preinvoice_customer_search_load_without_balance_exception(): void
    {
        Customer::query()->create([
            'first_name' => 'سارا',
            'last_name' => 'کریمی',
            'mobile' => '09121111111',
            'opening_balance' => 0,
        ]);

        $this->withoutMiddleware([
            Authenticate::class,
            RoutePermissionMiddleware::class,
            CheckPermission::class,
        ]);

        $this->get('/customers')->assertOk();
        $this->get('/preinvoice/api/customers?q=سارا')
            ->assertOk()
            ->assertJsonPath('data.customers.0.mobile', '09121111111');
    }
}
