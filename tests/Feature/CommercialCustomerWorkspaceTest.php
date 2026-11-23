<?php

namespace Tests\Feature;

use App\Models\AccessPermission;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\SalesReturnDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommercialCustomerWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_invoice_and_print_follow_customer_ownership_and_remain_readonly(): void
    {
        $old = $this->seller();
        $owner = $this->seller();
        $customer = Customer::factory()->create();
        $customer->assignment()->create(['seller_id' => $owner->id]);
        $other = Customer::factory()->create();
        $other->assignment()->create(['seller_id' => $owner->id]);
        $invoice = Invoice::create(['uuid' => 'FULL-0099', 'customer_id' => $customer->id, 'seller_id' => $old->id, 'subtotal' => 100, 'total' => 100]);
        $base = '/commercial/my-customers/'.$customer->id.'/documents/invoice/'.$invoice->id;
        $this->actingAs($owner)->get($base.'/view')->assertOk()->assertViewIs('invoices.show')->assertSee('FULL-0099')->assertDontSee('ویرایش فاکتور')->assertDontSee('افزودن پرداخت');
        $this->get($base.'/print')->assertOk()->assertViewIs('prints.invoice')->assertViewHas('printData', fn ($d) => $d['mode'] === 'customer' && str_contains($d['backUrl'], '/commercial/my-customers/'));
        foreach (['view', 'print'] as $action) {
            $this->get('/commercial/my-customers/'.$other->id.'/documents/invoice/'.$invoice->id.'/'.$action)->assertNotFound();
            $this->actingAs($old)->get($base.'/'.$action)->assertNotFound();
            $this->actingAs($owner);
        }
        $this->assertSame($old->id, $invoice->fresh()->seller_id);
    }

    public function test_full_return_and_print_are_scoped_and_ledger_reuses_finance_table(): void
    {
        $owner = $this->seller();
        $customer = Customer::factory()->create(['opening_balance' => 1000]);
        $customer->assignment()->create(['seller_id' => $owner->id]);
        $document = SalesReturnDocument::create(['document_number' => 'RET-FULL', 'source_type' => 'sazeh_hesab', 'status' => 'draft', 'customer_id' => $customer->id, 'total_refund_amount' => 200]);
        $base = '/commercial/my-customers/'.$customer->id.'/documents/return/'.$document->id;
        $this->actingAs($owner)->get($base.'/view')->assertOk()->assertViewIs('vouchers.return-from-sale.show')->assertSee('RET-FULL')->assertDontSee('لغو Draft')->assertDontSee('ارسال به صف انبار');
        $this->get($base.'/print')->assertOk()->assertViewIs('vouchers.return-from-sale.print');
        foreach (['pending_warehouse', 'applied', 'cancelled'] as $status) {
            $document->update(['status' => $status]);
            $this->get($base.'/view')->assertOk()->assertDontSee('name="cancel_reason"', false)->assertDontSee('name="document_version"', false)->assertDontSee('ثبت اصلاح قیمت');
        }
        CustomerLedger::create(['customer_id' => $customer->id, 'type' => 'credit', 'amount' => 200, 'note' => 'SHARED_LEDGER']);
        $this->get('/commercial/my-customers/'.$customer->id.'?tab=ledger')->assertOk()->assertSee('asd-table', false)->assertSee('شماره سند')->assertSee('مانده پس از سند')->assertSee('SHARED_LEDGER')->assertDontSee('افزودن پرداخت');
        $this->actingAs($this->seller())->get($base.'/print')->assertNotFound();
    }

    public function test_returns_summary_ignores_pending_cancelled_and_never_exposes_purchase_cost(): void
    {
        $seller = $this->seller();
        $customer = Customer::factory()->create();
        $customer->assignment()->create(['seller_id' => $seller->id]);
        $warehouse = Warehouse::create(['name' => 'TEST_RETURN_WAREHOUSE', 'type' => 'return', 'is_active' => true]);
        foreach (['applied' => 100, 'pending_warehouse' => 200, 'cancelled' => 300] as $status => $amount) {
            $document = SalesReturnDocument::create(['document_number' => 'RET-'.$status, 'source_type' => 'sazeh_hesab', 'status' => $status, 'customer_id' => $customer->id, 'total_refund_amount' => $amount]);
            $document->items()->create(['destination_warehouse_id' => $warehouse->id, 'item_source' => 'new_product', 'item_condition' => 'damaged', 'product_name_snapshot' => 'SAFE_RETURN_NAME', 'return_quantity' => 1, 'refund_unit_price' => $amount, 'refund_amount' => $amount, 'purchase_price' => 987654321]);
        }
        $this->actingAs($seller)->get('/commercial/my-customers/'.$customer->id)->assertOk()->assertViewHas('stats', fn ($s) => $s['returns'] === 3 && $s['returned_amount'] === 100);
        $this->get('/commercial/my-customers/'.$customer->id.'?tab=returns', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertSee('در انتظار انبار')->assertSee('لغوشده')->assertDontSee('<html', false);
        $this->get('/commercial/my-customers/'.$customer->id.'/documents/return/'.$document->id)->assertOk()->assertSee('SAFE_RETURN_NAME')->assertDontSee('987654321')->assertDontSee('987,654,321');
    }

    public function test_ledger_second_page_preserves_running_balance_and_filters_are_bounded(): void
    {
        $seller = $this->seller();
        $customer = Customer::factory()->create(['opening_balance' => 1000]);
        $customer->assignment()->create(['seller_id' => $seller->id]);
        for ($i = 1; $i <= 17; $i++) {
            CustomerLedger::create(['customer_id' => $customer->id, 'type' => 'debit', 'amount' => 10]);
        }
        $response = $this->actingAs($seller)->get('/commercial/my-customers/'.$customer->id.'?tab=ledger&page=2')->assertOk();
        $rows = $response->viewData('records');
        $meta = $response->viewData('meta');
        $this->assertCount(2, $rows);
        $this->assertSame(1020, $meta['rowBalances'][$rows->first()->id]);
        $this->assertSame(1010, $meta['rowBalances'][$rows->last()->id]);
        $this->getJson('/commercial/my-customers/'.$customer->id.'?tab=bad')->assertUnprocessable();
    }

    public function test_explicit_broad_reader_can_see_unassigned_while_inactive_customers_are_opt_in(): void
    {
        $seller = $this->seller();
        $seller->permissions()->attach(AccessPermission::where('key', 'commercial.customers.sell_any')->value('id'));
        $active = Customer::factory()->create(['is_active' => true, 'name' => 'ACTIVE_UNASSIGNED']);
        $inactive = Customer::factory()->create(['is_active' => false, 'name' => 'INACTIVE_CUSTOMER']);
        $this->actingAs($seller)->get('/commercial/my-customers')->assertOk()->assertSee('ACTIVE_UNASSIGNED')->assertDontSee('INACTIVE_CUSTOMER');
        $this->get('/commercial/my-customers?status=inactive&seller_id=0')->assertOk()->assertSee('INACTIVE_CUSTOMER')->assertDontSee('ACTIVE_UNASSIGNED');
        $this->get('/commercial/my-customers/'.$inactive->id)->assertOk();
    }

    private function seller(): User
    {
        PermissionCatalog::syncToDatabase();
        $user = User::factory()->create(['is_active' => true, 'can_access_erp' => true]);
        $user->permissions()->attach(AccessPermission::where('key', 'preinvoices.own.view')->value('id'));

        return $user;
    }

    public function test_customer_list_and_direct_profile_are_scoped_to_current_assignment(): void
    {
        $a = $this->seller();
        $b = $this->seller();
        $own = Customer::factory()->create(['name' => 'OWN_CUSTOMER', 'is_active' => true]);
        $other = Customer::factory()->create(['name' => 'PRIVATE_OTHER', 'is_active' => true]);
        $own->assignment()->create(['seller_id' => $a->id]);
        $other->assignment()->create(['seller_id' => $b->id]);
        $this->actingAs($a)->get('/commercial/my-customers')->assertOk()->assertSee('OWN_CUSTOMER')->assertDontSee('PRIVATE_OTHER');
        $this->get('/commercial/my-customers/'.$own->id)->assertOk()->assertSee('OWN_CUSTOMER');
        $this->get('/commercial/my-customers/'.$other->id)->assertNotFound();
        $this->get('/commercial/my-customers/'.$other->id.'?tab=ledger')->assertNotFound();
        $this->get('/commercial/my-customers?seller_id='.$b->id)->assertForbidden();
    }

    public function test_profile_balance_uses_effective_ledger_and_reading_does_not_write(): void
    {
        $seller = $this->seller();
        $customer = Customer::factory()->create(['opening_balance' => 100, 'is_active' => true]);
        $customer->assignment()->create(['seller_id' => $seller->id]);
        $invoice = Invoice::create(['uuid' => 'READ-001', 'customer_id' => $customer->id, 'seller_id' => $seller->id, 'subtotal' => 900, 'total' => 900, 'status' => 'shipped']);
        CustomerLedger::create(['customer_id' => $customer->id, 'type' => 'debit', 'amount' => 900, 'reference_type' => Invoice::class, 'reference_id' => $invoice->id]);
        CustomerLedger::create(['customer_id' => $customer->id, 'type' => 'credit', 'amount' => 250, 'note' => 'READ_PAYMENT']);
        $cancelled = Invoice::create(['uuid' => 'CANCELLED-001', 'customer_id' => $customer->id, 'subtotal' => 5000, 'total' => 5000, 'status' => Invoice::cancelledStatuses()[0]]);
        CustomerLedger::create(['customer_id' => $customer->id, 'type' => 'debit', 'amount' => 5000, 'reference_type' => Invoice::class, 'reference_id' => $cancelled->id]);
        $tables = ['customers', 'customer_assignments', 'customer_ledgers', 'invoices', 'warehouse_stocks'];
        $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->toJson()])->all();
        $this->actingAs($seller)->get('/commercial/my-customers/'.$customer->id)->assertOk()->assertViewHas('customer', fn ($c) => $c->balance === 750);
        $this->get('/commercial/my-customers/'.$customer->id.'?tab=ledger')->assertOk()->assertSee('READ_PAYMENT');
        $this->assertSame($before, collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->toJson()])->all());
    }

    public function test_historical_invoice_is_readonly_for_new_customer_owner_and_cannot_be_cross_linked(): void
    {
        $old = $this->seller();
        $new = $this->seller();
        $c = Customer::factory()->create();
        $c->assignment()->create(['seller_id' => $new->id]);
        $other = Customer::factory()->create();
        $other->assignment()->create(['seller_id' => $new->id]);
        $invoice = Invoice::create(['uuid' => 'HISTORY-0099', 'customer_id' => $c->id, 'seller_id' => $old->id, 'subtotal' => 100, 'total' => 100]);
        $url = '/commercial/my-customers/'.$c->id.'/documents/invoice/'.$invoice->id;
        $this->actingAs($new)->get($url)->assertOk()->assertSee('HISTORY-0099')->assertDontSee('ثبت پرداخت')->assertDontSee('ویرایش فاکتور');
        $this->get('/commercial/my-customers/'.$other->id.'/documents/invoice/'.$invoice->id)->assertNotFound();
        $this->actingAs($old)->get($url)->assertNotFound();
        $this->assertSame($old->id, $invoice->fresh()->seller_id);
    }

    public function test_search_normalizes_persian_arabic_and_country_prefix_and_filters_balances(): void
    {
        $seller = $this->seller();
        $c = Customer::factory()->create(['name' => 'علي كريمي', 'mobile' => '09123456789', 'opening_balance' => -120, 'is_active' => true]);
        $c->assignment()->create(['seller_id' => $seller->id]);
        $this->actingAs($seller)->get('/commercial/my-customers?q='.urlencode('علی کریمی').'&balance=credit')->assertOk()->assertSee($c->mobile);
        $this->get('/commercial/my-customers?q='.urlencode('+۹۸۹۱۲۳۴۵۶۷۸۹'))->assertOk()->assertSee($c->mobile);
        $this->get('/commercial/my-customers?balance=debt')->assertOk()->assertDontSee($c->mobile);
        $this->getJson('/commercial/my-customers?sort=bad')->assertUnprocessable();
    }

    public function test_ajax_pagination_is_bounded_and_customer_text_is_escaped(): void
    {
        $seller = $this->seller();
        Customer::factory()->count(14)->create(['is_active' => true])->each(fn ($c) => $c->assignment()->create(['seller_id' => $seller->id]));
        $c = Customer::factory()->create(['name' => '<script>alert("x")</script>', 'is_active' => true]);
        $c->assignment()->create(['seller_id' => $seller->id]);
        $this->actingAs($seller)->get('/commercial/my-customers', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertViewHas('customers', fn ($p) => $p->count() === 12 && $p->total() === 15)->assertDontSee('<script>alert("x")</script>', false)->assertSee('&lt;script&gt;', false);
        $this->get('/commercial/my-customers?page=2', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertViewHas('customers', fn ($p) => $p->count() === 3);
    }
}
