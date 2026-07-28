<?php

namespace App\Services\Permissions;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

class PermissionManagementService
{
    public function __construct(private PermissionDependencyResolver $dependencies) {}

    public function effective(User $user, ?array $directOverride = null): array
    {
        $guard = PermissionCatalog::guardName();
        $direct = $directOverride ?? $user->permissions()
            ->where('guard_name', $guard)
            ->whereNotNull('key')
            ->pluck('key')
            ->filter(fn ($key): bool => is_string($key) && $key !== '')
            ->values()
            ->all();

        $role = DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('model_has_roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.guard_name', $guard)
            ->where('permissions.guard_name', $guard)
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.model_id', $user->id)
            ->whereNotNull('permissions.key')
            ->pluck('permissions.key')
            ->filter(fn ($key): bool => is_string($key) && $key !== '')
            ->unique()
            ->values()
            ->all();

        $registry = PermissionCatalog::registry();
        foreach (array_diff(array_unique(array_merge($direct, $role)), array_keys($registry)) as $legacyKey) {
            $registry[$legacyKey] = [
                'key' => $legacyKey,
                'label' => $legacyKey,
                'description' => 'دسترسی قدیمی خارج از فهرست فعال',
                'module' => 'legacy',
                'module_label' => 'دسترسی های قدیمی',
                'action' => 'legacy',
                'risk' => 'normal',
                'depends_on' => [],
                'deprecated' => true,
                'active' => false,
                'sort_order' => PHP_INT_MAX,
                'page_permission' => false,
                'sidebar' => false,
                'routes' => [],
            ];
        }

        return collect($registry)->mapWithKeys(function (array $meta, string $key) use ($direct, $role, $user): array {
            $fromRole = $user->isSuperAdmin() || in_array($key, $role, true);
            $isDirect = in_array($key, $direct, true);

            return [$key => $meta + [
                'granted' => $fromRole || $isDirect,
                'source' => $fromRole && $isDirect ? 'both' : ($fromRole ? 'role' : ($isDirect ? 'direct' : 'none')),
            ]];
        })->all();
    }

    public function update(
        User $target,
        array $directKeys,
        array $roles,
        User $actor,
        bool $changePermissions,
        bool $changeRoles,
        ?string $ipAddress = null
    ): bool {
        $directKeys = $changePermissions ? $this->normalizeDirectPermissions($directKeys) : [];
        $roles = $changeRoles ? $this->validateRoles($roles) : [];

        $changed = DB::transaction(function () use (
            $target,
            $directKeys,
            $roles,
            $actor,
            $changePermissions,
            $changeRoles,
            $ipAddress
        ): bool {
            $lockedTarget = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($lockedTarget);

            if ($changeRoles) {
                $this->protectLastSuperAdmin($lockedTarget, $roles);
                $lockedTarget->syncRoles($roles);
            }

            if ($changePermissions) {
                $activeKeys = PermissionCatalog::activeKeys();
                $activePermissionIds = DB::table('permissions')
                    ->where('guard_name', PermissionCatalog::guardName())
                    ->whereIn('key', $directKeys)
                    ->pluck('id')
                    ->all();

                if (count($activePermissionIds) !== count($directKeys)) {
                    throw ValidationException::withMessages([
                        'direct_permissions' => 'یک یا چند دسترسی در پایگاه داده موجود نیست. ابتدا دستور همگام سازی دسترسی ها را اجرا کنید.',
                    ]);
                }

                $legacyPermissionIds = $lockedTarget->permissions()
                    ->where('guard_name', PermissionCatalog::guardName())
                    ->where(function ($query) use ($activeKeys): void {
                        $query->whereNull('key')->orWhereNotIn('key', $activeKeys);
                    })
                    ->pluck('permissions.id')
                    ->all();

                $lockedTarget->permissions()->sync(array_values(array_unique(array_merge(
                    $activePermissionIds,
                    $legacyPermissionIds
                ))));
            }

            $lockedTarget->unsetRelation('roles');
            $lockedTarget->unsetRelation('permissions');
            $after = $this->snapshot($lockedTarget);
            $this->protectActorManagementAccess($lockedTarget, $actor, $after);

            if ($before === $after) {
                return false;
            }

            ActivityLog::query()->create([
                'user_id' => $actor->id,
                'action' => 'permissions.updated',
                'subject_type' => $lockedTarget->getMorphClass(),
                'subject_id' => $lockedTarget->id,
                'description' => 'به روزرسانی نقش ها و دسترسی های مستقیم کاربر',
                'properties' => [
                    'actor_id' => $actor->id,
                    'target_user_id' => $lockedTarget->id,
                    'before' => $before,
                    'after' => $after,
                    'ip' => $ipAddress,
                    'changed_at' => now()->toIso8601String(),
                ],
                'occurred_at' => now(),
            ]);

            return true;
        });

        $target->refresh()->load(['roles', 'permissions']);

        if ($changed) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            cache()->forget('user_permissions:'.$target->id);
        }

