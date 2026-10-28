<?php

namespace App\Services;

use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pre-invoice availability and stock movements for physical items and automatic bundles.
 * Automatic bundles have no physical SKU stock: their allocated components are the stock.
 */
final class PreinvoiceBundleStockService
{
    public function constrainAvailableVariants(Builder $query): Builder
    {
        return $query->active()->where('sales_enabled', true)->where(function (Builder $q) {
            $q->where('stock', '>', 0)
                ->orWhere(function (Builder $automatic) {
                    $automatic->whereHas('product', fn (Builder $product) => $product
                        ->where('is_bundle', true)->where('bundle_stock_mode', 'automatic'))
                        ->whereExists(fn ($parts) => $parts->selectRaw('1')
                            ->from('product_bundle_components as recipe')
                            ->whereColumn('recipe.bundle_variant_id', 'product_variants.id'))
                        // All recipe components must have protected stock for at least one bundle.
                        ->whereNotExists(fn ($parts) => $parts->selectRaw('1')
                            ->from('product_bundle_components as recipe')
                            ->leftJoin('bundle_component_allocations as allocation', function ($join) {
                                $join->on('allocation.bundle_variant_id', '=', 'recipe.bundle_variant_id')
                                    ->on('allocation.component_variant_id', '=', 'recipe.component_variant_id');
                            })
                            ->leftJoin('product_variants as part_variant', 'part_variant.id', '=', 'recipe.component_variant_id')
                            ->leftJoin('products as part_product', 'part_product.id', '=', 'part_variant.product_id')
                            ->whereColumn('recipe.bundle_variant_id', 'product_variants.id')
                            ->whereRaw('(COALESCE(allocation.remaining, 0) < recipe.quantity OR COALESCE(part_variant.is_active, 0) = 0 OR COALESCE(part_variant.sales_enabled, 0) = 0 OR COALESCE(part_product.is_sellable, 0) = 0 OR COALESCE(part_product.is_bundle, 0) <> 0)'));
                });
        });
    }

    public function isAutomatic(int $productId, int $variantId): bool
    {
        return ProductVariant::query()->whereKey($variantId)->where('product_id', $productId)
            ->whereHas('product', fn (Builder $q) => $q->where('is_bundle', true)
                ->where('bundle_stock_mode', 'automatic'))->exists();
    }

    public function free(int $productId, int $variantId): int
    {
        return $this->isAutomatic($productId, $variantId)
            ? app(AutomaticBundleAvailabilityService::class)->available($variantId)
            : WarehouseStockService::available(WarehouseStockService::centralWarehouseId(), $productId, $variantId);
    }

    public function reserve(int $productId, int $variantId, int $quantity): void
    {
        app(InventoryCountFreezeService::class)->assertProduct($productId);
        if ($quantity <= 0) return;
        if (! $this->isAutomatic($productId, $variantId)) {
            WarehouseStockService::change(WarehouseStockService::centralWarehouseId(), $productId, -$quantity, $variantId);
            return;
        }

        $requirements = $this->requirements($variantId, $quantity);
        // Allocation service locks the bundle and all component rows and rejects shortages.
        app(BundleComponentAllocationService::class)->reserve([$variantId => $requirements]);
    }

    public function release(int $productId, int $variantId, int $quantity): void
    {
        if ($quantity <= 0) return;
        if (! $this->isAutomatic($productId, $variantId)) {
            WarehouseStockService::change(WarehouseStockService::centralWarehouseId(), $productId, $quantity, $variantId);
            return;
        }
        app(BundleComponentAllocationService::class)->restoreByBundle([
            $variantId => $this->requirements($variantId, $quantity),
        ]);
    }

    private function requirements(int $variantId, int $quantity): array
    {
        $recipe = DB::table('product_bundle_components')->where('bundle_variant_id', $variantId)
            ->pluck('quantity', 'component_variant_id');
        if ($recipe->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'ترکیب بسته تعریف نشده است.']);
        }
        $required = [];
        foreach ($recipe as $partId => $perBundle) {
            $perBundle = (int) $perBundle;
            if ($perBundle < 1 || $quantity > intdiv(PHP_INT_MAX, $perBundle)) {
                throw ValidationException::withMessages(['items' => 'تعداد قطعات بسته نامعتبر است.']);
            }
            $required[(int) $partId] = $quantity * $perBundle;
        }
        ksort($required, SORT_NUMERIC);
        return $required;
    }
}
