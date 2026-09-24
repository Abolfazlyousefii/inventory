<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DefaultProductDesignService
{
    private const ELECTRIC_CATEGORY_NAME = 'برقیجات';

    private const ELECTRIC_CATEGORY_SLUG = 'barghijat';

    private const DEFAULT_COLOR_NAMES = ['مشکی', 'سفید'];

    // Automatic black/white creation for electrical products is retired. Only
    // read-only helpers remain, so historical synthetic variants can still be
    // identified and protected without any path recreating them.

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

    private function variantMatchesColor(ProductVariant $variant, string $colorName): bool
    {
        $normalizedColor = $this->normalizePersianText($colorName);

        return in_array($normalizedColor, [
            $this->normalizePersianText((string) $variant->variety_name),
            $this->normalizePersianText((string) $variant->variant_name),
        ], true)
            || Str::contains($this->normalizePersianText((string) $variant->variant_name), $normalizedColor);
    }

    private function normalizePersianText(string $text): string
    {
        return trim(str_replace(['ي', 'ك', '‌'], ['ی', 'ک', ' '], $text));
    }
}
