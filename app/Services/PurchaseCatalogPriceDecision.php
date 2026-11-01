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

    public function requiresSalePriceApproval(?int $oldPurchasePrice, int $currentCatalogPrice): bool {
        // Even first-time pricing changes the live catalog. The server's
        // normalization observer may also affect other variants of this product;
        // never exempt zero-priced variants from price-change authorization.
        return true;
    }

    /**
     * Even a snapshot-only sale-price edit must use the version shown to the
     * operator. Quantity-only edits that retain the historical price do not
     * need a fresh catalog-price version.
     */
    public function isStaleForExplicitSalePriceEdit( ?int $oldPurchasePrice, int $submittedPrice, int $lockedCatalogPrice, ?int $expectedCatalogPrice ): bool {
        return $this->isExplicitChange($oldPurchasePrice, $submittedPrice, $lockedCatalogPrice)
               && $this->isCurrentPriceStale($expectedCatalogPrice, $lockedCatalogPrice);
    }

    public function isCurrentPriceStale(?int $expectedCatalogPrice, int $lockedCatalogPrice): bool
    {
        return $expectedCatalogPrice === null || $expectedCatalogPrice !== $lockedCatalogPrice;
    }
}
