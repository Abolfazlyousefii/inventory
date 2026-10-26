<?php

namespace App\Services\Permissions;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\PageAccessCatalog;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Replace only explicitly selected page grants; leave other workflows untouched. */
class ScopedPageAccessService
{
    public function permissionKeys(array $pages): array
    {
        $keys = [];
        foreach ($pages as $page) {
            $meta = PageAccessCatalog::page($page);
            if (! $meta) {
                throw new InvalidArgumentException('Unknown page: '.$page);
            }
            $keys[] = $meta['permission'];
            $keys = array_merge($keys, $meta['action_permissions']);
        }
        $legacy = collect(PermissionCatalog::groups())->flatMap(fn ($group) => array_keys($group));
        foreach ($legacy as $key) {
            if (in_array(PageAccessCatalog::pagePermissionForLegacy($key), $keys, true)) {
                $keys[] = $key;
            }
        }
        foreach (PermissionCatalog::routePermissions() as $route => $key) {
            $owners = PageAccessCatalog::routeOwners()[$route] ?? [];
            if (count($owners) === 1 && in_array($owners[0], $keys, true)) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    public function replace(array $grants, User $actor, array $administratorRemovals = []): void
    {
        if (! $actor->isAdministrator()) {
            throw new InvalidArgumentException('An administrator is required.');
        }
        $keys = $this->permissionKeys(array_keys($grants));
        $administratorRemovals = array_values(array_unique(array_map('intval', $administratorRemovals)));
        if (in_array((int) $actor->id, $administratorRemovals, true)
            || User::whereIn('id', $administratorRemovals)->count() !== count($administratorRemovals)) {
            throw new InvalidArgumentException('Invalid administrator removal.');
        }
        foreach ($grants as $ids) {
            if (User::whereIn('id', array_unique($ids))->count() !== count(array_unique($ids))) {
                throw new InvalidArgumentException('A target user does not exist.');
            }
        }
        try {
            DB::transaction(function () use ($grants, $keys, $actor, $administratorRemovals) {
                User::orderBy('id')->lockForUpdate()->get(['id']);
                if (! User::findOrFail($actor->id)->isAdministrator()) {
                    throw new InvalidArgumentException('The operator is no longer an administrator.');
                }
                $administratorBefore = [];
                foreach ($administratorRemovals as $id) {
                    $target = User::with('roles')->findOrFail($id);
                    $administratorBefore[$id] = $target->roles->pluck('name')->all();
                    $target->syncRoles($target->roles->reject(fn ($role) => in_array($role->name, PermissionCatalog::administratorRoles(), true)));
                    $target->assignRole(Role::findOrCreate(PermissionCatalog::ADMINISTRATOR_SYNC_BLOCK_ROLE, 'web'));
                    if ($target->fresh()->isAdministrator()) {
                        throw new InvalidArgumentException('Administrator removal did not take effect.');
                    }
                }
                PermissionCatalog::syncToDatabase();
                $ids = DB::table('permissions')->where('guard_name', 'web')->whereIn('key', $keys)->pluck('id')->all();
                $before = [];
                foreach (['role_has_permissions', 'user_permissions', 'model_has_permissions'] as $table) {
                    $before[$table] = DB::table($table)->whereIn('permission_id', $ids)->get()->map(fn ($r) => (array) $r)->all();
                    DB::table($table)->whereIn('permission_id', $ids)->delete();
                }
                foreach ($grants as $page => $users) {
                    $role = Role::findOrCreate('Org:'.$page, 'web');
                    $roleKeys = array_merge(['page.'.$page], PageAccessCatalog::page($page)['action_permissions']);
                    foreach (DB::table('permissions')->whereIn('key', $roleKeys)->where('guard_name', 'web')->pluck('id') as $permission) {
                        DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permission]);
                    }
                    // This dedicated role contains only this page; old membership cannot retain access.
                    DB::table('model_has_roles')->where('role_id', $role->id)->delete();
                    foreach (array_unique($users) as $id) {
                        $user = User::findOrFail($id);
                        $user->assignRole($role);
                    }
                }
                app(PermissionRegistrar::class)->forgetCachedPermissions();
                foreach (User::with(['roles', 'permissions'])->get() as $user) {
                    foreach ($grants as $page => $ids) {
                        $expected = $user->isAdministrator() || in_array($user->id, $ids, true);
                        if (PageAccessCatalog::userCan($user, 'page.'.$page) !== $expected) {
                            throw new InvalidArgumentException('Unexpected access after update: '.$user->id.' / '.$page);
                        }
                    }
                }
                ActivityLog::create([
                    'user_id' => $actor->id, 'action' => 'permissions.scoped_replaced',
                    'subject_type' => User::class, 'subject_id' => $actor->id,
                    'description' => 'تنظیم دسترسی بخش‌های مالی و انبار طبق فهرست مصوب مدیریت',
                    'properties' => ['grants' => $grants, 'scoped_keys' => $keys, 'before' => $before, 'administrator_roles_before' => $administratorBefore],
                    'occurred_at' => now(),
                ]);
            });
        } finally {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
