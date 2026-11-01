<?php

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use App\Support\PageAccessCatalog;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function statementReader(bool $broad = true): User
{
    $user = User::factory()->create();
    $role = Role::findOrCreate('Org:finance.account_statements_readonly', 'web');
    if ($broad) {
        $user->assignRole(Role::findOrCreate('Accountant', 'web'));
        foreach (['page.finance.accounts', 'page.finance.payments', 'payments.create', 'account_statements.adjust', 'account_statements.payments.create'] as $key) {
            $user->permissions()->attach(DB::table('permissions')->where('key', $key)->value('id'));
        }
    } else {
        $permission = Permission::where('key', 'page.finance.account_statements_readonly')->firstOrFail();
        $role->givePermissionTo($permission);
    }
    $user->assignRole($role);

    return $user->fresh();
}

it('denies financial mutations despite inherited broad permissions', function () {
    $user = statementReader();
    foreach (['account-statements.payments.store', 'account-statements.payments.edit', 'account-statements.payments.update', 'account-statements.payments.destroy', 'account-statements.adjustments.store', 'invoices.payments.store', 'cheques.store'] as $route) {
        expect(PageAccessCatalog::userCanRoute($user, $route), $route)->toBeFalse();
    }
    foreach (['account_statements.adjust', 'account_statements.payments.create', 'payments.create'] as $key) {
        expect($user->hasPermission($key), $key)->toBeFalse()
            ->and(PermissionCatalog::userHasPermission($user, $key))->toBeFalse();
    }
});

it('shows a read-only statement and rejects a forged payment or balance adjustment', function () {
    $user = statementReader();
    $customer = Customer::factory()->create();
    $invoice = Invoice::withoutEvents(fn () => Invoice::create(['uuid' => 'READONLY-TEST', 'customer_id' => $customer->id, 'customer_name' => 'Test', 'total' => 1000, 'status' => Invoice::STATUS_SHIPPED]));
    $payment = InvoicePayment::withoutEvents(fn () => InvoicePayment::create(['invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'amount' => 100, 'method' => 'cash', 'paid_at' => '2026-10-26']));
    $this->actingAs($user)->get(route('account-statements.show', $customer))->assertOk()
        ->assertDontSee('id="openPaymentModalBtn"', false)
        ->assertDontSee('id="paymentModal"', false)
        ->assertDontSee('id="balanceAdjustmentModal"', false);
    $count = CustomerLedger::count();
    $this->postJson(route('account-statements.payments.store', $customer), [])->assertForbidden();
    $this->postJson(route('account-statements.adjustments.store', $customer), [])->assertForbidden();
    $this->get(route('account-statements.payments.edit', [$customer, $payment]))->assertForbidden();
    $this->putJson(route('account-statements.payments.update', [$customer, $payment]), [])->assertForbidden();
    $this->deleteJson(route('account-statements.payments.destroy', [$customer, $payment]))->assertForbidden();
    expect(CustomerLedger::count())->toBe($count);
    expect($payment->fresh())->not->toBeNull();
});

it('grants read routes and sidebar entry without opening financial reports or mutations', function () {
    $user = statementReader(false);
    foreach (['account-statements.index', 'account-statements.show', 'account-statements.documents.invoices.show', 'account-statements.documents.returns.show', 'account-statements.documents.payments.show'] as $route) {
        expect(PageAccessCatalog::userCanRoute($user, $route), $route)->toBeTrue();
    }
    expect(PermissionCatalog::userHasPermission($user, 'account_statements.view'))->toBeTrue()
        ->and(PageAccessCatalog::userCanRoute($user, 'finance.reports.index'))->toBeFalse()
        ->and(PageAccessCatalog::userCanRoute($user, 'account-statements.payments.store'))->toBeFalse();
});

it('preserves administrator and ordinary finance writer access', function () {
    $admin = statementReader();
    $admin->assignRole(Role::findOrCreate('Admin', 'web'));
    $writer = User::factory()->create();
    $writer->permissions()->attach(DB::table('permissions')->where('key', 'page.finance.accounts')->value('id'));
    expect(PageAccessCatalog::userCanRoute($admin->fresh(), 'account-statements.payments.store'))->toBeTrue()
        ->and(PageAccessCatalog::userCanRoute($writer, 'account-statements.payments.store'))->toBeTrue();
});

it('does not let a legacy named permission bypass the read-only constraint through gates', function () {
    $user=statementReader();
    $role=Role::findOrCreate('LegacyPaymentWriter','web');
    $role->givePermissionTo(Spatie\Permission\Models\Permission::findOrCreate('payments.create','web'));
    $user->assignRole($role);
    expect($user->fresh()->can('payments.create'))->toBeFalse();
});

it('allows an explicit shipping exception without granting other warehouse pages', function () {
    $user = statementReader(false);
    $role = Role::findOrCreate('Org:warehouse.shipping', 'web');
    $role->givePermissionTo(Permission::where('key', 'page.warehouse.shipping')->firstOrFail());
    $user->assignRole($role);
    expect(PageAccessCatalog::userCanRoute($user->fresh(), 'warehouse.shipping.ship'))->toBeTrue()
        ->and(PageAccessCatalog::userCanRoute($user->fresh(), 'vouchers.sales.print'))->toBeTrue()
        ->and(PageAccessCatalog::userCanRoute($user->fresh(), 'warehouse.inbound.receive'))->toBeFalse()
        ->and(PageAccessCatalog::userCanRoute($user->fresh(),'account-statements.payments.store'))->toBeFalse();
});
