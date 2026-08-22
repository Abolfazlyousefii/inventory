<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessPermission;
use App\Models\ActivityLog;
use App\Support\PageAccessCatalog;
use App\Support\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    public function index(): View
    {
        $roles = Role::with('permissions')->orderBy('name')->get();

        return view('admin.roles.index', ['roles' => $roles, 'protectedRoleNames' => PermissionCatalog::protectedSystemRoles()]);
    }

    public function create(): View
    {
        return view('admin.roles.form', ['role' => new Role, 'permissions' => $this->permissions(), 'commissionActionPermissions' => $this->commissionActionPermissions(), 'selectedPermissionIds' => [], 'protectedRoleNames' => PermissionCatalog::protectedSystemRoles()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        $this->syncPermissions($role, $data['permissions'] ?? []);
        $this->audit('role.created', $role, [], $role->permissions()->pluck('permissions.id')->all());

        return redirect()->route('admin.roles.index')->with('success', 'نقش با موفقیت ایجاد شد.');
    }

    public function edit(Role $role): View
    {
        return view('admin.roles.form', [
            'role' => $role,
            'permissions' => $this->permissions(),
            'commissionActionPermissions' => $this->commissionActionPermissions(),
            'selectedPermissionIds' => $role->permissions()->pluck('permissions.id')->all(),
            'protectedRoleNames' => PermissionCatalog::protectedSystemRoles(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $before = $role->permissions()->pluck('permissions.id')->all();
        $data = $this->validated($request, $role);
        if (! in_array($role->name, PermissionCatalog::protectedSystemRoles(), true)) {
            $role->update(['name' => $data['name']]);
        }
        $this->syncPermissions($role, $data['permissions'] ?? []);
        $this->audit('role.updated', $role, $before, $role->permissions()->pluck('permissions.id')->all());

        return redirect()->route('admin.roles.index')->with('success', 'نقش با موفقیت ویرایش شد.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        abort_if(in_array($role->name, PermissionCatalog::protectedSystemRoles(), true), 403, 'نقش‌های سیستمی قابل حذف نیستند.');
        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('admin.roles.index')->with('success', 'نقش حذف شد.');
    }

    private function permissions()
    {
        return AccessPermission::query()
            ->where('key', 'like', 'page.%')
            ->whereIn('key', collect(PageAccessCatalog::pages())->pluck('permission'))
            ->orderBy('group')
            ->orderBy('name')
            ->get()
            ->groupBy('group');
    }

    private function commissionActionPermissions()
    {
        return AccessPermission::query()->whereIn('key', ['commissions.manage_rates', 'commissions.manage_campaigns', 'commissions.manage_periods', 'commissions.manage_targets', 'commissions.recalculate', 'commissions.view_seller_details'])->orderBy('name')->get();
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role?->id)],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['integer', Rule::exists('permissions', 'id')->where(fn ($query) => $query->whereIn('key', $this->managedPermissionKeys()))],
        ]);
    }

    private function syncPermissions(Role $role, array $permissionIds): void
    {
        $managedIds = $this->managedPermissionIds();
        $submittedManagedIds = collect($permissionIds)
            ->map(fn ($id) => (int) $id)
            ->intersect($managedIds);
        $hiddenIds = $role->permissions()
            ->whereNotIn('permissions.id', $managedIds)
            ->pluck('permissions.id');

        $role->permissions()->sync($hiddenIds->merge($submittedManagedIds)->unique()->values()->all());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function managedPermissionIds(): array
    {
        return AccessPermission::query()
            ->whereIn('key', $this->managedPermissionKeys())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function managedPermissionKeys(): array
    {
        return collect(PageAccessCatalog::pages())
            ->pluck('permission')
            ->merge(['commissions.manage_rates', 'commissions.manage_campaigns', 'commissions.manage_periods', 'commissions.manage_targets', 'commissions.recalculate', 'commissions.view_seller_details'])
            ->values()
            ->all();
    }

    private function audit(string $action, Role $role, array $before, array $after): void
    {
        ActivityLog::query()->create([
            'user_id' => auth()->id(), 'action' => $action, 'subject_type' => Role::class, 'subject_id' => $role->id,
            'description' => 'تغییر صفحات مجاز نقش', 'properties' => ['role_id' => $role->id, 'before' => array_values($before), 'after' => array_values($after)],
            'occurred_at' => now(),
        ]);
    }
}
