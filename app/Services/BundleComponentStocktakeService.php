<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Quantities of automatic-bundle components that remain physically present in
 * the central warehouse but were removed from ordinary sellable stock.
 *
 * Free allocations and components held by ACTIVE bundle reservations are
 * disjoint: checkout moves units from allocation.remaining into reservations.
 * Site order hold lines also cover automatic-bundle components and ordinary
 * stock held outside the preinvoice reservation system.
 */
final class BundleComponentStocktakeService
{
    public function __construct(private readonly ReservationQueryService $reservations)
    {
    }

    /**
     * @param array<int,int> $componentIds
     * @return array<int,array{free:int,reserved:int,site_held:int}> keyed by component variant
     */
    public function quantitiesByComponent(array $componentIds, bool $lock = false): array
    {
        $componentIds = array_values(array_unique(array_map('intval', $componentIds)));
        if ($componentIds === []) {
            return [];
        }

        $recipeQuery = fn () => DB::table('product_bundle_components as recipe')
            ->join('product_variants as bundle_variant', 'bundle_variant.id', '=', 'recipe.bundle_variant_id')
            ->join('products as bundle_product', 'bundle_product.id', '=', 'bundle_variant.product_id')
            ->where('bundle_product.is_bundle', true)
            ->where('bundle_product.bundle_stock_mode', 'automatic')
            ->whereIn('recipe.component_variant_id', $componentIds);
        $allocationQuery = fn () => DB::table('bundle_component_allocations as allocation')
            ->join('product_variants as bundle_variant', 'bundle_variant.id', '=', 'allocation.bundle_variant_id')
            ->join('products as bundle_product', 'bundle_product.id', '=', 'bundle_variant.product_id')
            ->where('bundle_product.is_bundle', true)
            ->where('bundle_product.bundle_stock_mode', 'automatic')
            ->whereIn('allocation.component_variant_id', $componentIds);

        if ($lock) {
            // Allocation editing and bundle checkout both lock the bundle's
            // Product row before altering the allocation ledger. Lock in the
            // same order, BEFORE locking central WarehouseStock in finalize().
            $bundleIds = $recipeQuery()->pluck('recipe.bundle_variant_id')
                ->merge($allocationQuery()->pluck('allocation.bundle_variant_id'))
                ->unique()->values()->all();
            $bundleProductIds = ProductVariant::query()->whereIn('id', $bundleIds)
                ->pluck('product_id')->unique()->values()->all();
            Product::query()->whereIn('id', $bundleProductIds)
                ->orderBy('id')->lockForUpdate()->get();
        }

        $recipes = $recipeQuery()->get([
            'recipe.bundle_variant_id', 'recipe.component_variant_id', 'recipe.quantity',
        ]);
        $allocations = $allocationQuery()
            ->orderBy('allocation.bundle_variant_id')
            ->orderBy('allocation.component_variant_id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['allocation.bundle_variant_id', 'allocation.component_variant_id', 'allocation.remaining']);

        $bundleIds = $recipes->pluck('bundle_variant_id')->unique()->all();
        $reservedBundles = $bundleIds === [] ? collect() : $this->reservations
            ->quantitiesByVariant(variantIds: $bundleIds);

        // Site holds are separate from preinvoice reservations. The Site order
        // line ledger contains physical component units for automatic bundles
        // and ordinary products, with no additional recipe multiplication.
        $siteRows = DB::table('site_stock_hold_lines as line')
            ->join('site_stock_holds as hold', 'hold.id', '=', 'line.hold_id')
            ->leftJoin('invoices as invoice', 'invoice.id', '=', 'hold.invoice_id')
            ->whereIn('line.variant_id', $componentIds)
            ->where(function ($query) {
                $query->whereIn('hold.status', ['held', 'payment_confirmed'])
                    ->orWhere(function ($consumed) {
                        $consumed->where('hold.status', 'consumed')
                            ->whereNull('invoice.shipped_at')
                            ->where(function ($notShipped) {
                                $notShipped->where('invoice.status', '!=', 'shipped')
                                    ->orWhereNull('invoice.status');
                            });
                    });
            })
            ->orderBy('line.id')
            ->get(['line.variant_id', 'line.quantity', 'hold.status', 'hold.invoice_id',
                'invoice.id as resolved_invoice_id']);
        $siteHeld = [];
        foreach ($siteRows as $row) {
            if ($row->status === 'consumed' && $row->resolved_invoice_id === null) {
                throw new \RuntimeException('Consumed site hold has no traceable invoice during stock count.');
            }
            if ((int) $row->quantity < 0) {
                throw new \RuntimeException('Site stock hold has invalid negative quantity.');
            }
            $id = (int) $row->variant_id;
            $siteHeld[$id] = self::checkedAdd($siteHeld[$id] ?? 0, (int) $row->quantity);
        }

        return self::aggregate($recipes, $allocations, $reservedBundles->all(), $siteHeld);
    }

    /**
     * Aggregate each physical component once per ledger category. Bundle
     * reservations consume allocation.remaining, so they are not a second
     * claim on the free allocation units.
     *
     * @param iterable<object> $recipes
     * @param iterable<object> $allocations
     * @param array<int,int> $reservedBundles
     * @param array<int,int> $siteHeld
     * @return array<int,array{free:int,reserved:int,site_held:int}>
     */
    public static function aggregate(iterable $recipes, iterable $allocations, array $reservedBundles, array $siteHeld = []): array
    {
        $result = [];
        foreach ($allocations as $allocation) {
            $id = (int) $allocation->component_variant_id;
            $remaining = (int) $allocation->remaining;
            if ($remaining < 0) {
                throw new \RuntimeException('Invalid negative bundle allocation during stock count.');
            }
            $result[$id] ??= ['free' => 0, 'reserved' => 0, 'site_held' => 0];
            $result[$id]['free'] = self::checkedAdd($result[$id]['free'], $remaining);
        }
        foreach ($recipes as $recipe) {
            $id = (int) $recipe->component_variant_id;
            $perBundle = (int) $recipe->quantity;
            if ($perBundle < 1) {
                throw new \RuntimeException('Invalid automatic bundle recipe during stock count.');
            }
            $result[$id] ??= ['free' => 0, 'reserved' => 0, 'site_held' => 0];
            $bundleReserved = (int) ($reservedBundles[(int) $recipe->bundle_variant_id] ?? 0);
            if ($bundleReserved < 0) {
                throw new \RuntimeException('Invalid negative bundle reservation during stock count.');
            }
            if ($bundleReserved > intdiv(PHP_INT_MAX, $perBundle)) {
                throw new \RuntimeException('Bundle reservation quantity overflows during stock count.');
            }
            $result[$id]['reserved'] = self::checkedAdd($result[$id]['reserved'], $perBundle * $bundleReserved);
        }

        foreach ($siteHeld as $id => $quantity) {
            if ($quantity < 0) {
                throw new \RuntimeException('Invalid negative site hold quantity during stock count.');
            }
            $result[(int) $id] ??= ['free' => 0, 'reserved' => 0, 'site_held' => 0];
            $result[(int) $id]['site_held'] = self::checkedAdd($result[(int) $id]['site_held'], $quantity);
        }

        return $result;
    }

    private static function checkedAdd(int $total, int $addition): int
    {
        if ($total < 0 || $addition < 0 || $addition > PHP_INT_MAX - $total) {
            throw new \RuntimeException('Invalid or overflowing protected stock during stock count.');
        }

        return $total + $addition;
    }
}
