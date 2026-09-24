<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PurchaseVariantResolver
{
    public function __construct(
        private readonly ProductVariantStructureService $structure,
        private readonly CanonicalBaseVariantService $baseVariants,
        private readonly PurchaseSyntheticVariantGuard $syntheticGuard,
    ) {}

    /**
     * @param  bool  $existingLegacyRow  true when an existing purchase item keeps
     *                                   the variant it was already recorded on;
     *                                   only new placements are guarded.
     */
    public function resolve(Product $product, mixed $variantId, bool $existingLegacyRow = false): ProductVariant
    {
        $variantId = filter_var($variantId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

        if ($variantId !== null) {
            $variant = $this->structure
                ->applyValidConstraints(ProductVariant::query(), $product)
                ->whereKey($variantId)
                ->first();

            if ($variant) {
                if (! $existingLegacyRow) {
                    $this->guardSyntheticVariant($product, $variant);
                }

                return $variant;
            }

            $base = $this->syntheticGuard->redirectableBase($product, $variantId);
            if ($base) {
                return $base;
            }

            throw ValidationException::withMessages([
                'variant_id' => 'The selected variant does not belong to this product or is not purchase eligible.',
            ]);
        }

        $inspection = $this->baseVariants->inspect($product);
        if ($inspection['state'] === CanonicalBaseVariantService::AVAILABLE && $inspection['variant']) {
            $variant = $this->structure
                ->applyValidConstraints(ProductVariant::query(), $product)
                ->whereKey($inspection['variant']->id)
                ->first();
            if ($variant) {
                return $variant;
            }
        }

        $message = $inspection['state'] === CanonicalBaseVariantService::NOT_SIMPLE
            ? 'An explicit variant is required for a multi-variant product.'
            : 'This product has no safe Base Variant; review is required before purchase.';

        throw ValidationException::withMessages(['variant_id' => $message]);
    }

    private function guardSyntheticVariant(Product $product, ProductVariant $variant): void
    {
        $verdict = $this->syntheticGuard->evaluate($product, $variant);
        if ($verdict['synthetic_class'] === null) {
            return;
        }

        if ($verdict['blocked'] && $verdict['base']) {
            throw ValidationException::withMessages([
                'variant_id' => $this->syntheticGuard->blockedMessage($verdict['base']),
            ]);
        }

        // No Base to redirect to: keep daily work moving, but leave a trail.
        Log::warning('Purchase placed on a synthetic electrical variant without an available Base Variant', [
            'product_id' => (int) $product->id,
            'product_code' => (string) $product->code,
            'category_id' => (int) $product->category_id,
            'variant_id' => (int) $variant->id,
            'variant_code' => (string) $variant->variant_code,
            'variant_name' => (string) $variant->variant_name,
            'variety_name' => (string) $variant->variety_name,
            'synthetic_class' => $verdict['synthetic_class'],
            'reason' => 'no_active_canonical_base_variant',
        ]);
    }
}
