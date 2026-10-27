<?php

namespace App\Http\Controllers;

use App\Services\ProductSelectedStockExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductSelectedStockExportController extends Controller
{
    /**
     * A browser-native print preview. Data rows are still fetched in bounded
     * batches; no mPDF rendering or server-side PDF memory cost.
     */
    public function printPreview(Request $request, ProductSelectedStockExportService $export)
    {
        // Only verify the selected products here; the lightweight HTML shell
        // does not count or materialize any variants. The browser uses the
        // existing read-only cursor endpoint in bounded batches.
        $ids = $this->selectedIds($request, $export);

        return response()->view('products.stock-print', [
            'productIds' => $ids,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function start(Request $request, ProductSelectedStockExportService $export): JsonResponse
    {
        $ids = $this->selectedIds($request, $export);
        $total = $export->countRows($ids);

        return response()->json([
            'total_rows' => $total,
            'batch_size' => ProductSelectedStockExportService::BATCH_SIZE,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function batch(Request $request, ProductSelectedStockExportService $export): JsonResponse
    {
        $ids = $this->selectedIds($request, $export);
        $validated = $request->validate([
            'after_id' => ['required', 'integer', 'min:0'],
        ]);

        return response()->json($export->batch($ids, (int) $validated['after_id']))
            ->header('Cache-Control', 'private, no-store');
    }

    /** @return array<int, int> */
    private function selectedIds(Request $request, ProductSelectedStockExportService $export): array
    {
        abort_unless($request->user()?->hasPermission('products.export'), 403);

        $validated = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:200'],
            'product_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $ids = array_map('intval', $validated['product_ids']);
        $export->validateProducts($ids);

        return $ids;
    }
}
