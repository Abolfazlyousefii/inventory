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
                        $v->where('is_active', true);
                    });
            })
            ->with([
                'category:id,name,parent_id',
                'variants' => fn ($q) => $q->where('is_active', true)->orderBy('variant_name'),
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
        if (!$request->has('items') && $request->filled('product_id')) {
            $request->merge([
                'items' => [[
                    'category_id' => $request->input('category_id'),
                    'subcategory_id' => $request->input('subcategory_id'),
                    'product_id' => $request->input('product_id'),
                    'variant_id' => $request->input('variant_id'),
                ]],
            ]);
        }

        $data = $request->validate([
            'reason_text' => ['required', 'string', 'min:3', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'items.*.subcategory_id' => ['nullable', 'integer', 'exists:categories,id'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
        ], [
            'reason_text.required' => 'نوشتن دلیل غیرفعال‌سازی الزامی است.',
            'items.required' => 'حداقل یک ردیف کالا برای غیرفعال‌سازی وارد کنید.',
            'items.min' => 'حداقل یک ردیف کالا برای غیرفعال‌سازی وارد کنید.',
            'items.*.product_id.required' => 'انتخاب کالا برای هر ردیف الزامی است.',
        ]);

        $rootCategoryIds = Category::query()
            ->whereNull('parent_id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $rootCategoryLookup = array_fill_keys($rootCategoryIds, true);

        $subcategoryParentMap = Category::query()
            ->whereNotNull('parent_id')
            ->pluck('parent_id', 'id')
            ->mapWithKeys(fn ($parentId, $subcategoryId) => [(int) $subcategoryId => (int) $parentId])
            ->all();

        $messages = [];

        foreach (($data['items'] ?? []) as $index => $item) {
            $categoryId = (int) ($item['category_id'] ?? 0);
            $subcategoryId = (int) ($item['subcategory_id'] ?? 0);

            if ($categoryId && !isset($rootCategoryLookup[$categoryId])) {
                $messages["items.{$index}.category_id"] = 'فقط دسته‌بندی اصلی قابل انتخاب است.';
            }

            if ($subcategoryId) {
                $expectedParentId = (int) ($subcategoryParentMap[$subcategoryId] ?? 0);

                if (!$expectedParentId || ($categoryId && $expectedParentId !== $categoryId)) {
                    $messages["items.{$index}.subcategory_id"] = 'زیر‌دسته انتخاب‌شده با دسته‌بندی اصلی مطابقت ندارد.';
                }
            }
        }

        if (!empty($messages)) {
            throw ValidationException::withMessages($messages);
        }

        DB::transaction(function () use ($data) {
            $resolvedItems = [];
            $seenTargets = [];

            foreach ($data['items'] as $index => $itemData) {
                $product = Product::query()
                    ->with('category')
                    ->whereKey((int) $itemData['product_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $variant = null;
                $deactivationType = ProductDeactivationDocument::TYPE_PRODUCT;
                $targetKey = 'product:' . $product->id;

                if (!empty($itemData['variant_id'])) {
                    $variant = ProductVariant::query()
                        ->whereKey((int) $itemData['variant_id'])
                        ->where('product_id', $product->id)
                        ->lockForUpdate()
                        ->first();

                    if (!$variant) {
                        throw ValidationException::withMessages([
                            "items.{$index}.variant_id" => 'تنوع انتخاب‌شده با کالای همین ردیف مطابقت ندارد.',
                        ]);
                    }

                    $deactivationType = ProductDeactivationDocument::TYPE_VARIANT;
                    $targetKey = 'variant:' . $variant->id;
                }

                if (isset($seenTargets[$targetKey])) {
                    continue;
                }

                $seenTargets[$targetKey] = true;
                $resolvedItems[] = [
                    'product' => $product,
                    'variant' => $variant,
                    'deactivation_type' => $deactivationType,
                ];
            }

            if (empty($resolvedItems)) {
                throw ValidationException::withMessages([
                    'items' => 'حداقل یک هدف معتبر برای غیرفعال‌سازی لازم است.',
                ]);
            }

            $firstResolvedItem = $resolvedItems[0];
            /** @var Product $firstProduct */
            $firstProduct = $firstResolvedItem['product'];
            /** @var ProductVariant|null $firstVariant */
            $firstVariant = $firstResolvedItem['variant'];

            $doc = ProductDeactivationDocument::create([
                'document_number' => 'TMP-' . now()->format('YmdHis') . '-' . random_int(100, 999),
                'deactivation_type' => $firstResolvedItem['deactivation_type'],
                'product_id' => $firstProduct->id,
                'variant_id' => $firstVariant?->id,
                'items_count' => count($resolvedItems),
                'reason_type' => 'custom',
                'reason_text' => trim((string) $data['reason_text']),
                'description' => null,
                'product_name_snapshot' => (string) $firstProduct->name,
                'variant_name_snapshot' => $firstVariant?->variant_name,
                'created_by' => (int) auth()->id(),
            ]);

            // بروزرسانی شماره سند
            $doc->update([
                'document_number' => 'PD-' . now()->format('Ymd') . '-' . str_pad((string) $doc->id, 6, '0', STR_PAD_LEFT),
            ]);

            foreach ($resolvedItems as $resolvedItem) {
                /** @var Product $product */
                $product = $resolvedItem['product'];
                /** @var ProductVariant|null $variant */
                $variant = $resolvedItem['variant'];
                $deactivationType = $resolvedItem['deactivation_type'];

                if ($variant) {
                    if ((bool) $variant->is_active) {
                        $variant->update(['is_active' => false]);
                    }
                } else {
                    if ((bool) $product->is_sellable) {
                        $product->update(['is_sellable' => false]);
                    }
                    $product->variants()->where('is_active', true)->update(['is_active' => false]);
                }

                $category = null;
                $subcategory = null;
                if ($product->category) {
                    if ($product->category->parent_id) {
                        $subcategory = $product->category;
                        $category = Category::query()->find($product->category->parent_id);
                    } else {
                        $category = $product->category;
                    }
                }

                ProductDeactivationDocumentItem::create([
                    'document_id' => $doc->id,
                    'category_id' => $category?->id,
                    'subcategory_id' => $subcategory?->id,
                    'product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'deactivation_type' => $deactivationType,
                    'deactivation_status' => 'deactivated',
                    'category_name_snapshot' => $category?->name,
                    'subcategory_name_snapshot' => $subcategory?->name,
                    'product_name_snapshot' => (string) $product->name,
                    'variant_name_snapshot' => $variant?->variant_name,
                ]);
            }
        });

        // هدایت به صفحه لیست اسناد با پیغام موفقیت
        return redirect()
            ->route('product-deactivation-documents.index')
            ->with('success', 'سند غیرفعال‌سازی با موفقیت ثبت شد.');
    }

    public function show(ProductDeactivationDocument $productDeactivationDocument)
    {
        $productDeactivationDocument->load([
            'creator:id,name',
            'items.product:id,name,is_sellable',
            'items.variant:id,product_id,variant_name,is_active',
        ]);

        $typeLabels = ProductDeactivationDocument::typeLabels();

        return view('product-deactivation-documents.show', [
            'document' => $productDeactivationDocument,
            'typeLabels' => $typeLabels,
        ]);
    }
}
