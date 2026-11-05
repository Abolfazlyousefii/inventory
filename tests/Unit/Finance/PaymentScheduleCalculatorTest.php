<?php

use App\Services\Finance\PaymentScheduleCalculator;

it('matches the original Python financial formula for a cash advance and two equal-weight cheques', function () {
    $result = (new PaymentScheduleCalculator)->calculate(
        1_000_000,
        0.06,
        [['day' => 0, 'amount_rial' => 200_000]],
        [['day' => 30, 'weight' => 1], ['day' => 60, 'weight' => 1]],
    );

    expect($result['daily_rate'])->toBeGreaterThan(0)
        ->and($result['monthly_rate'])->toBe(0.06)
        ->and($result['fixed_total_rial'])->toBe(200_000)
        ->and(abs($result['fixed_present_value_rial'] - 200_000))->toBeLessThan(0.00001)
        ->and(abs($result['remaining_present_value_rial'] - 800_000))->toBeLessThan(0.00001)
        ->and($result['flexible_payments'][0]['amount_rial'])->toBe(424_000)
        ->and($result['flexible_payments'][1]['amount_rial'])->toBe(449_440)
        ->and($result['total_nominal_rial'])->toBe(1_073_440)
        ->and(abs($result['overall_rate'] - 0.07344))->toBeLessThan(0.00000001)
        ->and(abs($result['present_value_unrounded_rial'] - 1_000_000))->toBeLessThan(0.00001);
});

it('preserves amounts when the interest rate is zero', function () {
    $result = (new PaymentScheduleCalculator)->calculate(
        1_000,
        0.0,
        [['day' => 0, 'amount_rial' => 200]],
        [['day' => 10, 'weight' => 1], ['day' => 40, 'weight' => 3]],
    );

    expect($result['flexible_payments'][0]['amount_rial'])->toBe(200)
        ->and($result['flexible_payments'][1]['amount_rial'])->toBe(600)
        ->and($result['total_nominal_rial'])->toBe(1_000)
        ->and($result['overall_rate'])->toEqual(0.0);
});

it('supports a fully covered contract without weighted cheques', function () {
    $result = (new PaymentScheduleCalculator)->calculate(
        500,
        0.06,
        [['day' => 0, 'amount_rial' => 500]],
        [],
    );

    expect($result['flexible_payments'])->toBe([])
        ->and(abs($result['remaining_present_value_rial']))->toBeLessThan(0.000001)
        ->and($result['total_nominal_rial'])->toBe(500);
});

it('rejects a zero or negative total cheque weight', function () {
    expect(fn () => (new PaymentScheduleCalculator)->calculate(
        1_000, 0.06, [], [['day' => 30, 'weight' => 0]],
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects fixed payments whose present value exceeds the contract price', function () {
    expect(fn () => (new PaymentScheduleCalculator)->calculate(
        100, 0.06, [['day' => 0, 'amount_rial' => 120]], [['day' => 30, 'weight' => 1]],
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects a remaining balance with no weighted payments', function () {
    expect(fn () => (new PaymentScheduleCalculator)->calculate(
        1_000, 0.06, [['day' => 0, 'amount_rial' => 200]], [],
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects invalid days and monthly rates', function () {
    $calculator = new PaymentScheduleCalculator;
    expect(fn () => $calculator->calculate(1_000, -0.01, [], [['day' => 30, 'weight' => 1]]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $calculator->calculate(1_000, 0.06, [], [['day' => -1, 'weight' => 1]]))
        ->toThrow(InvalidArgumentException::class);
});
