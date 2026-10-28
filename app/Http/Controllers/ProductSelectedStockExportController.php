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

        if ($request->has('filters')) {
            $data = $request->validate([
                'filters' => ['array:q,category_id,subcategory_id,stock_status,sellable_status,product_type,min_price,max_price,has_variants,without_price,sort,direction'],
                'filters.q' => ['nullable', 'string', 'max:200'],
                'filters.category_id' => ['nullable', 'integer', 'min:1'],
                'filters.subcategory_id' => ['nullable', 'integer', 'min:1'],
                'filters.stock_status' => ['nullable', 'in:in_stock,out_of_stock,reserved'],
                'filters.sellable_status' => ['nullable', 'in:sellable,unsellable'],
                'filters.product_type' => ['nullable', 'in:bundle,regular'],
                'filters.min_price' => ['nullable', 'integer', 'min:0'],
                'filters.max_price' => ['nullable', 'integer', 'min:0'],
                'filters.has_variants' => ['nullable', 'in:1'],
                'filters.without_price' => ['nullable', 'in:1'],
                'filters.sort' => ['nullable', 'in:id,name,stock,price,updated_at'],
                'filters.direction' => ['nullable', 'in:asc,desc'],
            ]);
            $filters = Request::create('/', 'GET', $data['filters']);
            $ids = app(\App\Services\ProductSearchService::class)->productQuery($filters)
                ->whereIn('products.id', $ids)->get()->modelKeys();
            if ($ids === []) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'product_ids' => 'هیچ‌کدام از کالاهای انتخاب‌شده با فیلترهای فعلی مطابقت ندارند. انتخاب و فیلترها را بررسی کنید.',
                ]);
            }
        }

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
