<?php

namespace App\Http\Controllers;

use App\DataTables\UserDataTable;
use App\Services\CrmUserService;
use Illuminate\Http\Request;
use App\Models\User;

class UserController extends Controller
{
    public function index(
        Request $request,
        UserDataTable $dataTable
    ) {
        if ($request->has('draw')) {
            return $dataTable->ajax();
        }

        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'show_inactive' => ['nullable', 'boolean'], 'status' => ['nullable', 'in:active,inactive'], 'manager' => ['nullable', 'integer', 'exists:users,id'], 'unassigned' => ['nullable', 'boolean'], 'directory_tab' => ['nullable', 'in:organization,accounts']]);
        $directoryTab = $filters['directory_tab'] ?? 'organization';
        $showInactive = $request->boolean('show_inactive') || ($filters['status'] ?? null) === 'inactive';
        $query = User::query()
            ->when(filled($filters['q'] ?? null), fn ($query) => $query->where(function ($query) use ($filters) {
                foreach (['name', 'phone', 'username', 'personnel_code', 'department'] as $field) $query->orWhere($field, 'like', '%'.$filters['q'].'%');
            }))
            ->when(!$showInactive || ($filters['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true)->where('can_access_erp', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($q) => $q->where(fn ($q) => $q->where('is_active', false)->orWhere('can_access_erp', false)))
            ->when($filters['manager'] ?? null, fn ($q, $id) => $q->where('manager_id', $id))
            ->when($request->boolean('unassigned'), fn ($q) => $q->whereNull('manager_id'))
            ->orderBy('name');
        $matches=(clone $query)->where('show_in_organization_chart', $directoryTab === 'organization')->pluck('id');
        $users=User::with(['manager:id,name','roles'])->withCount('directReports')->orderBy('name')->get();
        $accounts=$directoryTab === 'accounts' ? $users->whereIn('id',$matches) : collect();
        $rows=$directoryTab === 'organization' ? app(\App\Services\Personnel\PersonnelTree::class)->rows($users,$matches,$showInactive) : [];
        $children=collect($rows)->groupBy(fn($row)=>$row['parent'] ?? 'root');
        $managers=$users->where('show_in_organization_chart',true)->where('direct_reports_count','>',0)->filter(fn ($user) => $showInactive || ($user->is_active && $user->can_access_erp));
        $visible=$users->filter(fn($user)=>$showInactive || ($user->is_active && $user->can_access_erp));
        $chartCount=$visible->where('show_in_organization_chart',true)->count();
        $accountCount=$visible->where('show_in_organization_chart',false)->count();
        $canManage=\App\Support\PageAccessCatalog::userCan($request->user(),'page.roles');
        $filtered=$request->filled('q') || $request->filled('status') || $request->filled('manager') || $request->boolean('unassigned');
        return view($request->ajax() ? 'users.partials.tree' : 'users.directory',compact('users','managers','rows','canManage','filtered','directoryTab','accounts','children','chartCount','accountCount'));
    }


    public function sync(CrmUserService $crmUserService)
    {
        $result = $crmUserService->syncUsers(full: false);

        if (!empty($result['error'])) {
            return redirect()
                ->route('users.index')
                ->with('sync_error', $result['error']);
        }

        return redirect()
            ->route('users.index')
            ->with(
                'sync_success',
                sprintf(
                    'سینک کاربران با موفقیت انجام شد. تعداد کاربران sync شده: %d | غیرفعال‌شده: %d',
                    $result['synced_count'],
                    $result['deactivated_count'] ?? 0
                )
            );
    }

    public function updateStatus(Request $request, User $user)
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $user->update([
            'is_active' => $data['is_active'],
            'can_access_erp' => $data['is_active'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'وضعیت کاربر تغییر کرد.',
        ]);
    }
}
