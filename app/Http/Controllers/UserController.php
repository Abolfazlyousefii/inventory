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

        return view('users.index');
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