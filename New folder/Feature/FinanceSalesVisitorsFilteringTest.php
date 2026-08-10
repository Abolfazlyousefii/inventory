<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinanceSalesVisitorsFilteringTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_uses_seller_date_and_optional_customer_without_status_whitelist(): void
    {
        $seller = User::factory()->create(['name' => 'فروشنده هدف', 'is_seller' => true]);
        $otherSeller = User::factory()->create(['is_seller' => true]);
        $customer = Customer::query()->create([
            'first_name' => 'مشتری', 'last_name' => 'هدف', 'mobile' => '09121111111',
        ]);

        $this->invoice($seller->id, '2026-07-01 00:00:00', Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION, 1000, $customer->id);
        $this->invoice($seller->id, '2026-08-01 23:59:59', Invoice::STATUS_SHIPPED, 2000, null);
        $this->invoice($seller->id, '2026-07-15 12:00:00', Invoice::STATUS_NOT_SHIPPED, 9000, $customer->id);
        $this->invoice($otherSeller->id, '2026-07-15 12:00:00', Invoice::STATUS_SHIPPED, 4000, $customer->id);
        $this->invoice(null, '2026-07-15 12:00:00', Invoice::STATUS_SHIPPED, 8000, $customer->id);
        $this->invoice($seller->id, '2026-06-30 23:59:59', Invoice::STATUS_SHIPPED, 7000, $customer->id);

        $response = $this->actingAs($this->owner())->get(route('finance.reports.sales-visitors', [
            'user_id' => $seller->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-08-01',
            'status' => Invoice::STATUS_NOT_SHIPPED,
        ]));

        $response->assertOk()->assertDontSee('name="status"', false);
        $row = collect($response->viewData('rows'))->sole();
        $this->assertSame(2, $row['invoice_count']);
        $this->assertSame(3000, $row['total_sales']);

        $customerResponse = $this->actingAs($this->owner())->get(route('finance.reports.sales-visitors', [
            'user_id' => $seller->id,
            'customer_id' => $customer->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-08-01',
        ]));

        $customerRow = collect($customerResponse->viewData('rows'))->sole();
        $this->assertSame(1, $customerRow['invoice_count']);
        $this->assertSame(1000, $customerRow['total_sales']);
    }

    private function invoice(?int $registeredByUserId, string $documentDate, ?string $status, int $total, ?int $customerId): Invoice
    {
        $preinvoiceId = $registeredByUserId ? PreinvoiceOrder::query()->create([
            'uuid' => fake()->unique()->uuid(),
            'created_by' => $registeredByUserId,
            'seller_id' => $registeredByUserId,
            'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
            'customer_name' => 'مشتری تست',
            'customer_mobile' => fake()->unique()->numerify('09#########'),
            'customer_address' => 'تهران',
            'province_id' => 1,
            'shipping_id' => 0,
        ])->id : null;

        return Invoice::query()->create([
            'uuid' => fake()->unique()->uuid(),
            'preinvoice_order_id' => $preinvoiceId,
            'seller_id' => $registeredByUserId,
            'document_date' => $documentDate,
            'customer_id' => $customerId,
            'customer_name' => 'مشتری تست',
            'subtotal' => $total,
            'total' => $total,
            'status' => $status,
        ]);
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        $owner->assignRole(Role::findOrCreate('Owner', 'web'));

        return $owner;
    }
}
