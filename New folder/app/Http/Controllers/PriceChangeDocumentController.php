<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\PriceChangeDocument;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\PriceChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class PriceChangeDocumentController extends Controller
{
    public function __construct(private readonly PriceChangeService $service)
    {
    }

    public function index(): View
    {
        $documents = PriceChangeDocument::query()->with('createdBy:id,name')->latest()->paginate(15);
        $stats = [
            'total' => PriceChangeDocument::query()->count(),
            'draft' => PriceChangeDocument::query()->where('status', PriceChangeDocument::STATUS_DRAFT)->count(),
            'applied' => PriceChangeDocument::query()->where('status', PriceChangeDocument::STATUS_APPLIED)->count(),
            'today' => PriceChangeDocument::query()->whereDate('created_at', today())->count(),
        ];

        return view('products.price-changes.index', compact('documents', 'stats'));
    }

    public function create(): View
    {
        $rootCategories = Category::query()->whereNull('parent_id')->orderBy('name')->get(['id', 'name', 'code', 'parent_id']);
        $selectedProduct = old('product_id')
            ? Product::query()->find(old('product_id'), ['id', 'name', 'code', 'sku', 'category_id'])
            : null;

        return view('products.price-changes.create', compact('rootCategories', 'selectedProduct'));
    }

    public function preview(Request $request): JsonResponse
    {
        try {
            $payload = $this->validatedPayload($request, requireChange: true);
            $preview = $this->service->buildPreview($payload, 50);
            $summary = $this->service->previewSummary($payload);

            return response()->json([
                'items' => $preview->values(),
                'count' => $summary['variants_count'],
                'summary' => $summary,
                'has_errors' => $summary['errors_count'] > 0,
            ]);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['scope' => $exception->getMessage()]);
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validatedPayload($request, requireChange: true);
        $preview = $this->service->buildPreview($payload);

        if ($preview->isEmpty()) {
            return back()->withInput()->withErrors(['scope' => 'هیچ تنوعی برای این محدوده پیدا نشد.']);
        }
        if ($preview->contains(fn ($item) => filled($item['error']))) {
            return back()->withInput()->withErrors(['change_value' => 'برخی آیتم‌ها خطای قیمت دارند و سند ذخیره نشد.']);
        }

        $document = $this->service->storeDraft($payload, $preview);

        return redirect()->route('products.price-changes.show', $document)->with('success', 'پیش‌نویس سند تغییر قیمت با موفقیت ثبت شد. قیمت کالاها هنوز تغییر نکرده است.');
    }

    public function show(PriceChangeDocument $document): View
    {
        $document->load(['items' => fn ($q) => $q->orderBy('id'), 'createdBy:id,name', 'appliedBy:id,name']);

        return view('products.price-changes.show', compact('document'));
    }

    public function apply(PriceChangeDocument $document): RedirectResponse
    {
        try {
            $document = $this->service->applyDocument($document, auth()->user());
            return redirect()->route('products.price-changes.show', $document)->with('success', 'سند اعمال شد و فقط قیمت فروش آینده تنوع‌ها تغییر کرد.');
        } catch (RuntimeException $exception) {
            return back()->withErrors(['apply' => $exception->getMessage()]);
        }
    }

    public function cancel(PriceChangeDocument $document): RedirectResponse
    {
        try {
            $document = $this->service->cancelDocument($document, auth()->user());
            return redirect()->route('products.price-changes.show', $document)->with('success', 'سند پیش‌نویس لغو شد و هیچ قیمتی تغییر نکرد.');
        } catch (RuntimeException $exception) {
            return back()->withErrors(['cancel' => $exception->getMessage()]);
        }
    }

    public function rootCategories(): JsonResponse
    {
        return response()->json(Category::query()->whereNull('parent_id')->orderBy('name')->get(['id', 'name', 'code']));
    }

    public function categoryChildren(Category $category): JsonResponse
    {
        $children = Category::query()->with('parent:id,name,parent_id')->whereIn('id', array_diff(Category::selfAndDescendantIds($category->id), [$category->id]))->orderBy('name')->get(['id', 'name', 'code', 'parent_id']);
        return response()->json($children->map(fn (Category $child) => ['id' => $child->id, 'text' => $this->service->categoryPath($child), 'parent_id' => $child->parent_id]));
    }

    public function productSearch(Request $request): JsonResponse
    {
        $payload = $this->validatedPayload($request, requireChange: false, partial: true);
        try {
            $this->service->assertScopeIsConsistent($payload);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['scope' => $exception->getMessage()]);
        }
        $term = Product::normalizeProductSearchTerm((string) ($payload['q'] ?? ''));
        $perPage = (int) ($payload['per_page'] ?? 20);
        $categoryId = (int) ($payload['subcategory_id'] ?? $payload['category_id'] ?? 0);
        $categoryIds = Category::selfAndDescendantIds($categoryId);
        $products = Product::query()
            ->with('category:id,name,parent_id')
            ->whereIn('products.category_id', $categoryIds)
            ->when($payload['include_active_products_only'] ?? true, fn ($q) => $q->where('is_sellable', true))
            ->when($payload['include_active_variants_only'] ?? true, fn ($q) => $q->whereHas('variants', fn ($variant) => $variant->where('is_active', true)))
            ->when($payload['in_stock_only'] ?? false, fn ($q) => $q->whereHas('variants', fn ($variant) => $variant->where('is_active', true)->where('stock', '>', 0)))
            ->when($term !== '', fn ($q) => $q->search($term))
            ->when($term === '', fn ($q) => $q->orderBy('products.name')->orderBy('products.id'))
            ->withCount([
                'variants as active_variants_count' => fn ($query) => $query->where('is_active', true),
                'variants as available_variants_count' => fn ($query) => $query->where('is_active', true)->where('stock', '>', 0),
            ])
            ->paginate($perPage, ['products.id', 'products.name', 'products.sku', 'products.code', 'products.category_id']);

        return response()->json([
            'results' => $products->getCollection()->map(fn (Product $product) => [
                'id' => $product->id,
                'text' => $product->name,
                'name' => $product->name,
                'code' => $product->code,
                'sku' => $product->sku,
                'category_path' => $product->category ? $this->service->categoryPath($product->category) : null,
                'active_variants_count' => (int) $product->active_variants_count,
                'available_variants_count' => (int) $product->available_variants_count,
            ])->values(),
            'pagination' => ['more' => $products->hasMorePages()],
            'meta' => ['current_page' => $products->currentPage(), 'total' => $products->total(), 'per_page' => $products->perPage()],
        ]);
    }

    public function productVariants(Product $product, Request $request): JsonResponse
    {
        $query = $product->variants()->orderBy('id');
        if ($request->boolean('include_active_variants_only', true)) $query->where('is_active', true);
        if ($request->boolean('in_stock_only')) $query->where('stock', '>', 0);
        return response()->json($query->get(['id', 'product_id', 'variant_name', 'variety_name', 'variant_code', 'sell_price', 'stock', 'is_active'])->map(fn (ProductVariant $variant) => [
            'id' => $variant->id, 'name' => $variant->variant_name ?: $variant->variety_name ?: 'تنوع اصلی', 'sku' => $variant->variant_code ?: $variant->sku,
            'sell_price' => (int) $variant->sell_price, 'stock' => (int) $variant->stock, 'is_active' => (bool) $variant->is_active,
        ]));
    }

    public function scopeSummary(Request $request): JsonResponse
    {
        try {
            $payload = $this->validatedPayload($request, requireChange: false);
            return response()->json($this->service->scopeCounts($payload));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['scope' => $exception->getMessage()]);
        }
    }

    private function validatedPayload(Request $request, bool $requireChange = true, bool $partial = false): array
    {
        $changeTypes = array_keys(PriceChangeDocument::changeTypeLabels());
        $roundingModes = array_keys(PriceChangeDocument::roundingLabels());
        $rules = [
            'title' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
            'category_id' => [$partial ? 'nullable' : 'required', 'integer', 'exists:categories,id'],
            'subcategory_id' => ['nullable', 'integer', 'exists:categories,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'variant_ids' => ['nullable', 'array'],
            'variant_ids.*' => ['integer', 'distinct', 'exists:product_variants,id'],
            'include_active_products_only' => ['sometimes', 'boolean'],
            'include_active_variants_only' => ['sometimes', 'boolean'],
            'in_stock_only' => ['sometimes', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:50'],
        ];
        if ($requireChange) {
            $rules += [
                'change_type' => ['required', Rule::in($changeTypes)],
                'change_value' => ['required', 'numeric', 'gt:0'],
                'rounding_mode' => ['required', Rule::in($roundingModes)],
            ];
        }
        $payload = $request->validate($rules);
        $payload['include_active_products_only'] = $request->boolean('include_active_products_only', true);
        $payload['include_active_variants_only'] = $request->boolean('include_active_variants_only', true);
        $payload['in_stock_only'] = $request->boolean('in_stock_only', false);
        $payload['variant_ids'] = array_values(array_unique(array_map('intval', $payload['variant_ids'] ?? [])));
        if (($payload['change_type'] ?? null) === PriceChangeDocument::CHANGE_DECREASE_PERCENT && (float) $payload['change_value'] >= 100) {
            throw ValidationException::withMessages(['change_value' => 'در کاهش درصدی مقدار باید کمتر از ۱۰۰ باشد.']);
        }
        return $payload;
    }
}
