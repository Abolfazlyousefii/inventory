<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\Sync\SiteCustomersSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        $role = Role::findOrCreate('customer-picker-test', 'web');
        $role->givePermissionTo(\Spatie\Permission\Models\Permission::where('key', 'page.sales.preinvoices')->firstOrFail());
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);
    }

    public function test_searches_local_identity_fields_and_all_digit_scripts(): void
    {
        $customer = Customer::factory()->create(['first_name' => 'علی', 'last_name' => 'رضایی', 'mobile' => '09123456789', 'crm_customer_id' => '123456']);
        foreach (['علی', 'رضایی', 'علی رضایی', '09123456789', '۰۹۱۲۳۴۵۶۷۸۹', '٠٩١٢٣٤٥٦٧٨٩', '123456', '۱۲۳۴۵۶', '0'] as $term) {
            $this->getJson(route('api.customers.search', ['q' => $term]))->assertOk()->assertJsonPath('data.customers.0.id', $customer->id);
        }
    }

    public function test_empty_and_matching_queries_are_bounded_and_recent_first(): void
    {
        $customers = Customer::factory()->count(25)->create(['first_name' => 'مشترک']);
        $deleted = Customer::factory()->create(['first_name' => 'مشترک']);
        $deleted->delete();
        foreach (['', 'مشترک'] as $term) {
            $this->getJson(route('api.customers.search', ['q' => $term]))->assertOk()->assertJsonCount(20, 'data.customers')->assertJsonPath('data.customers.0.id', $customers->last()->id);
        }
    }

    public function test_wildcards_and_escape_character_are_literal(): void
    {
        $customer = Customer::factory()->create(['first_name' => 'literal%_!\\value']);
        Customer::factory()->create(['first_name' => 'unrelated']);
        foreach (['%', '_', '!', '\\'] as $term) {
            $this->getJson(route('api.customers.search', ['q' => $term]))->assertOk()->assertJsonCount(1, 'data.customers')->assertJsonPath('data.customers.0.id', $customer->id);
        }
    }

    public function test_search_is_independent_of_site_outage_and_ledgers(): void
    {
        $customer = Customer::factory()->create(['first_name' => 'LocalOnly']);
        Http::fake(['*' => Http::response('Gateway Timeout', 504)]);
        $this->app->bind(SiteCustomersSyncService::class, fn () => throw new \RuntimeException('Search must not resolve site sync'));
        DB::enableQueryLog();
        $response = $this->getJson(route('api.customers.search', ['q' => 'LocalOnly']));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $response->assertOk()->assertJsonPath('data.customers.0.id', $customer->id);
        $this->assertEqualsCanonicalizing(['id', 'name', 'first_name', 'last_name', 'mobile', 'crm_customer_id'], array_keys($response->json('data.customers.0')));
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('customer_ledgers', $query['query']);
        }
        Http::assertNothingSent();
    }

    public function test_show_returns_complete_selected_customer_and_balance(): void
    {
        $customer = Customer::factory()->create(['opening_balance' => 100, 'reservation_tier' => 'vip', 'address' => 'تهران', 'postal_code' => '1234567890']);
        $customer->ledgers()->create(['type' => 'debit', 'amount' => 300]);
        $customer->ledgers()->create(['type' => 'credit', 'amount' => 50]);
        $this->getJson(route('api.customers.show', $customer))->assertOk()
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.customer.name', $customer->display_name)
            ->assertJsonPath('data.customer.mobile', $customer->mobile)
            ->assertJsonPath('data.customer.address', 'تهران')
            ->assertJsonPath('data.customer.balance', 350)
            ->assertJsonPath('data.customer.debt', 350)
            ->assertJsonPath('data.customer.credit', 0)
            ->assertJsonPath('data.customer.reservation_tier', 'vip')
            ->assertJsonStructure(['data' => ['customer' => ['reservation_duration_label', 'reservation_tier_label', 'first_name', 'last_name', 'postal_code', 'extra_description', 'province_id', 'city_id']]]);
        $customer->ledgers()->create(['type' => 'credit', 'amount' => 500]);
        $this->getJson(route('api.customers.show', $customer))->assertOk()->assertJsonPath('data.customer.balance', -150)->assertJsonPath('data.customer.credit', 150)->assertJsonPath('data.customer.debt', 0);
    }

    public function test_old_customer_is_rendered_for_preloading(): void
    {
        $customer = Customer::factory()->create();
        $this->withSession(['_old_input' => ['customer_id' => $customer->id, 'customer_name' => $customer->display_name, 'customer_mobile' => $customer->mobile]])
            ->get(route('preinvoice.create'))->assertOk()
            ->assertSee('oldCustomerId: '.$customer->id, false)
            ->assertSee('نام، موبایل یا کد مشتری...', false)
            ->assertSee('<option value=""></option>', false);
        $this->getJson(route('api.customers.show', $customer))->assertOk()->assertJsonPath('data.customer.id', $customer->id);
    }

    public function test_edit_customer_is_rendered_for_preloading(): void
    {
        $customer = Customer::factory()->create();
        $order = \App\Models\PreinvoiceOrder::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'status' => \App\Models\PreinvoiceOrder::STATUS_DRAFT,
            'created_by' => auth()->id(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->display_name,
            'customer_mobile' => $customer->mobile,
        ]);
        $this->get(route('preinvoice.draft.edit', $order->uuid))->assertOk()
            ->assertSee('oldCustomerId: '.$customer->id, false)
            ->assertSee('isEdit: true', false);
        $this->getJson(route('api.customers.show', $customer))->assertOk()->assertJsonPath('data.customer.id', $customer->id);
    }
}
