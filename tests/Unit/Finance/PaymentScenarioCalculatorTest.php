<?php

use App\Services\Finance\PaymentScenarioCalculator;
use App\Services\Finance\PaymentScheduleCalculator;

function reverseCalculator(): PaymentScenarioCalculator
{
    return new PaymentScenarioCalculator(new PaymentScheduleCalculator);
}

it('derives the required cash from the discounted value of a known 90-day cheque', function (): void {
    $result = reverseCalculator()->calculate(
        'cash_needed',
        100_000_000,
        0.06,
        [['day' => 90, 'amount_rial' => 80_000_000]],
    );

    expect($result['calculated_cash_rial'])->toBe(32_830_457)
        ->and($result['total_nominal_rial'])->toBe(112_830_457)
        ->and(abs($result['present_value_rounded_rial'] - 100_000_000))->toBeLessThanOrEqual(1);
});

it('values three specified future cheques as their present value without a contract amount', function (): void {
    $result = reverseCalculator()->calculate(
        'cash_value',
        null,
        0.06,
        [
            ['day' => 30, 'amount_rial' => 20_000_000],
            ['day' => 60, 'amount_rial' => 30_000_000],
            ['day' => 90, 'amount_rial' => 80_000_000],
        ],
    );

    expect($result['cash_equivalent_rial'])->toBe(112_737_360)
        ->and($result['total_nominal_rial'])->toBe(130_000_000)
        ->and($result['nominal_increase_rial'])->toBe(17_262_640);
});

it('handles exact same-day amounts and a zero monthly rate', function (): void {
    $value = reverseCalculator()->calculate('cash_value', null, 0.06, [
        ['day' => 0, 'amount_rial' => 25_000_000],
    ]);
    expect($value['cash_equivalent_rial'])->toBe(25_000_000)
        ->and($value['overall_rate'])->toEqual(0);

    $cash = reverseCalculator()->calculate('cash_needed', 100_000_000, 0.0, [
        ['day' => 60, 'amount_rial' => 80_000_000],
    ]);
    expect($cash['calculated_cash_rial'])->toBe(20_000_000)
        ->and($cash['total_nominal_rial'])->toBe(100_000_000);
});

it('rejects overpayments, empty valuation and an invalid scenario', function (): void {
    expect(fn () => reverseCalculator()->calculate('cash_needed', 100_000_000, 0.06, [
        ['day' => 0, 'amount_rial' => 100_000_001],
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => reverseCalculator()->calculate('cash_value', null, 0.06, []))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => reverseCalculator()->calculate('unknown', 1_000_000, 0.06, []))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a tiny long-dated present value rounded to zero instead of dividing by zero', function (): void {
    expect(fn () => reverseCalculator()->calculate('cash_value', null, 1.0, [
        ['day' => 3650, 'amount_rial' => 1],
    ]))->toThrow(InvalidArgumentException::class);
});
