<?php

namespace App\Services\Crm;

use App\Data\CrmUserData;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class CrmUserSynchronizer
{
    public function __construct(
        private readonly CrmRoleMapper $roleMapper,
        private readonly CrmAuditLogger $audit,
    ) {}

    public function sync(CrmUserData $data, bool $dryRun = false): array
    {
        if ($dryRun) {
            return [
                'user' => User::query()->where('crm_user_id', $data->crmUserId)->first(),
                'created' => false,
                'dry_run' => true,
                'mapped_roles' => $this->mappedRoleNames($data->roles),
                'unknown_roles' => [],
            ];
        }

        return DB::transaction(function () use ($data): array {
            $user = User::query()->where('crm_user_id', $data->crmUserId)->lockForUpdate()->first();

            if (! $user && config('crm.sync.allow_initial_phone_link', false) && $data->phone) {
                $matches = User::query()
                    ->whereNull('crm_user_id')
                    ->where('phone', $data->phone)
                    ->lockForUpdate()
                    ->get();
                if ($matches->count() > 1) throw new \RuntimeException('crm_phone_ambiguous');
                $user = $matches->first();
            }

            if ($data->phone && User::query()->where('phone', $data->phone)->whereNotNull('crm_user_id')
                ->where('crm_user_id', '!=', $data->crmUserId)->exists()) throw new \RuntimeException('crm_phone_conflict');

            $created = ! $user;
            $user ??= new User;
            $before = $user->exists ? $user->only(['is_active', 'manager_id', 'phone', 'name']) : [];

            $email = $this->availableEmail($data, $user);
            $user->fill([
                'external_crm_id' => ctype_digit($data->crmUserId) ? (int) $data->crmUserId : null,
                'crm_user_id' => $data->crmUserId,
                'name' => $data->name,
                'email' => $email,
                'phone' => $data->phone,
                'username' => $data->username,
                'is_active' => $data->isActive,
                'can_access_erp' => $data->canAccessErp,
                'is_seller' => $data->isSeller,
                'sync_source' => 'crm',
                'is_crm_managed' => true,
                'crm_role_raw' => $data->roles,
                'synced_at' => now(),
                'crm_created_at' => $data->createdAt,
                'crm_updated_at' => $data->updatedAt,
                'avatar' => $data->avatar,
                'department' => $data->department,
                'position' => $data->position,
                'personnel_code' => $data->personnelCode,
                'branch' => $data->branch,
                'manager_crm_user_id' => $data->managerCrmUserId,
            ]);

            if (! $user->exists) {
                $user->password = Hash::make(Str::random(80));
            }

            $user->save();
            if ((! $data->isActive || ! $data->canAccessErp) && config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }
            $beforeRoles = $user->load('roles')->roles->pluck('name')->sort()->values();
            $beforeSeller = $beforeRoles->intersect((array) config('crm.roles.seller', []))->isNotEmpty();
            $roleResult = $this->roleMapper->sync($user, $data->roles);
            $afterRoles = $user->fresh('roles')->roles->pluck('name')->sort()->values();
            $rolesChanged = $beforeRoles->all() !== $afterRoles->all();
            $sellerChanged = $beforeSeller !== $afterRoles->intersect((array) config('crm.roles.seller', []))->isNotEmpty();
            $managerChanged = $this->reconcileManager($user);

            $this->audit->record($created ? 'crm_user_created' : 'crm_user_updated', $user, [
                'crm_user_id' => $data->crmUserId,
                'erp_user_id' => $user->id,
                'created' => $created,
                'roles_changed' => $rolesChanged,
                'manager_changed' => $managerChanged,
                'active_changed' => array_key_exists('is_active', $before) && $before['is_active'] !== $user->is_active,
            ]);

            if ($rolesChanged) {
                $this->audit->record('crm_roles_changed', $user, [
                    'crm_user_id' => $data->crmUserId,
                    'erp_user_id' => $user->id,
                    'status' => 'changed',
                ]);
            }
            if ($sellerChanged) {
                $this->audit->record('seller_status_changed', $user, [
                    'crm_user_id' => $data->crmUserId,
                    'erp_user_id' => $user->id,
                    'status' => 'changed',
                ]);
            }
            if (array_key_exists('is_active', $before) && $before['is_active'] && ! $user->is_active) {
                $this->audit->record('crm_user_deactivated', $user, [
                    'crm_user_id' => $data->crmUserId,
                    'erp_user_id' => $user->id,
                    'status' => 'deactivated',
                ]);
            }

            return [
                'user' => $user->fresh(['roles', 'manager']),
                'created' => $created,
                'dry_run' => false,
                'mapped_roles' => $roleResult['mapped'],
                'unknown_roles' => $roleResult['unknown'],
            ];
        }, 3);
    }

    public function reconcileManagers(?array $crmUserIds = null): int
    {
        $changed = 0;
        User::query()->where('is_crm_managed', true)
            ->when($crmUserIds !== null, fn ($query) => $query->whereIn('crm_user_id', $crmUserIds))
            ->orderBy('id')->chunkById(100, function ($users) use (&$changed) {
                foreach ($users as $user) {
                    $changed += $this->reconcileManager($user) ? 1 : 0;
                }
            });

        return $changed;
    }

    private function reconcileManager(User $user): bool
    {
        $managerId = null;
        if ($user->manager_crm_user_id) {
            $managerId = User::query()
                ->where('crm_user_id', $user->manager_crm_user_id)
                ->value('id');
            if ((int) $managerId === (int) $user->id) $managerId = null;
        }

        if ((int) $user->manager_id === (int) $managerId) {
            return false;
        }

        $user->forceFill(['manager_id' => $managerId])->save();

        return true;
    }

    private function availableEmail(CrmUserData $data, User $user): string
    {
        if ($data->email) {
            $taken = User::query()->where('email', $data->email)
                ->when($user->exists, fn ($query) => $query->whereKeyNot($user->id))
                ->exists();
            if (! $taken) {
                return $data->email;
            }
        }

        return $user->email ?: 'crm_'.$data->crmUserId.'@local.invalid';
    }

    private function mappedRoleNames(array $crmRoles): array
    {
        $mapping = (array) config('crm.roles.mapping', []);

        return collect($crmRoles)
            ->flatMap(fn ($role) => (array) ($mapping[$role] ?? $mapping[mb_strtolower((string) $role)] ?? []))
            ->unique()->values()->all();
    }
}
