<?php

namespace App\Services;

/**
 * Pure decision rules for copying purchase-document prices into the current
 * product catalog. Never infer operator intent from a difference between a
 * historical purchase snapshot and today's catalog price.
 */
final class PurchaseCatalogPriceDecision
{
    /**
     * @param int|null $oldPurchasePrice The previous value in this very
     *     purchase item; null when the item is being added for the first time.
     */
    public function isExplicitChange(?int $oldPurchasePrice, int $submittedPrice, int $currentCatalogPrice): bool
    {
        if ($oldPurchasePrice !== null) {
            return $submittedPrice !== $oldPurchasePrice;
        }

        return $submittedPrice > 0 && $submittedPrice !== $currentCatalogPrice;
    }

    public function requiresSalePriceApproval(?int $oldPurchasePrice, int $currentCatalogPrice): bool
    {
        // A brand-new purchase may seed an unpriced variant. Existing purchase
        // edits and replacement of existing catalog prices always need consent.
        return $oldPurchasePrice !== null || $currentCatalogPrice > 0;
    }

    public function isCurrentPriceStale(?int $expectedCatalogPrice, int $lockedCatalogPrice): bool
    {
        return $expectedCatalogPrice === null || $expectedCatalogPrice !== $lockedCatalogPrice;
    }
}
