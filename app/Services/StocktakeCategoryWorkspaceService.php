<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Read-only category variant browser for stocktaking. The existing
 * product-level document workflow remains the only write path.
 */
final class StocktakeCategoryWorkspaceService
{
    public const PAGE_SIZE = 60;
    public const MAX_PAGE_SIZE = 100;

    /** @return array{rows:array,next_cursor:int,has_more:bool} */
    public function rows(int $categoryId, int $afterId, int $limit): array
    {
        $categories = Category::selfAndDescendantIds($categoryId);

        // Do not create a warehouse merely to display a category.
        $warehouseId = (int) (Warehouse::query()
            ->where('type', 'central')->where('name', 'انبار مرکزی')->value('id') ?? 0);
        abort_if($warehouseId < 1, 503, 'انبار مرکزی یافت نشد؛ گزارش موجودی قابل اتکا نیست.');

        $variants = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereIn('p.category_id', $categories)
            ->where('v.id', '>', $afterId)
            ->orderBy('v.id')
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
        $queue = app(WarehouseStockFlowReportService::class)->collectionQueueByVariantIds($ids);

        $rows = $page->map(static function ($variant) use ($free, $reserved, $queue) {
            $id = (int) $variant->variant_id;
            $available = (int) ($free->get($id) ?? 0);
            $held = (int) ($reserved->get($id) ?? 0);
            $flow = $queue->get($id);
            return [
                'variant_id' => $id,
                'product_id' => (int) $variant->product_id,
                'product_name' => (string) $variant->product_name,
                'name' => (string) ($variant->variant_name ?: $variant->variety_name ?: "#{$id}"),
                'sku' => (string) ($variant->variant_code ?: $variant->variety_code ?: ''),
                'system_available' => $available,
                'active_reserved' => $held,
                'expected_physical' => $available + $held,
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
}
