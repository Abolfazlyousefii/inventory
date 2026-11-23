<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Personnel\OrganizationManagement;
use App\Support\PageAccessCatalog;
use Illuminate\Http\Request;

class PersonnelOrganizationController extends Controller
{
    public function update(Request $request, User $user, OrganizationManagement $organization)
    {
        abort_unless(PageAccessCatalog::userCan($request->user(), 'page.roles'), 403);
        $data = $request->validate([
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
            'department' => ['nullable', 'string', 'max:100'],
            'position' => ['nullable', 'string', 'max:100'],
            'personnel_code' => ['nullable', 'string', 'max:100'],
            'branch' => ['nullable', 'string', 'max:100'],
            'organization_level' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(\App\Services\Personnel\OrganizationHierarchy::LEVELS))],
            'organization_version' => ['required', 'string', 'size:64'],
            'show_in_organization_chart' => ['sometimes', 'required', 'boolean'],
        ]);
        $organization->update($user, $request->user(), $data);
        if ($request->expectsJson()) return response()->json(['ok'=>true,'message'=>'مشخصات سازمانی ذخیره شد.']);
        return to_route('admin.permissions.index', ['user_id' => $user->id])->with('success', 'مشخصات سازمانی ذخیره شد؛ مدیر مستقیم این فرد در INV مدیریت می‌شود.');
    }
}
