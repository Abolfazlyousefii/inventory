<?php

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    PermissionCatalog::syncToDatabase();
    $user = User::factory()->create();
    $role = Role::findOrCreate('FinanceScenarioReader', 'web');
    $role->givePermissionTo(\Spatie\Permission\Models\Permission::query()
        ->where('key', 'page.finance.accounts')->firstOrFail());
    $user->assignRole($role);
    $this->actingAs($user);
});

function scenarioPayload(string $mode): array
{
    return [
        'mode' => $mode,
        'monthly_percent' => '۶',
        'base_date' => '۱۴۰۵/۰۸/۱۴',
        'customer_name' => 'مشتری آزمایشی',
        'fixed_payments' => [],
        'weighted_payments' => [],
    ];
}

it('calculates cash required today for a known 80m cheque due in 90 days', function (): void {
    $payload = scenarioPayload('cash_needed') + ['price_rial' => '100000000'];
    $payload['fixed_payments'] = [
        ['kind' => 'cheque', 'due_date' => '۱۴۰۵/۱۱/۱۴', 'amount_rial' => '۸۰٬۰۰۰٬۰۰۰'],
    ];

    $this->postJson(route('finance.reports.payment-calculator.calculate'), $payload)
        ->assertOk()
        ->assertJsonPath('result.mode', 'cash_needed')
        ->assertJsonPath('result.calculated_cash_rial', 32_830_457)
        ->assertJsonPath('result.total_nominal_rial', 112_830_457)
        ->assertJsonPath('result.total_cheques_rial', 80_000_000)
        ->assertJsonCount(1, 'result.computed_payments');
});

it('calculates the cash-equivalent goods value of three specified cheques', function (): void {
    $payload = scenarioPayload('cash_value'); // No price: the price is computed by the server.
    $payload['fixed_payments'] = [
        ['kind' => 'cheque', 'due_date' => '۱۴۰۵/۰۹/۱۴', 'amount_rial' => '۲۰٬۰۰۰٬۰۰۰'],
        ['kind' => 'cheque', 'due_date' => '۱۴۰۵/۱۰/۱۴', 'amount_rial' => '۳۰٬۰۰۰٬۰۰۰'],
        ['kind' => 'cheque', 'due_date' => '۱۴۰۵/۱۱/۱۴', 'amount_rial' => '۸۰٬۰۰۰٬۰۰۰'],
    ];

    $this->postJson(route('finance.reports.payment-calculator.calculate'), $payload)
        ->assertOk()
        ->assertJsonPath('result.mode', 'cash_value')
        ->assertJsonPath('result.cash_equivalent_rial', 112_737_360)
        ->assertJsonPath('result.total_price_rial', 112_737_360)
        ->assertJsonPath('result.total_nominal_rial', 130_000_000)
        ->assertJsonPath('result.total_cheques_rial', 130_000_000);
});

it('calculates the last cheque after a cash advance and an already known cheque', function (): void {
    $payload = scenarioPayload('schedule') + ['price_rial' => '100000000'];
    $payload['fixed_payments'] = [
        ['kind' => 'cash', 'due_date' => '۱۴۰۵/۰۸/۱۴', 'amount_rial' => '۲۰٬۰۰۰٬۰۰۰'],
        ['kind' => 'cheque', 'due_date' => '۱۴۰۵/۰۹/۱۴', 'amount_rial' => '۳۰٬۰۰۰٬۰۰۰'],
    ];
    $payload['weighted_payments'] = [
        ['due_date' => '۱۴۰۵/۱۰/۱۴', 'weight' => '۱'],
    ];

    $this->postJson(route('finance.reports.payment-calculator.calculate'), $payload)
        ->assertOk()
        ->assertJsonPath('result.flexible_payments.0.amount_rial', 58_088_000)
        ->assertJsonPath('result.total_nominal_rial', 108_088_000)
        ->assertJsonPath('result.total_cheques_rial', 88_088_000);
});

it('prints the calculated cash and scenario name without claiming it is a cheque', function (): void {
    $payload = scenarioPayload('cash_needed') + ['price_rial' => '100000000'];
    $payload['fixed_payments'] = [
        ['kind' => 'cheque', 'due_date' => '۱۴۰۵/۱۱/۱۴', 'amount_rial' => '۸۰٬۰۰۰٬۰۰۰'],
    ];
    $this->post(route('finance.reports.payment-calculator.print'), $payload)
        ->assertOk()
        ->assertSee('نقدی لازم امروز')
        ->assertSee('32,830,457')
        ->assertSee('112,830,457');
});

it('prints cash equivalent in place of the optional contract price', function (): void {
    $payload = scenarioPayload('cash_value');
    $payload['fixed_payments'] = [
        ['kind' => 'cheque', 'due_date' => '۱۴۰۵/۰۹/۱۴', 'amount_rial' => '۲۰٬۰۰۰٬۰۰۰'],
        ['kind' => 'cheque', 'due_date' => '۱۴۰۵/۱۰/۱۴', 'amount_rial' => '۳۰٬۰۰۰٬۰۰۰'],
        ['kind' => 'cheque', 'due_date' => '۱۴۰۵/۱۱/۱۴', 'amount_rial' => '۸۰٬۰۰۰٬۰۰۰'],
    ];
    $this->post(route('finance.reports.payment-calculator.print'), $payload)
        ->assertOk()
        ->assertSee('ارزش نقدی امروز')
        ->assertSee('112,737,360');
});

it('rejects overpaid plans, missing known amounts, or weighted rows in reverse modes', function (): void {
    $overpaid = scenarioPayload('cash_needed') + ['price_rial' => '100000000'];
    $overpaid['fixed_payments'] = [
        ['kind' => 'cheque', 'due_date' => '۱۴۰۵/۰۸/۱۴', 'amount_rial' => '۱۰۱٬۰۰۰٬۰۰۰'],
    ];
    $this->postJson(route('finance.reports.payment-calculator.calculate'), $overpaid)
        ->assertUnprocessable()->assertJsonValidationErrors('calculation');

    $this->postJson(route('finance.reports.payment-calculator.calculate'), scenarioPayload('cash_value'))
        ->assertUnprocessable()->assertJsonValidationErrors('calculation');

    $bad = scenarioPayload('cash_value');
    $bad['fixed_payments'] = [
        ['kind' => 'cheque', 'due_date' => '۱۴۰۵/۰۹/۱۴', 'amount_rial' => '۲۰٬۰۰۰٬۰۰۰'],
    ];
    $bad['weighted_payments'] = [['due_date' => '۱۴۰۵/۰۹/۱۴', 'weight' => '۱']];
    $this->postJson(route('finance.reports.payment-calculator.calculate'), $bad)
        ->assertUnprocessable()->assertJsonValidationErrors('weighted_payments');
});
