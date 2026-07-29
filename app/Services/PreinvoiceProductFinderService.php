<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Support\ProductFinderSearchNormalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PreinvoiceProductFinderService
{
    public function search(array $filters): array
    {
        $term = ProductFinderSearchNormalizer::normalize($filters['q'] ?? '');
        $categoryId = (int) ($filters['subcategory_id'] ?? $filters['category_id'] ?? 0);
        $inStockOnly = (bool) ($filters['in_stock_only'] ?? true);
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 10), 50);

        if (mb_strlen(ProductFinderSearchNormalizer::compact($term)) < 2 && $categoryId <= 0) {
            return ['data' => [], 'meta' => $this->emptyMeta($perPage), 'has_filters' => false];
        }

        $query = Product::query()
            ->select(['products.id', 'products.name', 'products.code', 'products.short_barcode', 'products.sku', 'products.barcode', 'products.image_path', 'products.category_id'])
            ->with('category:id,name,parent_id')
            ->where('products.is_sellable', true)
            ->withCount(['variants as sellable_variants_count' => fn (Builder $q) => $q->active()->where('sales_enabled', true)->where('stock', '>', 0)])
            ->withSum(['variants as total_available_stock' => fn (Builder $q) => $q->active()->where('sales_enabled', true)->where('stock', '>', 0)], 'stock');

        if ($categoryId > 0) {
            $query->whereIn('products.category_id', Category::selfAndDescendantIds($categoryId));
        }
        if ($inStockOnly) {
            $query->whereHas('variants', fn (Builder $q) => $q->active()->where('sales_enabled', true)->where('stock', '>', 0));
        }
        if ($term !== '') {
            $query->where(fn (Builder $q) => $this->applyMatch($q, $term));
            $query->withCount(['variants as matched_variants_count' => fn (Builder $q) => $this->applyVariantMatch($q, $term)]);
            $this->applyRanking($query, $term);
        } else {
            $query->orderBy('products.name')->orderBy('products.id');
        }

        $paginator = $query->paginate($perPage);
        $products = collect($paginator->items());
        $matched = $this->matchedVariants($products->pluck('id'), $term);
        $categoryPaths = $this->categoryPaths($products->pluck('category_id'));

        return [
            'data' => $products->map(fn (Product $product) => $this->productPayload($product, $matched[(int) $product->id] ?? collect(), $categoryPaths))->values()->all(),
            'meta' => $this->paginationMeta($paginator),
            'has_filters' => true,
        ];
    }

    public function categories(?int $parentId = null): array
    {
        return Category::query()->select(['id', 'name', 'parent_id'])
            ->where('parent_id', $parentId)->orderBy('name')->get()
            ->map(fn (Category $category) => ['id' => (int) $category->id, 'name' => $category->name])->all();
    }

    private function applyMatch(Builder $query, string $term): void
    {
        $variants = ProductFinderSearchNormalizer::databaseVariants($term);
        $compact = ProductFinderSearchNormalizer::compact($term);
        $shortBarcode = ctype_digit($term) && strlen($term) <= 4
            ? str_pad($term, 4, '0', STR_PAD_LEFT)
            : null;
        foreach ($variants as $index => $variant) {
            $method = $index === 0 ? 'where' : 'orWhere';
            $query->{$method}(function (Builder $q) use ($variant, $compact, $shortBarcode) {
                $like = '%'.$variant.'%';
                $q->when($shortBarcode !== null, fn (Builder $product) => $product->where('products.short_barcode', $shortBarcode)->orWhere('products.name', 'like', $like), fn (Builder $product) => $product->where('products.name', 'like', $like))
                    ->orWhere('products.code', 'like', $like)->orWhere('products.short_barcode', 'like', $like)
                    ->orWhere('products.sku', 'like', $like)->orWhere('products.barcode', 'like', $like)
                    ->orWhereRaw("LOWER(REPLACE(products.name, ' ', '')) LIKE ?", ['%'.$compact.'%'])
                    ->orWhereHas('category', fn (Builder $category) => $category->where('name', 'like', $like))
                    ->orWhereHas('variants', function (Builder $variantQuery) use ($like, $compact) {
                        $variantQuery->where('variant_name', 'like', $like)->orWhere('variety_name', 'like', $like)
                            ->orWhere('variant_code', 'like', $like)->orWhere('variety_code', 'like', $like)
                            ->orWhere('unique_key', 'like', $like)
                            ->orWhereRaw("LOWER(REPLACE(product_variants.variant_name, ' ', '')) LIKE ?", ['%'.$compact.'%'])
                            ->orWhereHas('modelList', fn (Builder $model) => $model->where('model_name', 'like', $like)
                                ->orWhere('brand', 'like', $like)
                                ->orWhere('code', 'like', $like)
                                ->orWhereRaw("LOWER(REPLACE(model_lists.model_name, ' ', '')) LIKE ?", ['%'.$compact.'%']));
                    });
            });
        }
    }

    private function applyRanking(Builder $query, string $term): void
    {
        $compact = ProductFinderSearchNormalizer::compact($term);
        $like = '%'.$term.'%';
        $prefix = $term.'%';
        $compactPrefix = $compact.'%';
        $query->orderByRaw("CASE
            WHEN products.short_barcode = ? THEN 0
            WHEN products.code = ? OR products.sku = ? OR products.barcode = ? THEN 1
            WHEN EXISTS (SELECT 1 FROM product_variants pv JOIN model_lists ml ON ml.id = pv.model_list_id WHERE pv.product_id = products.id AND LOWER(REPLACE(ml.model_name, ' ', '')) = ?) THEN 2
            WHEN EXISTS (SELECT 1 FROM product_variants pv JOIN model_lists ml ON ml.id = pv.model_list_id WHERE pv.product_id = products.id AND LOWER(REPLACE(ml.model_name, ' ', '')) LIKE ?) THEN 3
            WHEN EXISTS (SELECT 1 FROM product_variants pv WHERE pv.product_id = products.id AND (LOWER(REPLACE(pv.variant_name, ' ', '')) = ? OR LOWER(REPLACE(pv.variety_name, ' ', '')) = ?)) THEN 4
            WHEN EXISTS (SELECT 1 FROM product_variants pv WHERE pv.product_id = products.id AND (pv.variant_name LIKE ? OR pv.variety_name LIKE ?)) THEN 5
            WHEN EXISTS (SELECT 1 FROM product_variants pv LEFT JOIN model_lists ml ON ml.id = pv.model_list_id WHERE pv.product_id = products.id AND (pv.variant_name LIKE ? OR pv.variety_name LIKE ? OR ml.model_name LIKE ? OR ml.brand LIKE ?)) THEN 6
            WHEN products.name LIKE ? THEN 7
            WHEN EXISTS (SELECT 1 FROM categories c WHERE c.id = products.category_id AND c.name LIKE ?) THEN 8
            ELSE 9 END", [$term, $term, $term, $term, $compact, $compactPrefix, $compact, $compact, $prefix, $prefix, $like, $like, $like, $like, $like, $like])
            ->orderBy('products.name')->orderBy('products.id');
    }

    private function matchedVariants(Collection $productIds, string $term): Collection
    {
        if ($term === '' || $productIds->isEmpty()) {
            return collect();
        }
        $like = '%'.$term.'%';
        $compact = ProductFinderSearchNormalizer::compact($term);

        $ranked = DB::table('product_variants as pv')->leftJoin('model_lists as ml', 'ml.id', '=', 'pv.model_list_id')
            ->selectRaw('pv.id, pv.product_id, pv.variant_name, pv.variety_name, pv.stock, pv.is_active, pv.sales_enabled, ml.brand AS model_brand, ml.model_name, ROW_NUMBER() OVER (PARTITION BY pv.product_id ORDER BY pv.stock DESC, pv.id) AS preview_rank')
            ->whereIn('pv.product_id', $productIds)
            ->where(function ($q) use ($like, $compact) {
                $q->where('pv.variant_name', 'like', $like)->orWhere('pv.variety_name', 'like', $like)
                    ->orWhere('pv.variant_code', 'like', $like)->orWhere('pv.variety_code', 'like', $like)->orWhere('pv.unique_key', 'like', $like)
                    ->orWhere('ml.model_name', 'like', $like)->orWhere('ml.brand', 'like', $like)->orWhere('ml.code', 'like', $like)
                    ->orWhereRaw("LOWER(REPLACE(pv.variant_name, ' ', '')) LIKE ?", ['%'.$compact.'%'])
                    ->orWhereRaw("LOWER(REPLACE(ml.model_name, ' ', '')) LIKE ?", ['%'.$compact.'%']);
            });

        return DB::query()->fromSub($ranked, 'matched')->where('preview_rank', '<=', 3)
            ->orderBy('product_id')->orderBy('preview_rank')->get()->groupBy('product_id');
    }

    private function applyVariantMatch(Builder $query, string $term): void
    {
        $like = '%'.$term.'%';
        $compact = ProductFinderSearchNormalizer::compact($term);
        $query->where(function (Builder $q) use ($like, $compact) {
            $q->where('variant_name', 'like', $like)->orWhere('variety_name', 'like', $like)
                ->orWhere('variant_code', 'like', $like)->orWhere('variety_code', 'like', $like)->orWhere('unique_key', 'like', $like)
                ->orWhereRaw("LOWER(REPLACE(product_variants.variant_name, ' ', '')) LIKE ?", ['%'.$compact.'%'])
                ->orWhereHas('modelList', fn (Builder $model) => $model->where('model_name', 'like', $like)
                    ->orWhere('brand', 'like', $like)->orWhere('code', 'like', $like)
                    ->orWhereRaw("LOWER(REPLACE(model_lists.model_name, ' ', '')) LIKE ?", ['%'.$compact.'%']));
        });
    }

    private function productPayload(Product $product, Collection $matched, Collection $paths): array
    {
        return [
            'id' => (int) $product->id, 'name' => $product->name, 'title' => $product->name,
            'code' => $product->code, 'short_code' => $product->short_barcode, 'short_barcode' => $product->short_barcode,
            'sku' => $product->sku, 'image' => $product->image_path ? asset('storage/'.$product->image_path) : null,
            'category' => $product->category ? ['id' => (int) $product->category->id, 'name' => $product->category->name, 'path' => $paths[(int) $product->category_id] ?? $product->category->name] : null,
            'matched_variants_count' => (int) ($product->matched_variants_count ?? 0),
            'matched_variants' => $matched->map(fn (object $variant) => [
                'id' => (int) $variant->id,
                'name' => $variant->model_name
                    ? trim(($variant->model_brand ? $variant->model_brand.' ' : '').$variant->model_name)
                    : ($variant->variant_name ?: $variant->variety_name),
                'available_stock' => $variant->is_active && $variant->sales_enabled ? max((int) $variant->stock, 0) : 0,
            ])->values()->all(),
            'sellable_variants_count' => (int) $product->sellable_variants_count,
            'total_available_stock' => (int) ($product->total_available_stock ?? 0),
            'match_reason' => $matched->isNotEmpty() ? 'variant' : 'product',
        ];
    }

    private function categoryPaths(Collection $ids): Collection
    {
        $all = Category::query()->get(['id', 'name', 'parent_id'])->keyBy('id');

        return $ids->unique()->mapWithKeys(function ($id) use ($all) {
            $parts = [];
            $cursor = $all->get((int) $id);
            $guard = 0;
            while ($cursor && $guard++ < 20) {
                array_unshift($parts, $cursor->name);
                $cursor = $cursor->parent_id ? $all->get((int) $cursor->parent_id) : null;
            }

            return [(int) $id => implode(' / ', $parts)];
        });
    }

    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'has_more' => $paginator->hasMorePages()];
    }

    private function emptyMeta(int $perPage): array
    {
        return ['current_page' => 1, 'last_page' => 1, 'per_page' => $perPage, 'total' => 0, 'has_more' => false];
    }
}
