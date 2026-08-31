<?php

namespace App\Services;

use App\Models\SalesReturnDocument;

class SalesReturnCommissionPolicy
{
    /**
     * Known business reasons are authoritative. The legacy/manual selection is
     * consulted only for "other" (or an unknown future reason) so historical
     * forms remain backward-compatible without letting known reasons bypass
     * commission policy.
     */
    public function resolve(?string $reason, ?string $requestedEffect = null): string
    {
        return match ($reason) {
            'warranty',
            'damaged_product',
            'appearance_issue',
            'technical_issue' => SalesReturnDocument::COMMISSION_WARRANTY,

            'healthy_return',
            'product_mismatch',
            'wrong_collection',
            'wrong_dispatch',
            'customer_cancellation',
            'registration_error' => SalesReturnDocument::COMMISSION_COMMERCIAL,

            default => $this->validRequestedEffect($requestedEffect)
                ?? SalesReturnDocument::COMMISSION_COMMERCIAL,
        };
    }

    public function reducesCommission(?string $reason, ?string $requestedEffect = null): bool
    {
        return $this->resolve($reason, $requestedEffect) === SalesReturnDocument::COMMISSION_COMMERCIAL;
    }

    private function validRequestedEffect(?string $effect): ?string
    {
        return array_key_exists((string) $effect, SalesReturnDocument::commissionEffectLabels())
            ? $effect
            : null;
    }
}
