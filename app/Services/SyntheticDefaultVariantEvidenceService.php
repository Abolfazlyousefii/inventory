<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductVariant;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

class SyntheticDefaultVariantEvidenceService
{
    private const ACTIVITY_CHUNK_SIZE = 500;

    /**
     * Load a complete, audit-only evidence snapshot for one ProductVariant chunk.
     *
     * @param  Collection<int,ProductVariant>  $variants
     */
    public function load(Collection $variants): SyntheticDefaultVariantEvidenceSnapshot
    {
        return $this->loadScope($variants
            ->mapWithKeys(fn (ProductVariant $variant): array => [(int) $variant->id => (int) $variant->product_id])
            ->all());
    }

    /** @param array<int,int> $variantProducts */
    public function loadScope(array $variantProducts): SyntheticDefaultVariantEvidenceSnapshot
    {
        return SyntheticDefaultVariantEvidenceSnapshot::loadComplete($variantProducts, $this);
    }

    /**
     * @internal Called by SyntheticDefaultVariantEvidenceSnapshot::loadComplete().
     *
     * @param  array<int,int>  $variantProducts
     * @return array{
     *     variant_products:array<int,int>,
     *     creation_log_ids:array<int,int>,
     *     activity_reference_counts:array<int,int>
     * }
     */
    public function completeScan(array $variantProducts): array
    {
        $variantIds = array_keys($variantProducts);
        $productIds = array_values(array_unique(array_values($variantProducts)));
        $creationLogIds = [];
        $activityReferenceCounts = array_fill_keys($variantIds, 0);

        if ($variantIds === []) {
            return [
                'variant_products' => [],
                'creation_log_ids' => [],
                'activity_reference_counts' => [],
            ];
        }

        $creationComplete = $this->scanQuery(ActivityLog::query()
            ->select(['id', 'subject_id', 'properties'])
            ->where('action', 'electric_default_color_created')
            ->where('subject_type', Product::class)
            ->whereIntegerInRaw('subject_id', $productIds)
            ->orderBy('id'), function (Collection $logs) use (&$creationLogIds, $variantProducts): void {
                foreach ($logs as $log) {
                    $properties = $log->properties;
                    if (! is_array($properties)
                        || ! isset($properties['product_id'], $properties['variant_id'])
                        || ! is_int($properties['product_id'])
                        || ! is_int($properties['variant_id'])) {
                        continue;
                    }

                    $productId = $properties['product_id'];
                    $variantId = $properties['variant_id'];
                    if ((int) $log->subject_id !== $productId
                        || ($variantProducts[$variantId] ?? null) !== $productId
                        || isset($creationLogIds[$variantId])) {
                        continue;
                    }

                    $creationLogIds[$variantId] = (int) $log->id;
                }
            });
        if (! $creationComplete) {
            throw new RuntimeException('ActivityLog evidence scan did not complete.');
        }

        $contraryComplete = $this->scanQuery(ActivityLog::query()
            ->select(['id', 'subject_type', 'subject_id', 'properties'])
            ->whereNotIn('action', ['electric_default_color_created', 'created'])
            ->orderBy('id'), function (Collection $logs) use (&$activityReferenceCounts): void {
                foreach ($logs as $log) {
                    $matchedVariantIds = [];
                    $subjectId = (int) $log->subject_id;
                    if ($log->subject_type === ProductVariant::class
                        && array_key_exists($subjectId, $activityReferenceCounts)) {
                        $matchedVariantIds[$subjectId] = true;
                    }

                    $properties = $log->properties;
                    if (is_array($properties)
                        && isset($properties['variant_id'])
                        && is_int($properties['variant_id'])
                        && array_key_exists($properties['variant_id'], $activityReferenceCounts)) {
                        $matchedVariantIds[$properties['variant_id']] = true;
                    }

                    foreach (array_keys($matchedVariantIds) as $variantId) {
                        $activityReferenceCounts[$variantId]++;
                    }
                }
            });
        if (! $contraryComplete) {
            throw new RuntimeException('ActivityLog evidence scan did not complete.');
        }

        return [
            'variant_products' => $variantProducts,
            'creation_log_ids' => $creationLogIds,
            'activity_reference_counts' => $activityReferenceCounts,
        ];
    }

    protected function scanQuery(Builder $query, Closure $consume): bool
    {
        return $query->chunkById(self::ACTIVITY_CHUNK_SIZE, $consume);
    }
}
