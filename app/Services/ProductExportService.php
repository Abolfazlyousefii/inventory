<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ModelList;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class ProductExportService
{
    public function buildQuery(array $filters): Builder
    {
        $modelListIds = $this->modelListIds($filters);
        $categoryIds = $this->categoryIds($filters);
        $stockStatus = $filters['stock_status'] ?? 'all';

        return Product::query()
            ->select(['id', 'name', 'image_path', 'price', 'stock', 'category_id', 'models', 'has_colors', 'is_sellable', 'updated_at'])
            ->with(['category:id,name'])
            ->with(['catalogVariants' => function ($query) use ($modelListIds) {
                $this->applyCatalogVariantConstraints($query, ['model_list_ids' => $modelListIds]);

                $query->select([
                    'id', 'product_id', 'model_list_id', 'variant_name', 'variety_name', 'variety_code',
                    'variant_code', 'sell_price', 'stock', 'is_active', 'sales_enabled',
                ])
                    ->with(['modelList:id,brand,model_name,code'])
                    ->orderBy('model_list_id')
                    ->orderBy('variant_name')
                    ->orderBy('variety_name')
                    ->orderBy('id');
            }])
            ->when($categoryIds !== [], fn (Builder $query) => $query->whereIn('category_id', $categoryIds))
            ->when($modelListIds !== [], fn (Builder $query) => $query->whereHas('catalogVariants', function (Builder $variantQuery) use ($modelListIds) {
                $this->applyCatalogVariantConstraints($variantQuery, ['model_list_ids' => $modelListIds]);
            }))
            ->when($stockStatus !== 'all', fn (Builder $query) => $this->applyStockStatus($query, $stockStatus, $modelListIds))
            ->orderBy('name')
            ->orderBy('id');
    }

    public function applyCatalogVariantConstraints(Builder|Relation $query, array $filters = []): Builder|Relation
    {
        $modelListIds = $this->modelListIds($filters);

        $query->where('is_active', true);

        if ($modelListIds !== []) {
            $query->whereIn('model_list_id', $modelListIds);
        }

        return $query;
    }

    public function paginate(array $filters, int $perPage = 24): LengthAwarePaginator
    {
        $paginator = $this->buildQuery($filters)->paginate($perPage)->withQueryString();
        $paginator->setCollection($paginator->getCollection()->map(fn (Product $product) => $this->mapProduct($product, $filters)));

        return $paginator;
    }

    public function allForPrint(array $filters): Collection
    {
        $products = $this->buildQuery($filters)->get()->map(fn (Product $product) => $this->mapProduct($product, $filters));
        return $products;
    }

    public function mapProduct(Product $product, array $filters): array
    {
        $variantsCollection = $product->catalogVariants;
        $variants = $variantsCollection->map(fn (ProductVariant $variant) => [
            'id' => $variant->id,
            'name' => $this->variantDisplayName($variant),
            'model_list_name' => $this->cleanText($variant->modelList?->model_name, ''),
            'price' => $this->variantPrice($variant, $product),
            'price_label' => $this->priceLabel($this->variantPrice($variant, $product)),
        ])->values()->all();

        $price = $this->productPrice($product, $variantsCollection);

        return [
            'id' => $product->id,
            'name' => $this->cleanText($product->name, 'محصول بدون نام'),
            'image_url' => $this->imageUrl($product),
            'price' => $price,
            'price_label' => $this->productPriceLabel($price, $variantsCollection),
            'category_name' => $this->cleanText($product->category?->name, 'بدون دسته‌بندی'),
            'variants' => $variants,
        ];
    }

    public function meta(array $filters): array
    {
        $root = ! empty($filters['root_category_id']) ? Category::query()->find($filters['root_category_id']) : null;
        $child = ! empty($filters['subcategory_id']) ? Category::query()->find($filters['subcategory_id']) : null;
        $modelIds = $this->modelListIds($filters);
        $models = $modelIds === [] ? collect() : ModelList::query()->whereIn('id', $modelIds)->orderBy('model_name')->get();

        return [
            'root_category' => $this->cleanText($root?->name, 'همه دسته‌ها'),
            'subcategory' => $this->cleanText($child?->name, 'همه زیردسته‌ها'),
            'model_brand' => $this->cleanText($filters['model_brand'] ?? null, 'همه انواع مدل'),
            'model_lists' => $models->isEmpty() ? 'همه مدل‌ها' : $models->pluck('model_name')->implode('، '),
            'stock_status' => match ($filters['stock_status'] ?? 'all') {
                'in_stock' => 'موجود',
                'out_of_stock' => 'ناموجود',
                default => 'همه',
            },
            'generated_at' => now()->format('Y/m/d H:i'),
            'products_count' => null,
            'store_name' => config('app.name', 'سامانه انبارداری'),
        ];
    }

    public function modelListIds(array $filters): array
    {
        return collect($filters['model_list_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
    }

    private function applyStockStatus(Builder $query, string $status, array $modelListIds): void
    {
        $query->where(function (Builder $productQuery) use ($status, $modelListIds) {
            $base = ['model_list_ids' => $modelListIds];
            if ($status === 'in_stock') {
                $productQuery->whereHas('catalogVariants', function (Builder $variantQuery) use ($base) {
                    $this->applyCatalogVariantConstraints($variantQuery, $base)->where('stock', '>', 0);
                })->orWhere(function (Builder $noVariantQuery) {
                    $noVariantQuery->whereDoesntHave('catalogVariants')->where('stock', '>', 0);
                });
                return;
            }

            $productQuery->whereHas('catalogVariants', function (Builder $variantQuery) use ($base) {
                $this->applyCatalogVariantConstraints($variantQuery, $base);
            })->whereDoesntHave('catalogVariants', function (Builder $variantQuery) use ($base) {
                $this->applyCatalogVariantConstraints($variantQuery, $base)->where('stock', '>', 0);
            })->orWhere(function (Builder $noVariantQuery) {
                $noVariantQuery->whereDoesntHave('catalogVariants')->where('stock', '<=', 0);
            });
        });
    }

    private function categoryIds(array $filters): array
    {
        $categoryId = $filters['subcategory_id'] ?: ($filters['root_category_id'] ?? null);
        return $categoryId ? Category::selfAndDescendantIds((int) $categoryId) : [];
    }

    private function productPrice(Product $product, Collection $variants): ?int
    {
        $variantPrice = $variants->pluck('sell_price')->filter(fn ($price) => (int) $price > 0)->min();
        if ($variantPrice) return (int) $variantPrice;
        return (int) ($product->price ?? 0) > 0 ? (int) $product->price : null;
    }

    private function productPriceLabel(?int $price, Collection $variants): string
    {
        if (! $price) return 'بدون قیمت';
        $prices = $variants->pluck('sell_price')->map(fn ($price) => (int) $price)->filter(fn ($price) => $price > 0)->unique();
        return ($prices->count() > 1 ? 'از ' : '') . $this->priceLabel($price);
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

    private function variantDisplayName(ProductVariant $variant): string
    {
        $parts = collect([$variant->modelList?->model_name, $variant->variant_name, $variant->variety_name])
            ->map(fn ($value) => $this->cleanText($value, ''))
            ->filter()
            ->unique(fn ($value) => mb_strtolower($value))
            ->values();

        if ($parts->isEmpty()) {
            $parts->push($this->cleanText($variant->variant_code, 'تنوع بدون نام'));
        }

        return $parts->implode(' - ');
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
        return 'data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2232%22 height=%2232%22%3E%3Crect width=%2232%22 height=%2232%22 fill=%22%23f8fafc%22/%3E%3Ctext x=%2216%22 y=%2220%22 font-size=%2210%22 text-anchor=%22middle%22 fill=%22%23718096%22%3EARIA%3C/text%3E%3C/svg%3E';
    }

    private function cleanText(mixed $value, string $fallback = ''): string
    {
        $text = trim(strip_tags((string) $value));
        return $text === '' ? $fallback : str_replace(['ي', 'ك', "\u{200F}", "\u{200E}"], ['ی', 'ک', '', ''], $text);
    }
}
