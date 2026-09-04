<?php

namespace App\Http\Controllers;

use App\DataTables\ActivityLogDataTable;
use App\Models\ActivityLog;

class ActivityLogController extends Controller
{
    public function index(ActivityLogDataTable $dataTable)
    {
        return $dataTable->render('activity-logs.index');
    }}
