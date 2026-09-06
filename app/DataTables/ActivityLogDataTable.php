<?php

namespace App\DataTables;

use App\Models\ActivityLog;
use Yajra\DataTables\Facades\DataTables;

class ActivityLogDataTable
{
    public function html()
    {
        return null;
    }


    public function ajax()
    {
        $query = ActivityLog::query()
            ->select([
                'activity_logs.id',
                'activity_logs.user_id',
                'activity_logs.action',
                'activity_logs.subject_type',
                'activity_logs.subject_id',
                'activity_logs.description',
                'activity_logs.occurred_at',
            ])
            ->with('user:id,name');


        if (request()->filled('action')) {

            $query->where(
                'activity_logs.action',
                request('action')
            );

        }


        if (request()->filled('q')) {

            $search = request('q');


            $query->where(function($q) use ($search){

                $q->where(
                    'activity_logs.description',
                    'like',
                    "%{$search}%"
                )

                    ->orWhere(
                        'activity_logs.action',
                        'like',
                        "%{$search}%"
                    )

                    ->orWhere(
                        'activity_logs.subject_type',
                        'like',
                        "%{$search}%"
                    )

                    ->orWhere(
                        'activity_logs.subject_id',
                        'like',
                        "%{$search}%"
                    );

            });

        }


        return DataTables::eloquent($query)

            ->addColumn('user', function (ActivityLog $log) {

                return $log->user?->name ?? 'سیستم';

            })
                ->addColumn('action_badge', function (ActivityLog $log) {

                    $colors = [

                        'created' => 'success',
                        'updated' => 'primary',
                        'deleted' => 'danger',

                        'invoice_shipped' => 'info',
                        'invoice_cancelled' => 'danger',

                        'preinvoice_draft_saved' => 'secondary',
                        'preinvoice_draft_updated' => 'warning',
                        'preinvoice_submitted' => 'success',

                        'return_excluded_warranty' => 'dark',

                        'permissions.updated' => 'primary',
                        'role.updated' => 'warning',

                        'commission_rate.set' => 'info',
                        'commission_period.created' => 'success',
                        'commission_period.recalculated' => 'primary',

                        'seller_commission_reassigned' => 'warning',
                        'seller_commission_document.created' => 'success',

                        'finance_edited' => 'primary',
                        'finance_returned_preinvoice' => 'danger',

                        'invoice_payment_added' => 'success',

                        'reservation_expired' => 'dark',

                        'sales_return.applied_voided' => 'danger',

                        'crm_user_created' => 'success',

                        'electric_default_color_created' => 'success',

                        'return_commission_reversal_updated' => 'warning',

                        'commission_rate.revision_backdated' => 'dark',

                    ];


                    $class = $colors[$log->action] ?? 'secondary';


                    return '<span class="badge bg-'.$class.'">'
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