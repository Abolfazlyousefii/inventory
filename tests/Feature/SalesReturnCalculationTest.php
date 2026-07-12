<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\SalesReturnCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesReturnCalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_extra_discount_is_allocated_and_full_return_matches_invoice_total(): void
    {
        $invoice = Invoice::create(['uuid' => '91001', 'discount_amount' => 300, 'subtotal' => 3000, 'total' => 2700, 'status' => Invoice::STATUS_SHIPPED]);
        $a = InvoiceItem::create(['invoice_id' => $invoice->id, 'product_id' => 1, 'quantity' => 1, 'price' => 1000, 'line_total' => 1000]);
        $b = InvoiceItem::create(['invoice_id' => $invoice->id, 'product_id' => 1, 'quantity' => 1, 'price' => 2000, 'line_total' => 2000]);

        $preview = app(SalesReturnCalculationService::class)->calculateInternalInvoicePreview($invoice->fresh('items'), [
            ['invoice_item_id' => $a->id, 'return_quantity' => 1, 'item_condition' => 'healthy'],
            ['invoice_item_id' => $b->id, 'return_quantity' => 1, 'item_condition' => 'healthy'],
        ]);

        $this->assertSame(2700, $preview['refund_total']);
    }

    public function test_sazeh_refund_uses_refund_unit_price(): void
    {
        $preview = app(SalesReturnCalculationService::class)->calculateSazehPreview([
            ['return_quantity' => 3, 'refund_unit_price' => 2000, 'item_condition' => 'damaged'],
        ]);

        $this->assertSame(6000, $preview['refund_total']);
    }
}
