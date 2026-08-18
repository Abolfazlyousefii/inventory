<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ProductDeactivationDocument;
use App\Services\ProductSalesStatusBulkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductSalesStatusBulkController extends Controller
{
    public function create(): View
    {
        $categories = Category::query()->whereNull('parent_id')->orderBy('name')->get(['id', 'name']);

        return view('product-deactivation-documents.bulk', compact('categories'));
    }

    public function children(Category $category): JsonResponse
    {
        return response()->json(['data' => $category->children()->get(['id', 'name'])]);
    }

    public function preview(Request $request, ProductSalesStatusBulkService $service): JsonResponse
    {
        $data = $this->validatedScope($request);
        $ids = $service->resolveProductIds($data['scope_type'], $data['category_id'] ?? null, $data['subcategory_id'] ?? null, $data['product_ids'] ?? []);

        return response()->json($service->preview($ids, $data['action_type'], $data['scope_type']));
    }

    public function store(Request $request, ProductSalesStatusBulkService $service): RedirectResponse
    {
        $data = $this->validatedScope($request) + $request->validate([
            'reason_type' => ['required', 'string'],
            'reason_text' => ['nullable', 'string', 'max:2000', 'required_if:reason_type,custom'],
            'preview_token' => ['required', 'string', 'size:64'],
            'confirmed' => ['accepted'],
        ]);
        $allowedReasons = array_keys($data['action_type'] === 'activate' ? ProductDeactivationDocument::activationReasonLabels() : ProductDeactivationDocument::reasonLabels());
        if (! in_array($data['reason_type'], $allowedReasons, true)) {
            abort(422, 'دلیل تغییر وضعیت معتبر نیست.');
        }
        $ids = $service->resolveProductIds($data['scope_type'], $data['category_id'] ?? null, $data['subcategory_id'] ?? null, $data['product_ids'] ?? []);
        $document = $service->execute($ids, $data['action_type'], $data['scope_type'], $data['reason_type'], $data['reason_text'] ?? null, $request->user(), $data['preview_token']);

        return redirect()->route('product-deactivation-documents.show', $document)->with('success', 'عملیات گروهی وضعیت فروش با موفقیت ثبت شد.');
    }

    private function validatedScope(Request $request): array
    {
        return $request->validate([
            'scope_type' => ['required', 'in:category,subcategory,multiple_products'],
            'action_type' => ['required', 'in:activate,deactivate'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id', 'required_unless:scope_type,multiple_products'],
            'subcategory_id' => ['nullable', 'integer', 'exists:categories,id', 'required_if:scope_type,subcategory'],
            'product_ids' => ['nullable', 'array', 'min:1', 'max:'.ProductSalesStatusBulkService::MAX_PRODUCTS, 'required_if:scope_type,multiple_products'],
            'product_ids.*' => ['integer', 'exists:products,id'],
        ]);
    }
}
