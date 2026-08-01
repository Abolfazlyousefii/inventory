<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PreinvoiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinanceSalesVisitorsInvoiceRegistrarTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_is_grouped_by_preinvoice_creator_even_when_seller_is_different(): void
    {
        $registrar = User::factory()->create(['name' => 'ثبت‌کننده الف', 'crm_user_id' => '88001']);
        $other = User::factory()->create(['name' => 'کاربر ب', 'is_seller' => true]);
        $invoice = $this->linkedInvoice($registrar, $other, 5000);
        InvoicePayment::query()->create(['invoice_id' => $invoice->id, 'method' => 'cash', 'amount' => 1000]);
        InvoicePayment::query()->create(['invoice_id' => $invoice->id, 'method' => 'cash', 'amount' => 1500]);

        $response = $this->actingAs($this->owner())->get(route('finance.reports.sales-visitors', [
            'user_id' => $registrar->id,
        ]));

        $row = collect($response->viewData('rows'))->sole();
        $this->assertSame($registrar->id, $row['registered_by_user_id']);
        $this->assertSame(1, $row['invoice_count']);
        $this->assertSame(5000, $row['total_sales']);
        $this->assertSame(2500, $row['paid_amount']);
        $this->assertSame(2500, $row['remaining_amount']);

        $otherResponse = $this->actingAs($this->owner())->get(route('finance.reports.sales-visitors', ['user_id' => $other->id]));
        $this->assertTrue(collect($otherResponse->viewData('rows'))->isEmpty());

        $crmResponse = $this->actingAs($this->owner())->get(route('finance.reports.sales-visitors', ['user_id' => 88001]));
        $this->assertTrue(collect($crmResponse->viewData('rows'))->isEmpty());
    }

    private function linkedInvoice(User $registrar, User $seller, int $total): Invoice
    {
        $order = PreinvoiceOrder::query()->create([
            'uuid' => fake()->unique()->uuid(),
            'created_by' => $registrar->id,
            'seller_id' => $seller->id,
            'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
            'customer_name' => 'مشتری تست',
            'customer_mobile' => '09123333333',
            'customer_address' => 'تهران',
            'province_id' => 1,
            'shipping_id' => 0,
        ]);

        return Invoice::query()->create([
            'uuid' => fake()->unique()->uuid(),
            'preinvoice_order_id' => $order->id,
            'seller_id' => $seller->id,
            'document_date' => '2026-07-15 12:00:00',
            'customer_name' => 'مشتری تست',
            'subtotal' => $total,
            'total' => $total,
            'status' => Invoice::STATUS_SHIPPED,
        ]);
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        $owner->assignRole(Role::findOrCreate('Owner', 'web'));

        return $owner;
    }
}
