<?php

namespace App\Services\Commissions;

use App\Models\CommissionPeriod;
use Carbon\CarbonInterface;

class CurrentCommissionPeriodResolver
{
    public function __construct(private readonly CommissionPeriodService $periods) {}

    public function resolve(CarbonInterface|string|null $at = null): CommissionPeriod
    {
        return $this->periods->createForDate($at ?? now());
    }
}
