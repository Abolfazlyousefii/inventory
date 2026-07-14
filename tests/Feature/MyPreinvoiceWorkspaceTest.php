<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyPreinvoiceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_tab_shows_active_preinvoices_and_unshipped_invoices_only(): void
    {
        $seller = User::factory()->create();
        $active = $this->preinvoice($seller, PreinvoiceOrder::STATUS_PENDING_FINANCE, ['uuid' => 'PI-ACTIVE-1']);
        $converted = $this->preinvoice($seller, PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE, ['uuid' => 'PI-CONVERTED-1']);
        $this->invoice($converted, Invoice::STATUS_PENDING_COLLECTION, ['uuid' => 'INV-UNSHIPPED-1']);
        $this->preinvoice($seller, PreinvoiceOrder::STATUS_DRAFT, ['uuid' => 'PI-DRAFT-1']);
        $this->preinvoice($seller, PreinvoiceOrder::STATUS_RETURNED_TO_SALES, ['uuid' => 'PI-CORRECTION-1']);
        $cancelled = $this->preinvoice($seller, PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE, ['uuid' => 'PI-CANCELLED-1']);
        $this->invoice($cancelled, Invoice::STATUS_NOT_SHIPPED, ['uuid' => 'INV-CANCELLED-1']);

        $response = $this->actingAs($seller)->get(route('preinvoice.my.index', ['tab' => 'active']));

        $response->assertOk()
            ->assertSee('PI-ACTIVE-1')
            ->assertSee('INV-UNSHIPPED-1')
            ->assertDontSee('PI-DRAFT-1')
            ->assertDontSee('PI-CORRECTION-1')
            ->assertDontSee('INV-CANCELLED-1');
        $this->assertSame(1, substr_count($response->getContent(), 'INV-UNSHIPPED-1'));
    }

    protected function preinvoice(User $seller, string $status, array $overrides = []): PreinvoiceOrder
    {
        return PreinvoiceOrder::query()->create(array_merge([
            'uuid' => 'PI-' . uniqid(), 'created_by' => $seller->id, 'status' => $status,
            'customer_name' => 'علی رضایی', 'customer_mobile' => '09120000000', 'customer_address' => 'تهران',
            'province_id' => 1, 'city_id' => 1, 'shipping_id' => 0, 'shipping_price' => 0,
            'discount_amount' => 0, 'total_price' => 100000,
        ], $overrides));
    }

    protected function invoice(PreinvoiceOrder $order, string $status, array $overrides = []): Invoice
    {
        return Invoice::query()->create(array_merge([
            'uuid' => 'INV-' . uniqid(), 'preinvoice_order_id' => $order->id,
            'customer_name' => $order->customer_name, 'customer_mobile' => $order->customer_mobile,
            'total' => 100000, 'subtotal' => 100000, 'discount_amount' => 0,
            'status' => $status, 'status_changed_at' => now(),
        ], $overrides));
    }
}
