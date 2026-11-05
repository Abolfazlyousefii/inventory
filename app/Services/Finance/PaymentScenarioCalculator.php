<?php

namespace App\Services\Finance;

use InvalidArgumentException;

/**
 * Read-only reverse operations for the original Python calculator:
 *   current_value_calculator() => how much goods existing cheques fund today;
 *   remaining present value    => how much cash is still required today.
 *
 * All money is integer rials at the boundary, with unrounded PV intermediates.
 */
final class PaymentScenarioCalculator
{
    public function __construct(private readonly PaymentScheduleCalculator $scheduleCalculator)
    {
    }

    /**
     * @param array<int,array{day:int,amount_rial:int}> $knownPayments
     * @return array<string,mixed>
     */
    public function calculate(
        string $mode,
        ?int $totalPriceRial,
        float $monthlyRate,
        array $knownPayments,
    ): array {
        if (! in_array($mode, ['cash_needed', 'cash_value'], true)) {
            throw new InvalidArgumentException('نوع محاسبه مشخص‌شده معتبر نیست.');
        }

        $known = $this->scheduleCalculator->summarizeKnownPayments($monthlyRate, $knownPayments);
        $knownNominal = $known['fixed_total_rial'];
        $knownPresentValue = $known['fixed_present_value_rial'];
        $computedCash = 0;
        $cashEquivalent = 0;
        $computedPayments = [];

        if ($mode === 'cash_needed') {
            if ($totalPriceRial === null || $totalPriceRial <= 0) {
                throw new InvalidArgumentException('قیمت نقدی کالا باید مثبت باشد.');
            }
            if ($knownPresentValue > $totalPriceRial + 0.000001) {
                throw new InvalidArgumentException('ارزش فعلی چک‌ها و پیش‌پرداخت‌ها از مبلغ قرارداد بیشتر است.');
            }
            $computedCash = $this->roundedRials(max(0.0, $totalPriceRial - $knownPresentValue));
            $computedPayments[] = [
                'day' => 0,
                'amount_rial' => $computedCash,
                'present_value_rial' => (float) $computedCash,
            ];
            $effectivePrice = $totalPriceRial;
            $presentValue = $knownPresentValue + $computedCash;
            $nominalTotal = $this->sumRials($knownNominal, $computedCash);
        } else {
            if ($knownPresentValue <= 0) {
                throw new InvalidArgumentException('برای محاسبه ارزش نقدی، حداقل یک مبلغ پرداختی مثبت لازم است.');
            }
            $cashEquivalent = $this->roundedRials($knownPresentValue);
            if ($cashEquivalent === 0) {
                throw new InvalidArgumentException('ارزش نقدی این پرداخت‌ها کمتر از یک ریال است.');
            }
            $effectivePrice = $cashEquivalent;
            $presentValue = $knownPresentValue;
            $nominalTotal = $knownNominal;
        }

        return [
            'mode' => $mode,
            'total_price_rial' => $effectivePrice,
            'monthly_rate' => $monthlyRate,
            'daily_rate' => $known['daily_rate'],
            'days_per_month' => 30,
            'fixed_payments' => $known['fixed_payments'],
            'flexible_payments' => [],
            'computed_payments' => $computedPayments,
            'calculated_cash_rial' => $computedCash,
            'cash_equivalent_rial' => $cashEquivalent,
            'fixed_total_rial' => $knownNominal,
            'fixed_present_value_rial' => $knownPresentValue,
            'remaining_present_value_rial' => 0.0,
            'flexible_total_rial' => 0,
            'flexible_present_value_rial' => 0.0,
            'present_value_unrounded_rial' => $presentValue,
            'present_value_rounded_rial' => $presentValue,
            'total_nominal_rial' => $nominalTotal,
            'nominal_increase_rial' => $nominalTotal - $effectivePrice,
            'overall_rate' => $nominalTotal / $effectivePrice - 1,
        ];
    }

    private function roundedRials(float $amount): int
    {
        if (! is_finite($amount) || $amount < 0 || $amount >= PHP_INT_MAX - 1) {
            throw new InvalidArgumentException('مبلغ از محدوده ریالی قابل محاسبه خارج شده است.');
        }

        return (int) round($amount, 0, PHP_ROUND_HALF_UP);
    }

    private function sumRials(int $a, int $b): int
    {
        if ($b > PHP_INT_MAX - $a) {
            throw new InvalidArgumentException('جمع پرداخت‌ها از محدوده عدد صحیح خارج شده است.');
        }

        return $a + $b;
    }
}
