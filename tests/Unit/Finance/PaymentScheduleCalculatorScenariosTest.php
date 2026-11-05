<?php

use App\Services\Finance\PaymentScheduleCalculator;

/**
 * Reference values independently calculated with the original
 * payment-calculator-daily.py formula (rounded to integer rials per cheque).
 * These cases include the user's actual one-page payment report.
 */
it('matches reference Python cheque amounts across realistic schedules', function (
    int $basePrice,
    float $monthlyRate,
    array $fixed,
    array $weighted,
    array $expectedCheques,
    int $expectedTotal,
): void {
    $result = (new PaymentScheduleCalculator)->calculate($basePrice, $monthlyRate, $fixed, $weighted);

    expect(array_column($result['flexible_payments'], 'amount_rial'))->toBe($expectedCheques)
        ->and($result['total_nominal_rial'])->toBe($expectedTotal)
        ->and($result['fixed_total_rial'] + $result['flexible_total_rial'])->toBe($expectedTotal);

    // Final displayed rials may have a small present-value rounding drift.
    // Keep the financial invariant within the number of rounded cheques.
    $tolerance = max(1, count($weighted));
    expect(abs($result['present_value_rounded_rial'] - $basePrice))->toBeLessThanOrEqual($tolerance);
})->with([
    'user A4 report: 1 billion, 200m advance, 30/116 days, 1:2 weights' => [
        1_000_000_000, 0.06,
        [['day' => 0, 'amount_rial' => 200_000_000]],
        [['day' => 30, 'weight' => 1], ['day' => 116, 'weight' => 2]],
        [282_666_667, 668_110_156],
        1_150_776_823,
    ],
    'zero interest, 1:3 weights' => [
        1_000_000, 0.0,
        [['day' => 0, 'amount_rial' => 200_000]],
        [['day' => 30, 'weight' => 1], ['day' => 60, 'weight' => 3]],
        [200_000, 600_000],
        1_000_000,
    ],
    'fixed cheque due at day 30, flexible due at day 60' => [
        1_000_000, 0.06,
        [['day' => 30, 'amount_rial' => 212_000]],
        [['day' => 60, 'weight' => 1]],
        [898_880],
        1_110_880,
    ],
    'fractional weights 0.5 and 1.5' => [
        10_000_000, 0.04,
        [['day' => 0, 'amount_rial' => 1_000_000]],
        [['day' => 10, 'weight' => 0.5], ['day' => 40, 'weight' => 1.5]],
        [2_279_609, 7_112_379],
        10_391_988,
    ],
    'multiple fixed advances and three flexible cheques' => [
        900_000_000, 0.025,
        [['day' => 0, 'amount_rial' => 100_000_000], ['day' => 15, 'amount_rial' => 70_000_000]],
        [['day' => 30, 'weight' => 1], ['day' => 60, 'weight' => 1], ['day' => 90, 'weight' => 2]],
        [187_282_600, 191_964_665, 393_527_564],
        942_774_829,
    ],
    'all cash, no flexible cheques' => [
        2_000_000, 0.06,
        [['day' => 0, 'amount_rial' => 2_000_000]],
        [],
        [],
        2_000_000,
    ],
    'long-dated payments 30 and 360 days' => [
        500_000_000, 0.08,
        [],
        [['day' => 30, 'weight' => 1], ['day' => 360, 'weight' => 1]],
        [270_000_000, 629_542_529],
        899_542_529,
    ],
]);

it('rejects negative days, zero weights, and an overpaid fixed schedule', function (): void {
    $calculator = new PaymentScheduleCalculator;

    expect(fn () => $calculator->calculate(
        1_000_000, 0.06, [], [['day' => -1, 'weight' => 1]],
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => $calculator->calculate(
        1_000_000, 0.06, [], [['day' => 30, 'weight' => 0]],
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => $calculator->calculate(
        1_000_000, 0.06, [['day' => 0, 'amount_rial' => 1_000_001]], [['day' => 30, 'weight' => 1]],
    ))->toThrow(InvalidArgumentException::class);
});
