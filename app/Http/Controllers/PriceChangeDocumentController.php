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
        $categories = Category::query()->orderBy('name')->get(['id', 'name', 'code', 'parent_id']);
        $products = Product::query()->orderBy('name')->limit(100)->get(['id', 'name', 'sku', 'code']);
        $variants = ProductVariant::query()->with('product:id,name,sku')->active()->orderBy('id')->limit(150)->get();

        return view('products.price-changes.create', compact('categories', 'products', 'variants'));
    }

    public function preview(Request $request): JsonResponse
    {
        $payload = $this->validatedPayload($request);
        $preview = $this->service->buildPreview($payload);

        return response()->json([
            'items' => $preview->values(),
            'count' => $preview->count(),
            'has_errors' => $preview->contains(fn ($item) => filled($item['error'])),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validatedPayload($request);
        $preview = $this->service->buildPreview($payload);

        if ($preview->isEmpty()) {
            return back()->withInput()->withErrors(['scope_type' => 'هیچ تنوعی برای این محدوده پیدا نشد.']);
        }
        if ($preview->contains(fn ($item) => filled($item['error']))) {
            return back()->withInput()->withErrors(['change_value' => 'برخی آیتم‌ها خطای قیمت دارند و سند ذخیره نشد.']);
        }

        $document = $this->service->storeDraft($payload, $preview);

        return redirect()->route('products.price-changes.show', $document)->with('success', 'سند تغییر قیمت به صورت پیش‌نویس ذخیره شد. قیمت کالاها هنوز تغییر نکرده است.');
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

    public function productSearch(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        $products = Product::query()->when($term !== '', fn ($q) => $q->search($term))->orderBy('name')->limit(20)->get(['id', 'name', 'sku', 'code']);
        return response()->json($products);
    }

    public function variantSearch(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        $variants = ProductVariant::query()->with('product:id,name,sku')->active()
            ->when($term !== '', fn ($q) => $q->where(function ($w) use ($term) {
                $w->where('variant_name', 'like', "%{$term}%")->orWhere('variant_code', 'like', "%{$term}%")->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%"));
            }))
            ->limit(30)->get();

        return response()->json($variants->map(fn ($variant) => [
            'id' => $variant->id,
            'product_name' => $variant->product?->name,
            'variant_name' => $variant->variant_name ?: $variant->variety_name ?: 'تنوع اصلی',
            'sku' => $variant->variant_code ?: $variant->product?->sku,
            'sell_price' => (int) $variant->sell_price,
        ]));
    }

    public function categoriesTree(): JsonResponse
    {
        return response()->json(Category::query()->orderBy('name')->get(['id', 'name', 'code', 'parent_id']));
    }

    private function validatedPayload(Request $request): array
    {
        $scopeTypes = [PriceChangeDocument::SCOPE_CATEGORY, PriceChangeDocument::SCOPE_PRODUCT, PriceChangeDocument::SCOPE_VARIANT, PriceChangeDocument::SCOPE_MANUAL];
        $changeTypes = array_keys(PriceChangeDocument::changeTypeLabels());
        $roundingModes = array_keys(PriceChangeDocument::roundingLabels());

        return $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
            'scope_type' => ['required', Rule::in($scopeTypes)],
            'category_id' => ['required_if:scope_type,category', 'nullable', 'integer', 'exists:categories,id'],
            'product_id' => ['required_if:scope_type,product', 'nullable', 'integer', 'exists:products,id'],
            'variant_id' => ['required_if:scope_type,variant', 'nullable', 'integer', 'exists:product_variants,id'],
            'variant_ids' => ['required_if:scope_type,manual', 'nullable', 'array'],
            'variant_ids.*' => ['integer', 'exists:product_variants,id'],
            'change_type' => ['required', Rule::in($changeTypes)],
            'change_value' => ['required', 'numeric', 'min:0'],
            'rounding_mode' => ['required', Rule::in($roundingModes)],
        ]);
    }
}
