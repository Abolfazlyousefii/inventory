<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Services\ProductVariantStructureService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'sku',
        'category_id',
        'image_path',

        'code',
        'short_barcode',
        'barcode',

        // خلاصه‌ی محاسبه‌شده از variants (برای لیست/فیلتر)
        'stock',
        'reserved',
        'unit',

        // خلاصه‌ی محاسبه‌شده از variants (مثلاً کمترین قیمت فروش)
        'price',

        // اختیاری/قدیمی
        'sale_retail',
        'sale_wholesale',
        'buy_retail',
        'buy_wholesale',
        'color',

        // CRM
        'external_id',
        'synced_at',

        // اگر ستونش هست و نمی‌خوای حذف کنی:
        'models',
        'has_colors',
        'is_sellable',
        'warehouse_zone',
        'warehouse_rows',
        'warehouse_bins',
    ];

    protected $casts = [
        'stock'      => 'integer',
        'reserved'   => 'integer',
        'price'      => 'integer',

        'sale_retail'     => 'integer',
        'sale_wholesale'  => 'integer',
        'buy_retail'      => 'integer',
        'buy_wholesale'   => 'integer',

        'models'     => 'array',
        'has_colors' => 'boolean',
        'is_sellable' => 'boolean',
        'warehouse_zone' => 'integer',
        'warehouse_rows' => 'array',
        'warehouse_bins' => 'array',
        'synced_at'  => 'datetime',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function validVariants()
    {
        return app(ProductVariantStructureService::class)->applyValidConstraints($this->variants(), $this);
    }

    public function validVariantCollection(bool $activeOnly = true)
    {
        return app(ProductVariantStructureService::class)->validVariants($this, $activeOnly);
    }

    public function invalidVariantCollection()
    {
        return app(ProductVariantStructureService::class)->invalidVariants($this);
    }

    public function warehouseStocks()
    {
        return $this->hasMany(WarehouseStock::class);
    }

    public function getAvailableStockAttribute(): int
    {
        return max(0, (int) ($this->stock ?? 0));
    }

    public function scopeInStock($query)
    {
        return $query->where('stock', '>', 0);
    }

    public function scopeOutOfStock($query)
    {
        return $query->where('stock', 0);
    }

    public function scopeSearch(Builder $query, $q): Builder
    {
        $search = static::normalizeProductSearchTerm((string) $q);

        if ($search === '') {
            return $query;
        }

        $tokens = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokens === []) {
            return $query->whereRaw('0 = 1');
        }

        foreach ($tokens as $token) {
            $query->where(function (Builder $tokenQuery) use ($token) {
                static::orWhereProductSearchTokenMatches($tokenQuery, $token);
            });
        }

        return static::applyProductSearchScore($query, $search, $tokens);
    }

    private static function applyProductSearchScore(Builder $query, string $search, array $tokens): Builder
    {
        $name = static::normalizedSqlExpression('products.name');
        $searchLower = mb_strtolower($search);
        $compactSearch = str_replace(' ', '', $searchLower);
        $isCodeLike = static::isProductSearchCodeLike($search);

        $scoreSql = '0';
        $bindings = [];

        foreach (['products.barcode', 'products.sku', 'products.code', 'products.short_barcode'] as $column) {
            if (! static::hasProductColumnFromQualifiedName($column)) {
                continue;
            }

            $codeExpression = static::normalizedSqlExpression($column);
            $scoreSql .= " + CASE WHEN {$codeExpression} = ? THEN " . ($isCodeLike ? 1000 : 240) . " ELSE 0 END";
            $bindings[] = $searchLower;
        }

        $scoreSql .= " + CASE WHEN {$name} = ? THEN 1600 ELSE 0 END";
        $bindings[] = $searchLower;
        $scoreSql .= " + CASE WHEN {$name} LIKE ? THEN 1300 ELSE 0 END";
        $bindings[] = static::escapeProductSearchLike($searchLower) . '%';
        $scoreSql .= " + CASE WHEN {$name} LIKE ? THEN 1000 ELSE 0 END";
        $bindings[] = '%' . static::escapeProductSearchLike($searchLower) . '%';
        $scoreSql .= " + CASE WHEN REPLACE({$name}, ' ', '') LIKE ? THEN 950 ELSE 0 END";
        $bindings[] = '%' . static::escapeProductSearchLike($compactSearch) . '%';

        if (count($tokens) > 1) {
            $allTokensConditions = [];

            foreach ($tokens as $token) {
                $allTokensConditions[] = "{$name} LIKE ?";
                $bindings[] = '%' . static::escapeProductSearchLike(mb_strtolower($token)) . '%';
            }

            $scoreSql .= ' + CASE WHEN ' . implode(' AND ', $allTokensConditions) . ' THEN 260 ELSE 0 END';
        }

        foreach ($tokens as $token) {
            $token = mb_strtolower($token);
            $scoreSql .= " + CASE WHEN {$name} LIKE ? THEN 90 ELSE 0 END";
            $bindings[] = '%' . static::escapeProductSearchLike($token) . '%';
        }

        $categoryName = static::normalizedSqlExpression('categories.name');
        $query->leftJoin('categories as search_categories', 'search_categories.id', '=', 'products.category_id');
        $categoryName = str_replace('categories.', 'search_categories.', $categoryName);
        $scoreSql .= " + CASE WHEN {$categoryName} LIKE ? THEN 35 ELSE 0 END";
        $bindings[] = '%' . static::escapeProductSearchLike($searchLower) . '%';

        $variantExactCodeScore = static::variantExistsSql(static::variantExactCodeConditionSql());
        $scoreSql .= " + CASE WHEN {$variantExactCodeScore} THEN " . ($isCodeLike ? 980 : 230) . " ELSE 0 END";
        $bindings = array_merge($bindings, static::variantExactCodeBindings($searchLower));

        $variantScore = static::variantExistsSql(static::variantContainsConditionSql());
        $scoreSql .= " + CASE WHEN {$variantScore} THEN 330 ELSE 0 END";
        $bindings = array_merge($bindings, static::variantContainsBindings($searchLower));

        $variantNameScore = static::variantExistsSql(static::variantNameContainsConditionSql());
        $scoreSql .= " + CASE WHEN {$variantNameScore} THEN 820 ELSE 0 END";
        $bindings[] = '%' . static::escapeProductSearchLike($searchLower) . '%';

        if (count($tokens) > 1) {
            $variantAllTokensSql = static::variantExistsSql(static::variantAllTokensConditionSql($tokens));
            $scoreSql .= " + CASE WHEN {$variantAllTokensSql} THEN 240 ELSE 0 END";
            $bindings = array_merge($bindings, static::variantAllTokensBindings($tokens));
        }

        if (static::hasProductColumnFromQualifiedName('products.description')) {
            $description = static::normalizedSqlExpression('products.description');
            $scoreSql .= " + CASE WHEN {$description} LIKE ? THEN 20 ELSE 0 END";
            $bindings[] = '%' . static::escapeProductSearchLike($searchLower) . '%';
        }

        return $query
            ->select('products.*')
            ->orderByRaw("({$scoreSql}) DESC", $bindings)
            ->orderByDesc('products.id');
    }




    private static function isProductSearchCodeLike(string $search): bool
    {
        return preg_match('/^[\p{L}\d\-_\.\/]+$/u', $search) === 1
            && (preg_match('/\d/u', $search) === 1 || mb_strlen($search) <= 16);
    }

    private static function orWhereProductCodeMatches(Builder $query, string $search, bool $isCodeLike)
    {
        if (! $isCodeLike) {
            return;
        }

        $query->orWhere(function (Builder $codeQuery) use ($search) {
            $searchLower = mb_strtolower($search);

            foreach (['products.barcode', 'products.sku', 'products.code', 'products.short_barcode'] as $column) {
                if (! static::hasProductColumnFromQualifiedName($column)) {
                    continue;
                }

                $expression = static::normalizedSqlExpression($column);
                $codeQuery->orWhereRaw("{$expression} = ?", [$searchLower]);
                $codeQuery->orWhereRaw("{$expression} LIKE ?", ['%' . static::escapeProductSearchLike($searchLower) . '%']);
            }
        });

        return $query;
    }

    private static function orWhereProductSearchTokenMatches(Builder $query, string $token): Builder
    {
        $pattern = '%' . static::escapeProductSearchLike(mb_strtolower(static::normalizeProductSearchTerm($token))) . '%';
        $patterns = static::productSearchPersianArabicVariants($pattern);

        foreach (static::productSearchableColumns() as $column) {
            static::orWhereNormalizedLikeAny($query, 'products.' . $column, $patterns);
        }

        $query->orWhereHas('category', function (Builder $categoryQuery) use ($patterns) {
            static::orWhereNormalizedLikeAny($categoryQuery, 'categories.name', $patterns);
        });

        $query->orWhereHas('variants', function (Builder $variantQuery) use ($patterns) {
            foreach (static::variantSearchableColumns() as $column) {
                static::orWhereNormalizedLikeAny($variantQuery, 'product_variants.' . $column, $patterns);
            }
        });

        return $query;
    }

    private static function orWhereNormalizedLike(Builder $query, string $column, string $search)
    {
        $expression = static::normalizedSqlExpression($column);
        $searchLower = mb_strtolower(static::normalizeProductSearchTerm($search));
        $compactSearch = str_replace(' ', '', $searchLower);

        $query->orWhereRaw("{$expression} LIKE ?", ['%' . static::escapeProductSearchLike($searchLower) . '%']);

        if ($compactSearch !== $searchLower) {
            $query->orWhereRaw("REPLACE({$expression}, ' ', '') LIKE ?", ['%' . static::escapeProductSearchLike($compactSearch) . '%']);
        }
    }

    private static function orWhereNormalizedLikeAny(Builder $query, string $column, array $patterns)
    {
        $expression = static::normalizedSqlExpression($column);

        $query->orWhere(function (Builder $columnQuery) use ($expression, $patterns) {
            foreach ($patterns as $pattern) {
                $columnQuery->orWhereRaw("{$expression} LIKE ?", [$pattern]);
                $columnQuery->orWhereRaw("REPLACE({$expression}, ' ', '') LIKE ?", [str_replace(' ', '', $pattern)]);
            }
        });
    }

    private static function orWhereAllTokensInColumn(Builder $query, string $column, array $tokens)
    {
        $expression = static::normalizedSqlExpression($column);

        $query->orWhere(function (Builder $tokenQuery) use ($expression, $tokens) {
            foreach ($tokens as $token) {
                $tokenQuery->whereRaw("{$expression} LIKE ?", ['%' . static::escapeProductSearchLike(mb_strtolower($token)) . '%']);
            }
        });
    }

    public static function normalizeProductSearchTerm(string $term): string
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
        ]);

        return trim((string) preg_replace('/\s+/u', ' ', $term));
    }

    private static function buildProductSearchPatterns(string $term): array
    {
        $normalized = mb_strtolower(static::normalizeProductSearchTerm($term));

        if ($normalized === '') {
            return [];
        }

        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $loosePattern = '%' . implode('%', array_map([static::class, 'escapeProductSearchLike'], $tokens)) . '%';
        $exactPattern = '%' . static::escapeProductSearchLike($normalized) . '%';

        return collect([$exactPattern, $loosePattern])
            ->flatMap(fn (string $pattern) => static::productSearchPersianArabicVariants($pattern))
            ->unique()
            ->values()
            ->all();
    }

    private static function productSearchPersianArabicVariants(string $pattern): array
    {
        $patterns = [$pattern];

        foreach ([['ی', 'ي'], ['ک', 'ك']] as [$persian, $arabic]) {
            $patterns = collect($patterns)
                ->flatMap(fn (string $item) => [$item, str_replace($persian, $arabic, $item)])
                ->unique()
                ->values()
                ->all();
        }

        return $patterns;
    }

    private static function escapeProductSearchLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }

    private static function hasProductColumnFromQualifiedName(string $column): bool
    {
        return str_starts_with($column, 'products.') && Schema::hasColumn('products', substr($column, 9));
    }

    private static function variantSearchableColumns(): array
    {
        return ['variant_name', 'variant_code', 'variety_name', 'variety_code', 'unique_key'];
    }

    private static function variantExactCodeConditionSql(): string
    {
        return collect(['variant_code', 'variety_code', 'unique_key'])
            ->map(fn (string $column) => static::normalizedSqlExpression('product_variants.' . $column) . ' = ?')
            ->implode(' OR ');
    }

    private static function variantExactCodeBindings(string $searchLower): array
    {
        return array_fill(0, 3, $searchLower);
    }

    private static function variantContainsConditionSql(): string
    {
        return collect(static::variantSearchableColumns())
            ->map(fn (string $column) => static::normalizedSqlExpression('product_variants.' . $column) . ' LIKE ?')
            ->implode(' OR ');
    }

    private static function variantContainsBindings(string $searchLower): array
    {
        return array_fill(0, count(static::variantSearchableColumns()), '%' . static::escapeProductSearchLike($searchLower) . '%');
    }

    private static function variantNameContainsConditionSql(): string
    {
        return static::normalizedSqlExpression('product_variants.variant_name') . ' LIKE ?';
    }

    private static function variantAllTokensConditionSql(array $tokens): string
    {
        return collect($tokens)->map(function () {
            return '(' . static::variantContainsConditionSql() . ')';
        })->implode(' AND ');
    }

    private static function variantAllTokensBindings(array $tokens): array
    {
        $bindings = [];

        foreach ($tokens as $token) {
            array_push($bindings, ...static::variantContainsBindings(mb_strtolower($token)));
        }

        return $bindings;
    }

    private static function variantExistsSql(string $conditionSql): string
    {
        return "EXISTS (SELECT 1 FROM product_variants WHERE product_variants.product_id = products.id AND ({$conditionSql}))";
    }

    private static function productSearchableColumns(): array
    {
        static $columns = null;

        if ($columns !== null) {
            return $columns;
        }

        return $columns = collect(['name', 'code', 'sku', 'barcode', 'short_barcode'])
            ->filter(fn (string $column) => Schema::hasColumn('products', $column))
            ->values()
            ->all();
    }

    private static function orWhereProductSearchColumnLikeAny(Builder $query, string $column, array $patterns)
    {
        $query->orWhere(function (Builder $columnQuery) use ($column, $patterns) {
            foreach ($patterns as $pattern) {
                $columnQuery->orWhere($column, 'like', $pattern);
            }
        });
    }

    public static function normalizeSearchTerm(string $term): string
    {
        return trim(strtr($term, [
            'ي' => 'ی',
            'ى' => 'ی',
            'ك' => 'ک',
            'ة' => 'ه',
            'ۀ' => 'ه',
            "\u{200C}" => ' ',
            "\u{200D}" => ' ',
            "\u{0640}" => '',
        ]));
    }

    private static function searchableColumns(): array
    {
        return collect(['name', 'code', 'sku', 'barcode', 'short_barcode', 'description'])
            ->filter(fn (string $column) => Schema::hasColumn('products', $column))
            ->values()
            ->all();
    }

    private static function whereNormalizedLike(Builder $query, string $column, string $search)
    {
        $expression = static::normalizedSqlExpression($column);
        $compactExpression = "REPLACE({$expression}, ' ', '')";
        $compactSearch = str_replace(' ', '', $search);

        $query->whereRaw("{$expression} LIKE ?", ['%' . mb_strtolower($search) . '%']);

        $query->orWhereRaw("{$compactExpression} LIKE ?", ['%' . mb_strtolower($compactSearch) . '%']);
    }

    private static function normalizedSqlExpression(string $column): string
    {
        $expression = "LOWER(COALESCE({$column}, ''))";

        foreach ([
            'ي' => 'ی',
            'ى' => 'ی',
            'ك' => 'ک',
            'ة' => 'ه',
            'ۀ' => 'ه',
            "\u{200C}" => ' ',
            "\u{200D}" => ' ',
            "\u{0640}" => '',
        ] as $from => $to) {
            $expression = "REPLACE({$expression}, '{$from}', '{$to}')";
        }

        return $expression;
    }
}
