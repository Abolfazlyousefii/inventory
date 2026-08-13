<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductExportFilterRequest;
use App\Models\Category;
use App\Models\ModelList;
use App\Models\Product;
use App\Services\ProductExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProductExportController extends Controller
{
    public function __construct(private readonly ProductExportService $service) {}

    public function index(ProductExportFilterRequest $request): View
    {
        $filters = $request->filters();
        $rootCategories = Category::query()->whereNull('parent_id')->orderBy('name')->get(['id', 'name']);
        $subcategories = ! empty($filters['root_category_id'])
            ? Category::query()->where('parent_id', $filters['root_category_id'])->orderBy('name')->get(['id', 'name'])
            : collect();
        $modelBrands = ModelList::query()
            ->whereNotNull('brand')
            ->where('brand', '<>', '')
            ->select('brand')
            ->distinct()
            ->orderBy('brand')
            ->pluck('brand');
        $products = $this->service->paginate($filters, 24);
        $selectedProducts = $this->service->selectedProducts($filters['product_ids']);

        return view('product-exports.index', compact('rootCategories', 'subcategories', 'modelBrands', 'filters', 'products', 'selectedProducts'));
    }

    public function filter(ProductExportFilterRequest $request): string
    {
        $filters = $request->filters();
        $products = $this->service->paginate($filters, 24);

        return view('product-exports.partials.product-list', compact('products', 'filters'))->render();
    }

    public function print(ProductExportFilterRequest $request): View
    {
        $filters = $request->filters();
        $products = $this->service->allForPrint($filters);
        $meta = $this->service->meta($filters);
        $meta['products_count'] = $products->count();

        return view('product-exports.print', compact('products', 'meta', 'filters'));
    }

    public function download(ProductExportFilterRequest $request): RedirectResponse
    {
        return redirect()->route('admin.product-exports.print', $request->query());
    }

    public function export(ProductExportFilterRequest $request): RedirectResponse
    {
        return redirect()->route('admin.product-exports.print', $request->query());
    }

    public function modelLists(ProductExportFilterRequest $request): JsonResponse
    {
        $brand = trim((string) $request->query('brand', ''));

        if ($brand === '') {
            return response()->json(['items' => []]);
        }

        $items = ModelList::query()
            ->where('brand', $brand)
            ->whereHas('productVariants', fn ($query) => $this->service->applyCatalogVariantConstraints($query))
            ->withCount(['productVariants as products_count' => fn ($query) => $this->service->applyCatalogVariantConstraints($query)->select(\Illuminate\Support\Facades\DB::raw('count(distinct product_id)'))])
            ->get(['id', 'model_name', 'code'])
            ->sortBy(fn (ModelList $model) => str_replace('-', '', str_pad((string) $model->model_name, 20, '0', STR_PAD_LEFT)), SORT_NATURAL)
            ->map(fn (ModelList $model) => [
                'id' => $model->id,
                'name' => $model->model_name,
                'code' => $model->code,
                'products_count' => (int) $model->products_count,
            ])
            ->values();

        return response()->json(['items' => $items]);
    }

    public function searchProducts(ProductExportFilterRequest $request): JsonResponse
    {
        $filters = $request->filters();
        $selectedIds = $this->service->productIds($filters);
        $matchingIds = $this->service->matchingSelectedProductIds($filters, $selectedIds);
        $invalidSelectedIds = array_values(array_diff($selectedIds, $matchingIds));
        $term = (string) $request->query('q', '');
        $limit = $this->service->searchLimit($term);
        $items = $this->service->searchProducts($filters, $term, $limit)
            ->map(fn (Product $product) => $this->service->productSearchPayload($product, $term));

        return response()->json([
            'items' => $items->values(),
            'invalid_selected_ids' => $invalidSelectedIds,
            'limit' => $limit,
        ]);
    }

    public function children(Category $category): JsonResponse
    {
        return response()->json([
            'items' => $category->children()->get(['id', 'name'])->map(fn (Category $child) => [
                'id' => $child->id,
                'name' => $child->name,
            ])->values(),
        ]);
    }
}
