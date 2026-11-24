<?php

namespace Tests\Feature;

use App\Http\Middleware\RoutePermissionMiddleware;
use App\Models\AccessPermission;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\User;
use App\Services\CustomerOwnership;
use App\Services\SalesDocumentSellerReassignmentService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommercialCustomerOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function actor(array $permissions = []): User
    {
        PermissionCatalog::syncToDatabase();
        $u = User::factory()->create(['is_active' => true, 'can_access_erp' => true]);
        foreach ($permissions as $key) {
            $u->permissions()->attach(AccessPermission::where('key', $key)->value('id'));
        }

        return $u;
    }

    public function test_transfer_is_scoped_versioned_audited_and_does_not_change_existing_documents(): void
    {
        $manager = $this->actor(['commercial.customers.transfer']);
        $a = $this->actor(['preinvoices.create']);
        $b = $this->actor(['preinvoices.create']);
        $customer = Customer::factory()->create();
        $order = PreinvoiceOrder::create(['uuid' => (string) Str::uuid(), 'created_by' => $a->id, 'seller_id' => $a->id, 'customer_id' => $customer->id, 'customer_name' => 'Existing', 'customer_mobile' => $customer->mobile, 'status' => 'draft', 'total_price' => 1200]);
        $invoice = Invoice::create(['uuid' => (string) Str::uuid(), 'preinvoice_order_id' => $order->id, 'customer_id' => $customer->id, 'seller_id' => $a->id, 'subtotal' => 1200, 'total' => 1200]);
        $before = collect(['invoices', 'preinvoice_orders', 'customer_ledgers', 'warehouse_stocks'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->toJson()])->all();
        $this->actingAs($manager)->postJson('/commercial/customer-transfers/'.$customer->id, ['seller_id' => $a->id, 'version' => 0, 'reason' => 'تخصیص اولیه'])->assertOk();
        $this->actingAs($a)->get('/commercial/my-customers')->assertOk()->assertSee($customer->mobile);
        $this->actingAs($b)->get('/commercial/my-customers')->assertOk()->assertDontSee($customer->mobile);
        $this->actingAs($a)->postJson('/commercial/customer-transfers/'.$customer->id, ['seller_id' => $b->id, 'version' => 1, 'reason' => 'غیرمجاز'])->assertForbidden();
        $this->actingAs($manager)->postJson('/commercial/customer-transfers/'.$customer->id, ['seller_id' => $b->id, 'version' => 1, 'reason' => 'انتقال پیگیری'])->assertOk();
        $this->postJson('/commercial/customer-transfers/'.$customer->id, ['seller_id' => $a->id, 'version' => 1, 'reason' => 'نسخه قدیمی'])->assertConflict();
        $this->assertDatabaseHas('customer_assignments', ['customer_id' => $customer->id, 'seller_id' => $b->id, 'version' => 2]);
        $this->assertSame(2, DB::table('activity_logs')->where('action', 'customer_seller_transferred')->count());
        foreach ($before as $t => $json) {
            $this->assertSame($json, DB::table($t)->get()->toJson());
        }
        $this->actingAs($a)->get('/commercial/my-customers')->assertDontSee($customer->mobile);
    }

    public function test_customer_search_and_direct_lookup_only_expose_assigned_customers(): void
    {
        $a = $this->actor(['preinvoices.create', 'customers.view']);
        $b = $this->actor();
        $own = Customer::factory()->create(['first_name' => 'آزمایش خودی']);
        $other = Customer::factory()->create(['first_name' => 'آزمایش دیگر']);
        $own->assignment()->create(['seller_id' => $a->id]);
        $other->assignment()->create(['seller_id' => $b->id]);
        $this->actingAs($a)->getJson(route('api.customers.search', ['q' => 'آزمایش']))->assertOk()->assertSee($own->mobile)->assertDontSee($other->mobile);
        $this->getJson(route('api.customers.show', $other))->assertUnprocessable();
    }

    public function test_sale_guard_rejects_unassigned_other_owner_and_missing_customer(): void
    {
        $a = $this->actor();
        $b = $this->actor();
        $customer = Customer::factory()->create();
        $service = app(CustomerOwnership::class);
        foreach ([null, $customer] as $value) {
            try {
                $service->assertCanSell($value, $a);
                $this->fail('Unassigned customer accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('customer_id', $e->errors());
            }
        }
        $customer->assignment()->create(['seller_id' => $b->id]);
        try {
            $service->assertCanSell($customer, $a);
            $this->fail('Other owner accepted');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $service->assertCanSell($customer, $b);
        $this->assertTrue(true);
        $b->update(['is_active' => false]);
        $this->actingAs($b)->get('/commercial/my-customers')->assertRedirect();
    }

    public function test_actual_autosave_rejects_another_sellers_customer_before_writing(): void
    {
        require_once __DIR__.'/../Support/LargePayloadFixtures.php';
        $a = $this->actor(['preinvoices.create']);
        $b = $this->actor();
        $customer = Customer::factory()->create();
        $customer->assignment()->create(['seller_id' => $b->id]);
        $rows = largePayloadProducts(1);
        $payload = ['reservation_token' => (string) Str::uuid(), 'customer_id' => $customer->id, 'customer_name' => $customer->display_name, 'customer_mobile' => $customer->mobile, 'products' => $rows];
        $this->withoutMiddleware(RoutePermissionMiddleware::class);
        $this->actingAs($a)->postJson(route('preinvoice.autosave'), $payload)->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->assertDatabaseCount('preinvoice_orders', 0);
        $customer->assignment()->update(['seller_id' => $a->id]);
        $this->postJson(route('preinvoice.autosave'), $payload)->assertOk();
        $this->assertDatabaseHas('preinvoice_orders', ['customer_id' => $customer->id, 'created_by' => $a->id]);
    }

    public function test_invoice_transfer_does_not_transfer_the_customer(): void
    {
        $a = $this->actor();
        $b = $this->actor();
        $customer = Customer::factory()->create();
        $customer->assignment()->create(['seller_id' => $a->id]);
        $invoice = Invoice::create(['uuid' => (string) Str::uuid(), 'customer_id' => $customer->id, 'seller_id' => $a->id, 'subtotal' => 1200, 'total' => 1200]);
        app(SalesDocumentSellerReassignmentService::class)->reassignInvoiceSeller($invoice, $b, $a, 'اصلاح فروشنده فاکتور');
        $this->assertSame($b->id, $invoice->fresh()->seller_id);
        $this->assertSame($a->id, $customer->assignment()->first()->seller_id);
    }

    public function test_final_submission_cannot_use_a_free_text_customer_to_bypass_ownership(): void
    {
        require_once __DIR__.'/../Support/LargePayloadFixtures.php';
        $a = $this->actor(['preinvoices.create']);
        $rows = largePayloadProducts(1);
        $this->withoutMiddleware(RoutePermissionMiddleware::class);
        $this->actingAs($a)->postJson(route('preinvoice.draft.save'), largePayloadPost($rows))->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->assertDatabaseCount('preinvoice_orders', 0);
    }

    public function test_new_customer_is_assigned_to_creator_and_broad_sales_requires_explicit_permission(): void
    {
        $seller = $this->actor(['preinvoices.create', 'customers.create', 'customers.view']);
        $this->actingAs($seller)->postJson(route('api.customers.store'), ['customer_name' => 'مشتری آزمایشی جدید', 'mobile' => '09123456789'])->assertCreated();
        $customer = Customer::where('mobile', '09123456789')->firstOrFail();
        $this->assertDatabaseHas('customer_assignments', ['customer_id' => $customer->id, 'seller_id' => $seller->id]);
        $other = $this->actor(['preinvoices.create', 'customers.view', 'commercial.customers.sell_any']);
        $this->actingAs($other)->getJson(route('api.customers.show', $customer))->assertOk();
        app(CustomerOwnership::class)->assertCanSell($customer, $other);
        $this->assertTrue(true);
    }
}
