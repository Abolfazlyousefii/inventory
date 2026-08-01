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
    ) {}
}
