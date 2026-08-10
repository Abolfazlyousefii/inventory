<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductDeactivationDocument;
use App\Models\Category;
use App\Models\ProductDeactivationDocumentItem;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class ProductDeactivationDocumentController extends Controller
{
    public function index(Request $request)
    {
        $query = ProductDeactivationDocument::query()
            ->with(['creator:id,name']);

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('time_range')) {
            $days = match ((string) $request->time_range) {
                'today' => 0,
                '7d' => 7,
                '30d' => 30,
                default => null,
            };

            if (!is_null($days)) {
                $from = $days === 0 ? now()->startOfDay() : now()->subDays($days)->startOfDay();
                $query->where('created_at', '>=', $from);
            }
        }

        $documents = $query->latest('id')->paginate(20)->withQueryString();
        return view('product-deactivation-documents.index', compact('documents'));
    }

    public function create()
    {
        $allCategories = Category::query()
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);

        $categoriesById = $allCategories->keyBy('id');

        $resolveCategoryPath = static function (?int $categoryId) use ($categoriesById): array {
            if (!$categoryId || !$categoriesById->has($categoryId)) {
                return ['root_id' => null, 'subcategory_id' => null];
            }

            $current = $categoriesById->get($categoryId);
            $trail = [];

            while ($current) {
                array_unshift($trail, $current);

                if (!$current->parent_id) {
                    break;
                }

                $current = $categoriesById->get((int) $current->parent_id);
            }

            $root = $trail[0] ?? null;
            $subcategory = $trail[1] ?? null;

            return [
                'root_id' => $root ? (int) $root->id : null,
                'subcategory_id' => $subcategory ? (int) $subcategory->id : null,
            ];
        };

        $products = Product::query()
            ->where(function ($query) {
                $query->where('is_sellable', true)
                    ->orWhereHas('variants', function ($v) {
                        $v->where('is_active', true)->where('sales_enabled', true);
                    });
            })
            ->with([
                'category:id,name,parent_id',
                'variants' => fn ($q) => $q->where('is_active', true)->where('sales_enabled', true)->orderBy('variant_name'),
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'is_sellable', 'category_id'])
            ->map(function (Product $product) use ($resolveCategoryPath) {
                $path = $resolveCategoryPath($product->category_id ? (int) $product->category_id : null);
                $product->setAttribute('root_category_id', $path['root_id']);
                $product->setAttribute('subcategory_id', $path['subcategory_id']);
                return $product;
            });

        $rootCategories = $allCategories
            ->whereNull('parent_id')
            ->values();

        $subcategories = $allCategories
            ->whereNotNull('parent_id')
            ->filter(fn (Category $category) => $categoriesById->get((int) $category->parent_id)?->parent_id === null)
            ->values();

        return view('product-deactivation-documents.create', compact('products', 'rootCategories', 'subcategories'));
    }

    public function store(Request $request)
    {
        if (!$request->has('items') && ($request->filled('product_id') || $request->filled('category_id'))) {
            $request->merge([
                'items' => [[
                    'deactivation_type' => $request->input('deactivation_type'),
                    'category_id' => $request->input('category_id'),
                    'subcategory_id' => $request->input('subcategory_id'),
                    'product_id' => $request->input('product_id'),
                    'variant_id' => $request->input('variant_id'),
                ]],
            ]);
        }

        $data = $request->validate([
            'reason_type' => ['nullable', 'string', 'in:' . implode(',', array_keys(ProductDeactivationDocument::reasonLabels()))],
            'reason_text' => ['required_if:reason_type,custom', 'nullable', 'string', 'min:3', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.deactivation_type' => ['nullable', 'string', 'in:variant,product,subcategory,category'],
            'items.*.category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'items.*.subcategory_id' => ['nullable', 'integer', 'exists:categories,id'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
        ], [
            'reason_text.required_if' => 'نوشتن دلیل سفارشی غیرفعال‌سازی الزامی است.',
            'items.required' => 'حداقل یک هدف برای غیرفعال‌سازی وارد کنید.',
        ]);

        DB::transaction(function () use ($data) {
            $resolvedItems = collect();
            $seenVariantIds = [];
            $categoryIdsToDisableProducts = [];
            $productIdsToDisableProducts = [];

            foreach ($data['items'] as $index => $itemData) {
                $type = (string) ($itemData['deactivation_type'] ?? '');
                if ($type === '') {
                    $type = !empty($itemData['variant_id']) ? ProductDeactivationDocument::TYPE_VARIANT : ProductDeactivationDocument::TYPE_PRODUCT;
                }

                $query = ProductVariant::query()
                    ->with(['product.category'])
                    ->where('is_active', true)
                    ->lockForUpdate();

                if ($type === ProductDeactivationDocument::TYPE_VARIANT) {
                    if (empty($itemData['variant_id'])) {
                        throw ValidationException::withMessages(["items.{$index}.variant_id" => 'انتخاب تنوع برای غیرفعال‌سازی تنوع الزامی است.']);
                    }
                    $query->whereKey((int) $itemData['variant_id']);
                    if (!empty($itemData['product_id'])) {
                        $query->where('product_id', (int) $itemData['product_id']);
                    }
                } elseif ($type === ProductDeactivationDocument::TYPE_PRODUCT) {
                    if (empty($itemData['product_id'])) {
                        throw ValidationException::withMessages(["items.{$index}.product_id" => 'انتخاب کالا برای غیرفعال‌سازی کالا الزامی است.']);
                    }
                    $query->where('product_id', (int) $itemData['product_id']);
                    $productIdsToDisableProducts[(int) $itemData['product_id']] = true;
                } elseif ($type === ProductDeactivationDocument::TYPE_SUBCATEGORY) {
                    if (empty($itemData['subcategory_id'])) {
                        throw ValidationException::withMessages(["items.{$index}.subcategory_id" => 'انتخاب زیر‌دسته الزامی است.']);
                    }
                    $subcategory = Category::query()->findOrFail((int) $itemData['subcategory_id']);
                    if (!empty($itemData['category_id']) && (int) $subcategory->parent_id !== (int) $itemData['category_id']) {
                        throw ValidationException::withMessages(["items.{$index}.subcategory_id" => 'زیر‌دسته انتخاب‌شده با دسته‌بندی اصلی مطابقت ندارد.']);
                    }
                    $categoryIdsToDisableProducts[] = (int) $subcategory->id;
                    $query->whereHas('product', fn ($q) => $q->where('category_id', (int) $subcategory->id));
                } elseif ($type === ProductDeactivationDocument::TYPE_CATEGORY) {
                    if (empty($itemData['category_id'])) {
                        throw ValidationException::withMessages(["items.{$index}.category_id" => 'انتخاب دسته‌بندی اصلی الزامی است.']);
                    }
                    $categoryId = (int) $itemData['category_id'];
                    $childIds = Category::query()->where('parent_id', $categoryId)->pluck('id')->map(fn ($id) => (int) $id)->all();
                    $categoryScopeIds = array_values(array_unique(array_merge([$categoryId], $childIds)));
                    $categoryIdsToDisableProducts = array_merge($categoryIdsToDisableProducts, $categoryScopeIds);
                    $query->whereHas('product', fn ($q) => $q->whereIn('category_id', $categoryScopeIds));
                }

                foreach ($query->orderBy('id')->get() as $variant) {
                    if (isset($seenVariantIds[(int) $variant->id])) {
                        continue;
                    }
                    $seenVariantIds[(int) $variant->id] = true;
                    $resolvedItems->push(['product' => $variant->product, 'variant' => $variant, 'deactivation_type' => $type]);
                }
            }

            if ($resolvedItems->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'حداقل یک تنوع معتبر برای غیرفعال‌سازی لازم است.']);
            }

            $first = $resolvedItems->first();
            $doc = ProductDeactivationDocument::create([
                'document_number' => 'TMP-' . now()->format('YmdHis') . '-' . random_int(100, 999),
                'deactivation_type' => $first['deactivation_type'],
                'product_id' => $first['product']->id,
                'variant_id' => $first['variant']->id,
                'items_count' => 0,
                'reason_type' => (string) ($data['reason_type'] ?? 'custom'),
                'reason_text' => trim((string) ($data['reason_text'] ?? 'غیرفعال‌سازی فروش')),
                'description' => null,
                'product_name_snapshot' => (string) $first['product']->name,
                'variant_name_snapshot' => $first['variant']->variant_name,
                'created_by' => (int) auth()->id(),
            ]);
            $doc->update(['document_number' => 'PD-' . now()->format('Ymd') . '-' . str_pad((string) $doc->id, 6, '0', STR_PAD_LEFT)]);

            foreach ($resolvedItems as $resolvedItem) {
                $product = $resolvedItem['product'];
                $variant = $resolvedItem['variant'];
                $category = null; $subcategory = null;
                if ($product->category) {
                    if ($product->category->parent_id) { $subcategory = $product->category; $category = Category::query()->find($product->category->parent_id); }
                    else { $category = $product->category; }
                }
                ProductDeactivationDocumentItem::create([
                    'document_id' => $doc->id,
                    'category_id' => $category?->id,
                    'subcategory_id' => $subcategory?->id,
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'deactivation_type' => $resolvedItem['deactivation_type'],
                    'deactivation_status' => 'deactivated',
                    'category_name_snapshot' => $category?->name,
                    'subcategory_name_snapshot' => $subcategory?->name,
                    'product_name_snapshot' => (string) $product->name,
                    'variant_name_snapshot' => $variant->variant_name,
                ]);
                if ((bool) ($variant->sales_enabled ?? true)) {
                    $variant->update(['sales_enabled' => false]);
                }
            }

            if (!empty($productIdsToDisableProducts)) {
                Product::query()->whereIn('id', array_keys($productIdsToDisableProducts))->update(['is_sellable' => false]);
            }
            if (!empty($categoryIdsToDisableProducts)) {
                Product::query()->whereIn('category_id', array_values(array_unique($categoryIdsToDisableProducts)))->update(['is_sellable' => false]);
            }

            $doc->update(['items_count' => $doc->items()->count()]);
        });

        return redirect()->route('product-deactivation-documents.index')->with('success', 'سند غیرفعال‌سازی با موفقیت ثبت شد.');
    }

    public function show(ProductDeactivationDocument $productDeactivationDocument)
    {
        $productDeactivationDocument->load([
            'creator:id,name',
            'items.product:id,name,is_sellable',
            'items.variant:id,product_id,variant_name,is_active,sales_enabled',
        ]);

        $typeLabels = ProductDeactivationDocument::typeLabels();

        return view('product-deactivation-documents.show', [
            'document' => $productDeactivationDocument,
            'typeLabels' => $typeLabels,
        ]);
    }
}
