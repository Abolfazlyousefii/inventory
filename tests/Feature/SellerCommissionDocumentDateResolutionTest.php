<?php

namespace Tests\Feature;

use App\Services\Finance\SellerCommissionDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSellerCommissionDocuments;
use Tests\TestCase;

class SellerCommissionDocumentDateResolutionTest extends TestCase
{
    use CreatesSellerCommissionDocuments, RefreshDatabase;

    public function test_initial_preinvoice_created_at_is_used_instead_of_updated_at_or_invoice_date(): void
    {
        $owner = $this->erpUser();
        $invoice = $this->makeInvoice($owner, 1000, '2026-07-08 09:30:00', ['document_date' => '2026-08-20 10:00:00']);
        $date = app(SellerCommissionDocumentService::class)->resolveInvoiceInitialDate($invoice);
        $this->assertSame('2026-07-08 09:30:00', $date->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-01 12:00:00', (string) $invoice->preinvoiceOrder()->value('updated_at'));
    }

    public function test_snapshot_stores_original_preinvoice_date(): void
    {
        $owner = $this->erpUser();
        $invoice = $this->makeInvoice($owner, 1000, '2026-07-05 13:14:15');
        $this->createCommissionDocument($owner, [$invoice]);
        $this->assertDatabaseHas('seller_sales_document_items', ['invoice_id' => $invoice->id, 'invoice_date_snapshot' => '2026-07-05 13:14:15']);
    }

    public function test_jalali_dates_are_converted_by_request_and_boundaries_are_inclusive(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $invoice = $this->makeInvoice($owner, 1000, '2026-03-21 23:59:59');
        $this->actingAs($actor)->getJson(route('finance.seller-sales.available-invoices', ['user_id' => $owner->id, 'date_from' => '1405/01/01', 'date_to' => '1405/01/01']))->assertOk()->assertJsonFragment(['id' => $invoice->id]);
    }
}
