<?php

namespace App\Services;

final readonly class SellerReassignmentResult
{
    public function __construct(
        public int $invoiceId,
        public ?int $preinvoiceId,
        public ?int $oldSellerId,
        public int $newSellerId,
        public bool $changed,
        public bool $commissionClaimRepaired = false,
        public int $releasedCommissionClaims = 0,
    ) {}
}
