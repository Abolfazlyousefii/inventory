<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\AutomaticBundlePlan;
use Illuminate\Support\Facades\DB;
use App\Services\Sync\SiteStockChangeMarker;
use Illuminate\Validation\ValidationException;

/**
 * Protected component quantities are moved OUT of ordinary sellable warehouse stock
 * into an Inventory-owned bundle allocation ledger. No virtual stock is invented.
 * All public methods affecting balances must be called inside a database transaction.
 */
final class BundleComponentAllocationService
{
    /** @param array<int,int> $requested component variant => protected units */
    public function setAllocations(int $bundleVariantId, array $requested): void
    {
        DB::transaction(function () use ($bundleVariantId, $requested): void {
            $bundle = ProductVariant::query()->with('product')->findOrFail($bundleVariantId);
            if (!$bundle->product?->is_bundle || $bundle->product->bundle_stock_mode !== 'automatic') {
                throw ValidationException::withMessages(['allocations' => 'این تنظیم فقط برای بسته خودکار مجاز است.']);
            }
            // Serializes allocation editing for one bundle and with checkout holds.
            Product::query()->whereKey($bundle->product_id)->lockForUpdate()->firstOrFail();
            $recipe = DB::table('product_bundle_components')->where('bundle_variant_id', $bundleVariantId)
                ->pluck('quantity', 'component_variant_id')->all();
            $recipeIds = array_map('intval', array_keys($recipe));
            sort($recipeIds, SORT_NUMERIC);
            $requestedIds = array_map('intval', array_keys($requested));
            sort($requestedIds, SORT_NUMERIC);
            if (!$recipeIds || $recipeIds !== $requestedIds) {
                throw ValidationException::withMessages(['allocations' => 'برای هر قطعه بسته باید مقدار اختصاص‌یافته تعیین شود.']);
            }
            foreach ($requested as $id => $units) {
                if ((int) $id <= 0 || !is_int($units) || $units < 0 || $units > 1000000) {
                    throw ValidationException::withMessages(['allocations' => 'مقدار اختصاص‌یافته نامعتبر است.']);
                }
            }
            $rows = DB::table('bundle_component_allocations')->where('bundle_variant_id', $bundleVariantId)
                ->orderBy('component_variant_id')->lockForUpdate()->get()->keyBy('component_variant_id');
            // Do not silently ignore old allocation rows if the recipe was changed incorrectly.
            foreach ($rows as $id => $row) {
                if (!in_array((int) $id, $recipeIds, true) && (int) $row->remaining > 0) {
                    throw ValidationException::withMessages(['allocations' => 'ابتدا سهم رزروشده قطعات قدیمی را آزاد کنید.']);
                }
            }
            $products = ProductVariant::query()->whereIn('id', $recipeIds)->pluck('product_id', 'id');
            if ($products->count() !== count($recipeIds)) {
                throw ValidationException::withMessages(['allocations' => 'تنوع یکی از قطعات یافت نشد.']);
            }
            $warehouse = WarehouseStockService::centralWarehouseId();
            foreach ($recipeIds as $id) {
                $current = (int) ($rows->get($id)?->remaining ?? 0);
                $next = $requested[$id];
                $delta = $next - $current;
                if ($delta !== 0) {
                    // Allocate: subtract warehouse available units. Release: restore them.
                    WarehouseStockService::change($warehouse, (int) $products[$id], -$delta, $id);
                }
                DB::table('bundle_component_allocations')->updateOrInsert(
                    ['bundle_variant_id' => $bundleVariantId, 'component_variant_id' => $id],
                    ['remaining' => $next, 'updated_at' => now(), 'created_at' => $rows->has($id) ? $rows[$id]->created_at : now()]
                );
            }
            // Bundle stock is virtual: its availability can change even without
            // updating the bundle's product or variant row.
            SiteStockChangeMarker::mark((int) $bundle->product_id, $bundleVariantId);
        }, 3);
    }

    public function available(int $bundleVariantId, array $recipe): int
    {
        if (!$recipe) return 0;
        $reserved = DB::table('bundle_component_allocations')->where('bundle_variant_id', $bundleVariantId)
            ->pluck('remaining', 'component_variant_id')->mapWithKeys(fn ($qty, $id) => [(int) $id => (int) $qty])->all();
        return AutomaticBundlePlan::available($recipe, $reserved);
    }

    /**
     * @param array<int,array<int,int>> $bundleRequirements bundle SKU => component => required units
     * @return array<int,int> total protected units taken by physical component variant
     */
    public function reserve(array $bundleRequirements): array
    {
        $consumed = [];
        ksort($bundleRequirements, SORT_NUMERIC);
        // Lock bundle product rows in a stable order, consistent with admin allocation changes.
        $bundleProductMap = ProductVariant::query()->whereIn('id', array_keys($bundleRequirements))->pluck('product_id', 'id');
        $bundleProductIds = $bundleProductMap->values()->all();
        Product::query()->whereIn('id', $bundleProductIds)->orderBy('id')->lockForUpdate()->get();
        foreach ($bundleRequirements as $bundleVariantId => $parts) {
            ksort($parts, SORT_NUMERIC);
            $rows = DB::table('bundle_component_allocations')->where('bundle_variant_id', $bundleVariantId)
                ->orderBy('component_variant_id')->lockForUpdate()->get()->keyBy('component_variant_id');
            foreach ($parts as $id => $units) {
                $row = $rows->get($id);
                if (!$row || $units <= 0 || (int) $row->remaining < $units) {
                    throw ValidationException::withMessages(['stock' => "موجودی اختصاص‌یافته به بسته کافی نیست (تنوع {$id})."]);
                }
                DB::table('bundle_component_allocations')->where('id', $row->id)->update([
                    'remaining' => (int) $row->remaining - $units, 'updated_at' => now(),
                ]);
                $consumed[$id] = ($consumed[$id] ?? 0) + $units;
            }
            SiteStockChangeMarker::mark((int) ($bundleProductMap[$bundleVariantId] ?? 0), (int) $bundleVariantId);
        }
        ksort($consumed, SORT_NUMERIC);
        return $consumed;
    }

    /** @param array<int,array<int,int>> $bundleRequirements bundle SKU => component => reserved units */
    public function restoreByBundle(array $bundleRequirements): void
    {
        ksort($bundleRequirements, SORT_NUMERIC);
        $bundleProductMap = ProductVariant::query()->whereIn('id', array_keys($bundleRequirements))->pluck('product_id', 'id');
        $productIds = $bundleProductMap->values()->all();
        Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();
        foreach ($bundleRequirements as $bundleVariantId => $parts) {
            ksort($parts, SORT_NUMERIC);
            $rows = DB::table('bundle_component_allocations')->where('bundle_variant_id', $bundleVariantId)
                ->orderBy('component_variant_id')->lockForUpdate()->get()->keyBy('component_variant_id');
            foreach ($parts as $id => $units) {
                $row = $rows->get($id);
                if (!$row || $units < 1 || (int) $row->remaining > 1000000 - $units) {
                    throw ValidationException::withMessages(['stock' => 'امکان بازگرداندن سهمیه بسته وجود ندارد.']);
                }
                DB::table('bundle_component_allocations')->where('id', $row->id)->update([
                    'remaining' => (int) $row->remaining + $units, 'updated_at' => now(),
                ]);
            }
            SiteStockChangeMarker::mark((int) ($bundleProductMap[$bundleVariantId] ?? 0), (int) $bundleVariantId);
        }
    }
}
