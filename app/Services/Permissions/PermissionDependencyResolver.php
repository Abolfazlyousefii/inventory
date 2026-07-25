<?php

namespace App\Services\Permissions;

use App\Support\PermissionCatalog;
use InvalidArgumentException;

class PermissionDependencyResolver
{
    public function normalize(array $keys): array
    {
        $registry = PermissionCatalog::registry();
        $unknown = array_diff($keys, array_keys($registry));
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown permissions: '.implode(', ', $unknown));
        }

        $normalized = array_values(array_unique($keys));
        do {
            $before = count($normalized);
            foreach ($normalized as $key) {
                $normalized = array_merge($normalized, $registry[$key]['depends_on']);
            }
            $normalized = array_values(array_unique($normalized));
        } while (count($normalized) !== $before);

        return $normalized;
    }

    public function dependents(string $key): array
    {
        return collect(PermissionCatalog::registry())
            ->filter(fn (array $item): bool => in_array($key, $item['depends_on'], true))
            ->keys()->all();
    }
}
