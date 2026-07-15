<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessPermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Support\PermissionCatalog;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    private array $systemRoles = ['super_admin', 'admin', 'staff', 'editor', 'union_expert', 'user', 'employee'];

    public function index(): View
    {
        $roles = Role::with('permissions')->orderBy('name')->get();
        return view('admin.roles.index', compact('roles'));
    }

    public function create(): View
    {
        $this->syncCatalogPermissions();

        return view('admin.roles.form', ['role' => new Role(), 'permissions' => $this->permissions(), 'selectedPermissionIds' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        $this->syncPermissions($role, $data['permissions'] ?? []);
        return redirect()->route('admin.roles.index')->with('success', 'نقش با موفقیت ایجاد شد.');
    }

    public function edit(Role $role): View
    {
        $this->syncCatalogPermissions();

        return view('admin.roles.form', [
            'role' => $role,
            'permissions' => $this->permissions(),
            'selectedPermissionIds' => $role->permissions()->pluck('permissions.id')->all(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $this->validated($request, $role);
        if (! in_array($role->name, $this->systemRoles, true)) {
            $role->update(['name' => $data['name']]);
        }
        $this->syncPermissions($role, $data['permissions'] ?? []);
        return redirect()->route('admin.roles.index')->with('success', 'نقش با موفقیت ویرایش شد.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        abort_if(in_array($role->name, $this->systemRoles, true), 403, 'نقش‌های سیستمی قابل حذف نیستند.');
        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return redirect()->route('admin.roles.index')->with('success', 'نقش حذف شد.');
    }

    private function permissions()
    {
        return AccessPermission::query()->whereNotNull('key')->orderBy('group')->orderBy('name')->get()->groupBy('group');
    }

    private function syncCatalogPermissions(): void
    {
        PermissionCatalog::syncToDatabase();
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role?->id)],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);
    }

    private function syncPermissions(Role $role, array $permissionIds): void
    {
        DB::table('role_has_permissions')->where('role_id', $role->id)->delete();
        foreach ($permissionIds as $permissionId) {
            DB::table('role_has_permissions')->updateOrInsert(['role_id' => $role->id, 'permission_id' => $permissionId]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
