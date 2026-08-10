<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Services\Finance\SellerCommissionDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSellerCommissionDocuments;
use Tests\TestCase;

class SellerCommissionDocumentAvailableInvoicesTest extends TestCase
{
    use CreatesSellerCommissionDocuments, RefreshDatabase;

    public function test_endpoint_validates_user_and_date_parameters(): void
    {
        $actor = $this->financeActor();

        $this->actingAs($actor)->getJson(route('finance.seller-sales.available-invoices'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id', 'date_from', 'date_to']);
    }

    public function test_endpoint_rejects_inactive_or_non_erp_user(): void
    {
        $actor = $this->financeActor();
        $blocked = $this->erpUser(['is_active' => false]);

        $this->actingAs($actor)->getJson(route('finance.seller-sales.available-invoices', [
            'user_id' => $blocked->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]))->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }

    public function test_endpoint_only_returns_free_invoices_for_selected_user_in_inclusive_range(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $other = $this->erpUser();
        $from = $this->makeInvoice($owner, 100, '2026-07-01 00:00:00');
        $to = $this->makeInvoice($owner, 200, '2026-07-31 23:59:59');
        $outside = $this->makeInvoice($owner, 300, '2026-08-01 00:00:00');
        $otherInvoice = $this->makeInvoice($other);
        $response = $this->actingAs($actor)->getJson(route('finance.seller-sales.available-invoices', ['user_id' => $owner->id, 'date_from' => '2026-07-01', 'date_to' => '2026-07-31']));
        $response->assertOk()->assertJsonCount(2, 'data')->assertJsonFragment(['id' => $from->id])->assertJsonFragment(['id' => $to->id]);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertNotContains($outside->id, $ids);
        $this->assertNotContains($otherInvoice->id, $ids);
    }

    public function test_cancelled_invoice_is_excluded_but_workflow_status_is_not(): void
    {
        $owner = $this->erpUser();
        $cancelled = $this->makeInvoice($owner, 1, '2026-07-10 10:00:00', ['status' => Invoice::STATUS_NOT_SHIPPED]);
        $workflow = $this->makeInvoice($owner, 1, '2026-07-10 10:00:00', ['status' => Invoice::STATUS_PENDING_COLLECTION]);
        $ids = app(SellerCommissionDocumentService::class)->getAvailableInvoices($owner->id, '2026-07-01', '2026-07-31')->pluck('invoices.id');
        $this->assertNotContains($cancelled->id, $ids);
        $this->assertContains($workflow->id, $ids);
    }

    public function test_invoice_used_by_another_document_is_hidden_but_current_document_items_are_allowed_in_edit(): void
    {
        $owner = $this->erpUser();
        $used = $this->makeInvoice($owner);
        $free = $this->makeInvoice($owner);
        $document = $this->createCommissionDocument($owner, [$used]);
        $service = app(SellerCommissionDocumentService::class);
        $createIds = $service->getAvailableInvoices($owner->id, '2026-07-01', '2026-07-31')->pluck('invoices.id');
        $editIds = $service->getAvailableInvoices($owner->id, '2026-07-01', '2026-07-31', $document->id)->pluck('invoices.id');
        $this->assertNotContains($used->id, $createIds);
        $this->assertContains($free->id, $createIds);
        $this->assertContains($used->id, $editIds);
    }

    public function test_search_and_server_pagination_contract_are_exposed(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $invoice = $this->makeInvoice($owner, 4500, '2026-07-10 10:00:00', ['uuid' => '54321', 'customer_name' => 'مشتری ویژه']);
        $response = $this->actingAs($actor)->getJson(route('finance.seller-sales.available-invoices', ['user_id' => $owner->id, 'date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'search' => '54321']));
        $response->assertOk()->assertJsonPath('data.0.id', $invoice->id)->assertJsonPath('data.0.number', '54321')->assertJsonStructure(['current_page', 'last_page', 'per_page', 'total']);
    }

    public function test_search_by_customer_name_uses_invoice_snapshot_customer_field(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $invoice = $this->makeInvoice($owner, 4500, '2026-07-10 10:00:00', ['customer_name' => 'مشتری جست‌وجو']);

        $this->actingAs($actor)->getJson(route('finance.seller-sales.available-invoices', [
            'user_id' => $owner->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'search' => 'جست‌وجو',
        ]))->assertOk()->assertJsonPath('data.0.id', $invoice->id);
    }

    public function test_pagination_never_repeats_an_invoice_between_pages(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        foreach (range(1, 25) as $number) {
            $this->makeInvoice($owner, $number * 100);
        }

        $parameters = ['user_id' => $owner->id, 'date_from' => '2026-07-01', 'date_to' => '2026-07-31'];
        $first = $this->actingAs($actor)->getJson(route('finance.seller-sales.available-invoices', $parameters + ['page' => 1]));
        $second = $this->actingAs($actor)->getJson(route('finance.seller-sales.available-invoices', $parameters + ['page' => 2]));

        $first->assertOk()->assertJsonCount(20, 'data');
        $second->assertOk()->assertJsonCount(5, 'data');
        $this->assertEmpty(array_intersect($first->json('data.*.id'), $second->json('data.*.id')));
    }
}
