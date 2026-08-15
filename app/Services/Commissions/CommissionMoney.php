<?php

namespace App\Services\Commissions;

use DomainException;

final class CommissionMoney
{
    public static function percentageOf(int $amount, string $percentage): int
    {
        $units = self::percentageUnits($percentage);
        $denominator = 1_000_000;
        $whole = intdiv($amount, $denominator);
        $remainder = $amount % $denominator;
        if ($units > 0 && $whole > intdiv(PHP_INT_MAX, $units)) {
            throw new DomainException('Commission amount exceeds the supported integer range.');
        }

        return ($whole * $units) + intdiv(($remainder * $units) + intdiv($denominator, 2), $denominator);
    }

    public static function addPercentages(string $left, string $right): string
    {
        return number_format((self::percentageUnits($left) + self::percentageUnits($right)) / 10_000, 4, '.', '');
    }

    private static function percentageUnits(string $percentage): int
    {
        [$whole, $fraction] = array_pad(explode('.', $percentage, 2), 2, '');

        return ((int) $whole * 10_000) + (int) str_pad(substr($fraction, 0, 4), 4, '0');
    }
}
