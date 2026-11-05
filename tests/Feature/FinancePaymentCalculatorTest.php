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

it('maps calculator routes to the existing financial report permission', function () {
    foreach (['finance.reports.payment-calculator.index', 'finance.reports.payment-calculator.calculate'] as $name) {
        expect(route($name))->toBeString()
            ->and(PageAccessCatalog::permissionsForRoute($name))->toBe(['page.finance.accounts'])
            ->and(PermissionCatalog::routePermissions()[$name])->toBe('finance.reports.view');
    }
});

it('denies unauthorized users access to the calculator', function () {
    PermissionCatalog::syncToDatabase();
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('finance.reports.payment-calculator.index'))->assertForbidden();
    $this->actingAs($user)->postJson(route('finance.reports.payment-calculator.calculate'), [])->assertForbidden();
});

it('calculates Persian amounts and Jalali cheque due dates for permitted finance users', function () {
    PermissionCatalog::syncToDatabase();
    $user = User::factory()->create();
    $role = Role::findOrCreate('CalculatorReportReader', 'web');
    $role->givePermissionTo(\Spatie\Permission\Models\Permission::query()->where('key', 'page.finance.accounts')->firstOrFail());
    $user->assignRole($role);

    $this->actingAs($user)->get(route('finance.reports.payment-calculator.index'))
        ->assertOk()->assertSee('ماشین‌حساب نقد و چک')
        ->assertSee('tab-cheque')->assertSee('tab-cash')->assertSee('tab-value')
        ->assertSee('calendarDialog')->assertSee('finance-payment-quick-adapter.js');

    $this->actingAs($user)->postJson(route('finance.reports.payment-calculator.calculate'), [
        'price_rial' => '۱٬۰۰۰٬۰۰۰',
        'monthly_percent' => '۶',
        'base_date' => '۱۴۰۵/۰۱/۰۱',
        'fixed_payments' => [[
            'kind' => 'cash', 'due_date' => '۱۴۰۵/۰۱/۰۱', 'amount_rial' => '۲۰۰٬۰۰۰',
        ]],
        'weighted_payments' => [
            ['due_date' => '۱۴۰۵/۰۱/۳۱', 'weight' => '۱'],
            ['due_date' => '۱۴۰۵/۰۲/۳۰', 'weight' => '۱'],
        ],
    ])->assertOk()
        ->assertJsonPath('result.total_nominal_rial', 1_073_440)
        ->assertJsonPath('result.flexible_payments.0.amount_rial', 424_000)
        ->assertJsonPath('result.flexible_payments.1.amount_rial', 449_440);
});

it('rejects malformed amounts instead of silently treating them as zero', function () {
    PermissionCatalog::syncToDatabase();
    $user = User::factory()->create();
    $role = Role::findOrCreate('CalculatorReportReader', 'web');
    $role->givePermissionTo(\Spatie\Permission\Models\Permission::query()->where('key', 'page.finance.accounts')->firstOrFail());
    $user->assignRole($role);

    $this->actingAs($user)->postJson(route('finance.reports.payment-calculator.calculate'), [
        'price_rial' => '۱۰۰۰ریال', 'monthly_percent' => '۶', 'base_date' => '۱۴۰۵/۰۱/۰۱',
        'fixed_payments' => [],
        'weighted_payments' => [['due_date' => '۱۴۰۵/۰۱/۳۱', 'weight' => '۱']],
    ])->assertUnprocessable()->assertJsonValidationErrors('price_rial');
});
