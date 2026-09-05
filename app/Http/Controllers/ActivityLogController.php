<?php

namespace App\Http\Controllers;

use App\DataTables\ActivityLogDataTable;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request, ActivityLogDataTable $dataTable)
    {
        if ($request->ajax() || $request->has('draw')) {
            return $dataTable->json($request);
        }

        return $dataTable->render('activity-logs.index');
    }
}
