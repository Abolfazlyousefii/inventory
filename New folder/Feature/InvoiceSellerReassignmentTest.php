<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\SellerReassignmentAudit;
use App\Models\User;
use App\Services\SalesDocumentSellerReassignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvoiceSellerReassignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_reassignment_syncs_linked_document_without_changing_creator_or_financial_data(): void
    {
        $actor = User::factory()->create();
        $a = User::factory()->create(['is_active' => true, 'can_access_erp' => true, 'is_seller' => true]);
        $b = User::factory()->create(['is_active' => true, 'can_access_erp' => true, 'is_seller' => true]);
        $order = PreinvoiceOrder::query()->create(['uuid' => fake()->uuid(), 'created_by' => $a->id, 'seller_id' => $a->id, 'status' => 'draft', 'customer_name' => 'Test', 'customer_mobile' => '09120000000', 'customer_address' => 'Test', 'province_id' => 1, 'total_price' => 1200]);
        $invoice = Invoice::query()->create(['uuid' => fake()->uuid(), 'preinvoice_order_id' => $order->id, 'seller_id' => $a->id, 'total' => 1200, 'subtotal' => 1200]);

        $result = app(SalesDocumentSellerReassignmentService::class)->reassignInvoiceSeller($invoice, $b, $actor, 'اصلاح مالکیت');

        $this->assertTrue($result->changed);
        $this->assertSame($b->id, $invoice->fresh()->seller_id);
        $this->assertSame($b->id, $order->fresh()->seller_id);
        $this->assertSame($a->id, $order->fresh()->created_by);
        $this->assertSame(1200, $invoice->fresh()->total);
        $this->assertDatabaseHas(SellerReassignmentAudit::class, ['invoice_id' => $invoice->id, 'old_seller_id' => $a->id, 'new_seller_id' => $b->id, 'changed_by' => $actor->id]);
    }

    public function test_same_seller_is_an_idempotent_noop(): void
    {
        $seller = User::factory()->create(['is_active' => true, 'can_access_erp' => true, 'is_seller' => true]);
        $invoice = Invoice::query()->create(['uuid' => fake()->uuid(), 'seller_id' => $seller->id, 'total' => 1, 'subtotal' => 1]);
        $result = app(SalesDocumentSellerReassignmentService::class)->reassignInvoiceSeller($invoice, $seller, $seller, 'retry');
        $this->assertFalse($result->changed);
        $this->assertDatabaseCount('seller_reassignment_audits', 0);
    }

    public function test_inactive_seller_is_rejected(): void
    {
        $actor = User::factory()->create();
        $seller = User::factory()->create(['is_active' => false, 'can_access_erp' => true, 'is_seller' => true]);
        $invoice = Invoice::query()->create(['uuid' => fake()->uuid(), 'total' => 1, 'subtotal' => 1]);
        $this->expectException(ValidationException::class);
        app(SalesDocumentSellerReassignmentService::class)->reassignInvoiceSeller($invoice, $seller, $actor, 'invalid');
    }
}
