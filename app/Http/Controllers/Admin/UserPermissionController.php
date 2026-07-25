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
        PermissionCatalog::syncToDatabase();
        $users = User::with('roles')->orderBy('name')->get();
        $selectedUser = User::with(['permissions', 'roles'])->find($request->integer('user_id') ?: $users->first()?->id);
        $effective = $selectedUser ? $this->service->effective($selectedUser) : [];
        $modules = collect($effective)->reject('deprecated')->groupBy('module');
        $roles = Role::withCount('permissions')->orderBy('name')->get();
        $roleLabels = PermissionCatalog::roleLabels();
        $roleAliases = PermissionCatalog::roleAliases();

        return view('admin.permissions.index', compact('users', 'selectedUser', 'effective', 'modules', 'roles', 'roleLabels', 'roleAliases'));
    }

    public function update(UpdateUserPermissionsRequest $request, User $user): RedirectResponse
    {
        abort_unless($request->integer('user_id') === $user->id, 422, 'شناسه کاربر ناهماهنگ است.');
        $data = $request->validated();
        $actor = $request->user();
        $this->service->update(
            $user,
            $data['direct_permissions'] ?? [],
            $data['roles'] ?? $user->roles()->pluck('name')->all(),
            $actor,
            $actor->can('permissions.edit'),
            $actor->can('permissions.assign_roles'),
        );

        return to_route('admin.permissions.index', ['user_id' => $user->id])->with('success', 'نقش‌ها و دسترسی‌ها با موفقیت ذخیره شد.');
    }
}
