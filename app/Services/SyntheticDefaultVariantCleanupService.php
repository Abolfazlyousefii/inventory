<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use Closure;
use Illuminate\Support\Facades\DB;

class SyntheticDefaultVariantCleanupService
{
    public function __construct(
        private readonly SyntheticDefaultVariantClassifier $classifier,
        private readonly VariantUsageAuditService $usage,
        private readonly ProductVariantStructureService $summaries,
    ) {
    }

    /** @return array<string,mixed> */
    public function assess(int $variantId, bool $explicit): array
    {
        $variant = ProductVariant::query()->with('product.category.parent')->find($variantId);
        if (! $variant) {
            return $this->skipped($variantId, ['variant_not_found']);
        }

        return $this->assessment($variant, $explicit);
    }

    /** @return array<string,mixed> */
    public function cleanup(int $variantId, bool $explicit, ?Closure $beforeLockedRevalidation = null): array
    {
        if ($beforeLockedRevalidation) {
            $beforeLockedRevalidation();
        }

        return DB::transaction(function () use ($variantId, $explicit): array {
            $variant = ProductVariant::query()->whereKey($variantId)->lockForUpdate()->first();
            if (! $variant) {
                return $this->skipped($variantId, ['variant_not_found']);
            }
            $variant->load('product.category.parent');
            $assessment = $this->assessment($variant, $explicit);
            if (! $assessment['eligible']) {
                return $assessment;
            }

            $product = $variant->product;
            // Reauthorize immediately before mutation. This narrows, but
            // cannot eliminate, races from legacy reference columns that
            // have no foreign key and therefore cannot be safely row-locked.
            $assessment = $this->assessment($variant, $explicit);
            if (! $assessment['eligible']) {
                return $assessment;
            }

            WarehouseStock::query()
                ->where('product_variant_id', $variant->id)
                ->where('quantity', 0)
                ->delete();
            // Mass delete intentionally avoids creating a new audit-history row.
            ProductVariant::query()->whereKey($variant->id)->delete();
            $this->summaries->recalculateProductSummary($product);

            return array_merge($assessment, ['status' => 'DELETED']);
        });
    }

    /** @return array<string,mixed> */
    private function assessment(ProductVariant $variant, bool $explicit): array
    {
        $classification = $this->classifier->classify($variant);
        $usage = $this->usage->audit($variant);
        $blocking = $usage['blocking_reasons'];

        if ($classification['class'] === SyntheticDefaultVariantClassifier::NOT_SYNTHETIC) {
            $blocking[] = 'not_synthetic';
        }
        if ($classification['class'] === SyntheticDefaultVariantClassifier::PROBABLE_SYNTHETIC && ! $explicit) {
            $blocking[] = 'probable_requires_explicit_id';
        }
        $blocking = array_values(array_unique($blocking));

        return [
            'variant_id' => (int) $variant->id,
            'status' => $blocking === [] ? 'ELIGIBLE' : 'SKIPPED',
            'eligible' => $blocking === [],
            'synthetic_class' => $classification['class'],
            'blocking_reasons' => $blocking,
        ];
    }

    /** @return array<string,mixed> */
    private function skipped(int $variantId, array $blocking): array
    {
        return [
            'variant_id' => $variantId,
            'status' => 'SKIPPED',
            'eligible' => false,
            'synthetic_class' => SyntheticDefaultVariantClassifier::NOT_SYNTHETIC,
            'blocking_reasons' => $blocking,
        ];
    }
}
