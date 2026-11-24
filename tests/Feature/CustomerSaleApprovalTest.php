<?php

namespace Tests\Feature;

use App\Models\{AccessPermission, Customer, CustomerSaleApproval, PreinvoiceOrder, User};
use App\Services\CustomerSaleApprovalMessenger;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerSaleApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function seller(): User
    {
        PermissionCatalog::syncToDatabase();
        $user = User::factory()->create(['is_active' => true, 'can_access_erp' => true, 'is_seller' => true]);
        $user->permissions()->attach(AccessPermission::whereIn('key', ['preinvoices.create', 'page.sales.preinvoices'])->pluck('id'));
        return $user;
    }

    private function scenario(): array
    {
        $requester = $this->seller();
        $owner = $this->seller();
        $customer = Customer::factory()->create(['crm_customer_id' => '70099']);
        $customer->assignment()->create(['seller_id' => $owner->id]);
        $context = (string) Str::uuid();
        return compact('requester', 'owner', 'customer', 'context');
    }

    private function issue(array $s): array
    {
        $code = null;
        $this->mock(CustomerSaleApprovalMessenger::class)->shouldReceive('send')->once()->withArgs(function ($owner, $requester, $customer, $sent) use ($s, &$code) {
            $this->assertSame($s['owner']->id, $owner->id);
            $this->assertMatchesRegularExpression('/^[1-9][0-9]{3}$/', $sent);
            $code = $sent;
            return true;
        });
        $response = $this->actingAs($s['requester'])->postJson('/commercial/sale-approvals/request', ['customer_id' => $s['customer']->id, 'context' => $s['context']])->assertOk();
        $response->assertDontSee($code);
        return [$response->json('id'), $code];
    }

    public function test_main_search_discovers_other_owners_by_name_phone_and_code_without_financial_fields(): void
    {
        $s = $this->scenario();
        $s['customer']->update(['first_name' => 'ForeignUniqueName', 'last_name' => null]);
        $this->actingAs($s['requester']);
        foreach (['ForeignUniqueName', $s['customer']->mobile] as $term) {
            $this->getJson('/preinvoice/api/customers?q='.urlencode($term))->assertOk()->assertJsonPath('data.customers.0.id', $s['customer']->id)->assertJsonPath('data.customers.0.requires_approval', true);
        }
        $response = $this->getJson('/preinvoice/api/customers?q=70099')->assertOk()
            ->assertJsonPath('data.customers.0.id', $s['customer']->id)
            ->assertJsonPath('data.customers.0.requires_approval', true)
            ->assertJsonPath('data.customers.0.can_request', true);
        foreach (['address', 'balance', 'debt', 'credit'] as $field) {
            $this->assertArrayNotHasKey($field, $response->json('data.customers.0'));
        }
        $this->getJson('/preinvoice/api/customers?q='.urlencode('۷۰۰۹۹'))->assertOk()->assertJsonPath('data.customers.0.id', $s['customer']->id);
        $this->getJson('/preinvoice/api/customers/'.$s['customer']->id)->assertUnprocessable();
        $this->getJson('/preinvoice/api/customers?q[]=70099')->assertUnprocessable();
        $own = Customer::factory()->create(['crm_customer_id' => '80099']);
        $own->assignment()->create(['seller_id' => $s['requester']->id]);
        $this->getJson('/preinvoice/api/customers?q=80099')->assertOk()->assertJsonPath('data.customers.0.id', $own->id)->assertJsonPath('data.customers.0.mobile', $own->mobile);
        $s['customer']->update(['is_active' => false]);
        $this->getJson('/preinvoice/api/customers?q=70099')->assertOk()->assertJsonCount(0, 'data.customers');
    }

    public function test_sale_sms_uses_existing_code_pattern_when_custom_pattern_and_sender_are_missing(): void
    {
        $s = $this->scenario();
        config(['sms.default' => 'melipayamak', 'sales-approval.sms_pattern' => '', 'services.melipayamak.body_id' => '12345', 'services.melipayamak.from' => '']);
        $sms = $this->mock(\App\Services\Sms\SmsManager::class);
        $sms->shouldReceive('sendPattern')->once()->with($s['owner']->phone, '12345', ['1234']);
        $sms->shouldNotReceive('send');
        app(CustomerSaleApprovalMessenger::class)->send($s['owner'], $s['requester'], $s['customer'], '1234');
    }

    public function test_own_customer_responsible_seller_is_returned_and_unassigned_customer_needs_transfer(): void
    {
        $actor = $this->seller();
        $own = Customer::factory()->create(['crm_customer_id' => '4321']);
        $own->assignment()->create(['seller_id' => $actor->id]);
        $this->actingAs($actor)->getJson('/preinvoice/api/customers?q=4321')->assertOk()
            ->assertJsonPath('data.customers.0.owner_name', $actor->name);
        $this->getJson('/preinvoice/api/customers/'.$own->id)->assertOk()
            ->assertJsonPath('data.customer.owner_name', $actor->name);
        $unassigned = Customer::factory()->create(['crm_customer_id' => '4322']);
        $this->getJson('/preinvoice/api/customers?q=4322')->assertOk()
            ->assertJsonPath('data.customers.0.can_request', false);
        $this->getJson('/preinvoice/api/customers/'.$unassigned->id)->assertUnprocessable();
        $this->postJson('/commercial/sale-approvals/request', ['customer_id' => $unassigned->id, 'context' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_exact_discovery_is_minimal_and_unverified_customer_cannot_be_saved(): void
    {
        $s = $this->scenario();
        $this->actingAs($s['requester'])->postJson('/commercial/sale-approvals/lookup', ['code' => '70099'])->assertOk()->assertJsonPath('customer.id', $s['customer']->id)->assertJsonPath('requires_approval', true)->assertDontSee($s['customer']->mobile)->assertDontSee('balance');
        require_once __DIR__.'/../Support/LargePayloadFixtures.php';
        $this->postJson(route('preinvoice.autosave'), ['customer_id' => $s['customer']->id, 'reservation_token' => (string) Str::uuid(), 'sale_authorization_context' => $s['context'], 'customer_name' => 'Other', 'customer_mobile' => '09120000000', 'products' => largePayloadProducts(1)])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->assertDatabaseCount('preinvoice_orders', 0);
    }

    public function test_approval_is_hashed_scoped_to_one_document_and_never_transfers_ownership(): void
    {
        $s = $this->scenario();
        $s['requester']->update(['is_seller' => false]);
        [$id, $code] = $this->issue($s);
        $this->assertNotSame($code, CustomerSaleApproval::findOrFail($id)->code_hash);
        $this->postJson('/commercial/sale-approvals/'.$id.'/verify', ['context' => $s['context'], 'code' => $code])->assertOk();
        require_once __DIR__.'/../Support/LargePayloadFixtures.php';
        $payload = ['customer_id' => $s['customer']->id, 'reservation_token' => (string) Str::uuid(), 'sale_authorization_context' => $s['context'], 'customer_name' => 'Approved', 'customer_mobile' => '09120000000', 'products' => largePayloadProducts(1)];
        $saved = $this->postJson(route('preinvoice.autosave'), $payload)->assertOk()->json();
        $order = PreinvoiceOrder::firstOrFail();
        $this->assertSame($s['requester']->id, $order->seller_id);
        $this->assertSame($order->id, CustomerSaleApproval::find($id)->preinvoice_order_id);
        $this->assertSame($s['owner']->id, $s['customer']->assignment()->first()->seller_id);
        $submitted = largePayloadPost($payload['products'], [
            'autosave_uuid' => $saved['uuid'], 'reservation_token' => $payload['reservation_token'],
            'sale_authorization_context' => $s['context'], 'customer_id' => $s['customer']->id,
        ]);
        $this->post(route('preinvoice.draft.save'), $submitted)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(PreinvoiceOrder::STATUS_PENDING_FINANCE, $order->fresh()->status);
        $this->assertNotNull(CustomerSaleApproval::find($id)->submitted_at);
        $finance = $this->seller();
        $finance->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Admin', 'web'));
        $this->actingAs($finance)->post(route('preinvoice.draft.finalize', $order->uuid), [
            'finance_version' => \App\Support\FinanceDocumentVersion::token($order->fresh('items')),
        ])->assertSessionHasNoErrors();
        $invoice = \App\Models\Invoice::where('preinvoice_order_id', $order->id)->firstOrFail();
        $this->assertSame($s['requester']->id, $invoice->seller_id);
        $this->assertSame($s['customer']->id, $invoice->customer_id);
        $this->actingAs($s['requester']);
        $payload['reservation_token'] = (string) Str::uuid();
        $this->postJson(route('preinvoice.autosave'), $payload)->assertUnprocessable();
        $this->assertDatabaseCount('preinvoice_orders', 1);
        $this->assertDatabaseHas('activity_logs', ['action' => 'customer_sale_approval_bound']);
    }

    public function test_deleted_draft_does_not_release_approval_and_bound_document_can_be_reopened(): void
    {
        $s = $this->scenario(); [$id, $code] = $this->issue($s);
        $this->postJson('/commercial/sale-approvals/'.$id.'/verify', ['context' => $s['context'], 'code' => $code])->assertOk();
        $order = PreinvoiceOrder::create(['uuid' => 'BOUND-ONLY', 'customer_name' => 'Bound customer', 'customer_mobile' => '09120000000', 'total_price' => 0, 'customer_id' => $s['customer']->id, 'created_by' => $s['requester']->id, 'seller_id' => $s['requester']->id, 'status' => 'draft']);
        \Illuminate\Support\Facades\DB::transaction(fn () => app(\App\Services\CustomerSaleAuthorization::class)->bind($order, $s['requester'], $s['context']));
        $this->travel(20)->minutes();
        $this->getJson('/preinvoice/api/customers/'.$s['customer']->id.'?draft_uuid='.$order->uuid)->assertOk();
        $order->delete();
        $this->assertNull(CustomerSaleApproval::find($id)->preinvoice_order_id);
        $this->getJson('/preinvoice/api/customers/'.$s['customer']->id.'?sale_authorization_context='.$s['context'])->assertUnprocessable();
    }

    public function test_owner_phone_change_inactive_owner_and_non_sales_account_cannot_authorize(): void
    {
        $s = $this->scenario(); [$id, $code] = $this->issue($s);
        $s['owner']->update(['phone' => '09123450000']);
        $this->postJson('/commercial/sale-approvals/'.$id.'/verify', ['context' => $s['context'], 'code' => $code])->assertUnprocessable();
        $s['owner']->update(['is_active' => false]);
        $this->travel(2)->minutes();
        $this->postJson('/commercial/sale-approvals/request', ['customer_id' => $s['customer']->id, 'context' => $s['context']])->assertUnprocessable();
        $this->actingAs(User::factory()->create(['is_active' => true, 'can_access_erp' => true]))->postJson('/commercial/sale-approvals/lookup', ['code' => '70099'])->assertForbidden();
    }

    public function test_wrong_codes_lock_persistently_and_old_context_or_another_user_cannot_verify(): void
    {
        $s = $this->scenario(); [$id, $code] = $this->issue($s);
        $this->postJson('/commercial/sale-approvals/'.$id.'/verify', ['context' => (string) Str::uuid(), 'code' => $code])->assertNotFound();
        $this->actingAs($this->seller())->postJson('/commercial/sale-approvals/'.$id.'/verify', ['context' => $s['context'], 'code' => $code])->assertNotFound();
        $this->actingAs($s['requester']);
        for ($i = 0; $i < 5; $i++) $this->postJson('/commercial/sale-approvals/'.$id.'/verify', ['context' => $s['context'], 'code' => '0000'])->assertUnprocessable();
        $this->assertSame(5, CustomerSaleApproval::find($id)->attempts);
        $this->postJson('/commercial/sale-approvals/'.$id.'/verify', ['context' => $s['context'], 'code' => $code])->assertUnprocessable();
    }

    public function test_expiry_and_owner_transfer_revoke_authorization_and_resend_is_limited(): void
    {
        $s = $this->scenario(); [$id, $code] = $this->issue($s);
        $this->postJson('/commercial/sale-approvals/request', ['customer_id' => $s['customer']->id, 'context' => $s['context']])->assertStatus(429);
        $this->travel(6)->minutes();
        $this->postJson('/commercial/sale-approvals/'.$id.'/verify', ['context' => $s['context'], 'code' => $code])->assertUnprocessable();
        CustomerSaleApproval::find($id)->update(['verified_at' => now(), 'approval_expires_at' => now()->addMinutes(15)]);
        $s['customer']->assignment()->update(['version' => 2]);
        $this->getJson('/preinvoice/api/customers/'.$s['customer']->id.'?sale_authorization_context='.$s['context'])->assertUnprocessable();
    }

    public function test_delivery_failure_creates_no_grant_and_malformed_or_html_verification_never_flashes_code(): void
    {
        $s = $this->scenario();
        $this->mock(CustomerSaleApprovalMessenger::class)->shouldReceive('send')->once()->andThrow(new \RuntimeException('provider failed'));
        $this->actingAs($s['requester'])->postJson('/commercial/sale-approvals/request', ['customer_id' => $s['customer']->id, 'context' => $s['context']])->assertStatus(503)->assertDontSee('provider failed');
        $this->assertDatabaseCount('customer_sale_approvals', 0);
        $this->postJson('/commercial/sale-approvals/lookup', ['code' => ['70099']])->assertUnprocessable();
        $this->post('/commercial/sale-approvals/'.Str::uuid().'/verify', ['context' => $s['context'], 'code' => 'SECRET'])->assertUnprocessable()->assertSessionMissing('_old_input.code');
    }

    public function test_verified_unbound_grant_expires_and_revoked_sales_access_is_respected(): void
    {
        $s = $this->scenario(); [$id, $code] = $this->issue($s);
        $this->postJson('/commercial/sale-approvals/'.$id.'/verify', ['context' => $s['context'], 'code' => $code])->assertOk();
        $this->getJson('/preinvoice/api/customers/'.$s['customer']->id.'?sale_authorization_context='.$s['context'])->assertOk();
        $s['requester']->permissions()->detach(AccessPermission::whereIn('key', ['page.sales.preinvoices', 'preinvoices.create'])->pluck('id'));
        $s['requester']->unsetRelation('permissions');
        $s['requester']->permissions()->attach(AccessPermission::where('key', 'page.customers')->value('id'));
        $this->actingAs($s['requester']->fresh());
        $this->getJson('/preinvoice/api/customers/'.$s['customer']->id.'?sale_authorization_context='.$s['context'])->assertForbidden();
        $s['requester']->permissions()->attach(AccessPermission::where('key', 'page.sales.preinvoices')->value('id'));
        $s['requester']->unsetRelation('permissions');
        $this->travel(16)->minutes();
        $this->actingAs($s['requester']->fresh());
        $this->getJson('/preinvoice/api/customers/'.$s['customer']->id.'?sale_authorization_context='.$s['context'])->assertUnprocessable();
    }
}
