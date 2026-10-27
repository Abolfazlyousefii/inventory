<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Read-only, cursor-paginated rows for Excel-compatible stock exports.
 *
 * No PDF rendering, image downloads, worker/queue, temporary files,
 * or unbounded Eloquent relationships are involved.
 */
final class ProductSelectedStockExportService
{
    public const BATCH_SIZE = 300;
    public const MAX_ROWS = 50_000;

    /** @param array<int,int> $productIds */
    public function validateProducts(array $productIds): void
    {
        if (Product::query()->whereIn('id', $productIds)->count() !== count($productIds)) {
            throw ValidationException::withMessages([
                'product_ids' => 'یک یا چند کالای انتخاب‌شده دیگر در نرم‌افزار موجود نیست.',
            ]);
        }
    }

    /** @param array<int,int> $productIds */
    public function countRows(array $productIds): int
    {
        $variants = DB::table('product_variants')
            ->whereIn('product_id', $productIds)
            ->where('is_active', 1)
            ->where('stock', '>', 0)
            ->count();

        $simple = Product::query()->whereIn('id', $productIds)
            ->where('stock', '>', 0)
            ->whereDoesntHave('variants')
            ->count();

        return $variants + $simple;
    }

    /** @param array<int,int> $productIds
     *  @return array{rows:array<int,array<string,int|string|bool|null>>,next_cursor:int,has_more:bool}
     */
    public function batch(array $productIds, int $afterId): array
    {
        // Simple stocked products have no variant row and can fit in the first
        // response: the selection itself is limited to 200 products.
        $simple = [];
        if ($afterId === 0) {
            $simple = Product::query()->with('category:id,name')
                ->whereIn('id', $productIds)->where('stock', '>', 0)
                ->whereDoesntHave('variants')->orderBy('id')->get([
                    'id', 'name', 'code', 'sku', 'category_id', 'stock', 'reserved', 'price', 'is_sellable',
                ])->map(static fn (Product $p) => [
                    'product_id' => (int) $p->id,
                    'product_name' => (string) $p->name,
                    'product_code' => (string) ($p->code ?? ''),
                    'product_sku' => (string) ($p->sku ?? ''),
                    'category_name' => (string) ($p->category?->name ?? ''),
                    'variant_id' => null,
                    'variant_code' => '',
                    'variant_name' => '',
                    'model_name' => '',
                    'brand_name' => '',
                    'color_name' => '',
                    'variety_name' => '',
                    'stock' => (int) $p->stock,
                    'reserved' => (int) ($p->reserved ?? 0),
                    'sell_price_rial' => (int) ($p->price ?? 0),
                    'sales_enabled' => (bool) $p->is_sellable,
                ])->all();
        }

        $variants = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('model_lists as m', 'm.id', '=', 'v.model_list_id')
            ->leftJoin('colors as col', 'col.id', '=', 'v.color_id')
            ->whereIn('v.product_id', $productIds)
            ->where('v.is_active', 1)
            ->where('v.stock', '>', 0)
            ->where('v.id', '>', $afterId)
            ->orderBy('v.id')
            ->limit(self::BATCH_SIZE + 1)
            ->get([
                'v.id as variant_id', 'v.product_id', 'v.variant_code',
                'v.variant_name', 'v.variety_name', 'v.stock',
                'v.reserved', 'v.sell_price as sell_price_rial',
                'v.sales_enabled', 'p.name as product_name',
                'p.code as product_code', 'p.sku as product_sku',
                'p.is_sellable', 'c.name as category_name',
                'm.model_name', 'm.brand as brand_name', 'col.name as color_name',
            ]);

        $hasMore = $variants->count() > self::BATCH_SIZE;
        $page = $variants->take(self::BATCH_SIZE);
        $lastId = (int) ($page->last()?->variant_id ?? $afterId);

        $rows = $page->map(static fn ($v) => [
            'product_id' => (int) $v->product_id,
            'product_name' => (string) $v->product_name,
            'product_code' => (string) ($v->product_code ?? ''),
            'product_sku' => (string) ($v->product_sku ?? ''),
            'category_name' => (string) ($v->category_name ?? ''),
            'variant_id' => (int) $v->variant_id,
            'variant_code' => (string) ($v->variant_code ?? ''),
            'variant_name' => (string) ($v->variant_name ?? ''),
            'model_name' => (string) ($v->model_name ?? ''),
            'brand_name' => (string) ($v->brand_name ?? ''),
            'color_name' => (string) ($v->color_name ?? ''),
            'variety_name' => (string) ($v->variety_name ?? ''),
            'stock' => (int) $v->stock,
            'reserved' => (int) ($v->reserved ?? 0),
            'sell_price_rial' => (int) ($v->sell_price_rial ?? 0),
            'sales_enabled' => (bool) $v->sales_enabled && (bool) $v->is_sellable,
        ])->all();

        return ['rows' => [...$simple, ...$rows], 'next_cursor' => $lastId, 'has_more' => $hasMore];
    }
}
