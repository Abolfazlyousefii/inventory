<?php

namespace App\Services\Commissions;

use App\Models\CommissionSetting;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;

class CommissionFeatureService
{
    public function settings(): CommissionSetting
    {
        return CommissionSetting::current();
    }

    public function isPilotMode(): bool
    {
        return (bool) ($this->settings()->pilot_mode ?? true);
    }

    public function isSellerVisibilityEnabled(): bool
    {
        return (bool) ($this->settings()->seller_visibility_enabled ?? false);
    }

    public function areTargetsEnabled(): bool
    {
        return (bool) ($this->settings()->targets_enabled ?? false);
    }

    public function update(array $features, User $actor): CommissionSetting
    {
        return DB::transaction(function () use ($features, $actor) {
            CommissionSetting::current();
            $setting = CommissionSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $changes = [
                'pilot_mode' => (bool) $features['pilot_mode'],
                'seller_visibility_enabled' => (bool) $features['seller_visibility_enabled'],
                'targets_enabled' => (bool) $features['targets_enabled'],
            ];

            foreach ($changes as $attribute => $value) {
                if ((bool) $setting->{$attribute} === $value) {
                    continue;
                }

                ActivityLogger::log($this->auditAction($attribute), $setting, $this->auditDescription($attribute), [
                    'old_value' => (bool) $setting->{$attribute},
                    'new_value' => $value,
                    'actor_id' => $actor->id,
                ]);
            }

            $setting->update($changes + ['updated_by' => $actor->id]);

            return $setting->fresh();
        }, 3);
    }

    private function auditAction(string $attribute): string
    {
        return match ($attribute) {
            'pilot_mode' => 'commission_pilot_mode.updated',
            'seller_visibility_enabled' => 'commission_seller_visibility.updated',
            'targets_enabled' => 'commission_targets_visibility.updated',
        };
    }

    private function auditDescription(string $attribute): string
    {
        return match ($attribute) {
            'pilot_mode' => 'حالت آزمایشی سیستم پورسانت تغییر کرد.',
            'seller_visibility_enabled' => 'نمایش پورسانت برای فروشندگان تغییر کرد.',
            'targets_enabled' => 'نمایش تارگت پورسانت تغییر کرد.',
        };
    }
}
