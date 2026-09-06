<?php

namespace App\DataTables;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class UserDataTable extends DataTable
{
    /**
     * Build DataTable.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))

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
            ]);
    }

    /**
     * Main query.
     */
    public function query(User $model): QueryBuilder
    {
        $query = $model
            ->newQuery()
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

        return $query;
    }

    /**
     * DataTable HTML configuration.
     */
    public function html(): HtmlBuilder
    {
        $ajaxUrl = route('users.index', array_filter([
            'filter_search' => request('filter_search'),
            'role' => request('role'),
            'status' => request('status'),
        ], fn ($value) => $value !== null && $value !== ''));

        return $this->builder()
            ->setTableId('users-table')

            ->columns($this->getColumns())

            ->minifiedAjax($ajaxUrl)

            ->parameters([
                'processing' => true,
                'serverSide' => true,
                'searching' => false,
                'responsive' => true,
                'autoWidth' => false,
                'pageLength' => 10,

                'order' => [
                    [0, 'desc'],
                ],
            ]);
    }

    /**
     * All columns are defined here.
     */
    public function getColumns(): array
    {
        return [

            Column::make('id')
                ->title('شناسه داخلی'),

            Column::make('crm_id')
                ->name('crm_user_id')
                ->title('شناسه CRM'),

            Column::make('name')
                ->title('نام'),

            Column::make('phone')
                ->title('موبایل'),

            Column::make('email')
                ->title('ایمیل'),

            Column::make('username')
                ->title('نام کاربری'),

            Column::computed('manager_name')
                ->title('مدیر')
                ->orderable(false)
                ->searchable(false),

            Column::computed('roles_list')
                ->title('نقش‌ها')
                ->orderable(false)
                ->searchable(false),

            Column::computed('source_badge')
                ->title('منبع')
                ->orderable(false)
                ->searchable(false),

            Column::computed('status_badge')
                ->title('وضعیت')
                ->orderable(false)
                ->searchable(false),

            Column::make('synced_at')
                ->title('آخرین sync'),
        ];
    }
}