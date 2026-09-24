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
    ) {}

    /** @return array<string,mixed> */
    public function assess(int $variantId, bool $explicit): array
    {
        $variant = ProductVariant::query()->with('product.category.parent')->find($variantId);
        if (! $variant) {
            return $this->skipped($variantId, ['variant_not_found']);
        }

        return $this->assessment($variant, $explicit);
    }

    /**
     * Dry-run assessment built from an already completed bulk/scoped audit row.
     *
     * The bulk audit has already produced the exact synthetic class, complete
     * activity evidence, warehouse state, reservations, reference counts and
     * blockers, so a dry run must not repeat the expensive fresh per-variant
     * evidence work. A null row means the audit did not classify the variant
     * as synthetic at all. This path never mutates.
     *
     * @param  array<string,mixed>|null  $row
     * @return array<string,mixed>
     */
    public function assessAuditRow(int $variantId, ?array $row, bool $explicit): array
    {
        if ($row === null) {
            return ProductVariant::query()->whereKey($variantId)->exists()
                ? $this->skipped($variantId, ['not_synthetic'])
                : $this->skipped($variantId, ['variant_not_found']);
        }

        $class = (string) $row['synthetic_class'];
        $blocking = array_values(array_filter(
            array_map('trim', explode(',', (string) $row['blocking_reasons'])),
            fn (string $reason): bool => $reason !== '',
        ));
        if ($class === SyntheticDefaultVariantClassifier::NOT_SYNTHETIC) {
            $blocking[] = 'not_synthetic';
        }
        if ($class === SyntheticDefaultVariantClassifier::PROBABLE_SYNTHETIC && ! $explicit) {
            $blocking[] = 'probable_requires_explicit_id';
        }
        $blocking = array_values(array_unique($blocking));

        return [
            'variant_id' => $variantId,
            'status' => $blocking === [] ? 'ELIGIBLE' : 'SKIPPED',
            'eligible' => $blocking === [],
            'synthetic_class' => $class,
            'blocking_reasons' => $blocking,
        ];
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
