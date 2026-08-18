<?php

namespace App\Services\Commissions;

use App\Models\CommissionPeriod;
use App\Models\CommissionTarget;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommissionTargetService
{
    public function save(User $seller, CommissionPeriod $period, mixed $targetToman, User $actor, ?string $notes = null): CommissionTarget
    {
        if (! $seller->is_seller || ! $seller->is_active || ! $seller->can_access_erp) {
            throw ValidationException::withMessages(['seller_id' => 'کاربر انتخاب‌شده فروشنده فعال نیست.']);
        }

        $amount = Currency::tomanInput($targetToman);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['target_amount' => 'مبلغ تارگت باید بیشتر از صفر باشد.']);
        }

        return DB::transaction(function () use ($seller, $period, $amount, $actor, $notes) {
            $now = now();
            DB::table('commission_targets')->upsert([[
                'seller_id' => $seller->id,
                'commission_period_id' => $period->id,
                'target_amount' => $amount,
                'notes' => filled($notes) ? trim($notes) : null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['seller_id', 'commission_period_id'], ['target_amount', 'notes', 'updated_by', 'updated_at']);
            $target = CommissionTarget::query()->where('seller_id', $seller->id)
                ->where('commission_period_id', $period->id)->firstOrFail();

            ActivityLogger::log('commission_target.saved', $target, 'تارگت پورسانت فروشنده ذخیره شد.', [
                'seller_id' => $seller->id,
                'period_id' => $period->id,
                'target_amount' => $amount,
            ]);

            return $target->fresh();
        }, 3);
    }

    public function copyPrevious(CommissionPeriod $period, User $actor): array
    {
        $previous = CommissionPeriod::query()->where('end_at', '<=', $period->start_at)
            ->orderByDesc('end_at')->first();
        $activeSellerIds = User::query()->activeSellers()->pluck('id');

        if (! $previous) {
            return ['copied' => 0, 'existing' => 0, 'without_previous' => $activeSellerIds->count(), 'previous_period' => null];
        }

        return DB::transaction(function () use ($period, $previous, $actor, $activeSellerIds) {
            $previousTargets = CommissionTarget::query()->where('commission_period_id', $previous->id)
                ->whereIn('seller_id', $activeSellerIds)->get()->keyBy('seller_id');
            $existingSellerIds = CommissionTarget::query()->where('commission_period_id', $period->id)
                ->whereIn('seller_id', $previousTargets->keys())->lockForUpdate()->pluck('seller_id');
            $now = now();
            $rows = $previousTargets->reject(fn (CommissionTarget $target) => $existingSellerIds->contains($target->seller_id))->map(fn (CommissionTarget $target) => [
                'seller_id' => $target->seller_id,
                'commission_period_id' => $period->id,
                'target_amount' => $target->target_amount,
                'notes' => $target->notes,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values();
            $copied = $rows->isEmpty() ? 0 : DB::table('commission_targets')->insertOrIgnore($rows->all());

            ActivityLogger::log('commission_targets.copied', $period, 'تارگت‌های دوره قبل کپی شد.', [
                'previous_period_id' => $previous->id,
                'copied' => $copied,
                'existing' => $existingSellerIds->count(),
            ]);

            return [
                'copied' => $copied,
                'existing' => $existingSellerIds->count(),
                'without_previous' => $activeSellerIds->diff($previousTargets->keys())->count(),
                'previous_period' => $previous,
            ];
        }, 3);
    }

    public function managementRows(CommissionPeriod $period)
    {
        $previous = CommissionPeriod::query()->where('end_at', '<=', $period->start_at)->orderByDesc('end_at')->first();
        $currentTargets = CommissionTarget::query()->where('commission_period_id', $period->id)->get()->keyBy('seller_id');
        $previousTargets = $previous
            ? CommissionTarget::query()->where('commission_period_id', $previous->id)->get()->keyBy('seller_id')
            : collect();

        return User::query()->activeSellers()->orderBy('name')->get(['id', 'name'])->map(fn (User $seller) => [
            'seller' => $seller,
            'current' => $currentTargets->get($seller->id),
            'previous' => $previousTargets->get($seller->id),
        ]);
    }
}
