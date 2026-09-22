<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

class PurchaseVariantResolver
{
    public function __construct(
        private readonly ProductVariantStructureService $structure,
        private readonly CanonicalBaseVariantService $baseVariants,
    ) {
    }

    public function resolve(Product $product, mixed $variantId): ProductVariant
    {
        $variantId = filter_var($variantId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

        if ($variantId !== null) {
            $variant = $this->structure
                ->applyValidConstraints(ProductVariant::query(), $product)
                ->whereKey($variantId)
                ->first();

            if ($variant) {
                return $variant;
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
}
