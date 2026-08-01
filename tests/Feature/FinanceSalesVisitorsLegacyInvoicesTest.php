<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinanceSalesVisitorsLegacyInvoicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_invoice_is_attributed_through_preinvoice_creator_without_seller_id(): void
    {
        $registrar = User::factory()->create(['name' => 'ثبت‌کننده قدیمی']);
        $legacyOrder = $this->order($registrar->id);
        $this->invoice($legacyOrder->id, null, Invoice::STATUS_SHIPPED, 3000);

        $response = $this->actingAs($this->owner())->get(route('finance.reports.sales-visitors', [
            'user_id' => $registrar->id,
        ]));

        $row = collect($response->viewData('rows'))->sole();
        $this->assertSame(1, $row['invoice_count']);
        $this->assertSame(3000, $row['total_sales']);
    }

    public function test_direct_unknown_invalid_and_cancelled_invoices_are_not_attributed(): void
    {
        $registrar = User::factory()->create();
        $validOrder = $this->order($registrar->id);
        $invalidOrder = $this->order(999999);

        $this->invoice(null, $registrar->id, Invoice::STATUS_SHIPPED, 1000);
        $this->invoice($invalidOrder->id, $registrar->id, Invoice::STATUS_SHIPPED, 2000);
        $this->invoice($validOrder->id, $registrar->id, Invoice::STATUS_NOT_SHIPPED, 4000);

        $response = $this->actingAs($this->owner())->get(route('finance.reports.sales-visitors'));
        $this->assertTrue(collect($response->viewData('rows'))->isEmpty());
    }

    private function order(int $createdBy): PreinvoiceOrder
    {
        return PreinvoiceOrder::query()->create([
            'uuid' => fake()->unique()->uuid(),
            'created_by' => $createdBy,
            'seller_id' => null,
            'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
            'customer_name' => 'مشتری قدیمی',
            'customer_mobile' => fake()->unique()->numerify('09#########'),
            'customer_address' => 'تهران',
            'province_id' => 1,
            'shipping_id' => 0,
        ]);
    }

    private function invoice(?int $preinvoiceId, ?int $sellerId, string $status, int $total): Invoice
    {
        return Invoice::query()->create([
            'uuid' => fake()->unique()->uuid(),
            'preinvoice_order_id' => $preinvoiceId,
            'seller_id' => $sellerId,
            'document_date' => '2026-07-15 12:00:00',
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
