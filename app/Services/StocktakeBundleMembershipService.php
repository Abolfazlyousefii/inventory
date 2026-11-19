<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only bundle membership indicators for the stocktake UI.
 *
 * These are recipe relationships, not allocated/reserved component quantities.
 * Keep them out of physical-count and stock-adjustment calculations.
 */
final class StocktakeBundleMembershipService
{
    /**
     * @param array<int, int|string> $variantIds
     * @return Collection<int, int> Number of bundle variants using each component.
     */
    public function countsByComponent(array $variantIds): Collection
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $variantIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($ids === [] || ! Schema::hasTable('product_bundle_components')) {
            return collect();
        }

        return DB::table('product_bundle_components as recipe')
            ->join('product_variants as bundle_variant', 'bundle_variant.id', '=', 'recipe.bundle_variant_id')
            ->join('products as bundle_product', 'bundle_product.id', '=', 'bundle_variant.product_id')
            ->where('bundle_product.is_bundle', true)
            ->whereIn('recipe.component_variant_id', $ids)
            ->groupBy('recipe.component_variant_id')
            ->select('recipe.component_variant_id')
            ->selectRaw('COUNT(DISTINCT recipe.bundle_variant_id) AS bundles')
            ->pluck('bundles', 'component_variant_id')
            ->map(static fn ($count): int => (int) $count);
    }
}
