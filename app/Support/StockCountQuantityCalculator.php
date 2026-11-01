<?php

namespace App\Support;

/**
 * Physical counts include ordinary reserved stock and both free and held
 * automatic-bundle components, plus unshipped Site holds. Only the remainder
 * is freely sellable. Invalid snapshots must not silently wrap or go negative.
 */
final class StockCountQuantityCalculator
{
    public static function expectedPhysical(int $available, int $reserved, int $bundleFree, int $bundleReserved, int $siteHeld = 0): int
    {
        return self::sumNonnegative($available, $reserved, $bundleFree, $bundleReserved, $siteHeld);
    }

    public static function availableAfterCount(int $actual, int $reserved, int $bundleFree, int $bundleReserved, int $siteHeld = 0): int
    {
        if ($actual < 0) {
            throw new \InvalidArgumentException('Physical count cannot be negative.');
        }

        $protected = self::sumNonnegative($reserved, $bundleFree, $bundleReserved, $siteHeld);

        // A negative result is intentional: finalize() rejects counts below
        // protected stock with a specific conflict before any stock is written.
        return $actual - $protected;
    }

    private static function sumNonnegative(int ...$quantities): int
    {
        $sum = 0;
        foreach ($quantities as $quantity) {
            if ($quantity < 0 || $quantity > PHP_INT_MAX - $sum) {
                throw new \InvalidArgumentException('Invalid or overflowing stock count quantity.');
            }
            $sum += $quantity;
        }

        return $sum;
    }
}
