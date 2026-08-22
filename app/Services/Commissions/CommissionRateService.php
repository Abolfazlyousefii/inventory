<?php

namespace App\Services\Commissions;

use App\Models\CommissionRateRevision;
use App\Models\CommissionPeriod;
use App\Models\CommissionSetting;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Percentage;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommissionRateService
{
    public function __construct(private readonly CommissionPeriodDirtyMarker $dirtyMarker) {}

    public function setRate(string $type, int $id, mixed $percentage, User $actor, CarbonInterface|string|null $effectiveFrom = null): CommissionRateRevision
    {
        CommissionTarget::resolve($type, $id);
        $percentage = Percentage::normalize($percentage);
        $effectiveFrom = $effectiveFrom ? Carbon::parse($effectiveFrom) : now();
        $this->assertDateIsNotFinalized($effectiveFrom);

        return DB::transaction(function () use ($type, $id, $percentage, $actor, $effectiveFrom) {
            CommissionSetting::current();
            CommissionSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $key = CommissionTarget::key($type, $id);
            $active = CommissionRateRevision::query()->where('target_key', $key)->where('active_marker', 1)->lockForUpdate()->first();
            if ($active && $effectiveFrom->lte($active->effective_from)) {
                throw ValidationException::withMessages(['effective_from' => 'تاریخ مؤثر نرخ جدید باید بعد از شروع نرخ فعال فعلی باشد؛ برای اصلاح ابتدای دوره از ابزار Repair امن استفاده کنید.']);
            }
            $conflict = CommissionRateRevision::query()
                ->where('target_key', $key)
                ->when($active, fn ($query) => $query->whereKeyNot($active->id))
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $effectiveFrom))
                ->lockForUpdate()
                ->exists();
            if ($conflict) {
                throw ValidationException::withMessages(['effective_from' => 'تاریخ انتخاب‌شده با تاریخچه موجود این نرخ تداخل دارد.']);
            }
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

    public function backdateActiveRate(string $type, int $id, CarbonInterface|string $effectiveFrom, User $actor): CommissionRateRevision
    {
        CommissionTarget::resolve($type, $id);
        $effectiveFrom = Carbon::parse($effectiveFrom);
        $this->assertDateIsNotFinalized($effectiveFrom);

        return DB::transaction(function () use ($type, $id, $effectiveFrom, $actor) {
            CommissionSetting::current();
            CommissionSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $key = CommissionTarget::key($type, $id);
            $active = CommissionRateRevision::query()
                ->where('target_key', $key)
                ->where('active_marker', 1)
                ->lockForUpdate()
                ->firstOrFail();

            if ($effectiveFrom->gte($active->effective_from)) {
                throw ValidationException::withMessages(['effective_from' => 'تاریخ درخواستی باید قبل از شروع فعلی نرخ باشد.']);
            }

            $conflict = CommissionRateRevision::query()
                ->where('target_key', $key)
                ->whereKeyNot($active->id)
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $effectiveFrom))
                ->lockForUpdate()
                ->exists();
            if ($conflict) {
                throw ValidationException::withMessages(['effective_from' => 'Backdate با یک revision قبلی هم‌پوشانی ایجاد می‌کند و متوقف شد.']);
            }

            $oldEffectiveFrom = $active->effective_from->toDateTimeString();
            $active->update(['effective_from' => $effectiveFrom]);
            ActivityLogger::log('commission_rate.backdated', $active, 'تاریخ مؤثر نرخ پورسانت به‌صورت کنترل‌شده اصلاح شد.', [
                'target_key' => $key,
                'old_effective_from' => $oldEffectiveFrom,
                'new_effective_from' => $effectiveFrom->toDateTimeString(),
                'actor_id' => $actor->id,
            ]);
            $this->dirtyMarker->markAllMutable();

            return $active->fresh();
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

    private function assertDateIsNotFinalized(CarbonInterface $effectiveFrom): void
    {
        $finalized = CommissionPeriod::query()
            ->whereIn('status', [CommissionPeriod::STATUS_CLOSED, CommissionPeriod::STATUS_PAID])
            ->where('start_at', '<=', $effectiveFrom)
            ->where('end_at', '>', $effectiveFrom)
            ->exists();
        if ($finalized) {
            throw ValidationException::withMessages(['effective_from' => 'تاریخ مؤثر داخل یک دوره بسته یا پرداخت‌شده است و قابل تغییر نیست.']);
        }
    }
}
