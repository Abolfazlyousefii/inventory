<?php

namespace Tests\\Feature;

use App\\Models\\Customer;
use App\\Models\\CustomerLedger;
use App\\Models\\Purchase;
use App\\Models\\Supplier;
use App\\Models\\SupplierLedger;
use App\\Models\\User;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Spatie\\Permission\\Models\\Role;
use Tests\\TestCase;

class PersonAccountStatementTest extends TestCase
{
    use RefreshDatabase;

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

        $response = $this->actingAs($this->owner())
            ->get('/persons/'.$customer->mobile.'/account');

        $response->assertOk()
            ->assertSee('گردش حساب')
            ->assertSee('خرید مستقل از شخص')
            ->assertSee('پرداخت به شخص');

        $this->assertSame(500, $response->viewData('openingBalance'));
        $this->assertSame(1200, $response->viewData('totalDebit'));
        $this->assertSame(1000, $response->viewData('totalCredit'));
        $this->assertSame(700, $response->viewData('netBalance'));
        $this->assertCount(4, $response->viewData('ledgers'));
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
}
