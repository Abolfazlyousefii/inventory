<?php

namespace App\Http\Controllers;

use App\Services\ProductSelectedStockExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ProductSelectedStockExportController extends Controller
{
    public function start(Request $request, ProductSelectedStockExportService $export): JsonResponse
    {
        $ids = $this->selectedIds($request, $export);
        $total = $export->countRows($ids);

        if ($total > ProductSelectedStockExportService::MAX_ROWS) {
            throw ValidationException::withMessages([
                'product_ids' => 'تعداد تنوع‌های موجود بیش از سقف خروجی است. لطفاً کالاهای کمتری انتخاب کنید.',
            ]);
        }

        return response()->json([
            'total_rows' => $total,
            'batch_size' => ProductSelectedStockExportService::BATCH_SIZE,
            'max_rows' => ProductSelectedStockExportService::MAX_ROWS,
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
