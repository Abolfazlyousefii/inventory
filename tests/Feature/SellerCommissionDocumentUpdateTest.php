<?php

namespace Tests\Feature;

use App\Services\Finance\SellerCommissionDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSellerCommissionDocuments;
use Tests\TestCase;

class SellerCommissionDocumentUpdateTest extends TestCase
{
    use CreatesSellerCommissionDocuments, RefreshDatabase;

    public function test_edit_shows_current_user_range_and_checked_items(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser(['name' => 'فروشنده فعلی']);
        $invoice = $this->makeInvoice($owner, 5000);
        $document = $this->createCommissionDocument($owner, [$invoice], $actor);
        $this->actingAs($actor)->get(route('finance.seller-sales.edit', $document))->assertOk()->assertSee('فروشنده فعلی')->assertSee((string) $invoice->id)->assertSee($document->document_number);
    }

    public function test_update_adds_and_removes_items_and_recalculates_totals(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $removed = $this->makeInvoice($owner, 100);
        $retained = $this->makeInvoice($owner, 200);
        $added = $this->makeInvoice($owner, 300);
        $service = app(SellerCommissionDocumentService::class);
        $document = $this->createCommissionDocument($owner, [$removed, $retained], $actor);
        $updated = $service->updateDocument($document, $this->documentData($owner, [$retained, $added]), $actor);
        $this->assertSame(2, $updated->invoice_count);
        $this->assertSame(500, $updated->total_sales_amount);
        $this->assertDatabaseMissing('seller_sales_document_items', ['seller_sales_document_id' => $document->id, 'invoice_id' => $removed->id]);
        $this->assertDatabaseHas('seller_sales_document_items', ['seller_sales_document_id' => $document->id, 'invoice_id' => $added->id]);
    }

    public function test_update_requires_at_least_one_invoice(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $document = $this->createCommissionDocument($owner, [$this->makeInvoice($owner)], $actor);
        $this->actingAs($actor)->put(route('finance.seller-sales.update', $document), $this->documentData($owner, [], ['invoice_ids' => []]))->assertSessionHasErrors('invoice_ids');
        $this->assertSame(1, $document->fresh()->invoice_count);
    }

    public function test_update_with_invalid_new_invoice_rolls_back_removals(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $other = $this->erpUser();
        $original = $this->makeInvoice($owner);
        $document = $this->createCommissionDocument($owner, [$original], $actor);
        $service = app(SellerCommissionDocumentService::class);
        try {
            $service->updateDocument($document, $this->documentData($owner, [$this->makeInvoice($other)]), $actor);
            $this->fail('ValidationException expected.');
        } catch (ValidationException) {
        }
        $this->assertDatabaseHas('seller_sales_document_items', ['seller_sales_document_id' => $document->id, 'invoice_id' => $original->id]);
    }

    public function test_update_changes_notes_but_never_mutates_invoice_or_owner(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $invoice = $this->makeInvoice($owner, 4444);
        $before = (array) DB::table('invoices')->where('id', $invoice->id)->first();
        $document = $this->createCommissionDocument($owner, [$invoice], $actor);

        $updated = app(SellerCommissionDocumentService::class)->updateDocument(
            $document,
            $this->documentData($owner, [$invoice], ['notes' => 'توضیح ویرایش‌شده']),
            $actor,
        );

        $this->assertSame('توضیح ویرایش‌شده', $updated->notes);
        $this->assertSame($before, (array) DB::table('invoices')->where('id', $invoice->id)->first());
        $this->assertSame($owner->id, (int) $invoice->preinvoiceOrder()->value('created_by'));
    }
}
