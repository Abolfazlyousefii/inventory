<?php

namespace App\Services;

use App\Models\Category;
use App\Models\StockCountDocument;
use App\Models\Warehouse;
use App\Support\StockCountQuantityCalculator as Quantities;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paginated category variant browser for stocktaking. Existing counted rows
 * reuse their original server snapshot when a draft is reopened.
 */
final class StocktakeCategoryWorkspaceService
{
    public const PAGE_SIZE = 60;
    public const MAX_PAGE_SIZE = 100;

    /** @return array{rows:array,next_cursor:int,has_more:bool} */
    public function rows(
        int $categoryId,
        int $afterId,
        int $limit,
        string $search = '',
        ?StockCountDocument $document = null,
    ): array {
        if ($document) {
            abort_unless($document->type === 'category' && (int) $document->category_id === $categoryId, 404);
        }

        // Do not create a warehouse merely to display a category.
        $warehouseId = (int) (Warehouse::query()
            ->where('type', 'central')->where('name', 'انبار مرکزی')->value('id') ?? 0);
        abort_if($warehouseId < 1, 503, 'انبار مرکزی یافت نشد؛ گزارش موجودی قابل اتکا نیست.');

        $variantsQuery = $this->countableVariantsQuery($categoryId)
            ->where('v.id', '>', $afterId);

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\\\').'%';
            $variantsQuery->where(function ($query) use ($like) {
                $query->where('p.name', 'like', $like)
                    ->orWhere('p.sku', 'like', $like)
                    ->orWhere('v.variant_name', 'like', $like)
                    ->orWhere('v.variety_name', 'like', $like)
                    ->orWhere('v.variant_code', 'like', $like)
                    ->orWhere('v.variety_code', 'like', $like);
            });
        }

        $variants = $variantsQuery->orderBy('v.id')
            ->limit($limit + 1)
            ->get([
                'v.id as variant_id', 'v.product_id', 'p.name as product_name',
                'v.variant_name', 'v.variety_name', 'v.variant_code',
                'v.variety_code', 'v.is_active', 'v.sales_enabled',
            ]);

        $hasMore = $variants->count() > $limit;
        $page = $variants->take($limit);
        $ids = $page->pluck('variant_id')->map(fn ($v) => (int) $v)->all();
        if ($ids === []) {
            return ['rows' => [], 'next_cursor' => $afterId, 'has_more' => false];
        }

        $free = DB::table('warehouse_stocks')
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_variant_id', $ids)
            ->select('product_variant_id')
            ->selectRaw('SUM(quantity) as qty')
            ->groupBy('product_variant_id')
            ->pluck('qty', 'product_variant_id');

        $reserved = app(ReservationQueryService::class)->quantitiesByVariant(variantIds: $ids);
        $bundleQuantities = app(BundleComponentStocktakeService::class)->quantitiesByComponent($ids);
        $queue = app(WarehouseStockFlowReportService::class)->collectionQueueByVariantIds($ids);
        $savedItems = $document
            ? $document->items()->whereIn('product_variant_id', $ids)->get()->keyBy('product_variant_id')
            : collect();

        $rows = $page->map(static function ($variant) use ($free, $reserved, $bundleQuantities, $queue, $savedItems) {
            $id = (int) $variant->variant_id;
            $saved = $savedItems->get($id);
            $available = $saved ? (int) $saved->system_available_at_start : (int) ($free->get($id) ?? 0);
            $held = $saved ? (int) $saved->reserved_at_start : (int) ($reserved->get($id) ?? 0);
            $bundleFree = $saved ? (int) $saved->bundle_free_at_start : (int) ($bundleQuantities[$id]['free'] ?? 0);
            $bundleReserved = $saved ? (int) $saved->bundle_reserved_at_start : (int) ($bundleQuantities[$id]['reserved'] ?? 0);
            $siteHeld = $saved ? (int) $saved->site_held_at_start : (int) ($bundleQuantities[$id]['site_held'] ?? 0);
            $flow = $queue->get($id);

            return [
                'variant_id' => $id,
                'product_id' => (int) $variant->product_id,
                'product_name' => (string) $variant->product_name,
                'name' => (string) ($variant->variant_name ?: $variant->variety_name ?: "#{$id}"),
                'sku' => (string) ($variant->variant_code ?: $variant->variety_code ?: ''),
                'system_available' => $available,
                'active_reserved' => $held,
                'bundle_free' => $bundleFree,
                'bundle_reserved' => $bundleReserved,
                'site_held' => $siteHeld,
                'expected_physical' => $saved
                    ? (int) $saved->expected_physical_at_start
                    : Quantities::expectedPhysical($available, $held, $bundleFree, $bundleReserved, $siteHeld),
                'stock_updated_at' => $saved
                    ? $saved->warehouse_stock_updated_at_start?->toDateTimeString()
                    : null,
                'actual_physical' => $saved?->actual_quantity === null ? null : (int) $saved->actual_quantity,
                'is_counted' => $saved !== null && $saved->actual_quantity !== null,
                'pending_collection' => (int) ($flow->pending_collection ?? 0),
                'warehouse_received' => (int) ($flow->warehouse_received ?? 0),
                'collecting' => (int) ($flow->collecting ?? 0),
                'queue_total' => (int) ($flow->pending_collection ?? 0)
                    + (int) ($flow->warehouse_received ?? 0)
                    + (int) ($flow->collecting ?? 0),
                'sales_enabled' => (bool) $variant->is_active && (bool) $variant->sales_enabled,
            ];
        })->values()->all();

        return [
            'rows' => $rows,
            'next_cursor' => (int) $page->last()->variant_id,
            'has_more' => $hasMore,
        ];
    }
    /**
     * The same eligible category scope used by the browser and finalization.
     * One beyond the document ceiling ensures oversized scopes remain incomplete.
     *
     * @return list<int>
     */
    public function countableVariantIds(int $categoryId): array
    {
        return $this->countableVariantsQuery($categoryId)
            ->orderBy('v.id')->limit(50001)->pluck('v.id')
            ->map(static fn ($id) => (int) $id)->all();
    }

    private function countableVariantsQuery(int $categoryId): Builder
    {
        $query = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereIn('p.category_id', Category::selfAndDescendantIds($categoryId));

        // Automatic bundles have virtual stock: never count the same goods twice.
        if (Schema::hasColumn('products', 'is_bundle') && Schema::hasColumn('products', 'bundle_stock_mode')) {
            $query->where(function ($builder) {
                $builder->where('p.is_bundle', false)
                    ->orWhereNull('p.is_bundle')
                    ->orWhere('p.bundle_stock_mode', '<>', 'automatic')
                    ->orWhereNull('p.bundle_stock_mode');
            });
        }

        return $query;
    }

}
