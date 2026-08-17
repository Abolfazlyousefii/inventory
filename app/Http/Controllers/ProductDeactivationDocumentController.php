<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductDeactivationDocument;
use App\Services\ProductSalesStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductDeactivationDocumentController extends Controller
{
    public function index(Request $request): View
    {
        $documents = ProductDeactivationDocument::query()
            ->with(['creator:id,name', 'product:id,name,code,sku'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = trim((string) $request->q);
                $query->where(function ($nested) use ($term): void {
                    $nested->where('product_name_snapshot', 'like', "%{$term}%")
                        ->orWhereHas('product', fn ($product) => $product->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%"))
                        ->orWhereHas('items', fn ($item) => $item->where('product_name_snapshot', 'like', "%{$term}%"));
                });
            })
            ->when(in_array($request->action_type, ['activate', 'deactivate'], true), fn ($query) => $query->where('action_type', $request->action_type))
            ->when($request->filled('created_by'), fn ($query) => $query->where('created_by', $request->integer('created_by')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->date_to))
            ->latest('id')->paginate(20)->withQueryString();

        return view('product-deactivation-documents.index', compact('documents'));
    }

    public function create(Request $request): View
    {
        $selectedProduct = $request->integer('product_id')
            ? Product::query()->with('category:id,name')->withCount(['variants as structural_variants_count' => fn ($q) => $q->where('is_active', true), 'variants as sellable_variants_count' => fn ($q) => $q->where('is_active', true)->where('sales_enabled', true)])->find($request->integer('product_id'))
            : null;

        return view('product-deactivation-documents.create', compact('selectedProduct'));
    }

    public function store(Request $request, ProductSalesStatusService $service): RedirectResponse
    {
        $action = (string) $request->input('action_type');
        $reasonKeys = array_keys($action === 'activate' ? ProductDeactivationDocument::activationReasonLabels() : ProductDeactivationDocument::reasonLabels());
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'action_type' => ['required', 'in:activate,deactivate'],
            'scope_type' => ['required', 'in:product,variants'],
            'variant_ids' => ['nullable', 'array'],
            'variant_ids.*' => ['integer', 'exists:product_variants,id'],
            'reason_type' => ['required', 'in:'.implode(',', $reasonKeys)],
            'reason_text' => ['nullable', 'string', 'max:2000', 'required_if:reason_type,custom'],
            'return_to_edit' => ['nullable', 'boolean'],
        ], ['reason_type.required' => 'انتخاب دلیل تغییر وضعیت الزامی است.', 'reason_text.required_if' => 'برای دلیل سفارشی، توضیح تکمیلی الزامی است.']);

        $document = $service->change((int) $data['product_id'], $data['action_type'], $data['scope_type'], $data['variant_ids'] ?? [], $data['reason_type'], isset($data['reason_text']) ? trim($data['reason_text']) : null, $request->user());
        $redirect = $request->boolean('return_to_edit') ? redirect()->route('products.edit', $data['product_id']) : redirect()->route('product-deactivation-documents.show', $document);

        return $redirect->with('success', 'تغییر وضعیت فروش ثبت شد.');
    }

    public function show(ProductDeactivationDocument $productDeactivationDocument): View
    {
        $productDeactivationDocument->load(['creator:id,name', 'items.product:id,name,is_sellable', 'items.variant:id,product_id,variant_name,variant_code,is_active,sales_enabled']);
        $productIds = $productDeactivationDocument->items->pluck('product_id')->filter()->push($productDeactivationDocument->product_id)->unique();
        $history = ProductDeactivationDocument::query()->with('creator:id,name')
            ->where(fn ($query) => $query->whereIn('product_id', $productIds)->orWhereHas('items', fn ($item) => $item->whereIn('product_id', $productIds)))
            ->oldest('id')->get();

        return view('product-deactivation-documents.show', ['document' => $productDeactivationDocument, 'history' => $history]);
    }

    public function searchProducts(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $term = trim($data['q']);
        $products = Product::query()->with('category:id,name')
            ->withCount(['variants as structural_variants_count' => fn ($q) => $q->where('is_active', true), 'variants as sellable_variants_count' => fn ($q) => $q->where('is_active', true)->where('sales_enabled', true)])
            ->where(function ($query) use ($term): void {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', "%{$term}%")
                    ->orWhere('short_barcode', 'like', "%{$term}%")
                    ->orWhere('barcode', 'like', "%{$term}%")
                    ->orWhereHas('variants', fn ($variant) => $variant->where('variant_code', 'like', "%{$term}%"));
            })->orderBy('name')->paginate(15);

        return response()->json(['data' => $products->getCollection()->map(fn (Product $product) => $this->productResource($product)), 'next_page_url' => $products->nextPageUrl()]);
    }

    public function variants(Product $product): JsonResponse
    {
        $product->load('category')->loadCount(['variants as structural_variants_count' => fn ($q) => $q->where('is_active', true), 'variants as sellable_variants_count' => fn ($q) => $q->where('is_active', true)->where('sales_enabled', true)]);

        return response()->json(['product' => $this->productResource($product), 'variants' => $product->variants()->orderBy('variant_name')->get(['id', 'variant_name', 'variant_code', 'is_active', 'sales_enabled'])]);
    }

    private function productResource(Product $product): array
    {
        return ['id' => $product->id, 'name' => $product->name, 'code' => $product->code ?: $product->sku, 'category' => $product->category?->name, 'is_sellable' => (bool) $product->is_sellable, 'structural_variants_count' => (int) $product->structural_variants_count, 'sellable_variants_count' => (int) $product->sellable_variants_count];
    }
}
