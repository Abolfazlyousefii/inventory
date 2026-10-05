<?php

namespace Tests\Feature;

use App\Services\SalesDocumentSellerReassignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSellerCommissionDocuments;
use Tests\TestCase;

class CommercialInvoiceTransferRecipientsTest extends TestCase
{
    use CreatesSellerCommissionDocuments, RefreshDatabase;

    public function test_list_includes_active_erp_users_without_seller_flag(): void
    {
        $actor = $this->financeActor();
        $recipient = $this->erpUser(['name' => 'دایان سعیدیان']);
        $inactive = $this->erpUser(['is_active' => false]);
        $blocked = $this->erpUser(['can_access_erp' => false]);

        $this->actingAs($actor)->get(route('commercial.invoice-reassignments.index'))
            ->assertOk()
            ->assertViewHas('sellers', fn ($users) => $users->contains('id', $recipient->id)
                && ! $users->contains('id', $inactive->id)
                && ! $users->contains('id', $blocked->id));
    }

    public function test_preview_and_transfer_accept_active_non_seller_without_changing_user_flags_or_invoice_amounts(): void
    {
        $actor = $this->financeActor();
        $oldSeller = $this->erpUser(['is_seller' => true]);
        $recipient = $this->erpUser(['name' => 'دایان سعیدیان']);
        $invoice = $this->makeInvoice($oldSeller, 150000, '2026-07-10', ['seller_id' => $oldSeller->id]);
        $original = $invoice->toArray();
        $payload = ['invoice_ids' => [$invoice->id], 'seller_id' => $recipient->id,
            'reason' => 'اصلاح مالک فاکتور', 'sync_preinvoice' => true];

        $preview = $this->actingAs($actor)->postJson(route('commercial.invoice-reassignments.preview'), $payload)
            ->assertOk()->assertJsonPath('summary.destination_seller.id', $recipient->id);
        $this->post(route('commercial.invoice-reassignments.store'),
            $payload + ['preview_token' => $preview->json('preview_token')])
            ->assertRedirect(route('commercial.invoice-reassignments.index'));

        $this->assertSame($recipient->id, $invoice->fresh()->seller_id);
        $this->assertSame($recipient->id, $invoice->preinvoiceOrder->fresh()->seller_id);
        $this->assertSame($oldSeller->id, $invoice->preinvoiceOrder->fresh()->created_by);
        $this->assertSame($original['total'], $invoice->fresh()->total);
        $this->assertSame($original['status'], $invoice->fresh()->status);
        $this->assertFalse($recipient->fresh()->is_seller);
        $this->assertDatabaseHas('seller_reassignment_audits', ['invoice_id' => $invoice->id,
            'new_seller_id' => $recipient->id, 'changed_by' => $actor->id]);
    }

    public function test_preview_and_store_reject_inactive_or_erp_blocked_recipients(): void
    {
        $actor = $this->financeActor();
        $seller = $this->erpUser(['is_seller' => true]);
        $invoice = $this->makeInvoice($seller, 1000, '2026-07-10', ['seller_id' => $seller->id]);
        foreach ([['is_active' => false], ['can_access_erp' => false]] as $attributes) {
            $recipient = $this->erpUser($attributes);
            $payload = ['invoice_ids' => [$invoice->id], 'seller_id' => $recipient->id,
                'reason' => 'انتقال', 'sync_preinvoice' => true, 'preview_token' => str_repeat('a', 64)];
            $this->actingAs($actor)->postJson(route('commercial.invoice-reassignments.preview'), $payload)
                ->assertUnprocessable()->assertJsonValidationErrors('seller_id');
            $this->postJson(route('commercial.invoice-reassignments.store'), $payload)
                ->assertUnprocessable()->assertJsonValidationErrors('seller_id');
        }
        $this->assertSame($seller->id, $invoice->fresh()->seller_id);
        $this->assertDatabaseCount('seller_reassignment_audits', 0);
    }

    public function test_other_reassignment_callers_still_require_the_seller_flag(): void
    {
        $actor = $this->financeActor();
        $recipient = $this->erpUser();
        $invoice = $this->makeInvoice($actor);
        $this->expectException(ValidationException::class);
        app(SalesDocumentSellerReassignmentService::class)
            ->reassignInvoiceSeller($invoice, $recipient, $actor, 'انتقال از بخش دیگر');
    }
}
