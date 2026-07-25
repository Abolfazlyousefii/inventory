<?php

namespace App\Services\Permissions;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class PermissionManagementService
{
    public function __construct(private PermissionDependencyResolver $dependencies) {}

    public function effective(User $user): array
    {
        $direct = $user->permissions()->whereNotNull('key')->pluck('key')->all();
        $role = DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->join('model_has_roles', 'model_has_roles.role_id', '=', 'role_has_permissions.role_id')
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.model_id', $user->id)
            ->pluck('permissions.key')->filter()->unique()->all();

        return collect(PermissionCatalog::registry())->mapWithKeys(function (array $meta, string $key) use ($direct, $role, $user): array {
            $fromRole = $user->isSuperAdmin() || in_array($key, $role, true);
            $isDirect = in_array($key, $direct, true);
            return [$key => $meta + [
                'granted' => $fromRole || $isDirect,
                'source' => $fromRole && $isDirect ? 'both' : ($fromRole ? 'role' : ($isDirect ? 'direct' : 'none')),
            ]];
        })->all();
    }

    public function update(User $target, array $directKeys, array $roles, User $actor, bool $changePermissions, bool $changeRoles): void
    {
        $registry = PermissionCatalog::registry();
        foreach ($directKeys as $key) {
            if (! isset($registry[$key]) || $registry[$key]['deprecated']) {
                throw ValidationException::withMessages(['direct_permissions' => 'دسترسی ناشناخته یا قدیمی قابل انتساب نیست.']);
            }
        }
        $directKeys = $this->dependencies->normalize($directKeys);

        DB::transaction(function () use ($target, $directKeys, $roles, $actor, $changePermissions, $changeRoles): void {
            $before = ['roles' => $target->roles()->pluck('name')->all(), 'direct_permissions' => $target->permissions()->pluck('key')->all()];
            if ($changeRoles) {
                $this->protectLastSuperAdmin($target, $roles);
                $target->syncRoles($roles);
            }
            if ($changePermissions) {
                $ids = DB::table('permissions')->whereIn('key', $directKeys)->pluck('id')->all();
                $target->permissions()->sync($ids);
            }
            $after = ['roles' => $target->roles()->pluck('name')->all(), 'direct_permissions' => $target->permissions()->pluck('key')->all()];
            ActivityLog::create(['user_id'=>$actor->id,'action'=>'permissions.updated','subject_type'=>User::class,'subject_id'=>$target->id,'description'=>'به‌روزرسانی نقش‌ها و دسترسی‌های مستقیم کاربر','properties'=>compact('before','after'),'occurred_at'=>now()]);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        cache()->forget('user_permissions:'.$target->id);
    }

    private function protectLastSuperAdmin(User $target, array $roles): void
    {
        if ($target->hasRole('super_admin') && ! in_array('super_admin', $roles, true) && User::role('super_admin')->count() <= 1) {
            throw ValidationException::withMessages(['roles' => 'حذف نقش آخرین مدیر کل مجاز نیست.']);
        }
    }
}
