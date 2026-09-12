<?php

namespace App\Http\Controllers;

use App\Http\Requests\PreinvoiceProductFinderRequest;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\WarehouseStock;
use App\Services\PreinvoiceDraftReservationService;
use App\Services\PreinvoiceProductFinderService;
use App\Services\WarehouseStockService;
use App\Support\IranLocations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\ProductFinderSearchNormalizer;

class PreinvoiceApiController extends Controller
{
    public function __construct(
        private readonly PreinvoiceDraftReservationService $draftReservationService,
        private readonly PreinvoiceProductFinderService $productFinderService,
    ) {}

    public function productFinder(PreinvoiceProductFinderRequest $request)
    {
        return response()->json($this->productFinderService->search($request->validated()));
    }

    public function productFinderCategories(Request $request)
    {
        $data = $request->validate(['parent_id' => ['nullable', 'integer', 'exists:categories,id']]);

        return response()->json(['data' => $this->productFinderService->categories(isset($data['parent_id']) ? (int) $data['parent_id'] : null)]);
    }

    public function products(Request $request)
    {
        $q = ProductFinderSearchNormalizer::normalize($request->query('q'));
        $isPureNumeric = $q !== '' && ctype_digit($q);

        $centralWarehouseId = WarehouseStockService::centralWarehouseId();

        $products = Product::query()
            ->select(['id', 'name', 'sku', 'short_barcode', 'code', 'price'])
            ->where('is_sellable', true)
            ->whereHas('variants', fn ($q) => $q->active()->where('sales_enabled', true)->where('stock', '>', 0))
            ->when($q !== '', function ($query) use ($q, $isPureNumeric) {

                // ✅ اگر عدد وارد شد و طولش <= 4 یعنی PPPP
                if ($isPureNumeric && strlen($q) <= 4) {
                    $pppp = str_pad($q, 4, '0', STR_PAD_LEFT);
                    $query->where('short_barcode', $pppp);

                    return;
                }

                // ✅ اگر طولش 6 بود احتمالاً code محصول (CCPPPP) است
                if ($isPureNumeric && strlen($q) === 6) {
                    $query->where('code', $q);

                    return;
                }

                // ✅ سرچ عمومی روی محصول و تنوع‌ها
                $query->where(function ($qq) use ($q) {
                    $qq->where('name', 'like', "%{$q}%")
                        ->orWhere('short_barcode', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%")
                        ->orWhere('sku', 'like', "%{$q}%")
                        ->orWhereHas('variants', function ($variantQuery) use ($q) {
                            $variantQuery->where('variant_name', 'like', "%{$q}%")
                                ->orWhere('variety_name', 'like', "%{$q}%")
                                ->orWhere('variety_code', 'like', "%{$q}%")
                                ->orWhere('variant_code', 'like', "%{$q}%");
                        });
                });
            })
            ->orderBy('name')
            ->limit(300)
            ->get();

        $stockByProductId = WarehouseStock::query()
            ->select('product_id', DB::raw('SUM(quantity) as quantity'))
            ->where('warehouse_id', $centralWarehouseId)
            ->whereIn('product_id', $products->pluck('id'))
            ->whereNotNull('product_variant_id')
            ->groupBy('product_id')
            ->pluck('quantity', 'product_id');

        $items = $products
            ->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->name,

                // ✅ در پیش‌فاکتور به‌جای sku بهتره همون PPPP نمایش داده بشه
                'sku' => $p->short_barcode ?: ($p->sku ?: ''),

                // اگر بعداً خواستی توی UI نشون بدی:
                'code' => $p->code,
                'short_barcode' => $p->short_barcode,

                'price' => (int) ($p->price ?? 0),
                'quantity' => (int) ($stockByProductId[(int) $p->id] ?? 0),
            ])
            ->values();

