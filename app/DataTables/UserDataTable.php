<?php

namespace App\DataTables;

use App\Models\User;
use Yajra\DataTables\Facades\DataTables;

class UserDataTable
{
    public function ajax()
    {
        $query = User::query()
            ->with([
                'roles',
                'manager:id,name',
            ]);


        if (request()->filled('status')) {
            $query->where(
                'is_active',
                request('status') === 'active'
            );
        }


        if (request()->filled('role')) {
            $query->role(request('role'));
        }


        if (request()->filled('filter_search')) {

            $search = request('filter_search');

            $query->where(function ($query) use ($search) {
                $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }


        return DataTables::eloquent($query)

            ->addColumn('crm_id', function (User $user) {
                return $user->crm_user_id
                       ?? $user->external_crm_id
                          ?? '-';
            })

            ->addColumn('manager_name', function (User $user) {
                return $user->manager?->name ?? '-';
            })

            ->addColumn('roles_list', function (User $user) {
                return $user->roles
                    ->pluck('name')
                    ->implode('، ') ?: '-';
            })

            ->addColumn('source_badge', function (User $user) {

                if ($user->sync_source === 'crm') {
                    return '<span class="badge bg-info-subtle text-info">
                                CRM
                            </span>';
                }

                return '<span class="badge bg-secondary-subtle text-secondary">
                            داخلی
                        </span>';
            })

            ->addColumn('status_badge', function (User $user) {

                if ($user->is_active) {
                    return '<span class="badge bg-success">
                                فعال
                            </span>';
                }

                return '<span class="badge bg-danger">
                            غیرفعال
                        </span>';
            })

            ->editColumn('synced_at', function (User $user) {
                return $user->synced_at
                    ? $user->synced_at->format('Y-m-d H:i')
                    : '-';
            })

            ->rawColumns([
                'source_badge',
                'status_badge',
            ])

            ->toJson();
    }
}