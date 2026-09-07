<?php

namespace App\Http\Controllers;

use App\DataTables\ActivityLogDataTable;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request, ActivityLogDataTable $dataTable)
    {

        if ($request->expectsJson()) {
            return $dataTable->ajax();
        }


        return view('activity-logs.index', [
            'actions' => ActivityLog::query()
                ->select('action')
                ->distinct()
                ->pluck('action')->all(),
        ]);
    }
}