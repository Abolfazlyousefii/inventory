<?php

namespace App\Http\Controllers;

use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\WarehouseStock;
use App\Services\WarehouseStockService;
use App\Services\PreinvoiceDraftReservationService;
use App\Support\IranLocations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PreinvoiceApiController extends Controller
{
    public function __construct(
        private readonly PreinvoiceDraftReservationService $draftReservationService,
    ) {}

    public function products(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $qDigits = preg_replace('/\D+/', '', $q); // فقط عدد

        $centralWarehouseId = WarehouseStockService::centralWarehouseId();

        $products = Product::query()
            ->select(['id', 'name', 'sku', 'short_barcode', 'code', 'price'])
            ->where('is_sellable', true)
            ->whereHas('variants', fn ($q) => $q->active()->where('stock', '>', 0))
            ->when($q !== '', function ($query) use ($q, $qDigits) {

                // ✅ اگر عدد وارد شد و طولش <= 4 یعنی PPPP
                if ($qDigits !== '' && strlen($qDigits) <= 4) {
                    $pppp = str_pad($qDigits, 4, '0', STR_PAD_LEFT);
                    $query->where('short_barcode', $pppp);
                    return;
                }

                // ✅ اگر طولش 6 بود احتمالاً code محصول (CCPPPP) است
                if ($qDigits !== '' && strlen($qDigits) === 6) {
                    $query->where('code', $qDigits);
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

        $hasAvailableOrReservedVariant = $product->variants()
            ->where(function ($outerQuery) use ($reservedVariantIds, $currentPreinvoiceItemVariantIds) {
                $outerQuery->where(function ($query) use ($reservedVariantIds) {
                    $query->active()->where(function ($stockQuery) use ($reservedVariantIds) {
                        $stockQuery->where('stock', '>', 0);
                        if (! empty($reservedVariantIds)) {
                            $stockQuery->orWhereIn('id', $reservedVariantIds);
                        }
                    });
                });

                if (! empty($currentPreinvoiceItemVariantIds)) {
                    $outerQuery->orWhereIn('id', $currentPreinvoiceItemVariantIds);
                }
            })
            ->exists();

        abort_unless($hasAvailableOrReservedVariant, 404);

        $product->load(['variants' => fn ($q) => $q
            ->where(function ($outerQuery) use ($reservedVariantIds, $currentPreinvoiceItemVariantIds) {
                $outerQuery->where(function ($query) use ($reservedVariantIds) {
                    $query->active()->where(function ($stockQuery) use ($reservedVariantIds) {
                        $stockQuery->where('stock', '>', 0);
                        if (! empty($reservedVariantIds)) {
                            $stockQuery->orWhereIn('id', $reservedVariantIds);
                        }
                    });
                });

                if (! empty($currentPreinvoiceItemVariantIds)) {
                    $outerQuery->orWhereIn('id', $currentPreinvoiceItemVariantIds);
                }
            })
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

            'varieties' => $product->variants->map(fn ($v) => [
                'id' => $v->id,
                'price' => (int) ($v->sell_price ?? 0),
                'quantity' => (int) ($v->stock ?? 0),
                'reserved' => (int) ($v->reserved ?? 0),
                'sellable_stock' => max(0, (int) ($v->stock ?? 0) + (int) ($reservedByVariant[(int) $v->id] ?? 0)),
                'is_current_preinvoice_item' => in_array((int) $v->id, $currentPreinvoiceItemVariantIds, true),
                'is_active' => (bool) ($v->is_active ?? false),
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
            ])->values(),
        ];

        return response()->json(['data' => ['product' => $payload]]);
    }

    public function syncDraftReservation(Request $request)
    {
        abort_unless(auth()->check(), 403);

        $data = $request->validate([
            'reservation_token' => ['required', 'uuid'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required_with:items', 'integer', 'exists:products,id,is_sellable,1'],
            'items.*.variant_id' => [
                'required_with:items',
                'integer',
                Rule::exists('product_variants', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:0'],
        ]);

        $payload = $this->draftReservationService->syncReservationRows(
            (string) $data['reservation_token'],
            (int) auth()->id(),
            $data['items'] ?? []
        );

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

    private function syncReservationRows(string $token, int $userId, array $items): array
    {
        $desired = $this->normalizeReservationItems($items);

        return DB::transaction(function () use ($token, $userId, $desired) {
            $this->releaseExpiredDraftReservations();

            $existingRows = PreinvoiceDraftReservation::query()
                ->where('token', $token)
                ->where('user_id', $userId)
                ->whereNull('converted_at')
                ->lockForUpdate()
                ->get();

            $existing = [];
            foreach ($existingRows as $row) {
                $existing[$this->reservationKey((int) $row->product_id, (int) $row->variant_id)] = $row;
            }

            $allKeys = array_unique(array_merge(array_keys($existing), array_keys($desired)));
            $expiresAt = now()->addHours(4);

            foreach ($allKeys as $key) {
                [$productId, $variantId] = array_map('intval', explode(':', $key));
                $oldQty = (int) ($existing[$key]?->quantity ?? 0);
                $newQty = (int) ($desired[$key]['quantity'] ?? 0);

                if ($newQty > 0) {
                    $variantMatchesProduct = ProductVariant::query()
                        ->whereKey($variantId)
                        ->where('product_id', $productId)
                        ->where('is_active', true)
                        ->exists();

                    if (! $variantMatchesProduct) {
                        throw ValidationException::withMessages([
                            'items' => 'تنوع انتخابی برای کالا معتبر یا فعال نیست.',
                        ]);
                    }
                }

                $delta = $newQty - $oldQty;
                if ($delta > 0) {
                    $this->reserveVariantDelta($productId, $variantId, $delta);
                } elseif ($delta < 0) {
                    $this->releaseVariantDelta($productId, $variantId, abs($delta));
                }

                if ($newQty > 0) {
                    PreinvoiceDraftReservation::query()->updateOrCreate(
                        [
                            'token' => $token,
                            'product_id' => $productId,
                            'variant_id' => $variantId,
                        ],
                        [
                            'user_id' => $userId,
                            'quantity' => $newQty,
                            'expires_at' => $expiresAt,
                            'converted_at' => null,
                            'preinvoice_order_id' => null,
                        ]
                    );
                } elseif (isset($existing[$key])) {
                    $existing[$key]->delete();
                }
            }

            return [
                'reserved' => array_values($desired),
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        });
    }

    private function normalizeReservationItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $row) {
            $productId = (int) ($row['product_id'] ?? $row['id'] ?? 0);
            $variantId = (int) ($row['variant_id'] ?? $row['variety_id'] ?? 0);
            $quantity = max(0, (int) ($row['quantity'] ?? 0));
            if ($productId <= 0 || $variantId <= 0 || $quantity <= 0) {
                continue;
            }

            $key = $this->reservationKey($productId, $variantId);
            if (! isset($normalized[$key])) {
                $normalized[$key] = [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'quantity' => 0,
                ];
            }
            $normalized[$key]['quantity'] += $quantity;
        }

        return $normalized;
    }

    private function activeReservationQuantities(string $token): array
    {
        if ($token === '' || ! auth()->check()) {
            return [];
        }

        $this->releaseExpiredDraftReservations();

        return PreinvoiceDraftReservation::query()
            ->where('token', $token)
            ->where('user_id', auth()->id())
            ->whereNull('converted_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->pluck('quantity', 'variant_id')
            ->mapWithKeys(fn ($quantity, $variantId) => [(int) $variantId => (int) $quantity])
            ->all();
    }

    private function reserveVariantDelta(int $productId, int $variantId, int $delta): void
    {
        if ($delta <= 0) {
            return;
        }

        $variant = ProductVariant::query()->whereKey($variantId)->lockForUpdate()->firstOrFail();
        $available = max(0, (int) $variant->stock);

        if ($delta > $available) {
            throw ValidationException::withMessages([
                'items' => "موجودی قابل فریز برای تنوع انتخابی کافی نیست. موجودی انبار مرکزی: {$available} | درخواست جدید: {$delta}",
            ]);
        }

        WarehouseStockService::change(WarehouseStockService::centralWarehouseId(), $productId, -$delta, $variantId);

        $variant = ProductVariant::query()->whereKey($variantId)->lockForUpdate()->firstOrFail();
        $variant->reserved = (int) $variant->reserved + $delta;
        $variant->save();

        $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
        if ($product) {
            $product->reserved = (int) $product->reserved + $delta;
            $product->save();
        }
    }

    private function releaseVariantDelta(int $productId, int $variantId, int $delta): void
    {
        if ($delta <= 0) {
            return;
        }

        $variant = ProductVariant::query()->whereKey($variantId)->lockForUpdate()->first();
        if ($variant) {
            $variant->reserved = max(0, (int) $variant->reserved - $delta);
            $variant->save();
        }

        $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
        if ($product) {
            $product->reserved = max(0, (int) $product->reserved - $delta);
            $product->save();
        }

        WarehouseStockService::change(WarehouseStockService::centralWarehouseId(), $productId, $delta, $variantId);
    }

    private function releaseExpiredDraftReservations(): void
    {
        DB::transaction(function () {
            $expiredRows = PreinvoiceDraftReservation::query()
                ->whereNull('converted_at')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->lockForUpdate()
                ->get();

            foreach ($expiredRows as $row) {
                $this->releaseVariantDelta((int) $row->product_id, (int) $row->variant_id, (int) $row->quantity);
                $row->delete();
            }
        });
    }

    private function reservationKey(int $productId, int $variantId): string
    {
        return $productId . ':' . $variantId;
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
