<?php

namespace App\Support;

use InvalidArgumentException;

/** Normalizes a fixed, physical bundle bill of materials without touching stock. */
final class BundleRecipe
{
    /**
     * @param array<int,array{variant_id:mixed,quantity:mixed}> $components
     * @return array<int,int> Ordered component variant ID => quantity per finished unit.
     */
    public static function normalize(int $bundleVariantId, array $components): array
    {
        if ($bundleVariantId < 1 || $components === [] || count($components) > 100) {
            throw new InvalidArgumentException('Bundle recipe must contain 1 to 100 components.');
        }

        $normalized = [];
        foreach ($components as $component) {
            $variant = $component['variant_id'] ?? null;
            $quantity = $component['quantity'] ?? null;
            if (! self::positiveInteger($variant) || ! self::positiveInteger($quantity)
                || (int) $quantity > 100000) {
                throw new InvalidArgumentException('Invalid bundle component variant or quantity.');
            }

            $variantId = (int) $variant;
            if ($variantId === $bundleVariantId || isset($normalized[$variantId])) {
                throw new InvalidArgumentException('Duplicate or self-referencing component variant.');
            }
            $normalized[$variantId] = (int) $quantity;
        }

        ksort($normalized, SORT_NUMERIC);
        return $normalized;
    }

    /** @param array<int,int> $recipe @return array<int,int> */
    public static function requirements(array $recipe, int $packages): array
    {
        if ($packages < 1 || $packages > 100000) {
            throw new InvalidArgumentException('Package quantity must be positive.');
        }
        $requirements = [];
        foreach ($recipe as $variantId => $quantity) {
            if (! self::positiveInteger($variantId) || ! self::positiveInteger($quantity)
                || (int) $quantity > intdiv(PHP_INT_MAX, $packages)) {
                throw new InvalidArgumentException('Invalid or overflowing recipe quantity.');
            }
            $requirements[(int) $variantId] = (int) $quantity * $packages;
        }
        if ($requirements === []) {
            throw new InvalidArgumentException('Cannot assemble an empty recipe.');
        }
        ksort($requirements, SORT_NUMERIC);
        return $requirements;
    }

    private static function positiveInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }
        return is_string($value) && ctype_digit($value) && (int) $value > 0;
    }
}
