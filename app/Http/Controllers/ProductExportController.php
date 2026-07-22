<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductExportFilterRequest;
use App\Models\Category;
use App\Models\ModelList;
use App\Services\ProductExportService;
use App\Services\ProductPriceListPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\View\View;

class ProductExportController extends Controller
{
    public function __construct(private readonly ProductExportService $service, private readonly ProductPriceListPdfService $pdfService) {}

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

        return view('product-exports.index', compact('rootCategories', 'subcategories', 'modelBrands', 'filters', 'products'));
    }

    public function filter(ProductExportFilterRequest $request): string
    {
        $filters = $request->filters();
        $products = $this->service->paginate($filters, 24);

        return view('product-exports.partials.product-list', compact('products', 'filters'))->render();
    }

    public function print(ProductExportFilterRequest $request): RedirectResponse
    {
        return redirect()->route('admin.product-exports.download', $request->query());
    }

    public function download(ProductExportFilterRequest $request): Response
    {
        $filters = $request->filters();
        $products = $this->service->allForPrint($filters);
        $meta = $this->service->meta($filters);
        $meta['products_count'] = $products->count();
        $pdf = $this->pdfService->render($products->all(), $meta);
        $filename = 'aria-gostar-price-list-'.now()->format('Y-m-d-Hi').'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
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
