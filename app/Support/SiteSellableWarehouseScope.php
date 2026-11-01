<?php

namespace App\Support;

/**
 * Resolves the explicit warehouse allow-list for site-visible stock.
 * An empty allow-list means central only, never "all warehouses".
 */
final class SiteSellableWarehouseScope
{
    /** @return list<int> */
    public static function resolve(int $centralWarehouseId, mixed $configuredIds): array
    {
        if ($centralWarehouseId <= 0) {
            throw new \InvalidArgumentException('The central warehouse ID is invalid.');
        }
        if ($configuredIds === null || $configuredIds === []) {
            return [$centralWarehouseId];
        }
        if (! is_array($configuredIds)) {
            throw new \InvalidArgumentException('Site sellable warehouse IDs must be an array.');
        }

        $ids = [];
        foreach ($configuredIds as $id) {
            if (! (is_int($id) || (is_string($id) && ctype_digit($id)))
                || (int) $id <= 0 || (string) (int) $id !== (string) $id) {
                throw new \InvalidArgumentException('Site sellable warehouse IDs must be positive integers.');
            }
            $ids[(int) $id] = (int) $id;
        }
        if ($ids === []) {
            throw new \InvalidArgumentException('Site sellable warehouse list must not be empty after normalization.');
        }
        sort($ids, SORT_NUMERIC);

        return array_values($ids);
    }
}
