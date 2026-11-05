<?php

use App\Models\User;
use App\Support\PageAccessCatalog;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // UI layout references @vite; these tests exercise the application without a built frontend.
    $this->withoutVite();
});

function financeCalculatorPrintReader(): User
{
    PermissionCatalog::syncToDatabase();
    $user = User::factory()->create();
    $role = Role::findOrCreate('FinanceCalculatorPrintableReader', 'web');
    $role->givePermissionTo(\Spatie\Permission\Models\Permission::query()
        ->where('key', 'page.finance.accounts')->firstOrFail());
    $user->assignRole($role);

    return $user;
}

it('requires the financial reports page permission for printing', function () {
    expect(PageAccessCatalog::permissionsForRoute('finance.reports.payment-calculator.print'))
        ->toBe(['page.finance.accounts'])
        ->and(PermissionCatalog::routePermissions()['finance.reports.payment-calculator.print'])
        ->toBe('finance.reports.view');

    PermissionCatalog::syncToDatabase();
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('finance.reports.payment-calculator.print'), [])
        ->assertForbidden();
});

it('recalculates the print report using original inputs and includes every payment', function () {
    $this->actingAs(financeCalculatorPrintReader())
        ->post(route('finance.reports.payment-calculator.print'), [
            'price_rial' => '۱۰۰٬۰۰۰٬۰۰۰',
            'monthly_percent' => '۶',
            'base_date' => '۱۴۰۵/۰۸/۱۴',
            'customer_name' => 'مشتری نمونه',
            'fixed_payments' => [
                ['kind' => 'cash', 'due_date' => '۱۴۰۵/۰۸/۱۴', 'amount_rial' => '۵۰٬۰۰۰٬۰۰۰'],
            ],
            'weighted_payments' => [
                ['due_date' => '۱۴۰۵/۰۹/۱۴', 'weight' => '۱'],
            ],
        ])->assertOk()->assertViewIs('finance.payment-calculator.print')
        ->assertSee('مشتری نمونه')
        ->assertSee('103,000,000')
        ->assertSee('53,000,000')
        ->assertSee('50,000,000')
        ->assertSee('1405/09/14');
});

it('refuses to print an invalid payment schedule', function () {
    $this->actingAs(financeCalculatorPrintReader())
        ->post(route('finance.reports.payment-calculator.print'), [
            'price_rial' => '1000000',
            'monthly_percent' => '6',
            'base_date' => '1405/08/14',
            'fixed_payments' => [['kind' => 'cash', 'due_date' => '1405/08/14', 'amount_rial' => '1200000']],
            'weighted_payments' => [['due_date' => '1405/09/14', 'weight' => '1']],
        ])->assertSessionHasErrors('calculation');
});
