<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $action = trim((string) $request->query('action', ''));

        $logs = ActivityLog::query()
            ->with('user:id,name')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($qq) use ($q) {
                    $qq->where('description', 'like', "%{$q}%")
                        ->orWhere('subject_type', 'like', "%{$q}%")
                        ->orWhere('subject_id', 'like', "%{$q}%");
                });
            })
            ->when($action !== '', fn($query) => $query->where('action', $action))
            ->orderByDesc('occurred_at')
            ->paginate(30)
            ->withQueryString();

        $actions = ActivityLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        return view('activity-logs.index', compact('logs', 'q', 'action', 'actions'));
    }
}
