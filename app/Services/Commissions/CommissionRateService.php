<?php

namespace App\Services\Commissions;

use App\Models\CommissionRateRevision;
use App\Models\CommissionSetting;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Percentage;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class CommissionRateService
{
    public function __construct(private readonly CommissionPeriodDirtyMarker $dirtyMarker) {}

    public function setRate(string $type, int $id, mixed $percentage, User $actor, CarbonInterface|string|null $effectiveFrom = null): CommissionRateRevision
    {
        CommissionTarget::resolve($type, $id);
        $percentage = Percentage::normalize($percentage);
        $effectiveFrom = $effectiveFrom ? Carbon::parse($effectiveFrom) : now();

        return DB::transaction(function () use ($type, $id, $percentage, $actor, $effectiveFrom) {
            CommissionSetting::current();
            CommissionSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $key = CommissionTarget::key($type, $id);
            $active = CommissionRateRevision::query()->where('target_key', $key)->where('active_marker', 1)->lockForUpdate()->first();
            if ($active) {
                $active->update(['effective_to' => $effectiveFrom, 'active_marker' => null]);
            }

            $revision = CommissionRateRevision::query()->create(array_merge([
                'target_type' => $type, 'target_id' => $id, 'target_key' => $key, 'active_marker' => 1,
                'percentage' => $percentage, 'effective_from' => $effectiveFrom, 'effective_to' => null,
                'created_by' => $actor->id,
            ], CommissionTarget::foreignKeys($type, $id)));

            ActivityLogger::log('commission_rate.set', $revision, 'نرخ پورسانت با حفظ تاریخچه ثبت شد.', [
                'target_key' => $key,
                'percentage' => $percentage,
                'actor_id' => $actor->id,
            ]);
            $this->dirtyMarker->markAllMutable();

            return $revision;
        });
    }

    public function removeRate(string $type, int $id, User $actor, CarbonInterface|string|null $effectiveTo = null): bool
    {
        CommissionTarget::resolve($type, $id);
        $effectiveTo = $effectiveTo ? Carbon::parse($effectiveTo) : now();

        return DB::transaction(function () use ($type, $id, $actor, $effectiveTo) {
            $active = CommissionRateRevision::query()
                ->where('target_key', CommissionTarget::key($type, $id))
                ->where('active_marker', 1)->lockForUpdate()->first();
            if (! $active) {
                return false;
            }
            $active->update(['effective_to' => $effectiveTo, 'active_marker' => null]);
            ActivityLogger::log('commission_rate.removed', $active, 'نرخ اختصاصی پورسانت بسته شد.', [
                'target_key' => $active->target_key,
                'effective_to' => $effectiveTo->toDateTimeString(),
                'actor_id' => $actor->id,
            ]);
            $this->dirtyMarker->markAllMutable();

            return true;
        });
    }
}
