<?php

namespace App\Services;

use App\Exceptions\StockCountFinalizeValidationException;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockCountDocument;
use App\Models\StockCountDocumentHistory;
use App\Models\StockCountDocumentItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Support\StockCountQuantityCalculator as Quantities;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockCountDocumentService
{
    public function __construct(
        private readonly ReservationQueryService $reservations,
        private readonly BundleComponentStocktakeService $bundleStocktake,
    )
    {
    }

    public function centralWarehouse(): Warehouse
    {
        return Warehouse::firstOrCreate(['type' => 'central', 'name' => 'انبار مرکزی'], ['is_active' => true]);
    }

    public function activeReserved(ProductVariant $variant): int
    {
        return (int) ($this->reservations->quantitiesByVariant(
            productId: (int) $variant->product_id,
            variantIds: [(int) $variant->id],
        )->get((int) $variant->id, 0));
    }

    /** Bind a batch confirmation to the exact saved counts shown for review. */
    public function reviewToken(StockCountDocument $document): string
    {
        $rows = $document->items()->orderBy('product_variant_id')->get()->map(static fn ($item) => [
            (int) $item->product_variant_id,
            $item->actual_quantity === null ? null : (int) $item->actual_quantity,
            (int) $item->system_available_at_start, (int) $item->reserved_at_start,
            $item->description,
        ])->all();
        return hash('sha256', json_encode([
            (int) $document->id, (int) $document->product_id,
            $document->document_date?->format('Y-m-d'), $document->description, $rows,
        ], JSON_THROW_ON_ERROR));
    }

    public function createProductDraft(array $payload, int $userId): StockCountDocument
    {
        return app(InventoryCountFreezeService::class)->forStocktake(fn()=>DB::transaction(function () use ($payload, $userId) {
            app(InventoryCountFreezeService::class)->fence();
            $warehouse = $this->centralWarehouse();
            $product = Product::query()->findOrFail((int) $payload['product_id']);
            $this->assertCountableProduct($product);
            $this->validateCounts((int) $product->id, $payload);
            $this->assertNoSiteHolds((int) $product->id);
            Warehouse::query()->whereKey($warehouse->id)->lockForUpdate()->first();
            $document = StockCountDocument::create([
                'document_number' => $this->nextDocumentNumber(), 'type' => 'product', 'warehouse_id' => $warehouse->id,
                'product_id' => $product->id, 'document_date' => $payload['document_date'], 'status' => 'draft',
                'description' => $payload['description'] ?? null, 'created_by' => $userId, 'updated_by' => $userId,
            ]);
            $actuals = $payload['actual_quantities'] ?? [];
            $notes = $payload['notes'] ?? [];
            $rows = [];
            ProductVariant::query()->where('product_id', $product->id)->orderBy('id')->chunkById(500, function ($variants) use (&$rows, $document, $warehouse, $product, $actuals, $notes) {
                $bundleQuantities = $this->bundleStocktake->quantitiesByComponent($variants->pluck('id')->all());
                foreach ($variants as $variant) {
                    $stock = $this->lockOrCreateStock((int) $warehouse->id, (int) $product->id, (int) $variant->id, false);
                    $available = (int) $stock->quantity;
                    $reserved = $this->activeReserved($variant);

                    $actual = array_key_exists($variant->id, $actuals)
                              && $actuals[$variant->id] !== ''
                              && $actuals[$variant->id] !== null
                        ? (int) $actuals[$variant->id]
                        : null;

                    $rows[] = $this->itemPayload(
                        $document,
                        $warehouse,
                        $product,
                        $variant,
                        $stock,
                        $available,
                        $reserved,
                        $actual,
                        $notes[$variant->id] ?? null,
                        $bundleQuantities[$variant->id]['free'] ?? 0,
                        $bundleQuantities[$variant->id]['reserved'] ?? 0,
                        $bundleQuantities[$variant->id]['site_held'] ?? 0
                    );
                }
                if (count($rows) >= 500) { StockCountDocumentItem::insert($rows); $rows = []; }
            });
            if ($rows) StockCountDocumentItem::insert($rows);
            $this->refreshSummary($document);
            $this->addHistory($document->id, 'created', null, ['status' => 'draft', 'type' => 'product'], 'ایجاد پیش‌نویس انبارگردانی محصولی', $userId);
            return $document->fresh(['warehouse','product','items.variant']);
        }));
    }

    public function updateProductDraft(StockCountDocument $document, array $payload, int $userId): StockCountDocument
    {
        if ($document->status !== 'draft') abort(422, 'فقط سند پیش‌نویس قابل ویرایش است.');
        return DB::transaction(function () use ($document, $payload, $userId) {
            $document = StockCountDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($document->status !== 'draft') abort(422, 'فقط سند پیش‌نویس قابل ویرایش است.');
            $this->assertExpectedReview($document, $payload);
            $this->assertCountableProduct(Product::query()->findOrFail($document->product_id));
            $this->validateCounts((int) $document->product_id, $payload);
            $currentVariantIds = ProductVariant::query()->where('product_id', $document->product_id)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
            $savedVariantIds = $document->items()->orderBy('product_variant_id')->pluck('product_variant_id')->map(fn ($id) => (int) $id)->all();
            if ($currentVariantIds !== $savedVariantIds) {
                throw ValidationException::withMessages(['actual_quantities' => 'تنوع‌های این محصول پس از ایجاد سند تغییر کرده‌اند؛ شمارش‌های قبلی حفظ شد. برای فهرست جدید سند تازه بسازید.']);
            }
            $actuals = $payload['actual_quantities'] ?? [];
            $notes = $payload['notes'] ?? [];
            $document->update(['document_date' => $payload['document_date'], 'description' => $payload['description'] ?? null, 'updated_by' => $userId]);
            foreach ($document->items()->lockForUpdate()->get() as $item) {
                $vid = (int) $item->product_variant_id;
                // A paginated or filtered form must not erase quantities that
                // were saved previously but were not included in this request.
                $updates = [];
                if (array_key_exists($vid, $actuals)) {
                    $updates['actual_quantity'] = $actuals[$vid] === '' || $actuals[$vid] === null
                        ? null : (int) $actuals[$vid];
                }
                if (array_key_exists($vid, $notes)) $updates['description'] = $notes[$vid];
                if ($updates !== []) $item->update($updates);
            }
            $this->refreshSummary($document);
            $this->addHistory($document->id, 'updated', null, ['status' => 'draft'], 'ذخیره پیش‌نویس انبارگردانی محصولی', $userId);
            return $document->fresh(['warehouse','product','items.variant']);
        });
    }

    /** One durable document contains the entire category snapshot, with unresolved rows remaining NULL until explicitly counted. */
    public function saveCategoryDraft(array $payload, int $userId): StockCountDocument
    {
        return app(InventoryCountFreezeService::class)->forStocktake(fn () => DB::transaction(function () use ($payload, $userId) {
            app(InventoryCountFreezeService::class)->fence();
            $warehouse = $this->centralWarehouse();
            Warehouse::query()->whereKey($warehouse->id)->lockForUpdate()->first();
            $category = Category::query()->findOrFail($payload['category_id']);
            $document = !empty($payload['document_id'])
                ? StockCountDocument::query()->lockForUpdate()->findOrFail($payload['document_id']) : null;
            if (!$document && ($existing = StockCountDocument::query()->where('type','category')->where('category_id',$category->id)
                ->where('status','draft')->whereNull('superseded_by_id')->lockForUpdate()->first())) {
                abort(409,'برای این دسته پیش‌نویس '.$existing->document_number.' وجود دارد؛ همان سند را از آرشیو ادامه دهید. هیچ سند دیگری ساخته نشد.');
            }
            if ($document) {
                $this->assertNotSuperseded($document);
                abort_unless($document->type === 'category' && $document->status === 'draft'
                    && (int) $document->category_id === (int) $category->id, 422, 'فقط پیش‌نویس همین دسته قابل ادامه است.');
                abort_unless(!empty($payload['expected_review_token']), 409, 'نسخهٔ پیش‌نویس مشخص نیست؛ سند را دوباره باز کنید.');
                $this->assertExpectedReview($document, $payload);
            }
            $variants = $this->categoryVariantsQuery((int) $category->id)->lockForUpdate()->get();
            abort_if($variants->isEmpty(), 422, 'این دسته کالای قابل انبارگردانی ندارد.');
            abort_if($variants->count() > 50000, 422, 'برای این تعداد تنوع، زیردستهٔ کوچک‌تری انتخاب کنید.');
            $ids = $variants->pluck('id')->map(fn ($id) => (int) $id)->all();
            $payload = $this->validateCategoryCounts($ids, $payload);
            if ($document && $document->items()->orderBy('product_variant_id')->pluck('product_variant_id')->map(fn ($id) => (int) $id)->all() !== $ids) {
                throw ValidationException::withMessages(['stocktake'=>'فهرست کالاهای دسته پس از شروع شمارش تغییر کرده است؛ شمارش قبلی حفظ شد. برای محدودهٔ جدید سند تازه بسازید.']);
            }
            $productIds = $variants->pluck('product_id')->unique()->values()->all();
            $this->assertNoSiteHoldsForProducts($productIds);
            if (!$document) {
                $document = StockCountDocument::create([
                    'document_number'=>$this->nextDocumentNumber(), 'type'=>'category', 'product_id'=>null,
                    'category_id'=>$category->id, 'scope_name'=>$category->name, 'warehouse_id'=>$warehouse->id,
                    'document_date'=>now('Asia/Tehran')->toDateString(), 'status'=>'draft',
                    'description'=>'انبارگردانی یکپارچهٔ دسته '.$category->name, 'created_by'=>$userId, 'updated_by'=>$userId,
                ]);
                $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
                $reservedByVariant = $this->reservations->quantitiesByVariant(variantIds: $ids);
                // Capture protected bundle allocations and Site holds as part of the same physical snapshot.
                $bundleQuantities = $this->bundleStocktake->quantitiesByComponent($ids);
                $stocks = WarehouseStock::query()->where('warehouse_id',$warehouse->id)->whereIn('product_variant_id',$ids)
                    ->orderBy('product_variant_id')->lockForUpdate()->get()->keyBy('product_variant_id');
                $rows = [];
                foreach ($variants as $variant) {
                    $stock = $stocks->get($variant->id) ?? $this->lockOrCreateStock((int) $warehouse->id, (int) $variant->product_id, (int) $variant->id, true);
                    $rows[] = $this->itemPayload($document, $warehouse, $products[$variant->product_id], $variant, $stock,
                        (int) $stock->quantity, (int) $reservedByVariant->get($variant->id,0),
                        array_key_exists($variant->id, $payload['actual_quantities'] ?? [])
                            && $payload['actual_quantities'][$variant->id] !== ''
                            && $payload['actual_quantities'][$variant->id] !== null
                                ? (int) $payload['actual_quantities'][$variant->id] : null,
                        $payload['notes'][$variant->id] ?? null,
                        (int) ($bundleQuantities[$variant->id]['free'] ?? 0),
                        (int) ($bundleQuantities[$variant->id]['reserved'] ?? 0),
                        (int) ($bundleQuantities[$variant->id]['site_held'] ?? 0));
                    if (count($rows) >= 500) { StockCountDocumentItem::insert($rows); $rows = []; }
                }
                if ($rows) StockCountDocumentItem::insert($rows);
                $action = 'created';
            } else {
                foreach ($document->items()->lockForUpdate()->get() as $item) {
                    $updates = [];
                    if (array_key_exists($item->product_variant_id, $payload['actual_quantities'] ?? [])) {
                        $value = $payload['actual_quantities'][$item->product_variant_id];
                        $updates['actual_quantity'] = $value === null || $value === '' ? null : (int) $value;
                    }
                    if (array_key_exists($item->product_variant_id, $payload['notes'] ?? [])) $updates['description'] = $payload['notes'][$item->product_variant_id];
                    if ($updates) $item->update($updates);
                }
                $document->update(['updated_by'=>$userId]);
                $action = 'updated';
            }
            $this->refreshSummary($document);
            $this->addHistory($document->id, $action, null, ['status'=>'draft','type'=>'category'], 'ذخیرهٔ سند واحد انبارگردانی دسته', $userId);
            return $document->fresh(['warehouse','category','items.variant']);
        }, 3));
    }


    private function categoryVariantsQuery(int $categoryId): \Illuminate\Database\Eloquent\Builder
    {
        $products = Product::query()->select('id')->whereIn('category_id', Category::selfAndDescendantIds($categoryId));
        if (\Illuminate\Support\Facades\Schema::hasColumn('products', 'is_bundle') && \Illuminate\Support\Facades\Schema::hasColumn('products', 'bundle_stock_mode')) {
            $products->where(fn ($q) => $q->where('is_bundle', false)->orWhereNull('is_bundle')->orWhere('bundle_stock_mode','<>','automatic')->orWhereNull('bundle_stock_mode'));
        }
        return ProductVariant::query()->whereIn('product_id', $products)->orderBy('id');
    }

    private function validateCategoryCounts(array $allowedIds, array $payload): array
    {
        \Illuminate\Support\Facades\Validator::make($payload, [
            'actual_quantities'=>['sometimes','array','max:50000'], 'actual_quantities.*'=>['nullable','integer','min:0','max:2147483647'],
            'notes'=>['sometimes','array','max:50000'], 'notes.*'=>['nullable','string','max:1000'],
        ])->validate();
        $allowed = array_fill_keys($allowedIds, true);
        foreach (array_unique(array_merge(array_keys($payload['actual_quantities'] ?? []), array_keys($payload['notes'] ?? []))) as $id) {
            if (!ctype_digit((string) $id) || (string) (int) $id !== (string) $id || !isset($allowed[(int) $id])) {
                throw ValidationException::withMessages(['actual_quantities'=>'تنوع واردشده خارج از محدودهٔ سند است؛ هیچ تغییری ذخیره نشد.']);
            }
        }
        return $payload;
    }


    public function finalize(StockCountDocument $document, int $userId, bool $confirmEmptyAsZero = false, array $payload = []): StockCountDocument
    {
        // Explicit zero is a valid count; an empty value is always unresolved.
        // The legacy confirmation flag is intentionally ignored for safety.
        return app(InventoryCountFreezeService::class)->forStocktake(fn()=>DB::transaction(function () use ($document, $userId, $payload) {
            app(InventoryCountFreezeService::class)->fence();
            $document = StockCountDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($document->status !== 'draft') abort(422, 'فقط سند پیش‌نویس قابل نهایی‌سازی است.');
            $this->assertNotSuperseded($document);
            abort_unless(in_array($document->type ?? 'product', ['product','category'], true), 422, 'نوع سند قابل اعمال نیست.');
            $categoryDocument = $document->type === 'category';
            $this->assertExpectedReview($document, $payload);
            $productIds = $categoryDocument
                ? $document->items()->distinct()->pluck('product_id')->map(fn ($id) => (int) $id)->all()
                : [(int) $document->product_id];
            foreach (Product::query()->whereIn('id', $productIds)->get() as $product) {
                $this->assertCountableProduct($product);
            }
            if ($categoryDocument) {
                $payload = $this->validateCategoryCounts($document->items()->pluck('product_variant_id')->all(), $payload);
            } else {
                $this->validateCounts((int) $document->product_id, $payload);
            }
            $this->assertNoSiteHoldsForProducts($productIds);
            $warehouse = $this->centralWarehouse();
            if ((int) $document->warehouse_id !== (int) $warehouse->id) abort(422, 'انبار سند با انبار مرکزی پروژه همخوان نیست.');
            $items = $document->items()->lockForUpdate()->orderBy('product_variant_id')->get();
            $actuals = $payload['actual_quantities'] ?? [];
            $notes = $payload['notes'] ?? [];
            foreach ($items as $item) {
                $vid = (int) $item->product_variant_id;
                if (array_key_exists($vid, $actuals) || array_key_exists((string) $vid, $actuals)) {
                    $rawActual = $actuals[$vid] ?? $actuals[(string) $vid];
                    $item->actual_quantity = $rawActual === '' || $rawActual === null ? null : (int) $rawActual;
                }
                if (array_key_exists($vid, $notes) || array_key_exists((string) $vid, $notes)) {
                    $item->description = $notes[$vid] ?? $notes[(string) $vid];
                }
            }
            $unresolved = $items->filter(static fn ($item) => $item->actual_quantity === null);
            if ($items->isEmpty() || $unresolved->isNotEmpty()) {
                $conflicts = $unresolved->take(30)->map(
                    fn ($item) => $this->finalizeConflict(
                        $item, 'uncounted_variant',
                        'این تنوع هنوز شمارش یا تعیین تکلیف نشده است؛ برای موجودی صفر، عدد ۰ را صریحاً وارد کنید.',
                        null, (int)$item->reserved_at_start, (int)$item->system_available_at_start
                    )
                )->values()->all();
                if ($items->isEmpty()) {
                    $conflicts[] = [
                        'variant_id'=>null,'variant_name'=>$document->product?->name ?: 'محصول سند',
                        'reason_code'=>'no_variants','reason'=>'سند هیچ تنوعی برای شمارش ندارد.',
                    ];
                }
                throw new StockCountFinalizeValidationException(
                    'ثبت نهایی تا تعیین تکلیف همه تنوع‌ها ممنوع است. '. $unresolved->count() .' تنوع بدون شمارش مانده است.',
                    $conflicts
                );
            }
            $variantIds = $items->pluck('product_variant_id')->map(fn($v)=>(int)$v)->all();
            $currentIds = ($categoryDocument ? $this->categoryVariantsQuery((int) $document->category_id) : ProductVariant::query()->where('product_id', (int) $document->product_id)->orderBy('id'))->pluck('id')->map(fn($v)=>(int)$v)->all();
            $conflicts = [];
            if ($currentIds !== $variantIds) {
                $conflicts[] = [
                    'variant_id' => null,
                    'variant_name' => $categoryDocument ? ($document->scope_name ?: 'دسته سند') : ($document->product?->name ?: 'محصول سند'),
                    'sku' => null,
                    'reason_code' => 'variant_set_changed',
                    'reason' => 'مجموعه تنوع‌های محصول پس از شروع انبارگردانی تغییر کرده است. سند را به‌روزرسانی کنید.',
                    'actual_quantity' => null,
                    'reserved_quantity' => null,
                    'current_quantity' => null,
                ];
            }
            // Lock bundle product rows BEFORE central stock rows, matching the
            // order used by allocation changes and bundle checkout.
            $bundleQuantities = $this->bundleStocktake->quantitiesByComponent($variantIds, true);
            $categoryReservations = $categoryDocument ? $this->reservations->quantitiesByVariant(variantIds: $variantIds) : null;
            $variants = ProductVariant::query()->whereIn('id', $variantIds)->lockForUpdate()->get()->keyBy('id');
            $stocks = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->whereIn('product_variant_id', $variantIds)->lockForUpdate()->get()->keyBy('product_variant_id');
            foreach ($items as $item) if (! $stocks->has((int)$item->product_variant_id)) $stocks[(int)$item->product_variant_id] = $this->lockOrCreateStock($warehouse->id, (int)$item->product_id, (int)$item->product_variant_id, true);
            foreach ($items as $item) {
                $variant = $variants[(int)$item->product_variant_id] ?? null; $stock = $stocks[(int)$item->product_variant_id];
                $reserved = $categoryDocument ? (int) $categoryReservations->get($item->product_variant_id, 0) : ($variant ? $this->activeReserved($variant) : 0);
                $available = (int)$stock->quantity; $actual = (int)$item->actual_quantity;
                if ($available !== (int)$item->system_available_at_start) $conflicts[] = $this->finalizeConflict($item, 'stock_changed', 'موجودی این تنوع پس از شروع شمارش تغییر کرده است. اطلاعات را تازه‌سازی کنید.', $actual, $reserved, $available);
                if ($item->warehouse_stock_updated_at_start && $stock->updated_at && ! $item->warehouse_stock_updated_at_start->equalTo($stock->updated_at)) {
                    $conflicts[] = $this->finalizeConflict($item, 'stock_changed_since_snapshot', 'موجودی این تنوع پس از شروع شمارش جابه‌جا شده است؛ حتی اگر دوباره به همان عدد برگشته باشد، شمارش باید بازبینی شود.', $actual, $reserved, $available);
                }
                if ($reserved !== (int)$item->reserved_at_start) $conflicts[] = $this->finalizeConflict($item, 'reservation_changed', 'رزرو فعال این تنوع پس از شروع شمارش تغییر کرده است. اطلاعات را تازه‌سازی کنید.', $actual, $reserved, $available);
                $bundleFree = (int) ($bundleQuantities[(int)$item->product_variant_id]['free'] ?? 0);
                $bundleReserved = (int) ($bundleQuantities[(int)$item->product_variant_id]['reserved'] ?? 0);
                $siteHeld = (int) ($bundleQuantities[(int)$item->product_variant_id]['site_held'] ?? 0);
                if ($siteHeld !== (int)$item->site_held_at_start) {
                    $conflicts[] = $this->finalizeConflict($item, 'site_hold_changed', 'رزرو فیزیکی سایت پس از شروع شمارش تغییر کرده است. سند جدید تهیه کنید.', $actual, $reserved, $available);
                }
                if ($bundleFree !== (int)$item->bundle_free_at_start || $bundleReserved !== (int)$item->bundle_reserved_at_start) {
                    $conflicts[] = $this->finalizeConflict($item, 'bundle_allocation_changed', 'سهم آزاد یا رزروشده سبد پس از شروع شمارش تغییر کرده است. سند جدید تهیه کنید.', $actual, $reserved, $available);
                }
                if (Quantities::availableAfterCount($actual, $reserved, $bundleFree, $bundleReserved, $siteHeld) < 0) {
                    $conflicts[] = $this->finalizeConflict($item, 'actual_below_protected', 'موجودی واقعی کمتر از مجموع رزرو عادی، سهم سبد و رزرو سایت است.', $actual, $reserved, $available);
                }
            }
            if ($conflicts) throw new StockCountFinalizeValidationException('امکان ثبت نهایی سند وجود ندارد. موارد مشخص‌شده را اصلاح کنید.', $conflicts);
            foreach ($items as $item) {
                $stock = $stocks[(int)$item->product_variant_id]; $reserved = (int)$item->reserved_at_start; $actual = $item->actual_quantity === null ? 0 : (int)$item->actual_quantity;
                $newAvailable = Quantities::availableAfterCount($actual, $reserved,
                    (int)$item->bundle_free_at_start, (int)$item->bundle_reserved_at_start, (int)$item->site_held_at_start);
                $diff = $actual - (int)$item->expected_physical_at_start; $before = (int)$stock->quantity;
                $stock->update(['quantity' => $newAvailable]);
                WarehouseStockService::syncVariantStockFromCentral((int)$item->product_variant_id);
                $item->update(['actual_quantity'=>$actual, 'new_available'=>$newAvailable, 'difference_quantity'=>$diff, 'system_quantity'=>(int)$item->system_available_at_start]);
                if ($diff !== 0) StockMovement::create(['product_id'=>(int)$item->product_id,'product_variant_id'=>(int)$item->product_variant_id,'warehouse_id'=>(int)$warehouse->id,'user_id'=>$userId,'type'=>$diff>0?'in':'out','reason'=>'adjustment','transaction_type'=>'stocktake_adjustment','quantity'=>abs($newAvailable-$before),'stock_before'=>$before,'stock_after'=>$newAvailable,'note'=>'تعدیل ناشی از انبارگردانی سند '.$document->document_number,'reference'=>$document->document_number,'reference_type'=>'stock_count_document','reference_id'=>$document->id]);
            }
            foreach ($productIds as $productId) WarehouseStockService::syncProductStockFromCentral((int)$productId);
            $this->refreshSummary($document);
            $document->update(['status'=>'finalized','finalized_by'=>$userId,'finalized_at'=>now(),'updated_by'=>$userId]);
            $this->addHistory($document->id, 'finalized', null, ['status'=>'finalized'], $categoryDocument ? 'اعمال سند واحد انبارگردانی دسته در انبار مرکزی' : 'اعمال انبارگردانی محصولی در انبار مرکزی', $userId);
            DB::afterCommit(fn() => logger()->info('سند انبارگردانی اعمال شد', ['document_id'=>$document->id,'document_number'=>$document->document_number]));
            return $document->fresh(['warehouse','product','category','items.variant','creator','finalizer']);
        }));
    }

    public function cancel(StockCountDocument $document, int $userId, ?string $reason = null): StockCountDocument
    {
        return DB::transaction(function () use ($document, $userId, $reason) {
            $document = StockCountDocument::query()->lockForUpdate()->findOrFail($document->id);
            $this->assertNotSuperseded($document);
            if ($document->status === 'cancelled') return $document;
            if ($document->status !== 'draft') abort(422, 'لغو سند اعمال‌شده مجاز نیست.');
            $document->update(['status'=>'cancelled','cancelled_by'=>$userId,'cancelled_at'=>now(),'cancel_reason'=>$reason,'updated_by'=>$userId]);
            $this->addHistory($document->id, 'cancelled', null, ['status'=>'cancelled'], 'لغو پیش‌نویس انبارگردانی', $userId);
            return $document->fresh(['warehouse','product','items.variant']);
        });
    }

    public function variantsPage(int $productId, ?StockCountDocument $document = null, int $page = 1, int $limit = 100, ?string $q = null): array
    {
        $query = $document ? $document->items()->with('variant')->orderBy('product_variant_id') : ProductVariant::query()->where('product_id',$productId)->orderBy('id');
        if ($q) $query->where(function($x) use($q,$document){ $cols=$document?['variant_name_snapshot','sku_snapshot']:['variant_name','variant_code','variety_name','variety_code']; foreach($cols as $c) $x->orWhere($c,'like','%'.$q.'%'); });
        $p = $query->paginate($limit, ['*'], 'page', $page);
        $warehouse = $this->centralWarehouse();
        $bundleQuantities = $document ? [] : $this->bundleStocktake->quantitiesByComponent($p->getCollection()->pluck('id')->all());
        $rows = $p->getCollection()->map(function($row) use($document,$warehouse,$productId,$bundleQuantities){
            if ($document) return $this->itemResource($row);
            $stock = WarehouseStock::query()->where('warehouse_id',$warehouse->id)->where('product_variant_id',$row->id)->first(); $reserved=$this->activeReserved($row); $available=(int)($stock?->quantity ?? 0);
            $bundleFree = $bundleQuantities[$row->id]['free'] ?? 0;
            $bundleReserved = $bundleQuantities[$row->id]['reserved'] ?? 0;
            $siteHeld = $bundleQuantities[$row->id]['site_held'] ?? 0;
            return ['variant_id'=>(int)$row->id,'name'=>$row->variant_name ?: $row->variety_name ?: ('#'.$row->id),'sku'=>$row->variant_code ?: $row->variety_code,'barcode'=>$row->variant_code,'sales_enabled'=>(bool)$row->sales_enabled && (bool)$row->is_active,'system_available'=>$available,'active_reserved'=>$reserved,'bundle_free'=>$bundleFree,'bundle_reserved'=>$bundleReserved,'site_held'=>$siteHeld,'expected_physical'=>Quantities::expectedPhysical($available, $reserved, $bundleFree, $bundleReserved, $siteHeld),'stock_updated_at'=>$stock?->updated_at?->toDateTimeString(),'actual_physical'=>null,'new_available'=>null,'difference'=>null,'note'=>null];
        })->values();
        return ['data'=>$rows,'meta'=>['current_page'=>$p->currentPage(),'last_page'=>$p->lastPage(),'has_more'=>$p->hasMorePages(),'total'=>$p->total()]];
    }

    private function finalizeConflict($item, string $reasonCode, string $reason, ?int $actual, ?int $reserved, ?int $current): array
    {
        return [
            'variant_id' => (int) $item->product_variant_id,
            'variant_name' => $item->variant_name_snapshot ?: ('#'.$item->product_variant_id),
            'sku' => $item->sku_snapshot,
            'reason_code' => $reasonCode,
            'reason' => $reason,
            'actual_quantity' => $actual,
            'reserved_quantity' => $reserved,
            'current_quantity' => $current,
            'expected_quantity' => (int) $item->expected_physical_at_start,
            'start_reserved_quantity' => (int) $item->reserved_at_start,
            'start_quantity' => (int) $item->system_available_at_start,
        ];
    }


    private function itemPayload(
        $document,
        $warehouse,
        $product,
        $variant,
        $stock,
        int $available,
        int $reserved,
        ?int $actual,
        ?string $note,
        int $bundleFree,
        int $bundleReserved,
        int $siteHeld
    ): array {
        $expected = Quantities::expectedPhysical(
            $available,
            $reserved,
            $bundleFree,
            $bundleReserved,
            $siteHeld
        );

        return [
            'document_id' => $document->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'warehouse_stock_id' => $stock->id,
            'product_name_snapshot' => $product->name,
            'variant_name_snapshot' => $variant->variant_name
                ?: $variant->variety_name
                    ?: ('#' . $variant->id),
            'sku_snapshot' => $variant->variant_code
                ?: $variant->variety_code,
            'system_quantity' => $available,
            'system_available_at_start' => $available,
            'reserved_at_start' => $reserved,
            'bundle_free_at_start' => $bundleFree,
            'bundle_reserved_at_start' => $bundleReserved,
            'site_held_at_start' => $siteHeld,
            'expected_physical_at_start' => $expected,
            'actual_quantity' => $actual,
            'new_available' => null,
            'difference_quantity' => $actual === null
                ? 0
                : $actual - $expected,
            'warehouse_stock_updated_at_start' => $stock->updated_at,
            'stock_updated_at_start' => $variant->updated_at,
            'description' => $note,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function itemResource($item): array
    {
        $actual = $item->actual_quantity;
        $reserved = (int) $item->reserved_at_start;
        $bundleFree = (int) $item->bundle_free_at_start;
        $bundleReserved = (int) $item->bundle_reserved_at_start;
        $siteHeld = (int) $item->site_held_at_start;

        return [
            'variant_id' => (int) $item->product_variant_id,
            'name' => $item->variant_name_snapshot,
            'sku' => $item->sku_snapshot,
            'barcode' => $item->sku_snapshot,
            'sales_enabled' => (bool) (
                $item->variant?->sales_enabled
                && $item->variant?->is_active
            ),
            'system_available' => (int) $item->system_available_at_start,
            'active_reserved' => $reserved,
            'bundle_free' => $bundleFree,
            'bundle_reserved' => $bundleReserved,
            'site_held' => $siteHeld,
            'expected_physical' => (int) $item->expected_physical_at_start,
            'stock_updated_at' =>
                $item->warehouse_stock_updated_at_start?->toDateTimeString(),
            'actual_physical' => $actual,
            'new_available' => $actual === null
                ? null
                : Quantities::availableAfterCount(
                    (int) $actual,
                    $reserved,
                    $bundleFree,
                    $bundleReserved,
                    $siteHeld
                ),
            'difference' => $actual === null
                ? null
                : $actual - (int) $item->expected_physical_at_start,
            'note' => $item->description,
        ];
    }

    private function validateCounts(int $productId, array $payload): void
    {
        \Illuminate\Support\Facades\Validator::make($payload, [
            'actual_quantities' => ['nullable', 'array'],
            'actual_quantities.*' => [
                'nullable', 'integer', 'min:0', 'max:2147483647'
            ],
            'notes' => ['nullable', 'array'],
            'notes.*' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        $keys = array_unique(array_merge(
            array_keys($payload['actual_quantities'] ?? []),
            array_keys($payload['notes'] ?? [])
        ));

        if ($keys === []) {
            return;
        }

        $allowed = ProductVariant::query()
            ->where('product_id', $productId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($keys as $key) {
            if (
                ! ctype_digit((string) $key)
                || (string) (int) $key !== (string) $key
                || ! in_array((int) $key, $allowed, true)
            ) {
                throw ValidationException::withMessages([
                    'actual_quantities' =>
                        'تنوع واردشده متعلق به این کالا نیست؛ هیچ تغییری اعمال نشد.',
                ]);
            }
        }
    }

    private function assertExpectedReview(StockCountDocument $document, array $payload): void
    {
        if (array_key_exists('expected_review_token', $payload)
            && ! hash_equals($this->reviewToken($document), (string) $payload['expected_review_token'])) {
            abort(409, 'این پیش‌نویس در صفحهٔ دیگری تغییر کرده است؛ نسخهٔ جدید را باز کنید. هیچ شمارشی بازنویسی یا اعمال نشد.');
        }
    }

    private function assertNotSuperseded(StockCountDocument $document): void
    {
        abort_if((bool) $document->superseded_by_id, 409, 'این پیش‌نویس در سند واحد شمارهٔ '.$document->superseded_by_id.' تجمیع شده است؛ شمارش را از همان سند ادامه دهید.');
    }


    private function assertCountableProduct(Product $product): void
    {
        if (
            $product->is_bundle
            && $product->bundle_stock_mode === 'automatic'
        ) {
            throw ValidationException::withMessages([
                'product_id' =>
                    'موجودی بستهٔ خودکار از قطعات آن محاسبه می‌شود؛ قطعات را انبارگردانی کنید.',
            ]);
        }
    }
    private function assertNoSiteHolds(int $productId): void
    {
        $this->assertNoSiteHoldsForProducts([$productId]);
    }

    private function assertNoSiteHoldsForProducts(array $productIds): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('site_stock_holds')
            || ! \Illuminate\Support\Facades\Schema::hasTable('site_stock_hold_lines')) return;
        $hold = \App\Models\SiteStockHold::query()->whereIn('status', ['held', 'payment_confirmed'])
            ->whereHas('lines', fn ($query) => $query->whereIn('product_id', $productIds))
            ->lockForUpdate()->first(['id', 'site_order_id']);
        if ($hold) throw ValidationException::withMessages(['stocktake' => 'این کالا رزرو فعال سفارش سایت شمارهٔ '.$hold->site_order_id.' دارد؛ ابتدا سفارش سایت را تعیین تکلیف کنید. شمارش اعمال نشد.']);
    }


    private function lockOrCreateStock(int $warehouseId,int $productId,int $variantId,bool $lock): WarehouseStock { $q=WarehouseStock::query()->where('warehouse_id',$warehouseId)->where('product_id',$productId)->where('product_variant_id',$variantId); if($lock)$q->lockForUpdate(); $s=$q->first(); return $s ?: WarehouseStock::create(['warehouse_id'=>$warehouseId,'product_id'=>$productId,'product_variant_id'=>$variantId,'quantity'=>0]); }
    private function refreshSummary(StockCountDocument $document): void { $items=$document->items()->get(); $actualTotal=0;$inc=0;$dec=0;$counted=0;$zero=0;$incC=0;$decC=0; foreach($items as $i){$a=$i->actual_quantity; if($a===null)continue; $counted++; $final=(int)$a; if($final===0)$zero++; $actualTotal+=$final; $d=$final-(int)$i->expected_physical_at_start; if($d>0){$inc+=$d;$incC++;} if($d<0){$dec+=abs($d);$decC++;}} $document->update(['variants_count'=>$items->count(),'counted_count'=>$counted,'zeroed_count'=>$zero,'increased_count'=>$incC,'decreased_count'=>$decC,'total_before'=>(int)$items->sum('expected_physical_at_start'),'total_actual'=>$actualTotal,'total_increase'=>$inc,'total_decrease'=>$dec]); }
    private function addHistory(int $documentId,string $actionType,mixed $oldValue,mixed $newValue,?string $description,int $userId): void { StockCountDocumentHistory::create(['document_id'=>$documentId,'action_type'=>$actionType,'old_value'=>$oldValue,'new_value'=>$newValue,'description'=>$description,'done_by'=>$userId,'done_at'=>now()]); }
    private function nextDocumentNumber(): string
    {
        $prefix = 'STC-'.now('Asia/Tehran')->format('Ymd').'-';
        // The warehouse row is the sequence mutex. A locking read is essential:
        // MySQL REPEATABLE READ may already hold an older snapshot before waiting.
        $last = StockCountDocument::query()->where('document_number', 'like', $prefix.'%')
            ->orderByDesc('id')->lockForUpdate()->value('document_number');
        $sequence = $last ? (int) substr($last, strlen($prefix)) : 0;
        return $prefix.str_pad((string) ($sequence + 1), 4, '0', STR_PAD_LEFT);
    }
}
