<?php

namespace App\Support;

use InvalidArgumentException;

/** Pure canonical representation of physical lines of one Site order. */
final class HoldLinePlan
{
    /** @param array<int,array{variant_id:mixed,quantity:mixed}> $lines @return array<int,int> */
    public static function normalize(array $lines): array
    {
        if ($lines === [] || count($lines) > 500) {
            throw new InvalidArgumentException('Checkout must have 1 to 500 physical lines.');
        }
        $total = [];
        foreach ($lines as $line) {
            $variant = $line['variant_id'] ?? null;
            $quantity = $line['quantity'] ?? null;
            if (! self::positiveInteger($variant) || ! self::positiveInteger($quantity) || (int) $quantity > 100000) {
                throw new InvalidArgumentException('Invalid checkout stock line.');
            }
            $id = (int) $variant;
            $q = (int) $quantity;
            if (($total[$id] ?? 0) > 100000 - $q) {
                throw new InvalidArgumentException('Exceeded maximum quantity of one variant.');
            }
            $total[$id] = ($total[$id] ?? 0) + $q;
        }
        ksort($total, SORT_NUMERIC);
        return $total;
    }

    /** @param array<int,int> $canonical */
    public static function fingerprint(array $canonical): string
    {
        ksort($canonical, SORT_NUMERIC);
        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    private static function positiveInteger(mixed $value): bool
    {
        if (is_int($value)) return $value > 0;
        return is_string($value) && ctype_digit($value) && (int) $value > 0;
    }
}
