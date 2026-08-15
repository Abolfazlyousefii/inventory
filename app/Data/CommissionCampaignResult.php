<?php

namespace App\Data;

final readonly class CommissionCampaignResult
{
    public function __construct(
        public int $campaignId,
        public string $bonusPercentage,
        public string $matchedTargetType,
        public int $matchedTargetId,
        public array $matchedTargets,
        public ?string $campaignName = null,
    ) {}
}
