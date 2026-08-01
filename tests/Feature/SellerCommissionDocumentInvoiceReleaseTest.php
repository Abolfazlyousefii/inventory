<?php

namespace Tests\Feature;

use App\Services\Finance\SellerCommissionDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSellerCommissionDocuments;
use Tests\TestCase;

class SellerCommissionDocumentInvoiceReleaseTest extends TestCase
{
    use CreatesSellerCommissionDocuments, RefreshDatabase;

    public function test_removed_invoice_becomes_available_again_without_changing_invoice(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $removed = $this->makeInvoice($owner);
        $kept = $this->makeInvoice($owner);
        $status = $removed->status;
        $service = app(SellerCommissionDocumentService::class);
        $document = $this->createCommissionDocument($owner, [$removed, $kept], $actor);
        $service->updateDocument($document, $this->documentData($owner, [$kept]), $actor);
        $this->assertTrue($service->getAvailableInvoices($owner->id, '2026-07-01', '2026-07-31')->where('invoices.id', $removed->id)->exists());
        $this->assertSame($status, $removed->fresh()->status);
    }

    public function test_released_invoice_can_be_saved_in_a_new_document(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $released = $this->makeInvoice($owner);
        $kept = $this->makeInvoice($owner);
        $service = app(SellerCommissionDocumentService::class);
        $first = $this->createCommissionDocument($owner, [$released, $kept], $actor);
        $service->updateDocument($first, $this->documentData($owner, [$kept]), $actor);
        $second = $this->createCommissionDocument($owner, [$released], $actor);
        $this->assertDatabaseHas('seller_sales_document_items', ['seller_sales_document_id' => $second->id, 'invoice_id' => $released->id]);
        $availableIds = $service->getAvailableInvoices($owner->id, '2026-07-01', '2026-07-31')->pluck('invoices.id');
        $this->assertNotContains($kept->id, $availableIds);
        $this->assertNotContains($released->id, $availableIds);
    }

    public function test_failed_validation_does_not_block_any_free_invoice(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $free = $this->makeInvoice($owner);

        $this->actingAs($actor)->post(route('finance.seller-sales.store'), [
            'user_id' => $owner->id,
            'date_from' => '2026-07-31',
            'date_to' => '2026-07-01',
            'invoice_ids' => [$free->id],
        ])->assertSessionHasErrors('date_to');

        $this->assertTrue(app(SellerCommissionDocumentService::class)
            ->getAvailableInvoices($owner->id, '2026-07-01', '2026-07-31')
            ->where('invoices.id', $free->id)->exists());
    }

    public function test_merely_loading_an_invoice_never_reserves_it(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $invoice = $this->makeInvoice($owner);
        $this->actingAs($actor)->getJson(route('finance.seller-sales.available-invoices', ['user_id' => $owner->id, 'date_from' => '2026-07-01', 'date_to' => '2026-07-31']))->assertOk();
        $this->assertDatabaseCount('seller_sales_document_items', 0);
        $this->createCommissionDocument($owner, [$invoice], $actor);
        $this->assertDatabaseCount('seller_sales_document_items', 1);
    }
}
