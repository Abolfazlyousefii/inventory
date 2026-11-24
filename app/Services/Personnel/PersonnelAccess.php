<?php

namespace App\Services\Personnel;

use App\Models\PersonnelEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class PersonnelAccess
{
    public static function active(User $user): bool
    {
        return $user->is_active && $user->can_access_erp;
    }

    public static function permission(string $kind, string $action): string
    {
        return 'personnel.'.($kind === 'leave' ? 'leaves' : 'reports').'.'.$action;
    }

    public function canUse(User $user, string $kind): bool
    {
        return self::active($user) && $user->hasPermission(self::permission($kind, 'use'));
    }

    public function canReview(User $user, string $kind): bool
    {
        return self::active($user) && $user->hasPermission(self::permission($kind, 'review'));
    }

    public function visible(Builder $query, User $user, string $kind, bool $team): Builder
    {
        $query->where('kind', $kind);
        if (! $team) {
            return $query->where('user_id', $user->id);
        }
        abort_unless($this->canReview($user, $kind) || ($kind === 'leave' && $this->canUse($user, 'leave')), 403);

        // Frozen assignments are the request's authority: changing the chart never silently reroutes history.
        return $query->where('user_id', '!=', $user->id)->whereNotIn('status', ['draft'])->whereHas('steps', function ($q) use ($user, $kind) {
            $q->where('approver_id', $user->id);
            if (! $this->canReview($user, $kind)) {
                $q->where('stage', 'substitute');
            }
        });
    }

    public function view(User $user, PersonnelEntry $entry): bool
    {
        if (! self::active($user)) {
            return false;
        }
        if ((int) $entry->user_id === (int) $user->id) {
            return $this->canUse($user, $entry->kind);
        }
        if ($entry->kind === 'leave' && $this->canUse($user, 'leave') && $entry->steps()->where('stage', 'substitute')->where('approver_id', $user->id)->exists()) {
            return true;
        }

        return $entry->status !== 'draft' && $this->canReview($user, $entry->kind) && $entry->steps()->where('approver_id', $user->id)->exists();
    }

    public function decide(User $user, PersonnelEntry $entry): bool
    {
        if ((int) $entry->user_id === (int) $user->id || ! self::active($user)) {
            return false;
        }
        $steps = $entry->steps()->where('status', 'pending');
        // Historical CRM requests used a shared approver pool, not a sequential route.
        // Preserve that explicit source assignment without widening any new request.
        if ($entry->source === PersonnelEntry::SOURCE_CRM && ($entry->payload['crm_approval_route'] ?? null) === 'legacy') {
            $steps->where('stage', 'legacy_pool')->where('approver_id', $user->id);
        }
        $step = $steps->first();
        if (! $step || (int) $step->approver_id !== (int) $user->id) {
            return false;
        }

        return $step->stage === 'substitute' ? $this->canUse($user,'leave') : $this->canReview($user,'leave');
    }
}
