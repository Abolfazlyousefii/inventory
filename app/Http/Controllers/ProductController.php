<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ModelList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\PreinvoiceOrderItem;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\CrmProductSyncService;
use App\Services\DefaultProductDesignService;
use App\Services\ProductVariantStructureService;
use App\Services\ProductSearchService;
use App\Services\WarehouseStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ProductController extends Controller
{
    public function index(Request $request, ProductSearchService $searchService)
    {
        $categoryTree = $searchService->categoriesTree();

        return view('products.index', [
            'categoryTree' => $categoryTree,
        ]);
    }

    public function data(Request $request, ProductSearchService $searchService)
    {
        $products = $searchService->products($request);

        return response()->json([
            'data' => $products->getCollection()->map(fn ($product) => $searchService->productPayload($product))->values(),
            'meta' => [
                'returned' => $products->count(),
                'has_more' => $products->hasMorePages(),
                'next_cursor' => optional($products->nextCursor())->encode(),
            ],
        ]);
    }

    public function variants(Product $product, Request $request, ProductSearchService $searchService)
    {
        $variants = $searchService->variants($product, $request);

        return response()->json([
            'product' => [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'short_code' => $product->short_barcode ?: $product->code,
            ],
            'data' => $variants->getCollection()->map(fn ($variant) => $searchService->variantPayload($variant))->values(),
            'meta' => [
                'returned' => $variants->count(),
                'has_more' => $variants->hasMorePages(),
                'next_cursor' => optional($variants->nextCursor())->encode(),
            ],
        ]);
    }


    public function warehouseStock(Product $product, Request $request)
    {
        $variantId = $request->filled('variant_id') ? (int) $request->input('variant_id') : null;
        $limit = min(50, max(1, (int) $request->integer('limit', 30)));
        $cursor = max(0, (int) $request->integer('cursor', 0));
        $search = trim((string) $request->query('q', ''));

        $allVariantsQuery = $product->variants()
            ->select(['id', 'product_id', 'variant_name', 'variety_name', 'variant_code', 'variety_code', 'stock', 'reserved'])
            ->orderBy('variant_code')
            ->orderBy('id');

        if ($variantId) {
            $allVariantsQuery->whereKey($variantId);
        }

        $allVariants = $allVariantsQuery->get();

        if ($variantId && $allVariants->isEmpty()) {
            throw ValidationException::withMessages([
                'variant_id' => 'تنوع انتخاب‌شده متعلق به این کالا نیست.',
            ]);
        }

        $allVariantIds = $allVariants->pluck('id')->map(fn ($id) => (int) $id)->values();
        $hasVariants = $allVariantIds->isNotEmpty();
        $centralWarehouseId = WarehouseStockService::centralWarehouseId();

        $stockTotals = WarehouseStock::query()
            ->where('product_id', $product->id)
            ->when($hasVariants, fn ($query) => $query->whereIn('product_variant_id', $allVariantIds))
            ->when(! $hasVariants, fn ($query) => $query->whereNull('product_variant_id'))
            ->selectRaw('COALESCE(product_variant_id, 0) as variant_key')
            ->selectRaw('SUM(quantity) as available_quantity')
            ->groupBy('variant_key')
            ->pluck('available_quantity', 'variant_key')
            ->map(fn ($quantity) => (int) $quantity);

        $reservedByVariant = $hasVariants
            ? $this->reservedPreinvoiceQuantities($product, $allVariantIds->all())
            : collect();

        $draftReservedByVariant = $hasVariants
            ? $this->draftReservationQuantities($product, $allVariantIds->all())
            : collect();

        $reservedTotalByVariant = $allVariantIds
            ->mapWithKeys(fn ($id) => [(int) $id => (int) ($reservedByVariant[(int) $id] ?? 0) + (int) ($draftReservedByVariant[(int) $id] ?? 0)]);

        $summaryAvailable = (int) $stockTotals->sum();
        $summaryReserved = (int) $reservedTotalByVariant->sum();
        $summaryTotal = $summaryAvailable + $summaryReserved;

        $warehousesCount = WarehouseStock::query()
            ->where('product_id', $product->id)
            ->when($hasVariants, fn ($query) => $query->whereIn('product_variant_id', $allVariantIds))
            ->when(! $hasVariants, fn ($query) => $query->whereNull('product_variant_id'))
            ->distinct('warehouse_id')
            ->count('warehouse_id');

        $productPayload = [
            'id' => (int) $product->id,
            'title' => (string) $product->name,
            'code' => (string) ($product->short_barcode ?: ($product->code ?: '—')),
            'is_variant_mode' => $hasVariants,
        ];

        $summaryPayload = [
            'total_quantity' => $summaryTotal,
            'reserved_quantity' => $summaryReserved,
            'available_quantity' => $summaryAvailable,
            'warehouses_count' => $warehousesCount,
            'variants_count' => $allVariantIds->count(),
            'reservation_allocation' => $hasVariants ? 'central_warehouse' : 'none',
        ];

        if (! $hasVariants) {
            $rows = $this->warehouseStockRows($product, collect(), $reservedTotalByVariant, $centralWarehouseId, false);

            return response()->json([
                'product_id' => (int) $product->id,
                'variant_id' => null,
                'title' => $product->name,
                'is_variant_mode' => false,
                'rows' => $rows->values(),
                'product' => $productPayload,
                'summary' => $summaryPayload,
                'variants' => [],
                'meta' => ['has_more' => false, 'next_cursor' => null, 'limit' => $limit],
            ]);
        }

        $filteredVariants = $allVariants;
        if ($search !== '') {
            $normalized = ProductSearchService::normalize($search);
            $filteredVariants = $allVariants->filter(function (ProductVariant $variant) use ($normalized) {
                $haystack = ProductSearchService::normalize(implode(' ', [
                    $variant->variant_name,
                    $variant->variety_name,
                    $variant->variant_code,
                    $variant->variety_code,
                    $variant->sku,
                    $variant->barcode,
                ]));

                return str_contains($haystack, $normalized);
            })->values();
        }

        $pagedVariants = $filteredVariants->slice($cursor, $limit)->values();
        $rows = $this->warehouseStockRows($product, $pagedVariants->pluck('id')->map(fn ($id) => (int) $id), $reservedTotalByVariant, $centralWarehouseId, true);
        $variantMeta = $pagedVariants->keyBy('id');
        $rows = $rows->map(function ($row) use ($variantMeta) {
            $variant = $variantMeta->get((int) $row['variant_id']);
            $row['variant_title'] = $variant ? $this->variantDisplayTitle($variant) : null;
            $row['display_code'] = $variant ? (string) ($variant->variant_code ?: ($variant->variety_code ?: '—')) : null;

            return $row;
        });
        $rowsByVariant = $rows->groupBy('variant_id');

        $variantsPayload = $pagedVariants->map(function (ProductVariant $variant) use ($rowsByVariant, $reservedTotalByVariant, $stockTotals) {
            $variantId = (int) $variant->id;
            $available = (int) ($stockTotals[$variantId] ?? 0);
            $reserved = (int) ($reservedTotalByVariant[$variantId] ?? 0);
            $total = $available + $reserved;

            return [
                'id' => $variantId,
                'title' => $this->variantDisplayTitle($variant),
                'code' => (string) ($variant->variant_code ?: ($variant->variety_code ?: '—')),
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'summary' => [
                    'total_quantity' => $total,
                    'reserved_quantity' => $reserved,
                    'available_quantity' => $available,
                ],
                'warehouses' => ($rowsByVariant->get($variantId, collect()))->map(fn ($row) => [
                    'id' => (int) $row['warehouse_id'],
                    'name' => (string) $row['warehouse_name'],
                    'total_quantity' => (int) $row['total_quantity'],
                    'reserved_quantity' => (int) $row['reserved_quantity'],
                    'available_quantity' => (int) $row['available_quantity'],
                    'has_warning' => (bool) $row['has_over_reserved_warning'],
                ])->values(),
            ];
        })->values();

        $selectedVariant = $variantId ? $allVariants->first() : null;
        $nextCursor = $cursor + $limit;
        $hasMore = $nextCursor < $filteredVariants->count();

        return response()->json([
            'product_id' => (int) $product->id,
            'variant_id' => $variantId,
            'title' => $selectedVariant
                ? $product->name . ' / ' . $this->variantDisplayTitle($selectedVariant)
                : $product->name,
            'is_variant_mode' => (bool) $selectedVariant,
            'rows' => $rows->values(),
            'product' => $productPayload,
            'summary' => $summaryPayload,
            'variants' => $variantsPayload,
            'meta' => [
                'has_more' => $hasMore,
                'next_cursor' => $hasMore ? $nextCursor : null,
                'limit' => $limit,
                'filtered_variants_count' => $filteredVariants->count(),
            ],
        ]);
    }

    private function warehouseStockRows(Product $product, $variantIds, $reservedTotalByVariant, int $centralWarehouseId, bool $hasVariants)
    {
        $variantIds = collect($variantIds)->map(fn ($id) => (int) $id)->filter()->values();

        $warehouses = Warehouse::query()
            ->orderByDesc('type')
            ->orderBy('name')
            ->get(['id', 'name', 'type']);

        $stockRows = WarehouseStock::query()
            ->where('product_id', $product->id)
            ->when($hasVariants, fn ($query) => $query->whereIn('product_variant_id', $variantIds))
            ->when(! $hasVariants, fn ($query) => $query->whereNull('product_variant_id'))
            ->select('warehouse_id', 'product_variant_id')
            ->selectRaw('SUM(quantity) as quantity')
            ->groupBy('warehouse_id', 'product_variant_id')
            ->get();

        if (! $hasVariants) {
            return $stockRows->map(function ($stock) use ($warehouses) {
                $warehouse = $warehouses->firstWhere('id', (int) $stock->warehouse_id);
                $available = (int) $stock->quantity;

                return [
                    'variant_id' => null,
                    'variant_title' => 'کل کالا',
                    'display_code' => null,
                    'warehouse_id' => (int) $stock->warehouse_id,
                    'warehouse_name' => (string) ($warehouse?->name ?: '—'),
                    'total_quantity' => $available,
                    'reserved_quantity' => 0,
                    'available_quantity' => $available,
                    'has_over_reserved_warning' => false,
                ];
            })->filter(fn ($row) => (int) $row['available_quantity'] > 0)->values();
        }

        $stocksByVariantWarehouse = $stockRows
            ->groupBy(fn ($stock) => ((int) $stock->product_variant_id) . ':' . ((int) $stock->warehouse_id));

        $rows = collect();
        foreach ($variantIds as $variantId) {
            $reservedForVariant = (int) ($reservedTotalByVariant[(int) $variantId] ?? 0);

            foreach ($warehouses as $warehouse) {
                $key = ((int) $variantId) . ':' . ((int) $warehouse->id);
                $availableInWarehouse = (int) ($stocksByVariantWarehouse->get($key, collect())->sum('quantity'));
                $reservedInWarehouse = (int) $warehouse->id === $centralWarehouseId ? $reservedForVariant : 0;

                if ($availableInWarehouse === 0 && $reservedInWarehouse === 0) {
                    continue;
                }

                $physicalTotal = $availableInWarehouse + $reservedInWarehouse;

                $rows->push([
                    'variant_id' => (int) $variantId,
                    'variant_title' => null,
                    'display_code' => null,
                    'warehouse_id' => (int) $warehouse->id,
                    'warehouse_name' => (string) $warehouse->name,
                    'total_quantity' => $physicalTotal,
                    'reserved_quantity' => $reservedInWarehouse,
                    'available_quantity' => $availableInWarehouse,
                    'has_over_reserved_warning' => $reservedInWarehouse > $physicalTotal,
                ]);
            }
        }

        return $rows->values();
    }


    public function create()
    {
        $categories = Category::query()->orderBy('name')->get();

        $modelLists = ModelList::query()
            ->whereNotNull('code')
            ->where('code', '<>', '')
            ->orderBy('brand')
            ->orderBy('model_name')
            ->get(['id', 'brand', 'model_name', 'code']);

        $previewSeq4 = $this->peekNextProductSeq4();

        return view('products.create', compact('categories', 'modelLists', 'previewSeq4'));
    }


    private function reservedPreinvoiceQuantities(Product $product, array $variantIds)
    {
        return PreinvoiceDraftReservation::query()
            ->where('product_id', $product->id)
            ->whereIn('variant_id', $variantIds)
            ->where('reservation_scope', 'official')
            ->whereNotNull('converted_at')
            ->whereNull('released_at')
            ->where(function ($query) {
                $query->whereNull('release_reason')->orWhere('release_reason', '<>', 'consumed');
            })
            ->select('variant_id', DB::raw('SUM(quantity) as reserved_quantity'))
            ->groupBy('variant_id')
            ->pluck('reserved_quantity', 'variant_id')
            ->map(fn ($quantity) => (int) $quantity);
    }

    private function draftReservationQuantities(Product $product, array $variantIds)
    {
        return PreinvoiceDraftReservation::query()
            ->where('product_id', $product->id)
            ->whereIn('variant_id', $variantIds)
            ->whereNull('converted_at')
            ->whereNull('preinvoice_order_id')
            ->whereNull('released_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->select('variant_id', DB::raw('SUM(quantity) as reserved_quantity'))
            ->groupBy('variant_id')
            ->pluck('reserved_quantity', 'variant_id')
            ->map(fn ($quantity) => (int) $quantity);
    }

    private function variantDisplayTitle(ProductVariant $variant): string
    {
        return (string) ($variant->variant_name ?: ($variant->variant_code ?: 'تنوع اصلی'));
    }

    private function isCentralWarehouse(Warehouse $warehouse): bool
    {
        return $warehouse->type === 'central' || $warehouse->name === 'انبار مرکزی';
    }

    private function peekNextProductSeq4(): string
    {
        $next = $this->nextProductSeqNumber(false);
        if ($next > 9999) {
            $next = 9999;
        }

        return str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'max:4096'],

            'use_models' => ['nullable'],
            'use_designs' => ['nullable'],
            'model_brand_group' => ['nullable', 'string', 'max:100'],

            'model_list_ids' => ['nullable', 'array'],
            'model_list_ids.*' => ['integer', 'exists:model_lists,id'],

            'design_count' => ['nullable', 'integer', 'min:1', 'max:99'],
            'design_notes' => ['nullable', 'array'],
            'design_notes.*' => ['nullable', 'string', 'max:120'],

            'buy_price' => ['nullable', 'integer', 'min:0'],
            'sell_price' => ['nullable', 'integer', 'min:0'],
            'is_sellable' => ['nullable', 'boolean'],
        ]);

        $useModels = $request->boolean('use_models');
        $useDesigns = $request->boolean('use_designs');

        if ($useModels && empty($data['model_brand_group'])) {
            abort(422, 'برای مدل‌لیست: ابتدا گروه برند را انتخاب کنید.');
        }

        if ($useModels && empty($data['model_list_ids'])) {
            abort(422, 'برای مدل‌لیست: حداقل یک مدل انتخاب کنید.');
        }

        if ($useDesigns && empty($data['design_count'])) {
            abort(422, 'برای طرح‌بندی: تعداد طرح را وارد کنید.');
        }

        $designNotes = $this->normalizeDesignNotes($data['design_notes'] ?? []);

        $isSellable = $request->boolean('is_sellable', true);

        $imagePath = $this->storeProductImage($request);

        DB::transaction(function () use ($data, $useModels, $useDesigns, $designNotes, $isSellable, $imagePath) {
            $category = Category::query()->lockForUpdate()->findOrFail($data['category_id']);

            $cat2 = $this->normalizeCategory2($category->code);
            if ($cat2 === null) {
                abort(422, 'کد دسته‌بندی باید ۲ رقمی باشد (00 تا 99).');
            }

            $seq4 = $this->nextProductSeq4();
            $productCode6 = $cat2 . $seq4;

            $product = Product::create([
                'category_id' => $category->id,
                'name' => trim($data['name']),
                'image_path' => $imagePath,
                'sku' => 'AUTO-' . now()->format('YmdHis') . '-' . random_int(100, 999),
                'code' => $productCode6,
                'short_barcode' => $seq4,
                'stock' => 0,
                'price' => 0,
                'is_sellable' => $isSellable,
                'models' => app(ProductVariantStructureService::class)->metadata(
                    $useModels,
                    $data['model_list_ids'] ?? [],
                    $useDesigns,
                    $data['design_count'] ?? null,
                    $designNotes->all()
                ),
            ]);

            $sellPrice = (int) ($data['sell_price'] ?? 0);
            $buyPrice = isset($data['buy_price']) ? (int) $data['buy_price'] : null;

            $models = collect();
            if ($useModels) {
                $ids = array_values(array_map('intval', $data['model_list_ids']));
                $map = ModelList::query()
                    ->whereIn('id', $ids)
                    ->get(['id', 'model_name', 'code'])
                    ->keyBy('id');

                $models = collect($ids)->map(fn ($id) => $map->get($id))->filter()->values();
            }

            if (!$useModels && !$useDesigns) {
                $variantCode = $this->buildVariantCode11($productCode6, '000', '00');

                ProductVariant::create([
                    'product_id' => $product->id,
                    'model_list_id' => null,
                    'variant_name' => $product->name,
                    'variety_name' => '—',
                    'variety_code' => '0000',
                    'variant_code' => $variantCode,
                    'sell_price' => $sellPrice,
                    'buy_price' => $buyPrice,
                    'stock' => 0,
                    'reserved' => 0,
                ]);
            }

            if ($useDesigns && !$useModels) {
                $d = (int) $data['design_count'];

                for ($i = 1; $i <= $d; $i++) {
                    $design2 = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
                    $variantCode = $this->buildVariantCode11($productCode6, '000', $design2);

                    $designTitle = $this->designTitle($i, (string) ($designNotes->get($i - 1) ?? ''));

                    ProductVariant::create([
                        'product_id' => $product->id,
                        'model_list_id' => null,
                        'variant_name' => $product->name . ' ' . $designTitle,
                        'variety_name' => $designTitle,
                        'variety_code' => str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                        'variant_code' => $variantCode,
                        'sell_price' => $sellPrice,
                        'buy_price' => $buyPrice,
                        'stock' => 0,
                        'reserved' => 0,
                    ]);
                }
            }

            if ($useModels && !$useDesigns) {
                foreach ($models as $m) {
                    $model3 = $this->normalizeModel3($m->code);
                    $variantCode = $this->buildVariantCode11($productCode6, $model3, '00');

                    ProductVariant::create([
                        'product_id' => $product->id,
                        'model_list_id' => $m->id,
                        'variant_name' => $product->name . ' ' . $m->model_name,
                        'variety_name' => '—',
                        'variety_code' => '0000',
                        'variant_code' => $variantCode,
                        'sell_price' => $sellPrice,
                        'buy_price' => $buyPrice,
                        'stock' => 0,
                        'reserved' => 0,
                    ]);
                }
            }

            if ($useModels && $useDesigns) {
                $d = (int) $data['design_count'];

                foreach ($models as $m) {
                    $model3 = $this->normalizeModel3($m->code);

                    for ($i = 1; $i <= $d; $i++) {
                        $design2 = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
                        $variantCode = $this->buildVariantCode11($productCode6, $model3, $design2);

                        $designTitle = $this->designTitle($i, (string) ($designNotes->get($i - 1) ?? ''));

                        ProductVariant::create([
                            'product_id' => $product->id,
                            'model_list_id' => $m->id,
                            'variant_name' => $product->name . ' ' . $m->model_name . ' ' . $designTitle,
                            'variety_name' => $designTitle,
                            'variety_code' => str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                            'variant_code' => $variantCode,
                            'sell_price' => $sellPrice,
                            'buy_price' => $buyPrice,
                            'stock' => 0,
                            'reserved' => 0,
                        ]);
                    }
                }
            }

            app(DefaultProductDesignService::class)->ensureElectricDefaultColors($product, $sellPrice, $buyPrice);

            $this->recalcProductSummary($product);
        });

        return redirect()->route('products.index')->with('success', 'کالا و تنوع‌ها با موفقیت ساخته شدند.');
    }

    public function image(Product $product)
    {
        $imagePath = $this->resolveProductImagePath($product);

        if (!$imagePath) {
            abort(404);
        }

        return Storage::disk('public')->response($imagePath, null, [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function resolveProductImagePath(Product $product): ?string
    {
        $imagePath = trim((string) ($product->image_path ?? ''));

        if ($imagePath === '' || str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://')) {
            return null;
        }

        $candidates = array_values(array_unique(array_filter([
            ltrim($imagePath, '/'),
            preg_replace('#^/?storage/#', '', $imagePath),
            preg_replace('#^/?public/#', '', $imagePath),
            basename($imagePath) ? 'products/' . basename($imagePath) : null,
        ])));

        foreach ($candidates as $candidate) {
            if (Storage::disk('public')->exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function edit(Request $request, Product $product)
    {
        $product->load(['variants.warehouseStocks.warehouse']);
        $product->setRelation('variants', app(ProductVariantStructureService::class)->validVariants($product));

        $categories = Category::orderBy('name')->get();

        $modelListOptions = ModelList::query()
            ->orderBy('brand')
            ->orderBy('model_name')
            ->get(['id', 'brand', 'model_name', 'code']);

        $modelLists = $modelListOptions;
        $previewSeq4 = $product->short_barcode ?: substr((string) $product->code, 2, 4);
        $returnTo = $this->safeProductsReturnUrl($request->query('return_to'));

        return view('products.edit', compact('product', 'categories', 'modelListOptions', 'modelLists', 'previewSeq4', 'returnTo'));
    }

    public function update(Request $request, Product $product)
    {
        $this->sanitizeUpdateVariants($request, $product);

        $request->merge([
            'category_id' => $request->has('category_id') ? $request->input('category_id') : $product->category_id,
            'name' => $request->has('name') ? $request->input('name') : $product->name,
        ]);

        $data = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'remove_image' => ['nullable', 'boolean'],
            'is_sellable' => ['nullable', 'boolean'],
            'generate_new_variants' => ['nullable', 'boolean'],
            'use_models' => ['nullable'],
            'use_designs' => ['nullable'],
            'model_list_ids' => ['nullable', 'array'],
            'model_list_ids.*' => ['integer', 'exists:model_lists,id'],
            'design_count' => ['nullable', 'integer', 'min:1', 'max:99'],
            'design_notes' => ['nullable', 'array'],
            'design_notes.*' => ['nullable', 'string', 'max:120'],
            'buy_price' => ['nullable', 'integer', 'min:0'],
            'sell_price' => ['nullable', 'integer', 'min:0'],

            'variants' => ['nullable', 'array'],
            'variants.*.id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('product_id', $product->id)],
            'variants.*.variant_name' => ['required', 'string', 'max:255'],
            'variants.*.model_list_id' => ['nullable', 'integer', 'exists:model_lists,id'],
            'variants.*.variety_name' => ['required', 'string', 'max:255'],
            'variants.*.variety_code' => ['required', 'digits:4'],
            'variants.*.sell_price' => ['nullable', 'integer', 'min:0'],
            'variants.*.buy_price' => ['nullable', 'integer', 'min:0'],
            'variants.*.stock' => ['nullable', 'integer', 'min:0'],
            'variants.*.is_active' => ['nullable', 'boolean'],
            'variants.*.variety_id' => ['nullable', 'integer', 'min:1'],
            'variant_site_ids' => ['nullable', 'array'],
            'variant_site_ids.*' => ['nullable', 'integer', 'min:1'],
        ], [
            'variants.*.id.exists' => 'تنوع انتخاب‌شده متعلق به این کالا نیست.',
            'variants.*.variant_name.required' => 'اطلاعات یکی از ردیف‌های فعال تنوع ناقص است. لطفاً نام تنوع، عنوان طرح و کد تنوع را کامل کنید.',
            'variants.*.variety_name.required' => 'اطلاعات یکی از ردیف‌های فعال تنوع ناقص است. لطفاً نام تنوع، عنوان طرح و کد تنوع را کامل کنید.',
            'variants.*.variety_code.required' => 'اطلاعات یکی از ردیف‌های فعال تنوع ناقص است. لطفاً نام تنوع، عنوان طرح و کد تنوع را کامل کنید.',
            'variants.*.variety_code.digits' => 'کد تنوع ردیف‌های فعال باید دقیقاً ۴ رقم باشد.',
            'variants.*.model_list_id.exists' => 'مدل انتخاب‌شده برای یکی از تنوع‌های کالا معتبر نیست.',
        ]);

        $newImagePath = $this->storeProductImage($request);
        $removeImage = $request->boolean('remove_image');
        $oldImagePath = null;

        DB::transaction(function () use ($data, $request, $product, $newImagePath, $removeImage, &$oldImagePath) {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);

            $productUpdate = [
                'category_id' => (int) $data['category_id'],
                'name' => $data['name'],
                'is_sellable' => (bool) ($data['is_sellable'] ?? false),
                'models' => app(ProductVariantStructureService::class)->metadata(
                    filter_var($data['use_models'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    $data['model_list_ids'] ?? [],
                    filter_var($data['use_designs'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    $data['design_count'] ?? null,
                    $this->normalizeDesignNotes($data['design_notes'] ?? [])->all()
                ),
            ];

            if ($newImagePath || $removeImage) {
                $oldImagePath = $product->image_path;
                $productUpdate['image_path'] = $newImagePath;
            }

            $product->update($productUpdate);

            $keepIds = [];
            $centralWarehouseId = WarehouseStockService::centralWarehouseId();

            foreach (($data['variants'] ?? []) as $v) {
                $ignoreId = !empty($v['id']) ? (int) $v['id'] : null;

                $modelCode3 = '000';
                $modelListId = $v['model_list_id'] ?? null;

                if (!empty($modelListId)) {
                    $model = ModelList::findOrFail($modelListId);
                    $modelCode3 = $this->normalizeModel3($model->code);
                }

                $design2 = substr((string) $v['variety_code'], -2);
                if (!preg_match('/^\d{2}$/', $design2)) {
                    $design2 = '00';
                }

                $variant = null;

                if (!empty($v['id'])) {
                    $variant = ProductVariant::where('product_id', $product->id)
                        ->where('id', $v['id'])
                        ->first();
                }

                $sellPrice = array_key_exists('sell_price', $v) && $v['sell_price'] !== ''
                    ? (int) $v['sell_price']
                    : (int) ($variant?->sell_price ?? 0);

                $buyPrice = array_key_exists('buy_price', $v) && $v['buy_price'] !== ''
                    ? (int) $v['buy_price']
                    : $variant?->buy_price;

                $variantCode = $variant?->variant_code ?: $this->buildVariantCode11((string) $product->code, $modelCode3, $design2, $ignoreId);

                $payload = [
                    'variant_name' => $this->normalizeDesignTitle($v['variant_name']),
                    'model_list_id' => $modelListId,
                    'variety_name' => $this->normalizeDesignTitle($v['variety_name']),
                    'variety_code' => $v['variety_code'],
                    'variant_code' => $variantCode,
                    'sell_price' => $sellPrice,
                    'buy_price' => $buyPrice,
                    'is_active' => (bool) ($v['is_active'] ?? true),
                    'variety_id' => isset($v['variety_id']) && $v['variety_id'] !== '' ? (int) $v['variety_id'] : null,
                ];

                if ($variant) {
                    $variant->update($payload);
                    $keepIds[] = $variant->id;
                } else {
                    $variant = ProductVariant::create(array_merge($payload, [
                        'product_id' => $product->id,
                        'reserved' => 0,
                        'stock' => 0,
                    ]));

                    $keepIds[] = $variant->id;
                }

                if ($variant && array_key_exists('stock', $v) && $v['stock'] !== '') {
                    WarehouseStockService::set(
                        $centralWarehouseId,
                        (int) $product->id,
                        (int) $variant->id,
                        (int) $v['stock']
                    );
                }
            }

            $defaultDesignService = app(DefaultProductDesignService::class);
            $keepIds = array_values(array_unique(array_merge(
                $keepIds,
                $defaultDesignService->electricDefaultColorVariantIds($product)
            )));

            // ویرایش ساختار کالا نباید هیچ تنوع واقعی را صرفاً به‌خاطر نبودن در request حذف کند.
            // حذف/غیرفعال‌سازی واقعی باید بعداً از مسیر صریح و کنترل‌شده انجام شود.
            $this->syncProductVariants($product, $data);
            app(ProductVariantStructureService::class)->deactivateInvalidVariants($product);

            foreach (($data['variant_site_ids'] ?? []) as $variantId => $siteVariantId) {
                if ($siteVariantId === null || $siteVariantId === '') {
                    continue;
                }

                ProductVariant::where('product_id', $product->id)
                    ->where('id', (int) $variantId)
                    ->update(['variety_id' => (int) $siteVariantId]);
            }

            $defaultDesignService->ensureElectricDefaultColors($product);

            $this->recalcProductSummary($product);
        });

        if ($oldImagePath && ($newImagePath || $removeImage)) {
            Storage::disk('public')->delete($oldImagePath);
        }

        return redirect()->to($this->safeProductsReturnUrl($request->input('return_to')))->with('success', 'کالا بروزرسانی شد.');
    }


    private function syncProductVariants(Product $product, array $data): void
    {
        $useModels = filter_var($data['use_models'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $useDesigns = filter_var($data['use_designs'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $modelIds = $useModels
            ? collect($data['model_list_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()
            : collect([null]);

        if ($useModels && $modelIds->isEmpty()) {
            return;
        }

        $modelsById = $useModels
            ? ModelList::query()->whereIn('id', $modelIds->all())->get(['id', 'model_name', 'code'])->keyBy('id')
            : collect();

        $designCount = $useDesigns ? max(1, min(99, (int) ($data['design_count'] ?? 1))) : 0;
        $designNotes = $this->normalizeDesignNotes($data['design_notes'] ?? []);
        $designIndexes = $useDesigns ? range(1, $designCount) : [0];

        $defaultSellPrice = array_key_exists('sell_price', $data) && $data['sell_price'] !== null
            ? (int) $data['sell_price']
            : 0;
        $defaultBuyPrice = array_key_exists('buy_price', $data) && $data['buy_price'] !== null
            ? (int) $data['buy_price']
            : null;

        foreach ($modelIds as $modelId) {
            $model = $modelId ? $modelsById->get($modelId) : null;
            if ($useModels && !$model) {
                continue;
            }

            $modelCode3 = $model ? $this->normalizeModel3($model->code) : '000';

            foreach ($designIndexes as $designIndex) {
                $design2 = $designIndex > 0 ? str_pad((string) $designIndex, 2, '0', STR_PAD_LEFT) : '00';
                $varietyCode = $designIndex > 0 ? str_pad((string) $designIndex, 4, '0', STR_PAD_LEFT) : '0000';
                $designTitle = $designIndex > 0 ? $this->designTitle($designIndex, (string) ($designNotes->get($designIndex - 1) ?? '')) : '—';

                $existingQuery = ProductVariant::query()
                    ->where('product_id', $product->id)
                    ->where('variety_code', $varietyCode)
                    ->whereNull('color_id');

                if ($model) {
                    $existingQuery->where('model_list_id', $model->id);
                } else {
                    $existingQuery->whereNull('model_list_id');
                }

                $variant = $existingQuery->first();
                $variantCode = $variant?->variant_code ?: $this->buildVariantCode11((string) $product->code, $modelCode3, $design2, $variant?->id);

                $variantName = trim(collect([
                    $product->name,
                    $model?->model_name,
                    $designIndex > 0 ? $designTitle : null,
                ])->filter(fn ($part) => trim((string) $part) !== '' && $part !== '—')->implode(' '));

                $payload = [
                    'variant_name' => $variantName !== '' ? $variantName : $product->name,
                    'model_list_id' => $model?->id,
                    'variety_name' => $designTitle,
                    'variety_code' => $varietyCode,
                    'variant_code' => $variantCode,
                    'is_active' => true,
                ];

                if ($variant) {
                    $variant->update($payload);
                    continue;
                }

                ProductVariant::create(array_merge($payload, [
                    'product_id' => $product->id,
                    'is_active' => true,
                    'sell_price' => $defaultSellPrice,
                    'buy_price' => $defaultBuyPrice,
                    'stock' => 0,
                    'reserved' => 0,
                ]));
            }
        }
    }

    private function designTitle(int $index, string $note): string
    {
        $title = $this->normalizeDesignTitle($note);

        return $title !== '' ? $title : ('طرح ' . $index);
    }

    private function normalizeDesignTitle(?string $title): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $title));
    }

    private function normalizeDesignNotes(array $notes)
    {
        return collect($notes)
            ->map(fn ($note) => $this->normalizeDesignTitle(is_scalar($note) ? (string) $note : ''))
            ->values();
    }

    private function sanitizeUpdateVariants(Request $request, Product $product): void
    {
        if (!$request->has('variants')) {
            return;
        }

        $productVariantIds = $product->variants()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $allowNewVariants = $request->boolean('generate_new_variants');

        $cleanVariants = collect($request->input('variants', []))
            ->filter(function ($variant) use ($productVariantIds, $allowNewVariants) {
                if (!is_array($variant)) {
                    return false;
                }

                $isDeleted = filter_var($variant['_delete'] ?? $variant['deleted'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $isTemplate = filter_var($variant['_template'] ?? $variant['is_template'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $isDisabled = filter_var($variant['_disabled'] ?? $variant['disabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

                if ($isDeleted || $isTemplate || $isDisabled) {
                    return false;
                }

                $variantId = $variant['id'] ?? null;
                $hasRealId = filled($variantId);

                if ($hasRealId && !in_array((int) $variantId, $productVariantIds, true)) {
                    return false;
                }

                $hasAnyValue = filled($variant['variant_name'] ?? null)
                    || filled($variant['variety_name'] ?? null)
                    || filled($variant['variety_code'] ?? null)
                    || filled($variant['variant_code'] ?? null)
                    || filled($variant['model_list_id'] ?? null)
                    || filled($variant['variety_id'] ?? null);

                $hasActiveFlag = array_key_exists('is_active', $variant) || array_key_exists('enabled', $variant);
                $isActive = filter_var($variant['is_active'] ?? $variant['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

                if (!$hasRealId && !$allowNewVariants) {
                    return false;
                }

                if (!$hasRealId && $hasActiveFlag && !$isActive) {
                    return false;
                }

                return $hasRealId || ($allowNewVariants && ($hasAnyValue || $isActive));
            })
            ->map(function ($variant) {
                unset($variant['_delete'], $variant['deleted'], $variant['_template'], $variant['is_template'], $variant['_disabled'], $variant['disabled'], $variant['enabled']);

                foreach (['variant_name', 'variety_name', 'variety_code'] as $field) {
                    if (array_key_exists($field, $variant) && is_scalar($variant[$field])) {
                        $variant[$field] = $this->normalizeDesignTitle((string) $variant[$field]);
                    }
                }

                return $variant;
            })
            ->values()
            ->all();

        $request->merge([
            'variants' => $cleanVariants,
        ]);
    }

    private function safeProductsReturnUrl(?string $returnTo): string
    {
        if (!$returnTo) {
            return route('products.index');
        }

        if (str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//')) {
            return $returnTo;
        }

        $appHost = parse_url(config('app.url'), PHP_URL_HOST);
        $returnHost = parse_url($returnTo, PHP_URL_HOST);

        if ($returnHost && $appHost && $returnHost === $appHost) {
            return $returnTo;
        }

        if ($returnHost && $returnHost === request()->getHost()) {
            return $returnTo;
        }

        return route('products.index');
    }

    public function destroy(Product $product)
    {
        $imagePath = $product->image_path;

        $product->delete();

        if ($imagePath) {
            Storage::disk('public')->delete($imagePath);
        }

        return redirect()->route('products.index')->with('success', 'کالا حذف شد.');
    }

    public function priceList(Request $request)
    {
        $query = Product::query()->with('category');

        if ($request->filled('q')) {
            $q = trim((string) $request->q);
            $query->where(fn ($qq) => $qq->where('name', 'like', "%{$q}%")
                ->orWhere('code', 'like', "%{$q}%")
                ->orWhere('short_barcode', 'like', "%{$q}%"));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $products = $query->orderBy('name')->paginate(50)->withQueryString();

        return redirect()->route('admin.product-exports.index', request()->query());
    }

    public function syncCrm(CrmProductSyncService $service)
    {
        $res = $service->sync();

        return redirect()
            ->route('products.index')
            ->with(
                'success',
                "همگام‌سازی انجام شد. ایجاد: {$res['created']} | بروزرسانی: {$res['updated']} | خطا: {$res['failed']}"
            );
    }

    private function storeProductImage(Request $request): ?string
    {
        $file = $request->file('image');

        if (!$file) {
            return null;
        }

        if (is_array($file) || !$file instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'image' => 'فایل عکس کالا معتبر نیست؛ لطفاً فقط یک تصویر انتخاب کنید.',
            ]);
        }

        if (!$file->isValid()) {
            throw ValidationException::withMessages([
                'image' => $this->productImageUploadErrorMessage($file->getError()),
            ]);
        }

        $filePath = $file->getRealPath() ?: $file->getPathname();

        if (!is_string($filePath) || trim($filePath) === '' || !is_file($filePath)) {
            throw ValidationException::withMessages([
                'image' => 'مسیر فایل آپلودشده خالی است؛ لطفاً عکس را دوباره انتخاب و ارسال کنید.',
            ]);
        }

        if (($file->getSize() ?: 0) > 4096 * 1024) {
            throw ValidationException::withMessages([
                'image' => 'حجم عکس کالا نباید بیشتر از ۴ مگابایت باشد.',
            ]);
        }

        if (@getimagesize($filePath) === false) {
            throw ValidationException::withMessages([
                'image' => 'فایل انتخاب‌شده تصویر معتبر نیست.',
            ]);
        }

        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg');
        $extension = preg_replace('/[^a-z0-9]+/', '', $extension) ?: 'jpg';
        $filename = now()->format('YmdHis') . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $storedPath = 'products/' . $filename;
        $stream = @fopen($filePath, 'rb');

        if ($stream === false) {
            throw ValidationException::withMessages([
                'image' => 'خواندن فایل آپلودشده ناموفق بود؛ لطفاً عکس را دوباره انتخاب و ارسال کنید.',
            ]);
        }

        try {
            $stored = Storage::disk('public')->put($storedPath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (!$stored) {
            throw ValidationException::withMessages([
                'image' => 'ذخیره عکس کالا ناموفق بود؛ لطفاً دوباره تلاش کنید.',
            ]);
        }

        return $storedPath;
    }

    private function productImageUploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'حجم عکس کالا بیش از حد مجاز سرور است؛ لطفاً عکس کوچک‌تری انتخاب کنید.',
            UPLOAD_ERR_PARTIAL => 'آپلود عکس کامل انجام نشد؛ لطفاً دوباره تلاش کنید.',
            UPLOAD_ERR_NO_FILE => 'هیچ عکسی برای کالا انتخاب نشده است.',
            UPLOAD_ERR_NO_TMP_DIR => 'پوشه موقت آپلود روی سرور در دسترس نیست.',
            UPLOAD_ERR_CANT_WRITE => 'امکان نوشتن فایل آپلودشده روی سرور وجود ندارد.',
            UPLOAD_ERR_EXTENSION => 'آپلود عکس توسط تنظیمات سرور متوقف شد.',
            default => 'فایل عکس کالا معتبر نیست؛ لطفاً یک تصویر سالم با حجم حداکثر ۴ مگابایت انتخاب کنید.',
        };
    }

    private function recalcProductSummary(Product $product): void
    {
        app(ProductVariantStructureService::class)->recalculateProductSummary($product);
    }

    private function normalizeCategory2(?string $code): ?string
    {
        $c = trim((string) ($code ?? ''));
        if ($c === '') {
            return null;
        }
        if (!preg_match('/^\d{2}$/', $c)) {
            return null;
        }

        return $c;
    }

    private function normalizeModel3(?string $code): string
    {
        $c = preg_replace('/\D+/', '', (string) ($code ?? ''));
        $c = substr($c, 0, 3);

        return str_pad($c, 3, '0', STR_PAD_LEFT);
    }

    private function nextProductSeq4(): string
    {
        $next = $this->nextProductSeqNumber(true);
        if ($next > 9999) {
            abort(422, 'حداکثر 9999 کالا پشتیبانی می‌شود (PPPP چهار رقمی است).');
        }

        return str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function nextProductSeqNumber(bool $lockProducts): int
    {
        $productQuery = DB::table('products')
            ->selectRaw("MAX(CAST(COALESCE(NULLIF(short_barcode,''), SUBSTRING(code, 3, 4)) AS UNSIGNED)) as mx");

        if ($lockProducts) {
            $productQuery->lockForUpdate();
        }

        $productMax = (int) $productQuery->value('mx');

        $variantQuery = DB::table('product_variants')
            ->whereNotNull('variant_code')
            ->where('variant_code', '<>', '')
            ->selectRaw("MAX(CAST(SUBSTRING(variant_code, 3, 4) AS UNSIGNED)) as mx");

        if ($lockProducts) {
            $variantQuery->lockForUpdate();
        }

        $variantMax = (int) $variantQuery->value('mx');
        $next = max($productMax, $variantMax) + 1;

        return max(1, $next);
    }

    private function buildVariantCode11(string $productCode6, string $model3, string $design2, ?int $ignoreId = null): string
    {
        $code = $productCode6 . $model3 . $design2;

        $exists = ProductVariant::query()
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('variant_code', $code)
            ->exists();

        if ($exists) {
            $existingVariant = ProductVariant::query()
                ->with('product:id,name,code')
                ->where('variant_code', $code)
                ->first();
            $productName = $existingVariant?->product?->name ?: 'کالای دیگر';
            $productCode = $existingVariant?->product?->code ?: '---';

            abort(422, "کد تنوع تکراری است: {$code}. این کد قبلاً برای «{$productName}» با کد کالا {$productCode} ثبت شده است؛ لطفاً دوباره ثبت را بزنید تا کد جدید ساخته شود.");
        }

        return $code;
    }
}
