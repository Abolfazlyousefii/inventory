<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DefaultProductDesignService
{
    private const ELECTRIC_CATEGORY_NAME = 'برقیجات';
    private const ELECTRIC_CATEGORY_SLUG = 'barghijat';
    private const DEFAULT_COLOR_NAMES = ['مشکی', 'سفید'];

    /**
     * @return array{checked: bool, already_existing: int, created: int, created_names: array<int, string>}
     */
    public function ensureElectricDefaultColors(Product $product, ?int $sellPrice = null, ?int $buyPrice = null, ?int $userId = null): array
    {
        $product->loadMissing('category.parent');

        if (! $this->isElectricCategory($product->category)) {
            return [
                'checked' => false,
                'already_existing' => 0,
                'created' => 0,
                'created_names' => [],
            ];
        }

        $product = Product::query()
            ->with(['variants', 'category'])
            ->whereKey($product->id)
            ->lockForUpdate()
            ->firstOrFail();

        $alreadyExisting = 0;
        $created = 0;
        $createdNames = [];

        foreach (self::DEFAULT_COLOR_NAMES as $colorName) {
            if ($this->productHasColorVariant($product, $colorName)) {
                $alreadyExisting++;
                continue;
            }

            $variant = $this->createColorVariant($product, $colorName, $sellPrice, $buyPrice);
            $created++;
            $createdNames[] = $colorName;

            $this->logDefaultColorCreated($product, $variant, $colorName, $userId);
            $product->load('variants');
        }

        $this->recalculateProductSummary($product);

        return [
            'checked' => true,
            'already_existing' => $alreadyExisting,
            'created' => $created,
            'created_names' => $createdNames,
        ];
    }

    public function isElectricCategory(?Category $category): bool
    {
        $current = $category;
        $visited = [];
        $hasSlug = Schema::hasColumn('categories', 'slug');

        while ($current) {
            $id = (int) $current->id;
            if (isset($visited[$id])) {
                return false;
            }
            $visited[$id] = true;

            if ($hasSlug && Str::lower(trim((string) ($current->getAttribute('slug') ?? ''))) === self::ELECTRIC_CATEGORY_SLUG) {
                return true;
            }

            if (trim((string) $current->name) === self::ELECTRIC_CATEGORY_NAME) {
                return true;
            }

            $current = $current->parent ?: ($current->parent_id ? Category::query()->find($current->parent_id) : null);
        }

        return false;
    }

    /**
     * @return array<int, int>
     */
    public function electricCategoryIds(): array
    {
        $query = Category::query();

        if (Schema::hasColumn('categories', 'slug')) {
            $query->where('slug', self::ELECTRIC_CATEGORY_SLUG)
                ->orWhere('name', self::ELECTRIC_CATEGORY_NAME);
        } else {
            $query->where('name', self::ELECTRIC_CATEGORY_NAME);
        }

        $roots = $query->get();
        $ids = $roots->pluck('id')->map(fn ($id) => (int) $id)->all();
        $frontier = $ids;

        while ($frontier) {
            $children = Category::query()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $children = array_values(array_diff($children, $ids));
            $ids = array_values(array_unique(array_merge($ids, $children)));
            $frontier = $children;
        }

        return $ids;
    }


    /**
     * @return array<int, int>
     */
    public function electricDefaultColorVariantIds(Product $product): array
    {
        $product->loadMissing('category.parent', 'variants');

        if (! $this->isElectricCategory($product->category)) {
            return [];
        }

        return $product->variants
            ->filter(fn (ProductVariant $variant) => collect(self::DEFAULT_COLOR_NAMES)
                ->contains(fn (string $colorName) => $this->variantMatchesColor($variant, $colorName)))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function productHasColorVariant(Product $product, string $colorName): bool
    {
        return $product->variants->contains(fn (ProductVariant $variant) => $this->variantMatchesColor($variant, $colorName));
    }

    private function variantMatchesColor(ProductVariant $variant, string $colorName): bool
    {
        $normalizedColor = $this->normalizePersianText($colorName);

        return in_array($normalizedColor, [
            $this->normalizePersianText((string) $variant->variety_name),
            $this->normalizePersianText((string) $variant->variant_name),
        ], true)
            || Str::contains($this->normalizePersianText((string) $variant->variant_name), $normalizedColor);
    }

    private function createColorVariant(Product $product, string $colorName, ?int $sellPrice, ?int $buyPrice): ProductVariant
    {
        $varietyCode = $this->nextVarietyCode($product);
        $design2 = substr($varietyCode, -2);
        $variantCode = $this->buildUniqueVariantCode($product, '000', $design2);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'model_list_id' => null,
            'variant_name' => trim($product->name . ' ' . $colorName),
            'variety_name' => $colorName,
            'variety_code' => $varietyCode,
            'variant_code' => $variantCode,
            'sell_price' => $sellPrice ?? $this->defaultSellPrice($product),
            'buy_price' => $buyPrice ?? $this->defaultBuyPrice($product),
            'stock' => 0,
            'reserved' => 0,
            'is_active' => true,
        ]);

        WarehouseStockService::set(
            WarehouseStockService::centralWarehouseId(),
            (int) $product->id,
            (int) $variant->id,
            0
        );

        return $variant;
    }

    private function nextVarietyCode(Product $product): string
    {
        $usedCodes = ProductVariant::query()
            ->where('product_id', $product->id)
            ->whereNotNull('variety_code')
            ->pluck('variety_code')
            ->map(fn ($code) => (string) $code)
            ->all();

        $usedDesignSuffixes = ProductVariant::query()
            ->where('product_id', $product->id)
            ->whereNotNull('variant_code')
            ->pluck('variant_code')
            ->map(fn ($code) => substr((string) $code, -2))
            ->filter(fn ($code) => preg_match('/^\d{2}$/', $code))
            ->values()
            ->all();

        for ($i = 1; $i <= 99; $i++) {
            $varietyCode = str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $design2 = str_pad((string) $i, 2, '0', STR_PAD_LEFT);

            if (! in_array($varietyCode, $usedCodes, true) && ! in_array($design2, $usedDesignSuffixes, true)) {
                return $varietyCode;
            }
        }

        abort(422, 'برای افزودن رنگ پیش‌فرض، کد طرح‌بندی آزاد برای این کالا پیدا نشد.');
    }

    private function buildUniqueVariantCode(Product $product, string $model3, string $design2): string
    {
        $code = (string) $product->code . $model3 . $design2;

        if (ProductVariant::query()->where('variant_code', $code)->exists()) {
            abort(422, "کد تنوع تکراری است: {$code}. امکان افزودن رنگ پیش‌فرض وجود ندارد.");
        }

        return $code;
    }

    private function defaultSellPrice(Product $product): int
    {
        $variantPrice = $product->variants
            ->where('is_active', true)
            ->pluck('sell_price')
            ->filter(fn ($price) => $price !== null)
            ->map(fn ($price) => (int) $price)
            ->min();

        return max(0, (int) ($variantPrice ?? $product->price ?? 0));
    }

    private function defaultBuyPrice(Product $product): ?int
    {
        $variantPrice = $product->variants
            ->pluck('buy_price')
            ->filter(fn ($price) => $price !== null)
            ->map(fn ($price) => (int) $price)
            ->min();

        return $variantPrice !== null ? max(0, (int) $variantPrice) : null;
    }

    private function recalculateProductSummary(Product $product): void
    {
        $product->load('variants');

        $product->update([
            'stock' => max(0, (int) $product->variants->sum('stock')),
            'price' => max(0, (int) ($product->variants->where('is_active', true)->min('sell_price') ?? 0)),
        ]);
    }

    private function logDefaultColorCreated(Product $product, ProductVariant $variant, string $colorName, ?int $userId): void
    {
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        ActivityLog::query()->create([
            'user_id' => $userId ?? Auth::id(),
            'action' => 'electric_default_color_created',
            'subject_type' => Product::class,
            'subject_id' => $product->id,
            'description' => "رنگ پیش‌فرض {$colorName} برای کالای برقیجات به صورت خودکار اضافه شد.",
            'properties' => [
                'product_id' => (int) $product->id,
                'category_id' => (int) $product->category_id,
                'variant_id' => (int) $variant->id,
                'variant_code' => (string) $variant->variant_code,
                'color_name' => $colorName,
                'user_id' => $userId ?? Auth::id(),
            ],
            'occurred_at' => Carbon::now(),
        ]);
    }

    private function normalizePersianText(string $text): string
    {
        return trim(str_replace(['ي', 'ك', '‌'], ['ی', 'ک', ' '], $text));
    }
}
