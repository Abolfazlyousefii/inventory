<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Support\AutomaticBundlePlan;
use Illuminate\Support\Facades\DB;

/** Availability derived from real central warehouse component stock; NOT a new stock ledger. */
class AutomaticBundleAvailabilityService
{
    public function available(int $bundleVariantId): int
    {
        $variant = ProductVariant::query()->with('product')->find($bundleVariantId);
        if (!$variant || !$variant->is_active || !$variant->sales_enabled
            || !$variant->product?->is_sellable || !$variant->product?->is_bundle
            || $variant->product->bundle_stock_mode !== 'automatic') return 0;

        $recipe = DB::table('product_bundle_components')
            ->where('bundle_variant_id', $bundleVariantId)
            ->pluck('quantity', 'component_variant_id')
            ->mapWithKeys(fn ($qty, $id) => [(int) $id => (int) $qty])->all();
        if ($recipe === []) return 0;

        // Disabled components must make the entire kit unavailable.
        $usable = ProductVariant::query()->with('product')->whereIn('id', array_keys($recipe))
            ->get()->filter(fn ($component) => $component->is_active && $component->sales_enabled
                && $component->product?->is_sellable && !$component->product?->is_bundle);
        if ($usable->count() !== count($recipe)) return 0;

        // Only units deliberately protected for this bundle count toward availability.
        // Ordinary component stock was reduced at allocation time.
        return app(BundleComponentAllocationService::class)->available($bundleVariantId, $recipe);
    }
}
