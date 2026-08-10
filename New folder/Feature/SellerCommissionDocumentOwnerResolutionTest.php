<?php

namespace Tests\Feature;

use App\Services\Finance\SellerCommissionDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSellerCommissionDocuments;
use Tests\TestCase;

class SellerCommissionDocumentOwnerResolutionTest extends TestCase
{
    use CreatesSellerCommissionDocuments, RefreshDatabase;

    public function test_preinvoice_created_by_is_the_invoice_owner(): void
    {
        $owner = $this->erpUser();
        $invoice = $this->makeInvoice($owner);
        $this->assertSame($owner->id, app(SellerCommissionDocumentService::class)->resolveInvoiceOwner($invoice));
    }

    public function test_invoice_seller_takes_priority_over_original_creator(): void
    {
        $owner = $this->erpUser();
        $finance = $this->erpUser();
        $invoice = $this->makeInvoice($owner, 1000, '2026-07-10 10:00:00', ['seller_id' => $finance->id, 'status_changed_by' => $finance->id]);
        $service = app(SellerCommissionDocumentService::class);
        $this->assertSame($finance->id, $service->resolveInvoiceOwner($invoice));
        $this->expectException(ValidationException::class);
        $service->createDocument($this->documentData($owner, [$invoice]), $finance);
    }

    public function test_creating_document_never_changes_invoice_ownership_fields(): void
    {
        $owner = $this->erpUser();
        $legacySeller = $this->erpUser();
        $invoice = $this->makeInvoice($owner, 1000, '2026-07-10 10:00:00', ['seller_id' => $legacySeller->id]);
        $this->createCommissionDocument($legacySeller, [$invoice]);
        $this->assertSame($legacySeller->id, (int) $invoice->fresh()->seller_id);
        $this->assertSame($owner->id, (int) $invoice->preinvoiceOrder->created_by);
    }
}
