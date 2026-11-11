<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UnifiedPersonAccountStatementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('Owner', 'web'));
        $this->actingAs($user);
    }

    public function test_person_detail_shows_customer_and_supplier_ledgers_for_the_same_person_without_merging_their_balances(): void
    {
        $customer = Customer::factory()->create([
            'first_name' => 'شخص مشترک',
            'mobile' => '09120000001',
            'opening_balance' => 1000,
        ]);
        $supplier = Supplier::query()->create([
            'name' => 'شخص مشترک',
            'phone' => '09120000001',
        ]);

        CustomerLedger::query()->create([
            'customer_id' => $customer->id,
            'type' => 'debit',
            'amount' => 2500,
            'note' => 'فروش به شخص',
        ]);
        CustomerLedger::query()->create([
            'customer_id' => $customer->id,
            'type' => 'credit',
            'amount' => 500,
            'note' => 'دریافت از شخص',
        ]);
        SupplierLedger::query()->create([
            'supplier_id' => $supplier->id,
            'type' => 'credit',
            'amount' => 4000,
            'note' => 'خرید از شخص',
        ]);
        SupplierLedger::query()->create([
            'supplier_id' => $supplier->id,
            'type' => 'debit',
            'amount' => 1000,
            'note' => 'پرداخت به شخص',
        ]);

        $response = $this->get(route('persons.show', '09120000001'));

        $response->assertOk()
            ->assertViewIs('persons.show')
            ->assertSee('شخص مشترک')
            ->assertSee('گردش حساب مشتری')
            ->assertSee('گردش حساب تأمین‌کننده')
            ->assertSee('مطالبات ما')
            ->assertSee('بدهی ما')
            ->assertSee('فروش به شخص')
            ->assertSee('خرید از شخص');

        $this->assertSame(3000, $customer->fresh()->balance);
        $this->assertSame(3000, (int) $response['supplierPayableBalance']);
        $this->assertSame(3000, (int) $response['customerReceivableBalance']);
    }

    public function test_person_list_links_to_one_shared_detail_page_for_a_person_with_both_roles(): void
    {
        $customer = Customer::factory()->create(['mobile' => '09120000002']);
        Supplier::query()->create(['name' => $customer->display_name, 'phone' => $customer->mobile]);

        $response = $this->get(route('persons.index'));

        $response->assertOk()
            ->assertSee(route('persons.show', '09120000002'), false);
    }

    public function test_person_detail_does_not_expose_another_persons_ledger_entries(): void
    {
        $customer = Customer::factory()->create(['mobile' => '09120000003']);
        $other = Customer::factory()->create(['mobile' => '09120000004']);
        CustomerLedger::query()->create([
            'customer_id' => $other->id,
            'type' => 'debit',
            'amount' => 999999,
            'note' => 'نباید نمایش داده شود',
        ]);

        $this->get(route('persons.show', $customer->mobile))
            ->assertOk()
            ->assertDontSee('نباید نمایش داده شود');
    }

    public function test_supplier_account_can_record_payments_and_receipts_as_audited_ledger_entries(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'تأمین‌کننده پرداختی',
            'phone' => '09120000005',
        ]);
        SupplierLedger::query()->create([
            'supplier_id' => $supplier->id,
            'type' => 'credit',
            'amount' => 10000,
            'note' => 'خرید اولیه',
        ]);

        $this->post(route('persons.supplier-ledgers.store', '09120000005'), [
            'direction' => 'payment',
            'amount' => 3000,
            'note' => 'پرداخت نقدی',
        ])->assertRedirect(route('persons.show', '09120000005'));

        $this->assertDatabaseHas('supplier_ledgers', [
            'supplier_id' => $supplier->id,
            'type' => 'debit',
            'amount' => 3000,
            'reference_type' => \\App\\Models\\SupplierAccountTransaction::class,
            'note' => 'پرداخت نقدی',
        ]);
        $this->assertDatabaseHas('supplier_account_transactions', [
            'supplier_id' => $supplier->id,
            'direction' => 'payment',
            'amount' => 3000,
            'method' => null,
        ]);

        $this->post(route('persons.supplier-ledgers.store', '09120000005'), [
            'direction' => 'receipt',
            'amount' => 1000,
            'note' => 'دریافت از تأمین‌کننده',
        ])->assertRedirect(route('persons.show', '09120000005'));

        $this->assertDatabaseHas('supplier_ledgers', [
            'supplier_id' => $supplier->id,
            'type' => 'credit',
            'amount' => 1000,
            'note' => 'دریافت از تأمین‌کننده',
        ]);
        $this->assertSame(8000, (int) $supplier->ledgers()->where('type', 'credit')->sum('amount')
            - (int) $supplier->ledgers()->where('type', 'debit')->sum('amount'));
    }
}
