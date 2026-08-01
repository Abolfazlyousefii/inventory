<?php

namespace Tests\Feature;

use App\Services\Finance\SellerCommissionDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSellerCommissionDocuments;
use Tests\TestCase;

class SellerCommissionDocumentDuplicateInvoiceTest extends TestCase
{
    use CreatesSellerCommissionDocuments, RefreshDatabase;

    public function test_same_invoice_cannot_be_added_to_two_documents_with_controlled_persian_error(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $invoice = $this->makeInvoice($owner);
        $this->createCommissionDocument($owner, [$invoice], $actor);
        try {
            $this->createCommissionDocument($owner, [$invoice], $actor);
            $this->fail('ValidationException expected.');
        } catch (ValidationException $exception) {
            $this->assertSame(SellerCommissionDocumentService::DUPLICATE_MESSAGE, $exception->errors()['invoice_ids'][0]);
        }
        $this->assertDatabaseCount('seller_sales_documents', 1);
    }

    public function test_duplicate_failure_never_leaves_a_partial_document(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $used = $this->makeInvoice($owner);
        $free = $this->makeInvoice($owner);
        $this->createCommissionDocument($owner, [$used], $actor);
        try {
            $this->createCommissionDocument($owner, [$used, $free], $actor);
        } catch (ValidationException) {
        }
        $this->assertDatabaseCount('seller_sales_documents', 1);
        $this->assertDatabaseMissing('seller_sales_document_items', ['invoice_id' => $free->id]);
    }
}
