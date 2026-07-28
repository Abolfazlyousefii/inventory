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
use Illuminate\Support\Facades\Schema;

class ProductExportService
{
    private ?array $productSearchColumnsCache = null;

    private ?array $variantSearchColumnsCache = null;

    public function __construct(private readonly ProductCatalogGroupingService $groupingService = new ProductCatalogGroupingService()) {}

    public function buildQuery(array $filters): Builder
    {
        $modelListIds = $this->modelListIds($filters);
        $productIds = $this->productIds($filters);
        $categoryIds = $this->categoryIds($filters);
        $stockStatus = $filters['stock_status'] ?? 'all';

        return Product::query()
            ->select(['id', 'name', 'image_path', 'price', 'stock', 'category_id', 'models', 'has_colors', 'is_sellable', 'updated_at'])
            ->with(['category:id,name'])
            ->with(['catalogVariants' => function ($query) use ($modelListIds) {
                $this->applyCatalogVariantConstraints($query, ['model_list_ids' => $modelListIds]);

                $query->select([
                    'id', 'product_id', 'model_list_id', 'color_id', 'variant_name', 'variety_name', 'variety_code',
                    'variant_code', 'sell_price', 'stock', 'is_active', 'sales_enabled',
                ])
                    ->with(['modelList:id,brand,model_name,code', 'color:id,name,code,hex_code'])
                    ->orderBy('model_list_id')
                    ->orderBy('variant_name')
                    ->orderBy('variety_name')
                    ->orderBy('id');
            }])
            ->when($categoryIds !== [], fn (Builder $query) => $query->whereIn('category_id', $categoryIds))
            ->when($productIds !== [], fn (Builder $query) => $query->whereIn('products.id', $productIds))
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
        $mapped = $paginator->getCollection()->map(fn (Product $product) => $this->mapProduct($product, $filters));
        $paginator->setCollection($this->filterWithoutPrice($mapped, $filters));

        return $paginator;
    }

    public function allForPrint(array $filters): Collection
    {
        $products = $this->buildQuery($filters)->get()->map(fn (Product $product) => $this->mapProduct($product, $filters));
        return $this->filterWithoutPrice($products, $filters)->values();
    }

    public function mapProduct(Product $product, array $filters): array
    {
        $variantsCollection = $product->catalogVariants;
        $grouped = $this->groupingService->group($product, $variantsCollection);
        $imagePath = $this->imagePath($product);
        $modelsTextLength = collect($grouped['groups'])->flatMap(fn ($group) => $group['models'])->implode('، ');

        return [
            'id' => $product->id,
            'name' => $this->cleanText($product->name, 'محصول بدون نام'),
            'category_name' => $this->cleanText($product->category?->name, 'بدون دسته‌بندی'),
            'image_path' => $imagePath,
            'has_real_image' => $imagePath !== null,
            'price_min' => $grouped['price_min'],
            'price_max' => $grouped['price_max'],
            'price_summary' => $grouped['price_summary'],
            'variant_count' => $grouped['variant_count'],
            'model_count' => $grouped['model_count'],
            'color_count' => $grouped['color_count'],
            'groups' => $grouped['groups'],
            'is_wide' => count($grouped['groups']) > 4 || $grouped['model_count'] > 20 || mb_strlen($modelsTextLength) > 180,
            'has_price' => $grouped['has_price'],
        ];
    }

    public function meta(array $filters): array
    {
        $root = ! empty($filters['root_category_id']) ? Category::query()->find($filters['root_category_id']) : null;
        $child = ! empty($filters['subcategory_id']) ? Category::query()->find($filters['subcategory_id']) : null;
        $modelIds = $this->modelListIds($filters);
        $models = $modelIds === [] ? collect() : ModelList::query()->whereIn('id', $modelIds)->orderBy('model_name')->get();
        $selectedProducts = $this->selectedProducts($this->productIds($filters));
        $selectedProductsLabel = match (true) {
            $selectedProducts->isEmpty() => 'همه محصولات',
            $selectedProducts->count() <= 3 => $selectedProducts->pluck('name')->implode('، '),
            default => number_format($selectedProducts->count()).' محصول انتخاب‌شده',
        };

        return [
            'title' => 'لیست قیمت محصولات',
            'root_category' => $this->cleanText($root?->name, 'همه دسته‌ها'),
            'subcategory' => $this->cleanText($child?->name, 'همه زیردسته‌ها'),
            'model_brand' => $this->cleanText($filters['model_brand'] ?? null, 'همه انواع مدل'),
            'model_lists' => $models->isEmpty() ? 'همه مدل‌ها' : number_format($models->count()).' مدل انتخاب‌شده',
            'selected_models_count' => $models->count(),
            'selected_products' => $selectedProductsLabel,
            'selected_products_count' => $selectedProducts->count(),
            'stock_status' => match ($filters['stock_status'] ?? 'all') {
                'in_stock' => 'موجود',
                'out_of_stock' => 'ناموجود',
                default => 'همه',
            },
            'generated_at' => now()->format('Y/m/d H:i'),
            'products_count' => null,
            'store_name' => 'آریا گستر',
        ];
    }

