<?php

namespace Tests\Feature;

use App\Services\Finance\SellerCommissionDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSellerCommissionDocuments;
use Tests\TestCase;

class SellerCommissionDocumentAmountResolutionTest extends TestCase
{
    use CreatesSellerCommissionDocuments, RefreshDatabase;

    public function test_invoice_total_is_the_only_final_amount_source(): void
    {
        $owner = $this->erpUser();
        $invoice = $this->makeInvoice($owner, 8500, '2026-07-10 10:00:00', ['subtotal' => 10000, 'shipping_price' => 500, 'discount_amount' => 2000]);
        $this->assertSame(8500, app(SellerCommissionDocumentService::class)->resolveInvoiceFinalAmount($invoice));
    }

    public function test_backend_total_matches_final_totals_including_existing_shipping_and_discount_calculation(): void
    {
        $owner = $this->erpUser();
        $a = $this->makeInvoice($owner, 8500, '2026-07-10 10:00:00', ['subtotal' => 10000, 'shipping_price' => 500, 'discount_amount' => 2000]);
        $b = $this->makeInvoice($owner, 2500);
        $document = $this->createCommissionDocument($owner, [$a, $b]);
        $this->assertSame(2, $document->invoice_count);
        $this->assertSame(11000, $document->total_sales_amount);
    }

    public function test_snapshot_contains_official_five_digit_number_customer_and_final_total(): void
    {
        $owner = $this->erpUser();
        $invoice = $this->makeInvoice($owner, 4321, '2026-07-10 10:00:00', ['uuid' => '12345', 'customer_name' => 'مشتری سند']);
        $this->createCommissionDocument($owner, [$invoice]);
        $this->assertDatabaseHas('seller_sales_document_items', ['invoice_id' => $invoice->id, 'invoice_number_snapshot' => '12345', 'customer_name_snapshot' => 'مشتری سند', 'invoice_total_snapshot' => 4321]);
    }
}