        return response()->json([
            'data' => [
                'products' => [
                    'data' => $items,
                    'last_page' => 1,
                ],
            ],
        ]);
    }

    public function product(Request $request, Product $product)
    {
        $includeUnavailable = $request->boolean('include_unavailable') && auth()->check();
        $editOrderUuid = trim((string) $request->query('preinvoice_uuid', ''));
        $currentPreinvoiceItemVariantIds = [];

        if ($editOrderUuid !== '' && auth()->check()) {
            $currentPreinvoiceItemVariantIds = PreinvoiceOrder::query()
                ->where('uuid', $editOrderUuid)
                ->whereHas('items', fn ($query) => $query->where('product_id', $product->id))
                ->with(['items' => fn ($query) => $query
                    ->select(['id', 'preinvoice_order_id', 'product_id', 'variant_id'])
                    ->where('product_id', $product->id)])
                ->first()?->items
                ->pluck('variant_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all() ?? [];
        }

        abort_unless((bool) $product->is_sellable || ! empty($currentPreinvoiceItemVariantIds), 404);

        $reservationToken = (string) $request->query('reservation_token', '');
        $reservedByVariant = $this->activeReservationQuantities($reservationToken);
        $reservedVariantIds = array_keys($reservedByVariant);

        $applyVariantVisibility = function ($outerQuery) use ($reservedVariantIds, $currentPreinvoiceItemVariantIds, $includeUnavailable) {
            if ($includeUnavailable) {
                return;
            }

            $outerQuery->where(function ($query) use ($reservedVariantIds) {
                $query->active()->where('sales_enabled', true)->where(function ($stockQuery) use ($reservedVariantIds) {
                    $stockQuery->where('stock', '>', 0);
                    if (! empty($reservedVariantIds)) {
                        $stockQuery->orWhereIn('id', $reservedVariantIds);
                    }
                });
            });

            if (! empty($currentPreinvoiceItemVariantIds)) {
                $outerQuery->orWhereIn('id', $currentPreinvoiceItemVariantIds);
            }
        };

        $hasAvailableOrReservedVariant = $product->variants()
            ->where($applyVariantVisibility)
            ->exists();

        abort_unless($hasAvailableOrReservedVariant, 404);

        $product->load(['variants' => fn ($q) => $q
            ->where($applyVariantVisibility)
            ->with('modelList')
            ->orderBy('variant_name')]);

        $centralWarehouseId = WarehouseStockService::centralWarehouseId();
        $centralStock = (int) WarehouseStock::query()
            ->where('warehouse_id', $centralWarehouseId)
            ->where('product_id', $product->id)
            ->value('quantity');

        $reservationToken = (string) $request->query('reservation_token', '');
        $reservedByVariant = $this->activeReservationQuantities($reservationToken);

        $payload = [
            'id' => $product->id,
            'title' => $product->name,

            // ✅ کد سریع 4 رقمی برای UI
            'sku' => $product->short_barcode ?: ($product->sku ?: ''),
            'short_barcode' => $product->short_barcode,
            'code' => $product->code,

            'price' => (int) ($product->price ?? 0),
            'quantity' => $centralStock,

            'varieties' => $product->variants->map(function ($v) use ($reservedByVariant, $currentPreinvoiceItemVariantIds) {
                $isSellableVariant = (bool) ($v->is_active ?? false) && (bool) ($v->sales_enabled ?? false);
                $freeStock = $isSellableVariant ? max(0, (int) ($v->stock ?? 0)) : 0;
                $totalReservedIncludingCurrent = max(0, (int) ($v->reserved ?? 0));
                $currentTokenReserved = max(0, (int) ($reservedByVariant[(int) $v->id] ?? 0));
                $reservedByOthers = max(0, $totalReservedIncludingCurrent - $currentTokenReserved);
                $maxSelectableForCurrentForm = $isSellableVariant ? $freeStock + $currentTokenReserved : 0;
                $totalStock = $freeStock + $totalReservedIncludingCurrent;

                return [
                    'id' => $v->id,
                    'price' => (int) ($v->sell_price ?? 0),

                    // Backward-compatible fields used by existing JS.
                    'quantity' => $freeStock,
                    'reserved' => $totalReservedIncludingCurrent,
                    'sellable_stock' => $maxSelectableForCurrentForm,

                    // Explicit availability fields for reservation-aware UI labels.
                    'free_stock' => $freeStock,
                    'reserved_by_others' => $reservedByOthers,
                    'current_token_reserved' => $currentTokenReserved,
                    'max_selectable_for_current_form' => $maxSelectableForCurrentForm,
                    'total_reserved_including_current' => $totalReservedIncludingCurrent,
                    'total_stock' => $totalStock,

                    'is_current_preinvoice_item' => in_array((int) $v->id, $currentPreinvoiceItemVariantIds, true),
                    'is_active' => (bool) ($v->is_active ?? false),
                    'sales_enabled' => (bool) ($v->sales_enabled ?? false),
                    'variant_name' => (string) ($v->variant_name ?? ''),
                    'variety_name' => (string) ($v->variety_name ?? ''),
                    'variety_code' => (string) ($v->variety_code ?? ''),
                    'model_list_name' => (string) ($v->modelList?->model_name ?? ''),

                    // ✅ بارکد 11 رقمی تنوع (برای اسکن/نمایش آینده)
                    'barcode' => $v->variant_code,

                    // سازگار با JS قبلی
                    'attributes' => [
                        ['pivot' => ['value' => $v->variant_name]],
                    ],
                    'unique_attributes_key' => (string) $v->variant_name,
                ];
            })->values(),
        ];

        return response()->json(['data' => ['product' => $payload]]);
    }

    public function syncDraftReservation(Request $request)
    {
        abort_unless(auth()->check(), 403);

        $data = $request->validate([
            'reservation_token' => ['required', 'uuid'],
            'submission_token' => ['required', 'uuid', 'same:reservation_token'],
            'items' => ['present', 'array', 'max:500'],
            'preinvoice_uuid' => ['nullable', 'string', 'max:255'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id,is_sellable,1'],
            'items.*.variant_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('product_variants', 'id')->where(fn ($query) => $query->where('is_active', true)->where('sales_enabled', true)),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'is_in_person' => ['nullable', 'boolean'],
        ]);

        try {
            $payload = $this->draftReservationService->syncReservationRows(
                (string) $data['reservation_token'], (int) auth()->id(), $data['items'] ?? [],
                (bool) ($data['is_in_person'] ?? false), $data['preinvoice_uuid'] ?? null,
            );
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $itemErrors = $errors['item_errors'] ?? [];
            unset($errors['item_errors']);
            return response()->json(['ok' => false, 'message' => 'موجودی یک یا چند قلم برای رزرو کافی نیست.', 'errors' => $errors, 'item_errors' => $itemErrors], 422);
        }

        return response()->json([
            'ok' => true,
            'data' => $payload,
        ]);
    }

    public function releaseDraftReservation(Request $request)
    {
        abort_unless(auth()->check(), 403);

        $data = $request->validate([
            'reservation_token' => ['required', 'uuid'],
        ]);

        $payload = $this->draftReservationService->releaseTokenReservations(
            (string) $data['reservation_token'],
            (int) auth()->id(),
            'manual_release',
            null
        );

        return response()->json([
            'ok' => true,
            'data' => $payload,
        ]);
    }

    private function activeReservationQuantities(string $token): array
    {
        if ($token === '' || ! auth()->check()) {
            return [];
        }

        $this->draftReservationService->releaseExpiredDraftReservations($token, (int) auth()->id());

        return PreinvoiceDraftReservation::query()
            ->where('token', $token)
            ->where('user_id', auth()->id())
            ->whereNull('converted_at')
            ->whereNull('preinvoice_order_id')
            ->whereIn('reservation_scope', ['temporary_online', 'temporary_in_person'])
            ->whereNull('released_at')
            ->whereNull('release_reason')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->pluck('quantity', 'variant_id')
            ->mapWithKeys(fn ($quantity, $variantId) => [(int) $variantId => (int) $quantity])
            ->all();
    }

    public function area()
    {
        return response()->json([
            'data' => [
                'provinces' => IranLocations::provinces(),
            ],
        ]);
    }

    public function provinces()
    {
        return response()->json(IranLocations::provinces());
    }

    public function cities(int $province)
    {
        abort_unless(IranLocations::provinceExists($province), 404);

        return response()->json(IranLocations::cities($province));
    }

    public function shippings()
    {
        $items = ShippingMethod::query()
            ->select(['id', 'name', 'price'])
            ->orderBy('name')
            ->get()
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'name' => $item->name,
                'price' => (int) $item->price,
            ])
            ->values();

        return response()->json([
            'data' => [
                'shippings' => [
                    'data' => $items,
                ],
            ],
        ]);
    }
}
