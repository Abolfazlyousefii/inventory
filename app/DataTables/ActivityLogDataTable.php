<?php

namespace App\DataTables;

use App\Models\ActivityLog;
use Yajra\DataTables\Facades\DataTables;

class ActivityLogDataTable
{
    public function render()
    {
        return DataTables::eloquent(
            ActivityLog::query()
                ->with('user:id,name')
        )
            ->addColumn('user', function (ActivityLog $log) {

                return $log->user?->name ?? 'سیستم';

            })

            ->addColumn('action_badge', function (ActivityLog $log) {

                return '<span class="badge bg-secondary">'
                       . e($log->action)
                       . '</span>';

            })

            ->addColumn('record', function (ActivityLog $log) {

                $record = class_basename($log->subject_type);

                if ($log->subject_id) {
                    $record .= ' #' . $log->subject_id;
                }

                return $record;

            })

            ->editColumn('occurred_at', function (ActivityLog $log) {

                return $log->occurred_at
                    ? \Morilog\Jalali\Jalalian::fromDateTime($log->occurred_at)
                        ->format('Y/m/d H:i:s')
                    : '—';

            })

            ->rawColumns([
                'action_badge'
            ])

            ->toJson();
    }
}