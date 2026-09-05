<?php

namespace App\DataTables;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

class ActivityLogDataTable
{
    /**
     * Base query for the activity log grid, with the page filters applied.
     */
    public function query(Request $request): Builder
    {
        $query = ActivityLog::query()->with('user:id,name');

        $action = trim((string) $request->input('action', ''));
        if ($action !== '') {
            $query->where('action', $action);
        }

        $term = trim((string) $request->input('q', ''));
        if ($term !== '') {
            $query->where(function (Builder $inner) use ($term): void {
                $inner->where('description', 'like', '%' . $term . '%')
                    ->orWhere('subject_type', 'like', '%' . $term . '%')
                    ->orWhere('subject_id', 'like', '%' . $term . '%');
            });
        }

        return $query;
    }

    /**
     * Server-side DataTables payload (draw / recordsTotal / recordsFiltered / data).
     */
    public function json(Request $request): JsonResponse
    {
        return DataTables::eloquent($this->query($request))
            ->addColumn('user', function (ActivityLog $log): string {
                return $log->user?->name ?? 'سیستم';
            })
            ->addColumn('action_badge', function (ActivityLog $log): string {
                return '<span class="badge bg-secondary">' . e($log->action) . '</span>';
            })
            ->addColumn('record', function (ActivityLog $log): string {
                $record = class_basename($log->subject_type);

                if ($log->subject_id) {
                    $record .= ' #' . $log->subject_id;
                }

                return $record;
            })
            ->editColumn('occurred_at', function (ActivityLog $log): string {
                return $log->occurred_at
                    ? \Morilog\Jalali\Jalalian::fromDateTime($log->occurred_at)->format('Y/m/d H:i:s')
                    : '—';
            })
            ->filterColumn('user', function (Builder $query, string $keyword): void {
                $query->whereHas('user', function (Builder $inner) use ($keyword): void {
                    $inner->where('name', 'like', '%' . $keyword . '%');
                });
            })
            ->rawColumns(['action_badge'])
            ->toJson();
    }

    /**
     * Distinct action values, used to build the action filter dropdown.
     *
     * @return array<int, string>
     */
    public function actions(): array
    {
        return ActivityLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->filter(fn ($action): bool => $action !== null && $action !== '')
            ->values()
            ->all();
    }

    /**
     * Render the HTML page for a normal (non-AJAX) request.
     */
    public function render(string $view, array $data = [])
    {
        return view($view, $data + ['actions' => $this->actions()]);
    }
}
