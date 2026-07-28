<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserPermissionsRequest;
use App\Models\User;
use App\Services\Permissions\PermissionManagementService;
use App\Support\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UserPermissionController extends Controller
{
    public function __construct(private PermissionManagementService $service) {}

    public function index(Request $request): View
    {
        $users = User::with(['roles' => fn ($query) => $query->where('guard_name', PermissionCatalog::guardName())])
            ->orderBy('name')
            ->get();
        $users->each(function (User $user): void {
            $user->setAttribute(
                'role_labels',
                $user->roles->map(fn (Role $role): string => PermissionCatalog::roleLabel($role->name))->join('، ')
            );
        });

        $requestedUserMissing = false;
        if ($request->has('user_id')) {
            $selectedUser = User::with(['permissions', 'roles'])->find($request->integer('user_id'));
            $requestedUserMissing = $selectedUser === null;
        } else {
            $selectedUser = $users->first()
                ? User::with(['permissions', 'roles'])->find($users->first()->id)
                : null;
        }

        $oldDirectPermissions = $request->old('direct_permissions_submitted')
            ? array_values(array_filter((array) $request->old('direct_permissions', []), 'is_string'))
            : null;
        $effective = $selectedUser ? $this->service->effective($selectedUser, $oldDirectPermissions) : [];
        $effective = $this->normalizeEffectiveItems($effective);
        $modules = collect($effective)->reject('deprecated')->groupBy('module');

        $selectedRoleNames = $selectedUser?->roles
            ->where('guard_name', PermissionCatalog::guardName())
            ->pluck('name')
            ->all() ?? [];
        if ($request->old('roles_submitted')) {
            $selectedRoleNames = array_values(array_filter((array) $request->old('roles', []), 'is_string'));
        }

        $roles = Role::query()
            ->where('guard_name', PermissionCatalog::guardName())
            ->withCount('permissions')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => [
                'name' => $role->name,
                'canonical_key' => PermissionCatalog::canonicalRoleKey($role->name),
                'label' => PermissionCatalog::roleLabel($role->name),
                'permissions_count' => (int) $role->permissions_count,
                'selected' => in_array($role->name, $selectedRoleNames, true),
                'legacy' => PermissionCatalog::isLegacyRole($role->name),
            ]);

        $canEditPermissions = PermissionCatalog::userHasPermission($request->user(), 'permissions.edit');
        $canAssignRoles = PermissionCatalog::userHasPermission($request->user(), 'permissions.assign_roles');
        $effectiveCount = collect($effective)->where('granted', true)->count();
        $directCount = collect($effective)->whereIn('source', ['direct', 'both'])->count();
        $selectedRoleCount = count($selectedRoleNames);

        return view('admin.permissions.index', compact(
            'users',
            'selectedUser',
            'requestedUserMissing',
            'effective',
            'effectiveCount',
            'directCount',
            'selectedRoleCount',
            'modules',
            'roles',
            'canEditPermissions',
            'canAssignRoles'
        ));
    }

    public function update(UpdateUserPermissionsRequest $request, User $user): RedirectResponse
    {
        abort_unless($request->integer('user_id') === $user->id, 422, 'شناسه کاربر ناهماهنگ است.');

        $data = $request->validated();
        $actor = $request->user();
        $canEditPermissions = PermissionCatalog::userHasPermission($actor, 'permissions.edit');
        $canAssignRoles = PermissionCatalog::userHasPermission($actor, 'permissions.assign_roles');
        $rolesSubmitted = $request->boolean('roles_submitted');
        $roles = $canAssignRoles && $rolesSubmitted
            ? array_values($data['roles'] ?? [])
            : $user->roles()->where('guard_name', PermissionCatalog::guardName())->pluck('name')->all();

        $changed = $this->service->update(
            $user,
            array_values($data['direct_permissions'] ?? []),
            $roles,
            $actor,
            $canEditPermissions,
            $canAssignRoles && $rolesSubmitted,
            $request->ip(),
        );

        $message = $changed
            ? 'نقش ها و دسترسی ها با موفقیت ذخیره شد.'
            : 'تغییری برای ذخیره وجود نداشت.';

        return to_route('admin.permissions.index', ['user_id' => $user->id])->with('success', $message);
    }

    private function normalizeEffectiveItems(array $effective): array
    {
        $allowedSources = ['role', 'direct', 'both', 'none'];
        $allowedRisks = ['normal', 'sensitive', 'critical'];
        $sourceMap = [
            'role' => ['label' => 'از نقش', 'variant' => 'primary'],
            'direct' => ['label' => 'مستقیم', 'variant' => 'success'],
            'both' => ['label' => 'نقش + مستقیم', 'variant' => 'info'],
            'none' => ['label' => 'فاقد دسترسی', 'variant' => 'secondary'],
        ];
        $riskMap = [
            'normal' => ['label' => 'عادی', 'variant' => 'secondary'],
            'sensitive' => ['label' => 'حساس', 'variant' => 'warning'],
            'critical' => ['label' => 'بسیار حساس', 'variant' => 'danger'],
        ];

        return collect($effective)->map(function ($item, string $key) use ($allowedSources, $allowedRisks, $sourceMap, $riskMap): array {
            $item = is_array($item) ? $item : [];
            $module = is_string($item['module'] ?? null) && trim($item['module']) !== ''
                ? $item['module']
                : str($key)->before('.')->toString();
            $moduleLabel = is_string($item['module_label'] ?? null) && trim($item['module_label']) !== ''
                ? $item['module_label']
                : $module;
            $source = in_array($item['source'] ?? null, $allowedSources, true) ? $item['source'] : 'none';
            $risk = in_array($item['risk'] ?? null, $allowedRisks, true) ? $item['risk'] : 'normal';

            return array_merge([
                'key' => $key,
                'label' => $key,
                'action' => 'view',
                'deprecated' => false,
                'granted' => false,
            ], $item, [
                'module' => $module,
                'module_label' => $moduleLabel,
                'source' => $source,
                'source_label' => $sourceMap[$source]['label'] ?? 'فاقد دسترسی',
                'source_variant' => $sourceMap[$source]['variant'] ?? 'secondary',
                'risk' => $risk,
                'risk_label' => $riskMap[$risk]['label'] ?? 'عادی',
                'risk_variant' => $riskMap[$risk]['variant'] ?? 'secondary',
                'depends_on' => array_values(array_filter((array) ($item['depends_on'] ?? []), 'is_string')),
            ]);
        })->all();
    }
}
