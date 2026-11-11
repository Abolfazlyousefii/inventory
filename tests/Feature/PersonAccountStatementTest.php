<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierAccountTransaction;
use App\Models\SupplierLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PersonAccountStatementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('Owner', 'web'));

        return $user;
    }

    public function test_person_list_merges_normalized_customer_and_supplier_phones_and_links_one_account(): void
    {
        $customer = Customer::factory()->create([
            'first_name' => 'شخص مشترک',
            'last_name' => null,
            'mobile' => '09121234567',
        ]);
        Supplier::query()->create([
            'name' => 'شخص مشترک',
            'phone' => '+98 912 123 4567',
        ]);

        $response = $this->actingAs($this->owner())->get(route('persons.index'));

        $response->assertOk();
        $this->assertSame(1, $response->viewData('people')->total());
        $response->assertSee('/persons/09121234567/account', false);
        $this->assertSame($customer->id, $response->viewData('people')->items()[0]['customer_id']);
        $this->assertNotNull($response->viewData('people')->items()[0]['supplier_id']);
    }

    public function test_person_update_matches_customer_and_supplier_roles_by_normalized_phone(): void
    {
        $customer = Customer::factory()->create([
            'first_name' => 'شخص مشترک',
            'mobile' => '09121234567',
        ]);
        $supplier = Supplier::query()->create([
            'name' => 'شخص مشترک',
            'phone' => '+98 912 123 4567',
        ]);

        $response = $this->actingAs($this->owner())->put('/persons/09121234567', [
            'name' => 'نام اصلاح‌شده',
            'mobile' => '+98 912 333 4444',
            'types' => ['customer', 'supplier'],
            'address' => 'تهران',
            'postal_code' => '1234567890',
            'description' => 'شخص مشترک',
            'province_id' => null,
            'city_id' => null,
        ]);

        $response->assertRedirect(route('persons.index'));
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'mobile' => '09123334444',
            'first_name' => 'نام اصلاح‌شده',
        ]);
        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'phone' => '09123334444',
            'name' => 'نام اصلاح‌شده',
        ]);
    }

    public function test_unified_account_combines_both_ledgers_and_deduplicates_customer_copied_purchase_credit(): void
    {
        $customer = Customer::factory()->create([
            'first_name' => 'طرف حساب',
            'mobile' => '09121112233',
            'opening_balance' => 500,
        ]);
        $supplier = Supplier::query()->create([
            'name' => 'طرف حساب',
            'phone' => '09121112233',
        ]);

        CustomerLedger::query()->create([
            'customer_id' => $customer->id,
            'type' => 'debit',
            'amount' => 1000,
            'note' => 'فروش به شخص',
        ]);
        CustomerLedger::query()->create([
            'customer_id' => $customer->id,
            'type' => 'credit',
            'amount' => 300,
            'reference_type' => Purchase::class,
            'reference_id' => 41,
            'note' => 'کپی خرید از مشتری',
        ]);
        SupplierLedger::query()->create([
            'supplier_id' => $supplier->id,
            'type' => 'credit',
            'amount' => 300,
            'reference_type' => Purchase::class,
            'reference_id' => 41,
            'note' => 'خرید از همان شخص',
        ]);
        SupplierLedger::query()->create([
            'supplier_id' => $supplier->id,
            'type' => 'credit',
            'amount' => 700,
            'reference_type' => Purchase::class,
            'reference_id' => 42,
            'note' => 'خرید مستقل از شخص',
        ]);
        SupplierLedger::query()->create([
            'supplier_id' => $supplier->id,
            'type' => 'debit',
            'amount' => 200,
            'note' => 'پرداخت به شخص',
        ]);
        DB::table('purchase_returns')->insert([
            'document_number' => '00001',
            'submission_token' => (string) Str::uuid(),
            'supplier_id' => $supplier->id,
            'purchase_id' => 41,
            'warehouse_id' => 1,
            'supplier_name' => 'طرف حساب',
            'returned_at' => '2026-11-01',
            'total_amount' => 250,
            'total_quantity' => 1,
            'created_by' => 1,
            'updated_by' => 1,
            'version' => 1,
            'created_at' => '2026-11-01 10:00:00',
            'updated_at' => '2026-11-01 10:00:00',
        ]);

        $response = $this->actingAs($this->owner())
            ->get('/persons/'.$customer->mobile.'/account');

        $response->assertOk()
            ->assertSee('گردش حساب')
            ->assertSee('خرید مستقل از شخص')
            ->assertSee('پرداخت به شخص')
            ->assertSee('PR-00001');

        $this->assertSame(500, $response->viewData('openingBalance'));
        $this->assertSame(1450, $response->viewData('totalDebit'));
        $this->assertSame(1000, $response->viewData('totalCredit'));
        $this->assertSame(950, $response->viewData('netBalance'));
        $this->assertCount(5, $response->viewData('ledgers'));
    }

    public function test_active_legacy_purchase_return_is_included_without_a_supplier_ledger_row_and_deleted_return_is_excluded(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'تأمین‌کننده قدیمی',
            'phone' => '09123334456',
        ]);
        $returnId = DB::table('purchase_returns')->insertGetId([
            'document_number' => '00002',
            'submission_token' => (string) Str::uuid(),
            'supplier_id' => $supplier->id,
            'purchase_id' => 99,
            'warehouse_id' => 1,
            'supplier_name' => 'تأمین‌کننده قدیمی',
            'returned_at' => '2026-11-02',
            'total_amount' => 2500,
            'total_quantity' => 2,
            'created_by' => 1,
            'updated_by' => 1,
            'version' => 1,
            'created_at' => '2026-11-02 10:00:00',
            'updated_at' => '2026-11-02 10:00:00',
        ]);

        $this->actingAs($this->owner())
            ->get('/persons/supplier-'.$supplier->id.'/account')
            ->assertOk()
            ->assertSee('PR-00002');

        $service = app(\App\Services\PersonAccountStatementService::class);
        $this->assertSame(2500, $service->statement('supplier-'.$supplier->id)['totalDebit']);

        DB::table('purchase_returns')->where('id', $returnId)->update(['deleted_at' => now()]);
        $statement = $service->statement('supplier-'.$supplier->id);
        $this->assertSame(0, $statement['totalDebit']);
        $this->assertCount(1, $statement['ledgers']);
        $this->assertSame(0, (int) $statement['ledgers']->first()->is_effective);
    }

    public function test_supplier_only_person_can_open_a_statement_without_a_customer_record(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'تأمین‌کننده مستقل',
            'phone' => '09123334455',
        ]);
        SupplierLedger::query()->create([
            'supplier_id' => $supplier->id,
            'type' => 'credit',
            'amount' => 125000,
            'note' => 'خرید از تأمین‌کننده',
        ]);

        $response = $this->actingAs($this->owner())
            ->get('/persons/supplier-'.$supplier->id.'/account');

        $response->assertOk()->assertSee('تأمین‌کننده مستقل');
        $this->assertSame(0, $response->viewData('openingBalance'));
        $this->assertSame(0, $response->viewData('totalDebit'));
        $this->assertSame(125000, $response->viewData('totalCredit'));
        $this->assertSame(-125000, $response->viewData('netBalance'));
    }
    public function test_supplier_account_statement_can_record_audited_payments_and_receipts(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'تأمین‌کننده تراکنش',
            'phone' => '09124445566',
        ]);

        $this->actingAs($this->owner())
            ->post('/persons/supplier-'.$supplier->id.'/supplier-transactions', [
                'direction' => 'payment',
                'submission_token' => '11111111-1111-4111-8111-111111111111',
                'amount' => 3000,
                'transaction_date' => '2026-11-10 12:30:00',
                'method' => 'bank',
                'reference' => 'TRX-100',
                'note' => 'پرداخت به تأمین‌کننده',
            ])
            ->assertRedirect('/persons/supplier-'.$supplier->id.'/account');

        $this->post('/persons/supplier-'.$supplier->id.'/supplier-transactions', [
            'direction' => 'payment',
            'submission_token' => '11111111-1111-4111-8111-111111111111',
            'amount' => 3000,
            'transaction_date' => '2026-11-10 12:30:00',
            'method' => 'bank',
            'reference' => 'TRX-100',
            'note' => 'پرداخت به تأمین‌کننده',
        ])->assertRedirect('/persons/supplier-'.$supplier->id.'/account');
        $this->assertDatabaseCount('supplier_account_transactions', 1);
        $this->assertDatabaseCount('supplier_ledgers', 1);

        $this->assertDatabaseHas('supplier_account_transactions', [
            'supplier_id' => $supplier->id,
            'direction' => 'payment',
            'amount' => 3000,
            'method' => 'bank',
            'reference' => 'TRX-100',
        ]);
        $this->assertDatabaseHas('supplier_ledgers', [
            'supplier_id' => $supplier->id,
            'type' => 'debit',
            'amount' => 3000,
            'reference_type' => SupplierAccountTransaction::class,
        ]);

        $this->post('/persons/supplier-'.$supplier->id.'/supplier-transactions', [
            'direction' => 'receipt',
            'amount' => 1000,
            'transaction_date' => '2026-11-11 09:00:00',
            'note' => 'دریافت از تأمین‌کننده',
        ])->assertRedirect('/persons/supplier-'.$supplier->id.'/account');

        $statement = app(\\App\\Services\\PersonAccountStatementService::class)
            ->statement('supplier-'.$supplier->id);
        $this->assertSame(3000, $statement['totalDebit']);
        $this->assertSame(1000, $statement['totalCredit']);
        $this->assertSame(2000, $statement['netBalance']);
        $this->assertCount(2, $statement['ledgers']);
    }

}
