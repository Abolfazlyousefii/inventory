<?php

namespace App\Data;

final readonly class CommissionRateResult
{
    public function __construct(
        public string $percentage,
        public ?string $sourceType,
        public ?int $sourceId,
        public ?int $ruleId,
        public bool $isExplicitZero,
        public bool $isMissing,
        public bool $isAmbiguous = false,
    ) {}
}
