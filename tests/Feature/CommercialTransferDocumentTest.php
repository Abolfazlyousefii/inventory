<?php

namespace Tests\Feature;

use App\Http\Middleware\ProtectPersonnelCsrf;
use App\Models\AccessPermission;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\SellerSalesDocumentItem;
use App\Models\User;
use App\Services\CustomerOwnership;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CommercialTransferDocumentTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\CreatesSellerCommissionDocuments;

    public function test_inactive_and_no_erp_sources_are_listed_but_destinations_stay_active(): void
    {
        [$actor,$a,$b,$c,$payload] = $this->setupTransfer();
        $a->update(['name' => 'INACTIVE_PICKER_SENTINEL', 'is_active' => false, 'can_access_erp' => false]);
        $noErp = $this->user();
        $noErp->update(['name' => 'NO_ERP_PICKER_SENTINEL', 'can_access_erp' => false]);
        $page = $this->get('/commercial/transfers')->assertOk();
        preg_match('/<select name="from_id"[^>]*>(.*?)<\/select>/s', $page->getContent(), $source);
        preg_match('/<select name="to_id"[^>]*>(.*?)<\/select>/s', $page->getContent(), $destination);
        $this->assertStringContainsString('INACTIVE_PICKER_SENTINEL', $source[1]);
        $this->assertStringContainsString('NO_ERP_PICKER_SENTINEL', $source[1]);
        $this->assertStringNotContainsString('INACTIVE_PICKER_SENTINEL', $destination[1]);
        $this->assertStringNotContainsString('NO_ERP_PICKER_SENTINEL', $destination[1]);
        $this->getJson('/commercial/transfers/search?kind=customer&from_id='.$a->id)->assertOk()->assertJsonPath('data.0.id', $c->id);
        foreach ([$a->id, $noErp->id] as $invalidDestination) {
            $invalid = $payload;
            $invalid['to_id'] = $invalidDestination;
            $this->postJson('/commercial/transfers', $invalid)->assertUnprocessable()->assertJsonValidationErrors('to_id');
        }
        $this->postJson('/commercial/transfers', $payload)->assertCreated();
        $this->assertSame($b->id, $c->assignment()->first()->seller_id);
        $this->assertFalse($a->fresh()->is_active);
        $this->assertFalse($a->fresh()->can_access_erp);
    }

    public function test_empty_customer_search_explains_missing_assignments_without_guessing_from_invoices(): void
    {
        $actor = $this->user(['commercial.customers.transfer']);
        $seller = $this->user();
        $customer = Customer::factory()->create();
        Invoice::create(['uuid' => 'customer-with-invoice', 'customer_id' => $customer->id, 'seller_id' => $seller->id, 'subtotal' => 10, 'total' => 10]);
        $this->actingAs($actor)->getJson('/commercial/transfers/search?kind=customer&from_id='.$seller->id)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('empty_reason', 'ownership_not_initialized')->assertJsonPath('can_choose_unassigned', true);
        $this->getJson('/commercial/transfers/search?kind=customer&from_id=0')->assertOk()->assertJsonPath('data.0.id', $customer->id);
        $this->assertDatabaseCount('customer_assignments', 0);
        $customer->assignment()->create(['seller_id' => $seller->id]);
        $this->getJson('/commercial/transfers/search?kind=customer&from_id='.$seller->id.'&q=notfound-sentinel')->assertOk()->assertJsonPath('empty_reason', 'search_no_matches');
    }

    public function test_source_deactivated_after_draft_can_transfer_without_reactivating_account(): void
    {
        [$actor,$a,$b,$c,$payload] = $this->setupTransfer();
        $payload['action'] = 'draft';
        $doc = $this->postJson('/commercial/transfers', $payload)->assertCreated()->json();
        $a->update(['is_active' => false]);
        $this->postJson('/commercial/transfers/'.$doc['id'].'/register', ['version' => 1])->assertOk();
        $this->get('/commercial/transfers/'.$doc['id'])->assertOk()->assertSee($a->name);
        $this->assertSame($b->id, $c->assignment()->first()->seller_id);
        $this->assertFalse($a->fresh()->is_active);
        $this->assertDatabaseHas('commercial_transfer_documents', ['id' => $doc['id'], 'status' => 'registered']);
    }

    public function test_new_invoice_document_preserves_commission_history_and_blocks_cancel_after_new_claim(): void
    {
        $actor = $this->user();
        $actor->assignRole(Role::findOrCreate('admin', 'web'));
        $a = $this->erpUser(['is_seller' => true]);
        $b = $this->erpUser(['is_seller' => true]);
        $invoice = $this->makeInvoice($a, 1000, '2026-07-10', ['seller_id' => $a->id]);
        $invoice->preinvoiceOrder->update(['seller_id' => $a->id]);
        $old = $this->createCommissionDocument($a, [$invoice], $actor);
        $item = $old->items()->firstOrFail();
        $this->actingAs($actor);
        $found = $this->getJson('/commercial/transfers/search?kind=invoice&from_id='.$a->id)->assertOk()->json('data.0');
        $doc = $this->postJson('/commercial/transfers', ['submission_key' => (string) Str::uuid(), 'kind' => 'invoice', 'from_id' => $a->id, 'to_id' => $b->id, 'reason' => 'انتقال با سابقه پورسانت', 'action' => 'register', 'items' => [['id' => $invoice->id, 'token' => $found['token']]]])->assertCreated()->json();
        $this->assertSame(SellerSalesDocumentItem::STATUS_REASSIGNED, $item->fresh()->status);
        $this->assertNull($item->fresh()->active_invoice_id);
        $this->assertSame($invoice->id, $item->fresh()->invoice_id);
        $new = $this->createCommissionDocument($b, [$invoice->fresh()], $actor);
        $this->postJson('/commercial/transfers/'.$doc['id'].'/cancel', ['version' => 1, 'reason' => 'لغو پس از سند جدید'])->assertConflict();
        $this->assertSame($b->id, $invoice->fresh()->seller_id);
        $this->assertSame($invoice->id, $new->items()->firstOrFail()->active_invoice_id);
        $this->assertDatabaseHas('commercial_transfer_documents', ['id' => $doc['id'], 'status' => 'registered']);
    }

    public function test_linked_invoice_batch_can_transfer_and_cancel_without_overwriting_original_null_sellers(): void
    {
        $actor = $this->user();
        $actor->assignRole(Role::findOrCreate('admin', 'web'));
        $a = $this->user();
        $b = $this->user();
        $orders = collect([1, 2])->map(fn () => PreinvoiceOrder::create(['uuid' => (string) Str::uuid(), 'created_by' => $a->id, 'seller_id' => null, 'status' => 'draft', 'customer_name' => 'مشتری مشترک', 'customer_mobile' => '09120000000', 'customer_address' => 'Test', 'province_id' => 1, 'total_price' => 600]));
        $invoices = $orders->map(fn ($order) => Invoice::create(['uuid' => '0099'.$order->id, 'customer_name' => 'مشتری مشترک', 'preinvoice_order_id' => $order->id, 'seller_id' => null, 'subtotal' => 600, 'total' => 600]));
        $this->actingAs($actor);
        $items = $this->getJson('/commercial/transfers/search?kind=invoice&from_id='.$a->id.'&q=مشترک')->assertOk()->json('data');
        $this->assertCount(2, $items);
        $doc = $this->postJson('/commercial/transfers', ['submission_key' => (string) Str::uuid(), 'kind' => 'invoice', 'from_id' => $a->id, 'to_id' => $b->id, 'reason' => 'انتقال گروهی', 'action' => 'register', 'items' => collect($items)->map(fn ($i) => ['id' => $i['id'], 'token' => $i['token']])->all()])->assertCreated()->json();
        foreach ($invoices as $invoice) {
            $this->assertSame($b->id, $invoice->fresh()->seller_id);
        }
        foreach ($orders as $order) {
            $this->assertSame($b->id, $order->fresh()->seller_id);
        }
        $this->postJson('/commercial/transfers/'.$doc['id'].'/cancel', ['version' => 1, 'reason' => 'لغو گروهی'])->assertOk();
        foreach ($invoices as $invoice) {
            $this->assertNull($invoice->fresh()->seller_id);
            $this->assertSame(600, $invoice->fresh()->total);
        }
        foreach ($orders as $order) {
            $this->assertNull($order->fresh()->seller_id);
            $this->assertSame($a->id, $order->fresh()->created_by);
        }
    }

    public function test_list_detail_print_and_ajax_pagination_render_without_exposing_invoice_kind(): void
    {
        [$actor,$a,$b,$c,$payload] = $this->setupTransfer();
        $payload['action'] = 'draft';
        for ($i = 0; $i < 11; $i++) {
            $payload['submission_key'] = (string) Str::uuid();
            $doc = $this->postJson('/commercial/transfers', $payload)->assertCreated()->json();
        }
        $this->get('/commercial/transfers')->assertOk()->assertSee('اسناد انتقال')->assertSee('500011')->assertDontSee('سوابق انتقال فاکتور پیش');
        $this->get('/commercial/transfers?page=2', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertSee('500001')->assertDontSee('id="transferWorkspace"', false);
        $this->get('/commercial/transfers/'.$doc['id'])->assertOk()->assertSee('دلیل انتقال');
        $this->get('/commercial/transfers/'.$doc['id'].'?print=1')->assertOk()->assertSee('آریا گستر');
        $this->getJson('/commercial/transfers/search?kind=invoice')->assertForbidden();
        $this->actingAs($this->user(['customers.view']))->get('/commercial/transfers/'.$doc['id'])->assertForbidden();
    }

    public function test_multiple_items_and_sequence_roll_back_on_stale_second_item(): void
    {
        [$actor,$a,$b,$c,$payload] = $this->setupTransfer();
        $second = Customer::factory()->create();
        $second->assignment()->create(['seller_id' => $a->id]);
        $payload['items'][] = ['id' => $second->id, 'token' => 'stale'];
        $this->postJson('/commercial/transfers', $payload)->assertConflict();
        $this->assertSame($a->id, $c->assignment()->first()->seller_id);
        $this->assertSame($a->id, $second->assignment()->first()->seller_id);
        $this->assertDatabaseCount('commercial_transfer_documents', 0);
        $this->assertDatabaseHas('commercial_transfer_sequences', ['next_number' => 500001]);
    }

    public function test_draft_edit_version_delete_and_inactive_destination_are_enforced(): void
    {
        [$actor,$a,$b,$c,$payload] = $this->setupTransfer();
        $payload['action'] = 'draft';
        $doc = $this->postJson('/commercial/transfers', $payload)->assertCreated()->json();
        $payload['version'] = 1;
        $payload['reason'] = 'ویرایش دلیل';
        $this->putJson('/commercial/transfers/'.$doc['id'], $payload)->assertOk()->assertJsonPath('version', 2);
        $this->putJson('/commercial/transfers/'.$doc['id'], $payload)->assertConflict();
        $this->postJson('/commercial/transfers/'.$doc['id'].'/register', ['version' => 1])->assertConflict();
        $b->update(['is_active' => false]);
        $this->postJson('/commercial/transfers/'.$doc['id'].'/register', ['version' => 2])->assertUnprocessable();
        $this->deleteJson('/commercial/transfers/'.$doc['id'], ['version' => 2])->assertOk();
        $this->get('/commercial/transfers/'.$doc['id'])->assertNotFound();
        $this->assertSame($a->id, $c->assignment()->first()->seller_id);
        $this->assertDatabaseCount('commercial_transfer_events', 3);
    }

    public function test_new_document_requires_csrf_when_testing_bypass_is_disabled(): void
    {
        [$actor,$a,$b,$c,$payload] = $this->setupTransfer();
        $this->app->bind(ProtectPersonnelCsrf::class, fn ($app) => new class($app, $app['encrypter']) extends ProtectPersonnelCsrf
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->postJson('/commercial/transfers', $payload)->assertStatus(419);
        $this->assertDatabaseCount('commercial_transfer_documents', 0);
    }

    private function user(array $permissions = []): User
    {
        PermissionCatalog::syncToDatabase();
        $u = User::factory()->create(['is_active' => true, 'can_access_erp' => true]);
        foreach ($permissions as $key) {
            $u->permissions()->attach(AccessPermission::where('key', $key)->value('id'));
        }

        return $u;
    }

    private function setupTransfer(): array
    {
        $actor = $this->user(['commercial.customers.transfer']);
        $a = $this->user();
        $b = $this->user();
        $c = Customer::factory()->create();
        $c->assignment()->create(['seller_id' => $a->id]);
        $this->actingAs($actor);
        $found = $this->getJson('/commercial/transfers/search?kind=customer&from_id='.$a->id)->assertOk()->json('data.0');

        return [$actor, $a, $b, $c, ['submission_key' => (string) Str::uuid(), 'kind' => 'customer', 'from_id' => $a->id, 'to_id' => $b->id, 'reason' => 'تقسیم مشتریان', 'action' => 'register', 'items' => [['id' => $c->id, 'token' => $found['token']]]]];
    }

    public function test_direct_customer_document_is_atomic_idempotent_and_reversible(): void
    {
        [$actor,$a,$b,$c,$payload] = $this->setupTransfer();
        $before = DB::table('customer_ledgers')->get()->toJson();
        $result = $this->postJson('/commercial/transfers', $payload)->assertCreated()->json();
        $this->assertSame(500001, $result['number']);
        $this->assertSame($b->id, $c->assignment()->first()->seller_id);
        $this->postJson('/commercial/transfers', $payload)->assertOk();
        $different = $payload;
        $different['reason'] = 'درخواست متفاوت با همان کلید';
        $this->postJson('/commercial/transfers', $different)->assertConflict();
        $this->assertDatabaseCount('commercial_transfer_documents', 1);
        $this->postJson('/commercial/transfers/'.$result['id'].'/cancel', ['version' => 1, 'reason' => 'اصلاح ثبت اشتباه'])->assertOk();
        $this->assertSame($a->id, $c->assignment()->first()->seller_id);
        $this->assertDatabaseHas('commercial_transfer_documents', ['id' => $result['id'], 'status' => 'cancelled']);
        $this->assertSame($before, DB::table('customer_ledgers')->get()->toJson());
    }

    public function test_draft_does_not_transfer_and_can_be_updated_registered_or_deleted(): void
    {
        [$actor,$a,$b,$c,$payload] = $this->setupTransfer();
        $payload['action'] = 'draft';
        $doc = $this->postJson('/commercial/transfers', $payload)->assertCreated()->json();
        $this->assertSame($a->id, $c->assignment()->first()->seller_id);
        $this->postJson('/commercial/transfers/'.$doc['id'].'/register', ['version' => 1])->assertOk();
        $this->assertSame($b->id, $c->assignment()->first()->seller_id);
        $this->deleteJson('/commercial/transfers/'.$doc['id'], ['version' => 2])->assertConflict();
    }

    public function test_stale_selection_rolls_back_all_items_and_denies_unrelated_permissions(): void
    {
        [$actor,$a,$b,$c,$payload] = $this->setupTransfer();
        $payload['items'][0]['token'] = 'stale';
        $this->postJson('/commercial/transfers', $payload)->assertConflict();
        $this->assertDatabaseCount('commercial_transfer_documents', 0);
        $this->assertSame($a->id, $c->assignment()->first()->seller_id);
        $this->actingAs($this->user(['customers.view']))->postJson('/commercial/transfers', $payload)->assertForbidden();
    }

    public function test_later_customer_transfer_blocks_cancellation_even_if_owner_returns(): void
    {
        [$actor,$a,$b,$c,$payload] = $this->setupTransfer();
        $doc = $this->postJson('/commercial/transfers', $payload)->assertCreated()->json();
        app(CustomerOwnership::class)->transfer($c, $a, $actor, 2, 'تغییر بعدی');
        app(CustomerOwnership::class)->transfer($c, $b, $actor, 3, 'برگشت بعدی');
        $this->postJson('/commercial/transfers/'.$doc['id'].'/cancel', ['version' => 1, 'reason' => 'لغو'])->assertConflict();
        $this->assertSame($b->id, $c->assignment()->first()->seller_id);
    }

    public function test_invoice_document_changes_seller_without_customer_ownership_or_totals(): void
    {
        $actor = $this->user();
        $actor->assignRole(Role::findOrCreate('admin', 'web'));
        $a = $this->user();
        $b = $this->user();
        $customer = Customer::factory()->create();
        $customer->assignment()->create(['seller_id' => $a->id]);
        $invoice = Invoice::create(['uuid' => '02618', 'customer_id' => $customer->id, 'seller_id' => $a->id, 'subtotal' => 10000, 'total' => 9000]);
        $this->actingAs($actor);
        $found = $this->getJson('/commercial/transfers/search?kind=invoice&from_id='.$a->id)->assertOk()->json('data.0');
        $doc = $this->postJson('/commercial/transfers', ['submission_key' => (string) Str::uuid(), 'kind' => 'invoice', 'from_id' => $a->id, 'to_id' => $b->id, 'reason' => 'اصلاح فروشنده', 'action' => 'register', 'items' => [['id' => $invoice->id, 'token' => $found['token']]]])->assertCreated()->json();
        $this->assertSame($b->id, $invoice->fresh()->seller_id);
        $this->assertSame(9000, $invoice->fresh()->total);
        $this->assertSame($a->id, $customer->assignment()->first()->seller_id);
        $this->postJson('/commercial/transfers/'.$doc['id'].'/cancel', ['version' => 1, 'reason' => 'لغو اشتباه'])->assertOk();
        $this->assertSame($a->id, $invoice->fresh()->seller_id);
    }
}