    private function filterWithoutPrice(Collection $products, array $filters): Collection
    {
        return ($filters['include_without_price'] ?? false) ? $products : $products->filter(fn (array $product) => $product['has_price']);
    }

    public function modelListIds(array $filters): array
    {
        return collect($filters['model_list_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
    }

    public function productIds(array $filters): array
    {
        return collect($filters['product_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    public function selectedProducts(array $productIds): Collection
    {
        $ids = $this->productIds(['product_ids' => $productIds]);
        if ($ids === []) {
            return collect();
        }

        $products = Product::query()
            ->with('category:id,name')
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'code', 'sku', 'barcode', 'short_barcode', 'category_id'])
            ->keyBy('id');

        return collect($ids)->map(fn ($id) => $products->get($id))->filter()->values();
    }

    public function searchProducts(array $filters, string $term, int $limit = 30): Collection
    {
        $term = $this->normalizeSearchTerm($term);
        if ($term === '') {
            return collect();
        }

        $searchFilters = array_merge($filters, ['product_ids' => []]);
        $modelListIds = $this->modelListIds($searchFilters);
        $limit = max(1, min($limit, $this->searchLimit($term)));
        $tokens = $this->searchTokens($term);
        $query = $this->buildQuery($searchFilters)
            ->addSelect(['code', 'sku', 'barcode', 'short_barcode'])
            ->reorder();

        foreach ($tokens as $token) {
            $variants = $this->searchValueVariants($token);
            $query->where(function (Builder $tokenQuery) use ($variants, $modelListIds, $token) {
                $this->applyProductSearchPatterns($tokenQuery, $variants);

                if (ctype_digit($token)) {
                    $tokenQuery->orWhere('products.id', (int) $token);
                }

                $tokenQuery->orWhereHas('catalogVariants', function (Builder $variantQuery) use ($variants, $modelListIds) {
                    $this->applyCatalogVariantConstraints($variantQuery, ['model_list_ids' => $modelListIds]);
                    $variantQuery->where(function (Builder $query) use ($variants) {
                        $this->applyLikePatterns($query, $this->variantSearchColumns(), $variants);
                        $query->orWhereHas('modelList', function (Builder $modelQuery) use ($variants) {
                            $modelQuery->where(function (Builder $query) use ($variants) {
                                $this->applyLikePatterns($query, ['model_lists.model_name', 'model_lists.code'], $variants);
                            });
                        });
                    });
                });
            });
        }

        if (! ($filters['include_without_price'] ?? false)) {
            $query->where(function (Builder $priceQuery) use ($modelListIds) {
                $priceQuery->where('products.price', '>', 0)
                    ->orWhereHas('catalogVariants', function (Builder $variantQuery) use ($modelListIds) {
                        $this->applyCatalogVariantConstraints($variantQuery, ['model_list_ids' => $modelListIds]);
                        $variantQuery->where('sell_price', '>', 0);
                    });
            });
        }

        [$scoreSql, $scoreBindings] = $this->searchScore($term, $modelListIds);

        return $query
            ->orderByRaw("({$scoreSql}) DESC", $scoreBindings)
            ->orderBy('products.name')
            ->orderBy('products.id')
            ->limit($limit)
            ->get()
            ->unique('id')
            ->values();
    }

    public function searchLimit(string $term): int
    {
        return mb_strlen($this->normalizeSearchTerm($term)) === 1 ? 15 : 30;
    }

    public function normalizeSearchTerm(string $term): string
    {
        $term = strtr($term, [
            'ي' => 'ی',
            'ى' => 'ی',
            'ك' => 'ک',
            'ة' => 'ه',
            'ۀ' => 'ه',
            "\u{200C}" => ' ',
            "\u{200D}" => ' ',
            "\u{0640}" => '',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $term = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $term) ?? $term;

        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $term));
    }

    public function productSearchPayload(Product $product, string $term): array
    {
        $variants = $product->catalogVariants;
        $hasPrice = (int) $product->price > 0
            || $variants->contains(fn (ProductVariant $variant) => (int) $variant->sell_price > 0);
        $inStock = $variants->isNotEmpty()
            ? $variants->contains(fn (ProductVariant $variant) => (int) $variant->stock > 0)
            : (int) $product->stock > 0;
        [$availability, $availabilityLabel] = match (true) {
            ! $hasPrice => ['no_price', 'بدون قیمت'],
            $inStock => ['in_stock', 'موجود'],
            default => ['out_of_stock', 'ناموجود'],
        };

        return [
            'id' => (int) $product->id,
            'name' => $product->name,
            'code' => $product->code,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'short_barcode' => $product->short_barcode,
            'category' => $product->category?->name,
            'matched_variant' => $this->matchingVariantLabel($product, $term),
            'availability' => $availability,
            'availability_label' => $availabilityLabel,
        ];
    }

    private function searchTokens(string $term): array
    {
        $tokens = preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($tokens) > 1) {
            $tokens = array_values(array_filter($tokens, fn (string $token) => mb_strlen($token) > 1));
        }

        return array_slice($tokens === [] ? [$term] : $tokens, 0, 8);
    }

    private function searchValueVariants(string $value): array
    {
        $normalized = $this->normalizeSearchTerm($value);
        $letterVariants = [
            $normalized,
            strtr($normalized, ['ی' => 'ي', 'ک' => 'ك']),
            strtr($normalized, ['ی' => 'ى', 'ک' => 'ك']),
        ];
        $digitMaps = [
            [],
            ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'],
            ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩'],
        ];
        $variants = [];

        foreach ($letterVariants as $letters) {
            foreach ($digitMaps as $digitMap) {
                $variant = $digitMap === [] ? $letters : strtr($letters, $digitMap);
                $variants[] = $variant;
                $variants[] = mb_strtolower($variant);
                $variants[] = mb_strtoupper($variant);
            }
        }

        return collect($variants)->filter()->unique()->values()->all();
    }

    private function applyProductSearchPatterns(Builder $query, array $variants): void
    {
        $this->applyLikePatterns($query, $this->productSearchColumns(), $variants);
    }

    private function applyLikePatterns(Builder $query, array $columns, array $values): void
    {
        foreach ($columns as $column) {
            foreach ($values as $value) {
                $query->orWhere($column, 'like', '%'.$this->escapeLike($value).'%');
            }
        }
    }

    private function productSearchColumns(): array
    {
        return $this->productSearchColumnsCache ??= collect(['name', 'code', 'sku', 'barcode', 'short_barcode'])
            ->filter(fn (string $column) => Schema::hasColumn('products', $column))
            ->map(fn (string $column) => 'products.'.$column)
            ->values()
            ->all();
    }

    private function variantSearchColumns(): array
    {
        return $this->variantSearchColumnsCache ??= collect(['variant_code', 'variety_code', 'variant_name', 'variety_name', 'unique_key', 'barcode', 'sku'])
            ->filter(fn (string $column) => Schema::hasColumn('product_variants', $column))
            ->map(fn (string $column) => 'product_variants.'.$column)
            ->values()
            ->all();
    }

    private function searchScore(string $term, array $modelListIds): array
    {
        $values = $this->searchValueVariants($term);
        $codeColumns = array_values(array_intersect(
            $this->productSearchColumns(),
            ['products.code', 'products.sku', 'products.barcode', 'products.short_barcode']
        ));
        $cases = [];
        $bindings = [];

        if (ctype_digit($term)) {
            $cases[] = 'CASE WHEN products.id = ? THEN 3000 ELSE 0 END';
            $bindings[] = (int) $term;
        }

        $this->appendScoreCase($cases, $bindings, $this->searchCondition($codeColumns, $values, 'exact'), 1000);
        $this->appendScoreCase($cases, $bindings, $this->searchCondition(['products.name'], $values, 'exact'), 800);
        $this->appendScoreCase($cases, $bindings, $this->searchCondition(['products.name'], $values, 'prefix'), 700);
        $this->appendScoreCase($cases, $bindings, $this->searchCondition($codeColumns, $values, 'prefix'), 650);
        $this->appendScoreCase($cases, $bindings, $this->searchCondition(['products.name'], $values, 'contains'), 600);
        $this->appendScoreCase($cases, $bindings, $this->searchCondition($codeColumns, $values, 'contains'), 550);
        $this->appendScoreCase($cases, $bindings, $this->variantScoreCondition($values, $modelListIds, 'exact'), 500);
        $this->appendScoreCase($cases, $bindings, $this->variantScoreCondition($values, $modelListIds, 'contains'), 400);
        $this->appendScoreCase($cases, $bindings, $this->categoryScoreCondition($values), 40);

        return [$cases === [] ? '0' : implode(' + ', $cases), $bindings];
    }

    private function appendScoreCase(array &$cases, array &$bindings, array $condition, int $score): void
    {
        [$sql, $conditionBindings] = $condition;
        if ($sql === '') {
            return;
        }

        $cases[] = "CASE WHEN ({$sql}) THEN {$score} ELSE 0 END";
        array_push($bindings, ...$conditionBindings);
    }

    private function searchCondition(array $columns, array $values, string $mode): array
    {
        $conditions = [];
        $bindings = [];

        foreach ($columns as $column) {
            foreach ($values as $value) {
                $conditions[] = $mode === 'exact' ? "{$column} = ?" : "{$column} LIKE ?";
                $bindings[] = match ($mode) {
                    'prefix' => $this->escapeLike($value).'%',
                    'contains' => '%'.$this->escapeLike($value).'%',
                    default => $value,
                };
            }
        }

        return [implode(' OR ', $conditions), $bindings];
    }

    private function variantScoreCondition(array $values, array $modelListIds, string $mode): array
    {
        [$matchSql, $matchBindings] = $this->searchCondition($this->variantSearchColumns(), $values, $mode);
        if ($matchSql === '') {
            return ['', []];
        }

        $constraints = ['product_variants.product_id = products.id', 'product_variants.is_active = ?'];
        $bindings = [1];
        if ($modelListIds !== []) {
            $constraints[] = 'product_variants.model_list_id IN ('.implode(',', array_fill(0, count($modelListIds), '?')).')';
            array_push($bindings, ...$modelListIds);
        }
        $constraints[] = "({$matchSql})";
        array_push($bindings, ...$matchBindings);

        return ['EXISTS (SELECT 1 FROM product_variants WHERE '.implode(' AND ', $constraints).')', $bindings];
    }

    private function categoryScoreCondition(array $values): array
    {
        [$matchSql, $bindings] = $this->searchCondition(['search_category.name'], $values, 'contains');

        return [
            "EXISTS (SELECT 1 FROM categories AS search_category WHERE search_category.id = products.category_id AND ({$matchSql}))",
            $bindings,
        ];
    }

    private function matchingVariantLabel(Product $product, string $term): ?string
    {
        $tokens = collect($this->searchTokens($this->normalizeSearchTerm($term)))
            ->map(fn (string $token) => mb_strtolower($token))
            ->all();
        $match = $product->catalogVariants
            ->map(function (ProductVariant $variant) use ($tokens) {
                $fields = collect([
                    $variant->variant_name,
                    $variant->variety_name,
                    $variant->variant_code,
                    $variant->variety_code,
                    $variant->unique_key,
                    $variant->getAttribute('barcode'),
                    $variant->getAttribute('sku'),
                    $variant->modelList?->model_name,
                    $variant->modelList?->code,
                ])->filter();
                $haystack = mb_strtolower($this->normalizeSearchTerm($fields->implode(' ')));
                $score = collect($tokens)->filter(fn (string $token) => str_contains($haystack, $token))->count();

                return ['variant' => $variant, 'score' => $score];
            })
            ->filter(fn (array $candidate) => $candidate['score'] > 0)
            ->sortByDesc('score')
            ->first();

        if (! $match) {
            return null;
        }

        /** @var ProductVariant $variant */
        $variant = $match['variant'];
        $name = collect([$variant->variant_name, $variant->variety_name, $variant->modelList?->model_name])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->implode(' - ');
        $code = collect([
            $variant->variant_code,
            $variant->variety_code,
            $variant->unique_key,
            $variant->getAttribute('barcode'),
            $variant->getAttribute('sku'),
            $variant->modelList?->code,
        ])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->implode(' / ');

        return collect([$name, $code])->filter()->implode(' | ') ?: null;
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }

    public function matchingSelectedProductIds(array $filters, array $productIds): array
    {
        $ids = $this->productIds(['product_ids' => $productIds]);
        if ($ids === []) {
            return [];
        }

        $matchingFilters = array_merge($filters, ['product_ids' => $ids]);
        $products = $this->buildQuery($matchingFilters)->get();
        if (! ($filters['include_without_price'] ?? false)) {
            $products = $products->filter(
                fn (Product $product) => $this->mapProduct($product, $matchingFilters)['has_price']
            );
        }

        return $products->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
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

    public function imagePath(Product $product): ?string
    {
        $path = trim((string) ($product->image_path ?? ''));
        if ($path === '' || filter_var($path, FILTER_VALIDATE_URL)) return null;
        $candidates = [public_path($path), public_path('storage/'.ltrim($path, '/')), storage_path('app/public/'.ltrim($path, '/')), storage_path('app/'.ltrim($path, '/'))];
        foreach ($candidates as $candidate) { if (is_file($candidate)) return $candidate; }
        return null;
    }

    private function cleanText(mixed $value, string $fallback = ''): string
    {
        $text = trim(strip_tags((string) $value));
        return $text === '' ? $fallback : str_replace(['ي', 'ك', "\u{200F}", "\u{200E}"], ['ی', 'ک', '', ''], $text);
    }
}
