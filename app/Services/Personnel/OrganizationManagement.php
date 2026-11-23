<?php

namespace App\Services\Personnel;

use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizationManagement
{
    private const FIELDS = ['manager_id', 'department', 'position', 'personnel_code', 'branch', 'organization_level', 'organization_managed_locally', 'show_in_organization_chart'];

    public static function version(User $user): string
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = match ($field) {
                'organization_managed_locally', 'show_in_organization_chart' => (bool) $user->$field,
                'manager_id' => $user->$field === null ? null : (int) $user->$field,
                default => $user->$field,
            };
        }
        return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE));
    }

    public function update(User $target, User $actor, array $data): void
    {
        DB::transaction(function () use ($target, $actor, $data): void {
            // Serialize hierarchy edits so two concurrent changes cannot introduce a cycle.
            $users = User::orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $user = $users->get($target->id);
            abort_unless($user, 404);
            if (! hash_equals(self::version($user), $data['organization_version'])) {
                throw ValidationException::withMessages(['organization_version' => 'اطلاعات این فرد تغییر کرده است؛ صفحه را تازه کنید و دوباره بررسی کنید.']);
            }
            $managerId = filled($data['manager_id'] ?? null) ? (int) $data['manager_id'] : null;
            if ($managerId !== null) {
                $manager = $users->get($managerId);
                if (! $manager || (! $manager->is_active || ! $manager->can_access_erp) && $managerId !== (int) $user->manager_id) {
                    throw ValidationException::withMessages(['manager_id' => 'مدیر انتخاب‌شده باید کاربر فعال سامانه باشد.']);
                }
                if (! $manager->show_in_organization_chart && $managerId !== (int) $user->manager_id) {
                    throw ValidationException::withMessages(['manager_id' => 'برای مدیر مستقیم، یک عضو چارت سازمانی انتخاب کنید.']);
                }
                $seen = [$user->id => true];
                for ($id = $managerId; $id !== null;) {
                    if (isset($seen[$id])) {
                        throw ValidationException::withMessages(['manager_id' => 'این انتخاب باعث چرخه مدیریتی می‌شود؛ مدیر دیگری انتخاب کنید.']);
                    }
                    $seen[$id] = true;
                    $id = $users->get($id)?->manager_id;
                }
            }
            $before = $user->only(self::FIELDS);
            $user->fill(collect($data)->only(['department', 'position', 'personnel_code', 'branch', 'organization_level', 'show_in_organization_chart'])->all());
            $user->manager_id = $managerId;
            $user->organization_managed_locally = true;
            if (! $user->isDirty()) return;
            $user->save();
            ActivityLogger::logForActor($actor->id, 'personnel.organization_updated', $user, 'ثبت ساختار سازمانی در INV', ['before' => $before, 'after' => $user->only(self::FIELDS)]);
        }, 3);
    }
}
