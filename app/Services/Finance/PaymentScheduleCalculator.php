<?php

namespace App\Services\Finance;

use InvalidArgumentException;

/**
 * Pure, read-only port of payment-calculator-daily.py.
 *
 * Monthly rate is a FRACTION (0.06 = 6%), not a percentage (6).
 * Payment amounts are integer rials; intermediate present values stay unrounded.
 * A month means exactly 30 days, matching the original Python calculator.
 */
final class PaymentScheduleCalculator
{
    private const DAYS_PER_MONTH = 30;

    private const MAX_DAYS = 3650;

    /**
     * @param array<int,array{day:int,amount_rial:int}> $fixedPayments
     * @param array<int,array{day:int,weight:int|float}> $weightedPayments
     * @return array<string,mixed>
     */
    public function calculate(int $totalPriceRial, float $monthlyRate, array $fixedPayments, array $weightedPayments): array
    {
        if ($totalPriceRial <= 0) {
            throw new InvalidArgumentException('مبلغ پایه باید بیشتر از صفر باشد.');
        }
        $known = $this->summarizeKnownPayments($monthlyRate, $fixedPayments);
        $dailyRate = $known['daily_rate'];
        $fixedTotal = $known['fixed_total_rial'];
        $fixedPresentValue = $known['fixed_present_value_rial'];
        $fixedRows = $known['fixed_payments'];

        $remaining = $totalPriceRial - $fixedPresentValue;
        // Floating-point computations may produce tiny negative residuals at exact equality.
        if ($remaining < -0.000001) {
            throw new InvalidArgumentException('ارزش فعلی پرداخت‌های ثابت بیشتر از مبلغ قرارداد است.');
        }
        $remaining = max(0.0, $remaining);

        $weightTotal = 0.0;
        foreach ($weightedPayments as $payment) {
            $this->day($payment);
            $weight = $payment['weight'] ?? null;
            if ((! is_int($weight) && ! is_float($weight)) || ! is_finite((float) $weight) || $weight <= 0) {
                throw new InvalidArgumentException('وزن هر چک باید عدد مثبت باشد.');
            }
            $weightTotal += $weight;
        }
        if (! is_finite($weightTotal)) {
            throw new InvalidArgumentException('مجموع وزن چک‌ها معتبر نیست.');
        }
        if ($remaining > 0.000001 && $weightTotal <= 0) {
            throw new InvalidArgumentException('برای مانده قرارداد حداقل یک چک وزنی لازم است.');
        }

        $weightedTotal = 0;
        $weightedPresentValueRounded = 0.0;
        $weightedRows = [];
        foreach ($weightedPayments as $payment) {
            $day = $payment['day'];
            $weight = $payment['weight'];
            $unroundedAmount = $remaining * $weight / $weightTotal * ((1 + $dailyRate) ** $day);
            if (! is_finite($unroundedAmount) || $unroundedAmount < 0 || $unroundedAmount >= PHP_INT_MAX - 1) {
                throw new InvalidArgumentException('مبلغ چک از محدوده عددی قابل محاسبه خارج شده است.');
            }
            $amount = (int) round($unroundedAmount, 0, PHP_ROUND_HALF_UP);
            $weightedTotal = $this->sumRials($weightedTotal, $amount);
            $presentValue = $amount / ((1 + $dailyRate) ** $day);
            $weightedPresentValueRounded += $presentValue;
            $weightedRows[] = [
                'day' => $day,
                'weight' => $weight,
                'amount_rial' => $amount,
                'present_value_rial' => $presentValue,
            ];
        }

        $nominalTotal = $this->sumRials($fixedTotal, $weightedTotal);

        return [
            'total_price_rial' => $totalPriceRial,
            'monthly_rate' => $monthlyRate,
            'daily_rate' => $dailyRate,
            'days_per_month' => self::DAYS_PER_MONTH,
            'fixed_payments' => $fixedRows,
            'flexible_payments' => $weightedRows,
            'fixed_total_rial' => $fixedTotal,
            'fixed_present_value_rial' => $fixedPresentValue,
            'remaining_present_value_rial' => $remaining,
            'flexible_total_rial' => $weightedTotal,
            'flexible_present_value_rial' => $weightedPresentValueRounded,
            'present_value_unrounded_rial' => $fixedPresentValue + ($weightTotal > 0 ? $remaining : 0),
            'present_value_rounded_rial' => $fixedPresentValue + $weightedPresentValueRounded,
            'total_nominal_rial' => $nominalTotal,
            'nominal_increase_rial' => $nominalTotal - $totalPriceRial,
            'overall_rate' => $nominalTotal / $totalPriceRial - 1,
        ];
    }

    /**
     * Evaluate known cash/cheque payments without assuming a contract price.
     * This is the current_value_calculator() operation in the original Python file.
     *
     * @param array<int,array{day:int,amount_rial:int}> $payments
     * @return array<string,mixed>
     */
    public function summarizeKnownPayments(float $monthlyRate, array $payments): array
    {
        if (! is_finite($monthlyRate) || $monthlyRate < 0 || $monthlyRate > 1) {
            throw new InvalidArgumentException('نرخ ماهانه باید بین صفر تا یک باشد.');
        }

        $dailyRate = (1 + $monthlyRate) ** (1 / self::DAYS_PER_MONTH) - 1;
        $total = 0;
        $presentValue = 0.0;
        $rows = [];

        foreach ($payments as $payment) {
            $day = $this->day($payment);
            $amount = $payment['amount_rial'] ?? null;
            if (! is_int($amount) || $amount < 0) {
                throw new InvalidArgumentException('مبلغ پرداخت باید عدد صحیح ریالی غیرمنفی باشد.');
            }
            $total = $this->sumRials($total, $amount);
            $discounted = $amount / ((1 + $dailyRate) ** $day);
            $presentValue += $discounted;
            $rows[] = [
                'day' => $day,
                'amount_rial' => $amount,
                'present_value_rial' => $discounted,
            ];
        }

        return [
            'daily_rate' => $dailyRate,
            'fixed_total_rial' => $total,
            'fixed_present_value_rial' => $presentValue,
            'fixed_payments' => $rows,
        ];
    }

    /** @param array<string,mixed> $payment */
    private function day(array $payment): int
    {
        $day = $payment['day'] ?? null;
        if (! is_int($day) || $day < 0 || $day > self::MAX_DAYS) {
            throw new InvalidArgumentException('روز سررسید باید عدد صحیح بین صفر تا ۳۶۵۰ باشد.');
        }

        return $day;
    }

    private function sumRials(int $a, int $b): int
    {
        if ($b > PHP_INT_MAX - $a) {
            throw new InvalidArgumentException('مجموع مبالغ از محدوده عدد صحیح ریالی خارج شده است.');
        }

        return $a + $b;
    }
}
