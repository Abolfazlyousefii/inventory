<?php

use App\Models\{Customer, CustomerLedger, Supplier, SupplierLedger, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('Owner', 'web'));
    $this->actingAs($user);
});

it('includes supplier accounts with their real balance without merging same-phone customer money', function () {
    $customer = Customer::factory()->create(['mobile' => '09123456789', 'opening_balance' => 100]);
    $supplier = Supplier::create(['name' => 'تأمین‌کننده آزمایش دفتر', 'phone' => $customer->mobile]);
    SupplierLedger::create(['supplier_id' => $supplier->id, 'type' => 'credit', 'amount' => 900]);
    SupplierLedger::create(['supplier_id' => $supplier->id, 'type' => 'debit', 'amount' => 200]);
    $before = [CustomerLedger::count(), SupplierLedger::count()];
    $response = $this->get(route('account-statements.index', ['role' => 'supplier']));
    $response->assertOk()->assertSee($supplier->name)->assertSee('supplier-only-'.$supplier->id);
    $row = collect($response->viewData('customers')->items())->firstWhere('id', $supplier->id);
    expect($row->balance)->toBe(-700)->and($row->credit)->toBe(700)->and($row->debt)->toBe(0);
    $statement = $this->get(route('persons.account', 'supplier-only-'.$supplier->id));
    $statement->assertOk();
    expect($statement->viewData('netBalance'))->toBe(-700)
        ->and([CustomerLedger::count(), SupplierLedger::count()])->toBe($before);
});

it('searches and sorts both roles with stable pagination and retains customer account links', function () {
    $customer = Customer::factory()->create(['first_name' => 'دفتر مشتری', 'opening_balance' => 1200]);
    $supplier = Supplier::create(['name' => 'دفتر تأمین', 'phone' => '09129998877']);
    SupplierLedger::create(['supplier_id' => $supplier->id, 'type' => 'credit', 'amount' => 3000]);
    $response = $this->get(route('account-statements.index', ['q' => 'دفتر', 'sort' => 'credit']));
    $response->assertOk()->assertSee(route('account-statements.show', $customer))->assertSee($supplier->name);
    expect($response->viewData('customers')->total())->toBe(2)
        ->and($response->viewData('customers')->items()[0]->account_role)->toBe('supplier');
    $this->get(route('account-statements.index', ['q' => '۹۱۲۹۹۹۸۸۷۷', 'role' => 'supplier']))
        ->assertOk()->assertSee($supplier->name);
    $this->get(route('persons.index', ['role' => 'supplier']))->assertOk()->assertSee($supplier->name)->assertDontSee($customer->display_name);
});

it('keeps supplier role visible when searching a shared person by its customer name', function () {
    Customer::factory()->create(['first_name' => 'نام مشتری مشترک', 'mobile' => '09125556677']);
    Supplier::create(['name' => 'عنوان متفاوت تأمین', 'phone' => '09125556677']);
    $response = $this->get(route('persons.index', ['q' => 'نام مشتری مشترک', 'role' => 'both']));
    $response->assertOk();
    expect($response->viewData('people')->total())->toBe(1)
        ->and($response->viewData('people')->items()[0]['is_supplier'])->toBeTrue();
});

it('paginates supplier accounts without losing role or including adjustment placeholders', function () {
    for ($i = 0; $i < 23; $i++) Supplier::create(['name' => 'دفتر '.$i]);
    $placeholder = Supplier::create(['name' => 'کسری و اضافه آزمایشی']);
    $placeholder->forceFill(['is_inventory_adjustment_source' => true])->save();
    $response = $this->get(route('account-statements.index', ['role' => 'supplier', 'page' => 2]));
    $response->assertOk()->assertDontSee($placeholder->name);
    expect($response->viewData('customers')->total())->toBe(23)
        ->and($response->viewData('customers')->count())->toBe(3);
    $this->get(route('persons.index', ['role' => 'supplier']))->assertOk()->assertDontSee($placeholder->name);
});
