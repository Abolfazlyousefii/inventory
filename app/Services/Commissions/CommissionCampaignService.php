<?php

namespace App\Services\Commissions;

use App\Models\CommissionCampaign;
use App\Models\CommissionSetting;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Percentage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommissionCampaignService
{
    public function __construct(private readonly CommissionPeriodDirtyMarker $dirtyMarker) {}

    public function save(array $data, User $actor, ?CommissionCampaign $campaign = null): CommissionCampaign
    {
        $start = Carbon::parse($data['start_at']);
        $end = Carbon::parse($data['end_at']);
        if (! $end->gt($start)) {
            throw ValidationException::withMessages(['end_at' => 'تاریخ پایان باید بعد از تاریخ شروع باشد.']);
        }

        $targets = collect($data['targets'] ?? [])->filter(fn ($target) => filled($target))->map(function (string $target) {
            [$type, $id] = array_pad(explode(':', $target, 2), 2, null);
            $id = (int) $id;
            CommissionTarget::resolve((string) $type, $id);

            return [(string) $type, $id];
        })->unique(fn ($target) => CommissionTarget::key(...$target))->values();
        if ($targets->isEmpty()) {
            throw ValidationException::withMessages(['targets' => 'حداقل یک هدف برای کمپین انتخاب کنید.']);
        }

        return DB::transaction(function () use ($data, $actor, $campaign, $start, $end, $targets) {
            CommissionSetting::current();
            CommissionSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $overlaps = CommissionCampaign::query()->whereNull('archived_at')
                ->when($campaign, fn ($query) => $query->whereKeyNot($campaign->id))
                ->where('start_at', '<', $end)->where('end_at', '>', $start)
                ->lockForUpdate()->exists();
            if ($overlaps) {
                throw ValidationException::withMessages(['start_at' => 'بازه این کمپین با کمپین دیگری هم‌پوشانی دارد.']);
            }
            $previousCampaignId = $campaign?->id;
            if ($campaign) {
                $campaign->update(['archived_at' => now(), 'updated_by' => $actor->id]);
            }
            $campaign = CommissionCampaign::query()->create(['name' => $data['name'], 'bonus_percentage' => Percentage::normalize($data['bonus_percentage']), 'start_at' => $start, 'end_at' => $end, 'notes' => $data['notes'] ?? null, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            foreach ($targets as [$type, $id]) {
                $campaign->targets()->create(array_merge(['target_type' => $type, 'target_id' => $id, 'target_key' => CommissionTarget::key($type, $id)], CommissionTarget::foreignKeys($type, $id)));
            }

            ActivityLogger::log($previousCampaignId ? 'commission_campaign.revised' : 'commission_campaign.created', $campaign, $previousCampaignId ? 'نسخه جدید کمپین پورسانت ثبت شد.' : 'کمپین پورسانت ایجاد شد.', [
                'previous_campaign_id' => $previousCampaignId,
                'actor_id' => $actor->id,
                'targets' => $campaign->targets()->pluck('target_key')->all(),
            ]);
            $this->dirtyMarker->markAllMutable();

            return $campaign->load('targets');
        });
    }

    public function archive(CommissionCampaign $campaign, User $actor): CommissionCampaign
    {
        $campaign->update(['archived_at' => now(), 'updated_by' => $actor->id]);
        ActivityLogger::log('commission_campaign.archived', $campaign, 'کمپین پورسانت بایگانی شد.', [
            'actor_id' => $actor->id,
        ]);
        $this->dirtyMarker->markAllMutable();

        return $campaign;
    }
}
