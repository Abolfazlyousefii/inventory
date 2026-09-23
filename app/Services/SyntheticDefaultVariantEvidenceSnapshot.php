<?php

namespace App\Services;

use App\Models\ProductVariant;
use Illuminate\Support\Collection;

final readonly class SyntheticDefaultVariantEvidenceSnapshot
{
    /**
     * @param  array<int,int>  $variantProducts
     * @param  array<int,array<int,int>>  $creationLogIds
     * @param  array<int,int>  $activityReferenceCounts
     */
    private function __construct(
        private array $variantProducts,
        private array $creationLogIds,
        private array $activityReferenceCounts,
    ) {}

    /** @param  Collection<int,ProductVariant>  $variants */
    public static function loadComplete(
        Collection $variants,
        SyntheticDefaultVariantEvidenceService $loader,
    ): self {
        $evidence = $loader->completeScan($variants);

        return new self(
            $evidence['variant_products'],
            $evidence['creation_log_ids'],
            $evidence['activity_reference_counts'],
        );
    }

    public function creationLogId(int $productId, int $variantId): ?int
    {
        $this->assertInScope($variantId, $productId);

        return $this->creationLogIds[$productId][$variantId] ?? null;
    }

    public function hasContraryEvidence(int $variantId): bool
    {
        return $this->activityReferenceCount($variantId) > 0;
    }

    public function activityReferenceCount(int $variantId): int
    {
        $this->assertInScope($variantId);

        return $this->activityReferenceCounts[$variantId] ?? 0;
    }

    private function assertInScope(int $variantId, ?int $productId = null): void
    {
        if (! array_key_exists($variantId, $this->variantProducts)
            || ($productId !== null && $this->variantProducts[$variantId] !== $productId)) {
            throw new \LogicException("Variant {$variantId} is outside the complete evidence snapshot scope.");
        }
    }
}
