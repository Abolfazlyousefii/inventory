<?php

namespace App\Services\Permissions;

use App\Support\PermissionCatalog;
use InvalidArgumentException;

class PermissionDependencyResolver
{
    public function normalize(array $keys): array
    {
        $registry = PermissionCatalog::registry();
        $keys = array_values(array_unique(array_filter($keys, 'is_string')));
        $unknown = array_values(array_diff($keys, array_keys($registry)));
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown permissions: '.implode(', ', $unknown));
        }

        $deprecated = array_values(array_filter(
            $keys,
            fn (string $key): bool => (bool) ($registry[$key]['deprecated'] ?? false)
        ));
        if ($deprecated !== []) {
            throw new InvalidArgumentException('Deprecated permissions: '.implode(', ', $deprecated));
        }

        $normalized = [];
        $visited = [];
        $visit = function (string $key) use (&$visit, &$normalized, &$visited, $registry): void {
            if (isset($visited[$key])) {
                return;
            }

            $visited[$key] = true;
            if (! isset($registry[$key]) || ($registry[$key]['deprecated'] ?? false)) {
                return;
            }

            $normalized[] = $key;
            foreach ((array) ($registry[$key]['depends_on'] ?? []) as $dependency) {
                if (is_string($dependency)) {
                    $visit($dependency);
                }
            }
        };

        foreach ($keys as $key) {
            $visit($key);
        }

        return array_values(array_unique($normalized));
    }

    public function dependents(string $key): array
    {
        return collect(PermissionCatalog::registry())
            ->filter(fn (array $item): bool => in_array($key, (array) ($item['depends_on'] ?? []), true))
            ->keys()->all();
    }
}
