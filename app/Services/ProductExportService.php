<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ModelList;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProductExportService
{
    public function buildQuery(array $filters): Builder
    {
        $modelListIds = $this->modelListIds($filters);
        $categoryIds = $this->categoryIds($filters);
        $stockStatus = $filters['stock_status'] ?? 'all';

        return Product::query()
            ->select(['id', 'name', 'image_path', 'price', 'stock', 'category_id', 'is_sellable', 'updated_at'])
            ->with(['category:id,name'])
            ->with(['validVariants' => function ($query) use ($modelListIds) {
                $query->select(['id', 'product_id', 'model_list_id', 'variant_name', 'variety_name', 'sell_price', 'stock', 'is_active', 'sales_enabled', 'variant_code'])
                    ->where('is_active', true)
                    ->where('sales_enabled', true)
                    ->when($modelListIds !== [], fn ($q) => $q->whereIn('model_list_id', $modelListIds))
                    ->with('modelList:id,model_name,code')
                    ->orderBy('variant_name')
                    ->orderBy('variety_name')
                    ->orderBy('id');
            }])
            ->when($categoryIds !== [], fn (Builder $query) => $query->whereIn('category_id', $categoryIds))
            ->when($modelListIds !== [], fn (Builder $query) => $query->whereHas('validVariants', function (Builder $variantQuery) use ($modelListIds) {
                $variantQuery->where('is_active', true)->where('sales_enabled', true)->whereIn('model_list_id', $modelListIds);
            }))
            ->when($stockStatus !== 'all', fn (Builder $query) => $this->applyStockStatus($query, $stockStatus, $modelListIds))
            ->orderBy('name')
            ->orderBy('id');
    }

    public function paginate(array $filters, int $perPage = 24): LengthAwarePaginator
    {
        $paginator = $this->buildQuery($filters)->paginate($perPage)->withQueryString();
        $paginator->setCollection($paginator->getCollection()->map(fn (Product $product) => $this->mapProduct($product, $filters)));

        return $paginator;
    }

    public function allForPrint(array $filters): Collection
    {
        return $this->buildQuery($filters)->get()->map(fn (Product $product) => $this->mapProduct($product, $filters));
    }

    public function mapProduct(Product $product, array $filters): array
    {
        $variants = $product->validVariants->map(fn (ProductVariant $variant) => [
            'id' => $variant->id,
            'name' => $this->variantName($variant),
            'model_list_name' => $this->cleanText($variant->modelList?->model_name, 'بدون مدل'),
            'price' => $this->variantPrice($variant, $product),
            'price_label' => $this->priceLabel($this->variantPrice($variant, $product)),
        ])->values()->all();

        $price = $this->productPrice($product);

        return [
            'id' => $product->id,
            'name' => $this->cleanText($product->name, 'محصول بدون نام'),
            'image_url' => $this->imageUrl($product),
            'price' => $price,
            'price_label' => $this->priceLabel($price),
            'category_name' => $this->cleanText($product->category?->name, 'بدون دسته‌بندی'),
            'variants' => $variants,
        ];
    }

    public function meta(array $filters): array
    {
        $root = ! empty($filters['root_category_id']) ? Category::query()->find($filters['root_category_id']) : null;
        $child = ! empty($filters['subcategory_id']) ? Category::query()->find($filters['subcategory_id']) : null;
        $models = $this->modelListIds($filters) === [] ? collect() : ModelList::query()->whereIn('id', $this->modelListIds($filters))->orderBy('model_name')->get();

        return [
            'root_category' => $this->cleanText($root?->name, 'همه دسته‌ها'),
            'subcategory' => $this->cleanText($child?->name, 'همه زیردسته‌ها'),
            'model_lists' => $models->isEmpty() ? 'همه مدل‌ها' : $models->pluck('model_name')->implode('، '),
            'stock_status' => match ($filters['stock_status'] ?? 'all') {
                'in_stock' => 'موجود',
                'out_of_stock' => 'ناموجود',
                default => 'همه',
            },
            'generated_at' => now()->format('Y/m/d H:i'),
            'store_name' => config('app.name', 'سامانه انبارداری'),
        ];
    }

    private function applyStockStatus(Builder $query, string $status, array $modelListIds): void
    {
        $query->where(function (Builder $productQuery) use ($status, $modelListIds) {
            if ($status === 'in_stock') {
                $productQuery->whereHas('validVariants', function (Builder $variantQuery) use ($modelListIds) {
                    $variantQuery->where('is_active', true)->where('sales_enabled', true)->where('stock', '>', 0)
                        ->when($modelListIds !== [], fn ($q) => $q->whereIn('model_list_id', $modelListIds));
                })->orWhere(function (Builder $noVariantQuery) {
                    $noVariantQuery->whereDoesntHave('validVariants')->where('stock', '>', 0);
                });
                return;
            }

            $productQuery->whereHas('validVariants', function (Builder $variantQuery) use ($modelListIds) {
                $variantQuery->where('is_active', true)->where('sales_enabled', true)
                    ->when($modelListIds !== [], fn ($q) => $q->whereIn('model_list_id', $modelListIds));
            })->whereDoesntHave('validVariants', function (Builder $variantQuery) use ($modelListIds) {
                $variantQuery->where('is_active', true)->where('sales_enabled', true)->where('stock', '>', 0)
                    ->when($modelListIds !== [], fn ($q) => $q->whereIn('model_list_id', $modelListIds));
            })->orWhere(function (Builder $noVariantQuery) {
                $noVariantQuery->whereDoesntHave('validVariants')->where('stock', '<=', 0);
            });
        });
    }

    private function categoryIds(array $filters): array
    {
        $categoryId = $filters['subcategory_id'] ?: ($filters['root_category_id'] ?? null);
        return $categoryId ? Category::selfAndDescendantIds((int) $categoryId) : [];
    }

    private function modelListIds(array $filters): array
    {
        return collect($filters['model_list_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
    }

    private function productPrice(Product $product): ?int
    {
        $variantPrice = $product->validVariants->pluck('sell_price')->filter(fn ($price) => (int) $price > 0)->min();
        if ($variantPrice) return (int) $variantPrice;
        return (int) ($product->price ?? 0) > 0 ? (int) $product->price : null;
    }

    private function variantPrice(ProductVariant $variant, Product $product): ?int
    {
        $price = (int) ($variant->sell_price ?? 0);
        if ($price > 0) return $price;
        return (int) ($product->price ?? 0) > 0 ? (int) $product->price : null;
    }

    private function priceLabel(?int $price): string
    {
        return $price ? number_format($price) . ' ریال' : 'بدون قیمت';
    }

    private function variantName(ProductVariant $variant): string
    {
        return $this->cleanText($variant->variant_name ?: $variant->variety_name ?: $variant->modelList?->model_name, 'تنوع بدون نام');
    }

    public function imageUrl(Product $product): string
    {
        $path = trim((string) ($product->image_path ?? ''));
        if ($path !== '' && filter_var($path, FILTER_VALIDATE_URL)) return $path;
        if ($path !== '') return route('products.image', $product);
        return $this->placeholderImage();
    }

    private function placeholderImage(): string
    {
        return 'data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2272%22 height=%2272%22%3E%3Crect width=%2272%22 height=%2272%22 rx=%2214%22 fill=%22%23eef6fc%22/%3E%3Ctext x=%2236%22 y=%2244%22 font-size=%2224%22 text-anchor=%22middle%22%3E📦%3C/text%3E%3C/svg%3E';
    }

    private function cleanText(mixed $value, string $fallback = ''): string
    {
        $text = trim(strip_tags((string) $value));
        return $text === '' ? $fallback : str_replace(['ي', 'ك', "\u{200F}", "\u{200E}"], ['ی', 'ک', '', ''], $text);
    }
}
