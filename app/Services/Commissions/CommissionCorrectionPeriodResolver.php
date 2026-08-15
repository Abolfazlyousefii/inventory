<?php

namespace App\Services\Commissions;

use App\Models\CommissionPeriod;
use Carbon\CarbonInterface;

class CommissionCorrectionPeriodResolver
{
    public function forMoment(CarbonInterface $moment): ?CommissionPeriod
    {
        return CommissionPeriod::query()->whereIn('status', [CommissionPeriod::STATUS_OPEN, CommissionPeriod::STATUS_REVIEW])
            ->where('start_at', '<=', $moment)->where('end_at', '>', $moment)->first();
    }

    public function firstEligibleAfter(CarbonInterface $moment): ?CommissionPeriod
    {
        return $this->forMoment($moment) ?? CommissionPeriod::query()
            ->whereIn('status', [CommissionPeriod::STATUS_OPEN, CommissionPeriod::STATUS_REVIEW])
            ->where('end_at', '>', $moment)->orderBy('start_at')->first();
    }
}