        return $changed;
    }

    private function normalizeDirectPermissions(array $directKeys): array
    {
        try {
            return $this->dependencies->normalize($directKeys);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'direct_permissions' => 'یک یا چند دسترسی ارسال‌شده در نسخه فعلی معتبر نیستند. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.',
            ]);
        }
    }

    private function validateRoles(array $roles): array
    {
        $roles = array_values(array_unique(array_filter(
            $roles,
            fn ($role): bool => is_string($role) && trim($role) !== ''
        )));
        $validRoles = DB::table('roles')
            ->where('guard_name', PermissionCatalog::guardName())
            ->whereIn('name', $roles)
            ->pluck('name')
            ->all();

        if (count($validRoles) !== count($roles)) {
            throw ValidationException::withMessages([
                'roles' => 'یک یا چند نقش معتبر نیست یا Guard صحیحی ندارد.',
            ]);
        }

        return $roles;
    }

    /** @return array{roles: array<int, string>, direct_permissions: array<int, string>} */
    private function snapshot(User $target): array
    {
        $roles = $target->roles()
            ->where('guard_name', PermissionCatalog::guardName())
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
        $permissions = $target->permissions()
            ->where('guard_name', PermissionCatalog::guardName())
            ->whereNotNull('key')
            ->pluck('key')
            ->filter()
            ->sort()
            ->values()
            ->all();

        return ['roles' => $roles, 'direct_permissions' => $permissions];
    }

    private function protectLastSuperAdmin(User $target, array $roles): void
    {
        $superAdminRoles = PermissionCatalog::superAdminRoles();
        $currentSuperAdminRoles = $target->roles()
            ->where('guard_name', PermissionCatalog::guardName())
            ->whereIn('name', $superAdminRoles)
            ->pluck('name')
            ->all();

        if ($currentSuperAdminRoles === [] || array_intersect($roles, $superAdminRoles) !== []) {
            return;
        }

        $superAdminIds = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.guard_name', PermissionCatalog::guardName())
            ->whereIn('roles.name', $superAdminRoles)
            ->where('model_has_roles.model_type', $target->getMorphClass())
            ->lockForUpdate()
            ->pluck('model_has_roles.model_id')
            ->unique();

        if ($superAdminIds->count() <= 1) {
            throw ValidationException::withMessages([
                'roles' => 'حذف نقش آخرین مدیر کل سیستم مجاز نیست.',
            ]);
        }
    }

    /** @param array{roles: array<int, string>, direct_permissions: array<int, string>} $after */
    private function protectActorManagementAccess(User $target, User $actor, array $after): void
    {
        if ($target->id !== $actor->id || array_intersect($after['roles'], PermissionCatalog::superAdminRoles()) !== []) {
            return;
        }

        $rolePermissions = DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('roles.guard_name', PermissionCatalog::guardName())
            ->where('permissions.guard_name', PermissionCatalog::guardName())
            ->whereIn('roles.name', $after['roles'])
            ->whereIn('permissions.key', ['permissions.view', 'permissions.edit'])
            ->pluck('permissions.key')
            ->all();
        $effective = array_unique(array_merge($rolePermissions, $after['direct_permissions']));

        if (! in_array('permissions.view', $effective, true) || ! in_array('permissions.edit', $effective, true)) {
            throw ValidationException::withMessages([
                'direct_permissions' => 'حذف آخرین دسترسی مدیریت دسترسی ها از حساب خودتان مجاز نیست.',
            ]);
        }
    }
}
