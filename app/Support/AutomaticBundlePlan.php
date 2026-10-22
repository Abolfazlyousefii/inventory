<?php

namespace App\Support;

use InvalidArgumentException;

/** Pure math; no inventory rows are mutated here. */
final class AutomaticBundlePlan
{
    /** @param array<int,int> $requiredPerBundle @param array<int,int> $available */
    public static function available(array $requiredPerBundle, array $available): int
    {
        if ($requiredPerBundle === []) return 0;
        $minimum = PHP_INT_MAX;
        foreach ($requiredPerBundle as $id => $perBundle) {
            if ((int) $id < 1 || $perBundle < 1) throw new InvalidArgumentException('Invalid bundle recipe.');
            $minimum = min($minimum, intdiv(max(0, (int) ($available[$id] ?? 0)), $perBundle));
        }
        return $minimum === PHP_INT_MAX ? 0 : $minimum;
    }

    /** A configured shared cap limits availability but never creates warehouse stock. */
    public static function withSharedLimit(int $componentCapacity, int $remainingAllowance): int
    {
        return min(max(0, $componentCapacity), max(0, $remainingAllowance));
    }

    /** @param array<int,int> $commercialPlan @param array<int,array<int,int>> $automaticRecipes @return array<int,int> */
    public static function expand(array $commercialPlan, array $automaticRecipes): array
    {
        $physical = [];
        foreach ($commercialPlan as $variantId => $units) {
            if ($variantId < 1 || $units < 1) throw new InvalidArgumentException('Invalid order quantity.');
            $recipe = $automaticRecipes[$variantId] ?? null;
            if ($recipe === null) {
                $physical[$variantId] = ($physical[$variantId] ?? 0) + $units;
                continue;
            }
            if ($recipe === []) throw new InvalidArgumentException('Automatic bundle has no components.');
            foreach ($recipe as $componentId => $perBundle) {
                if ($componentId < 1 || $perBundle < 1 || $units > intdiv(PHP_INT_MAX, $perBundle)) {
                    throw new InvalidArgumentException('Invalid automatic bundle quantity.');
                }
                $required = $units * $perBundle;
                if (($physical[$componentId] ?? 0) > PHP_INT_MAX - $required) throw new InvalidArgumentException('Quantity overflow.');
                $physical[$componentId] = ($physical[$componentId] ?? 0) + $required;
            }
        }
        ksort($physical, SORT_NUMERIC);
        return $physical;
    }
}
