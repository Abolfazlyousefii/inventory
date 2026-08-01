<?php

namespace Tests\Feature;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSellerCommissionDocuments;
use Tests\TestCase;

class SellerCommissionDocumentCreateTest extends TestCase
{
    use CreatesSellerCommissionDocuments, RefreshDatabase;

    public function test_create_lists_active_internal_users_with_users_id_as_value(): void
    {
        $actor = $this->financeActor();
        $user = $this->erpUser(['name' => 'کاربر داخلی']);
        $inactive = $this->erpUser(['name' => 'کاربر غیرفعال', 'is_active' => false]);
        $response = $this->actingAs($actor)->get(route('finance.seller-sales.create'));
        $response->assertOk()->assertSee('value="'.$user->id.'"', false)->assertSee('کاربر داخلی')->assertDontSee($inactive->name);
    }

    public function test_dropdown_excludes_users_without_erp_access_and_never_uses_crm_user_id_as_value(): void
    {
        $actor = $this->financeActor();
        $selectable = $this->erpUser(['name' => 'کاربر جدید قابل انتخاب', 'crm_user_id' => 987654]);
        $integration = $this->erpUser(['name' => 'کاربر یکپارچه‌سازی', 'can_access_erp' => false, 'sync_source' => 'integration']);

        $response = $this->actingAs($actor)->get(route('finance.seller-sales.create'));

        $response->assertOk()
            ->assertSee('value="'.$selectable->id.'"', false)
            ->assertDontSee('value="987654"', false)
            ->assertDontSee($integration->name);
    }

    public function test_dropdown_is_not_dependent_on_seller_role_or_an_existing_invoice(): void
    {
        $actor = $this->financeActor();
        $newUser = $this->erpUser(['name' => 'کاربر بدون فاکتور', 'is_seller' => false]);

        $this->actingAs($actor)->get(route('finance.seller-sales.create'))
            ->assertOk()
            ->assertSee($newUser->name)
            ->assertSee('value="'.$newUser->id.'"', false);
    }

    public function test_store_with_one_invoice_recalculates_count_and_total_in_backend(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $invoice = $this->makeInvoice($owner, 12500);
        $payload = $this->documentData($owner, [$invoice]) + ['invoice_count' => 99, 'total_sales_amount' => 1];
        $this->actingAs($actor)->post(route('finance.seller-sales.store'), $payload)->assertRedirect();
        $this->assertDatabaseHas('seller_sales_documents', ['invoice_count' => 1, 'total_sales_amount' => 12500]);
    }

    public function test_store_with_twenty_invoices_succeeds_and_only_selected_invoices_are_consumed(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $selected = collect(range(1, 20))->map(fn ($i) => $this->makeInvoice($owner, $i * 100));
        $unselected = $this->makeInvoice($owner, 999);
        $this->actingAs($actor)->post(route('finance.seller-sales.store'), $this->documentData($owner, $selected->all()))->assertRedirect();
        $this->assertDatabaseCount('seller_sales_document_items', 20);
        $this->assertDatabaseMissing('seller_sales_document_items', ['invoice_id' => $unselected->id]);
    }

    public function test_invalid_invoice_rolls_back_entire_transaction(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $other = $this->erpUser();
        $response = $this->actingAs($actor)->post(route('finance.seller-sales.store'), $this->documentData($owner, [$this->makeInvoice($owner), $this->makeInvoice($other)]));
        $response->assertSessionHasErrors('invoice_ids');
        $this->assertDatabaseCount('seller_sales_documents', 0);
        $this->assertDatabaseCount('seller_sales_document_items', 0);
    }

    public function test_store_request_rejects_missing_duplicate_and_invalid_date_payloads(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $invoice = $this->makeInvoice($owner);

        $this->actingAs($actor)->post(route('finance.seller-sales.store'), [
            'user_id' => $owner->id,
            'date_from' => '2026-07-31',
            'date_to' => '2026-07-01',
            'invoice_ids' => [$invoice->id, $invoice->id],
        ])->assertSessionHasErrors(['date_to', 'invoice_ids.1']);

        $this->actingAs($actor)->post(route('finance.seller-sales.store'), [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'invoice_ids' => [],
        ])->assertSessionHasErrors(['user_id', 'invoice_ids']);

        $this->assertDatabaseCount('seller_sales_documents', 0);
    }

    public function test_store_rejects_cancelled_and_out_of_range_invoices_without_reserving_them(): void
    {
        $actor = $this->financeActor();
        $owner = $this->erpUser();
        $cancelled = $this->makeInvoice($owner, 100, '2026-07-10 10:00:00', ['status' => Invoice::STATUS_NOT_SHIPPED]);
        $outside = $this->makeInvoice($owner, 200, '2026-08-01 00:00:00');

        foreach ([$cancelled, $outside] as $invoice) {
            $this->actingAs($actor)->post(route('finance.seller-sales.store'), $this->documentData($owner, [$invoice]))
                ->assertSessionHasErrors('invoice_ids');
        }

        $this->assertDatabaseCount('seller_sales_documents', 0);
        $this->assertDatabaseCount('seller_sales_document_items', 0);
    }
}
