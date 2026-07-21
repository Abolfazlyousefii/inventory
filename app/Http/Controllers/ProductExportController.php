<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductExportFilterRequest;
use App\Models\Category;
use App\Models\ModelList;
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
        $modelLists = ModelList::query()->orderBy('model_name')->get(['id', 'model_name', 'code']);
        $products = $this->service->paginate($filters, 24);

        return view('product-exports.index', compact('rootCategories', 'subcategories', 'modelLists', 'filters', 'products'));
    }

    public function filter(ProductExportFilterRequest $request): string
    {
        $filters = $request->filters();
        $products = $this->service->paginate($filters, 24);

        return view('product-exports.partials.product-list', compact('products'))->render();
    }

    public function print(ProductExportFilterRequest $request): View
    {
        $filters = $request->filters();
        $products = $this->service->allForPrint($filters);
        $meta = $this->service->meta($filters);

        return view('product-exports.print', compact('products', 'filters', 'meta'));
    }

    public function export(ProductExportFilterRequest $request): RedirectResponse
    {
        return redirect()->route('admin.product-exports.print', $request->query());
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
