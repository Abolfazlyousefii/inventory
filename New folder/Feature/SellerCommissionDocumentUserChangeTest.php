<?php

namespace Tests\Feature;

use App\Services\Finance\SellerCommissionDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSellerCommissionDocuments;
use Tests\TestCase;

class SellerCommissionDocumentUserChangeTest extends TestCase
{
    use CreatesSellerCommissionDocuments, RefreshDatabase;

    public function test_frontend_resets_selection_when_user_changes(): void
    {
        $actor = $this->financeActor();
        $response = $this->actingAs($actor)->get(route('finance.seller-sales.create'));
        $response->assertOk()
            ->assertSee('با تغییر فروشنده، فاکتورهای انتخاب‌شده فعلی پاک خواهند شد.')
            ->assertSee("byId('sellerUserId').addEventListener('change'", false)
            ->assertSee('selected.clear()', false)
            ->assertSee("byId('invoiceRows').innerHTML", false)
            ->assertSee("byId('foundCount').textContent = '۰'", false)
            ->assertSee('if (initialUser &&', false)
            ->assertSee('load(1)', false);
    }

    public function test_after_user_change_endpoint_only_returns_new_users_invoices(): void
    {
        $actor = $this->financeActor();
        $first = $this->erpUser();
        $second = $this->erpUser();
        $firstInvoice = $this->makeInvoice($first);
        $secondInvoice = $this->makeInvoice($second);
        $response = $this->actingAs($actor)->getJson(route('finance.seller-sales.available-invoices', ['user_id' => $second->id, 'date_from' => '2026-07-01', 'date_to' => '2026-07-31']));
        $response->assertOk()->assertJsonFragment(['id' => $secondInvoice->id]);
        $this->assertNotContains($firstInvoice->id, collect($response->json('data'))->pluck('id'));
    }

    public function test_document_user_can_change_only_with_invoices_owned_by_new_user(): void
    {
        $actor = $this->financeActor();
        $first = $this->erpUser();
        $second = $this->erpUser();
        $old = $this->makeInvoice($first);
        $new = $this->makeInvoice($second);
        $document = $this->createCommissionDocument($first, [$old], $actor);
        app(SellerCommissionDocumentService::class)->updateDocument($document, $this->documentData($second, [$new]), $actor);
        $this->assertSame($second->id, (int) $document->fresh()->seller_id);
        $this->assertNull($new->fresh()->seller_id);
        $this->assertSame($second->id, (int) $new->preinvoiceOrder->created_by);
    }
}
