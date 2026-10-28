<?php

namespace App\Http\Controllers;

use App\Services\WarehouseStockFlowReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class WarehouseStockFlowController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable','string','max:80'],
            'only_in_flow' => ['nullable','boolean'],
            'print' => ['nullable','boolean'],
        ]);
        return view('stocktake.stock-flow', [
            'q'=>trim((string)($filters['q'] ?? '')),
            'onlyInFlow'=>(bool)($filters['only_in_flow'] ?? false),
            'printMode'=>(bool)($filters['print'] ?? false),
            'evaluatedAt'=>now()->format('Y/m/d H:i:s'),
        ]);
    }

    /** Paginated, on-demand provenance for the quantity in a queue or an official hold. */
    public function details(Request $request, int $variant, WarehouseStockFlowReportService $report): JsonResponse
    {
        $filters = $request->validate([
            'kind' => ['required', 'in:queue,official'],
            'after_id' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return response()->json($report->documentDetails(
            $variant,
            $filters['kind'],
            (int) ($filters['after_id'] ?? 0),
            (int) ($filters['limit'] ?? 50),
        ))->header('Cache-Control', 'private, no-store');
    }

    public function rows(Request $request, WarehouseStockFlowReportService $report): JsonResponse
    {
        $filters = $request->validate([
            'after_id' => ['nullable','integer','min:0'],
            'limit' => ['nullable','integer','min:1','max:100'],
            'q' => ['nullable','string','max:80'],
            'only_in_flow' => ['nullable','boolean'],
        ]);
        return response()->json($report->rows(
            (int)($filters['after_id'] ?? 0),
            (int)($filters['limit'] ?? WarehouseStockFlowReportService::BATCH_SIZE),
            trim((string)($filters['q'] ?? '')),
            (bool)($filters['only_in_flow'] ?? false),
        ))->header('Cache-Control','private, no-store');
    }
}
