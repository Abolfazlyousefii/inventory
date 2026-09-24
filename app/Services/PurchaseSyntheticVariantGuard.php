<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;

/**
 * Stops new purchases from landing on legacy synthetic black/white variants of
 * electrical products when a real Base Variant exists.
 *
 * On damaged electrical products `use_designs` stayed true, so the synthetic
 * `0001`/`0002` rows count as structurally valid while the priced Base
 * (`0000`) does not, and stock kept landing on a zero-priced synthetic row.
 *
 * Read-only. Non-electrical products return after a memoized category check,
 * so the ordinary purchase path stays essentially free.
 */
class PurchaseSyntheticVariantGuard
{
    public const BLOCKED_LABEL = 'تنوع قدیمی — غیرقابل خرید';

    /** @var array<int,bool> */
    private array $electricByCategory = [];

    public function __construct(
        private readonly DefaultProductDesignService $design,
        private readonly SyntheticDefaultVariantClassifier $classifier,
        private readonly CanonicalBaseVariantService $baseVariants,
    ) {}

    public function isElectric(Product $product): bool
    {
        $categoryId = (int) $product->category_id;
        if ($categoryId <= 0) {
            return false;
        }

        if (! array_key_exists($categoryId, $this->electricByCategory)) {
            $product->loadMissing('category.parent');
            $this->electricByCategory[$categoryId] = $this->design->isElectricCategory($product->category);
        }

        return $this->electricByCategory[$categoryId];
    }

    /**
     * @return array{blocked:bool,synthetic_class:?string,base:?ProductVariant}
     */
    public function evaluate(Product $product, ProductVariant $variant): array
    {
        $allowed = ['blocked' => false, 'synthetic_class' => null, 'base' => null];

        // Cheapest checks first: category, then the classifier's necessary
        // conditions. classify() and the Base lookup only run for real suspects.
        if (! $this->isElectric($product) || ! $this->classifier->mayBeSynthetic($variant)) {
            return $allowed;
        }

        $class = $this->classifier->classify($variant)['class'];
        if ($class === SyntheticDefaultVariantClassifier::NOT_SYNTHETIC) {
            return $allowed;
        }

        $base = $this->baseVariants->existingAvailableBase($product);
        if ($base && (int) $base->id === (int) $variant->id) {
            $base = null;
        }

        return ['blocked' => $base !== null, 'synthetic_class' => $class, 'base' => $base];
    }

    /**
     * The Base Variant a blocked synthetic purchase is redirected to.
     *
     * While `use_designs` is stuck on true, structure validation excludes the
     * Base, so it must be explicitly accepted here or the redirect would leave
     * the user nothing purchasable.
     */
    public function redirectableBase(Product $product, int $variantId): ?ProductVariant
    {
        if (! $this->isElectric($product)) {
            return null;
        }

        $base = $this->baseVariants->existingAvailableBase($product);

        return $base && (int) $base->id === $variantId ? $base : null;
    }

    public function blockedMessage(ProductVariant $base): string
    {
        return 'این تنوع از تنوع‌های خودکار قدیمی است و برای خرید قابل استفاده نیست. '
            .'لطفاً تنوع پایه را انتخاب کنید: '
            .trim((string) $base->variant_name).' ('.(string) $base->variant_code.')';
    }
}
