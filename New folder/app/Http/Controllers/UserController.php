<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CrmUserService;

class UserController extends Controller
{
    public function index()
    {
        $users = User::query()
            ->with(['roles', 'manager'])
            ->when(request('role'), function ($query, $role) {
                $query->role($role);
            })
            ->when(request()->filled('status'), function ($query) {
                $query->where('is_active', request('status') === 'active');
            })
            ->when(request('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        return view('users.index', compact('users'));
    }

    public function sync(CrmUserService $crmUserService)
    {
        $result = $crmUserService->syncUsers(full: false);

        if (!empty($result['error'])) {
            return redirect()->route('users.index')->with('sync_error', $result['error']);
        }

        return redirect()->route('users.index')->with(
            'sync_success',
            sprintf(
                'سینک کاربران با موفقیت انجام شد. تعداد کاربران sync شده: %d | غیرفعال‌شده: %d',
                $result['synced_count'],
                $result['deactivated_count'] ?? 0
            )
        );
    }
}
