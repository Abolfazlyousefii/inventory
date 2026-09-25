<?php

namespace App\Services;

use App\Models\PreinvoiceDraftReservation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use App\Models\PreinvoiceOrder;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\ReservationSideEffects;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PreinvoiceDraftReservationService
{
    public function __construct(
        private InventoryReservationReleaseService $inventoryRelease,
        private ReservationClassificationService $classification,
    ) {}

    /**
     * @param  array<int>|null  $scopeProductIds  When non-empty, only keys of these products are
     *                                            reserved/released; every other key is left untouched.
     *                                            Null or empty keeps the whole-token behavior.
     */
    public function syncReservationRows(string $token, int $userId, array $items, bool $isInPerson = false, ?string $preinvoiceUuid = null, ?array $scopeProductIds = null): array
    {
        $desired = $this->normalizeReservationItems($items);
        $scope = array_values(array_unique(array_map('intval', $scopeProductIds ?? [])));

        return ReservationSideEffects::transaction(function () use ($token, $userId, $desired, $isInPerson, $preinvoiceUuid, $scope) {
            // A stable row exists even for the first request for an empty token.
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $tokenRows = PreinvoiceDraftReservation::query()->where('token', $token)->lockForUpdate()->get();
            $protected = $tokenRows->first(fn ($row) => $row->preinvoice_order_id !== null
                || $row->converted_at !== null || $row->reservation_scope === 'official'
                || (int) $row->user_id !== $userId);
            if ($protected) {
                Log::warning('RESERVATION_SYNC_SKIPPED', [
                    'reason' => 'protected_or_foreign_token', 'reservation_id' => $protected->id,
                    'preinvoice_order_id' => $protected->preinvoice_order_id, 'actor_id' => $userId,
                ]);
                return ['reserved' => [], 'skipped' => true, 'reason' => 'protected_or_foreign_token'];
            }

            if ($preinvoiceUuid !== null) {
                $order = PreinvoiceOrder::query()->where('uuid', $preinvoiceUuid)->lockForUpdate()->firstOrFail();
                abort_unless(app(SalesDocumentAccessService::class)->canSellerEditPreinvoiceItems($order, $user), 403);
                // Editable documents normally have no active official stock. Never reserve it twice.
                abort_if(PreinvoiceDraftReservation::query()->where('preinvoice_order_id', $order->id)->whereNull('released_at')->whereNull('release_reason')
                    ->where('reservation_scope', 'official')->exists(), 409, 'رزرو رسمی سند باید پیش از ویرایش بررسی شود.');
            }

            $this->releaseExpiredDraftReservations($token, $userId);

            $existingRows = $this->activeRowsQuery($token, $userId)
                ->lockForUpdate()
                ->get();

            $existing = [];
            foreach ($existingRows as $row) {
                $existing[$this->reservationKey((int) $row->product_id, (int) $row->variant_id)] = $row;
            }

            // ── FIX: هنگام ویرایش پیش‌فاکتور، آیتم‌هایی که قبلاً ذخیره شدن و
            // موجودیشون از انبار کم شده رو در محاسبه delta حساب کن تا دوباره
            // تلاش نکنه رزروشون کنه.
            $committedQty = [];
            if (isset($order) && $this->hasCommittedCentralStock($order)) {
                $order->loadMissing('items');
                foreach ($order->items as $orderItem) {
                    $key = $this->reservationKey((int) $orderItem->product_id, (int) $orderItem->variant_id);
                    $committedQty[$key] = ($committedQty[$key] ?? 0) + (int) $orderItem->quantity;
                }
            }

            $allKeys = array_unique(array_merge(array_keys($existing), array_keys($desired)));
            sort($allKeys, SORT_STRING);
            if ($scope !== []) {
                // Product-scoped sync: keys of other products are neither checked, reserved nor released.
                $allKeys = array_values(array_filter($allKeys, fn (string $key) => in_array((int) explode(':', $key)[0], $scope, true)));
                $desired = array_intersect_key($desired, array_flip($allKeys));
            }
            $expiresAt = $isInPerson ? null : now()->addHour();
            $reservationScope = $isInPerson ? 'temporary_in_person' : 'temporary_online';

            // Read-only pass: report every short item at once instead of the first one.
            $this->assertAllDeltasReservable($allKeys, $existing, $committedQty, $desired);

            foreach ($allKeys as $key) {
                [$productId, $variantId] = array_map('intval', explode(':', $key));
                $oldQty = (int) (($existing[$key] ?? null)?->quantity ?? 0);
                $committed = (int) ($committedQty[$key] ?? 0);
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

                // محاسبه delta با در نظر گرفتن موجودی committed
                // oldQty = رزرو موقت فعلی، committed = قبلاً از انبار کم شده
                $effectiveOld = $oldQty + $committed;
                $delta = $newQty - $effectiveOld;

                if ($delta > 0) {
                    $this->reserveVariantDelta($productId, $variantId, $delta, $effectiveOld);
                } elseif ($delta < 0) {
                    // فقط از رزروهای موقت آزاد کن، نه از committed
                    $releasable = min(abs($delta), $oldQty);
                    if ($releasable > 0) {
                        $this->releaseVariantDelta($productId, $variantId, $releasable);
                    }
                }

                // فقط برای مقدار اضافه بر committed رزرو موقت بساز
                $tempQty = max(0, $newQty - $committed);
                if ($tempQty > 0) {
                    $reservationAttributes = [
                        'user_id' => $userId,
                        'quantity' => $tempQty,
                        'expires_at' => $expiresAt,
                        'last_seen_at' => now(),
                        'converted_at' => null,
                        'preinvoice_order_id' => null,
                        'reservation_scope' => $reservationScope,
                        'reservation_tier' => null,
                    ];

                    $reservationAttributes += [
                        'released_at' => null,
                        'released_by' => null,
                        'release_reason' => null,
                        'release_note' => null,
                    ];

                    PreinvoiceDraftReservation::query()->updateOrCreate(
                        [
                            'token' => $token,
                            'product_id' => $productId,
                            'variant_id' => $variantId,
                        ],
                        $reservationAttributes
                    );
                } elseif (isset($existing[$key])) {
                    // اگه مقدار جدید کمتر یا مساوی committed هست، رزرو موقت لازم نیست
                    $this->markReleasedOrDelete($existing[$key], $userId, 'manual_release', null);
                }

                ReservationSideEffects::touchProduct($productId);
            }

            return [
                'reserved' => array_values($desired),
                'expires_at' => $expiresAt?->toIso8601String(),
                'reservation_scope' => $reservationScope,
            ];
        });
    }

    public function releaseTokenReservations(string $token, int $userId, string $reason, ?string $note = null): array
    {
        return ReservationSideEffects::transaction(function () use ($token, $userId, $reason, $note) {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $rows = $this->activeRowsQuery($token, $userId)
                ->lockForUpdate()
                ->get();

            $released = [];
            foreach ($rows as $row) {
                $quantity = (int) $row->quantity;
                $this->releaseVariantDelta((int) $row->product_id, (int) $row->variant_id, $quantity);
                $this->markReleasedOrDelete($row, $userId, $reason, $note);
                ReservationSideEffects::touchProduct((int) $row->product_id);
                $released[] = [
                    'product_id' => (int) $row->product_id,
                    'variant_id' => (int) $row->variant_id,
                    'quantity' => $quantity,
                ];
            }

            return ['released' => $released];
        });
    }

    public function heartbeat(string $token, int $userId, ?string $browserSessionId = null): int
    {
        return PreinvoiceDraftReservation::query()
            ->where('token', $token)
            ->where('user_id', $userId)
            ->whereNull('converted_at')
            ->whereNull('preinvoice_order_id')
            ->whereIn('reservation_scope', ['temporary_online', 'temporary_in_person'])
            ->whereNull('released_at')
            ->whereNull('release_reason')
            ->update([
                'last_seen_at' => now(),
                'browser_session_id' => $browserSessionId,
            ]);
    }

    public function cleanupStaleTemporaryReservations(
        int $onlineMinutes = PreinvoiceDraftReservation::DEFAULT_ONLINE_STALE_MINUTES,
        int $inPersonMinutes = PreinvoiceDraftReservation::DEFAULT_IN_PERSON_STALE_MINUTES,
        bool $dryRun = false,
    ): array {
        if ($dryRun) {
            $evaluatedAt = now();

            return $this->cleanupResult(
                $this->staleTemporaryReservationsQuery($onlineMinutes, $inPersonMinutes)
                    ->with([
                        'product:id,name',
                        'variant:id,variant_name,variety_name',
                        'user:id,name',
                        'order.invoice',
                        'activeDrafts',
                    ])
                    ->get()
                    ->filter(fn (PreinvoiceDraftReservation $reservation): bool =>
                        $this->classification->classify($reservation, $evaluatedAt)['state']
                            === ReservationClassificationService::STATE_TEMPORARY_STALE_RELEASABLE
                    )
                    ->values(),
                false,
            );
        }

        $releasedReservations = collect();
        $warningCount = 0;
        $candidates = $this->staleTemporaryReservationsQuery($onlineMinutes, $inPersonMinutes)
            ->select('id')
            ->lazyById(500);

        foreach ($candidates as $candidate) {
            $reservationId = (int) $candidate->id;
            $warning = null;

            try {
                $releasedReservation = ReservationSideEffects::transaction(function () use ($reservationId, $onlineMinutes, $inPersonMinutes, &$warning) {
                    $row = PreinvoiceDraftReservation::query()
                        ->whereKey($reservationId)
                        ->lockForUpdate()
                        ->first();

                    if (! $row) {
                        return null;
                    }

                    $row->load(['order.invoice', 'activeDrafts']);
                    $classification = $this->classification->classify($row, now());
                    if ($classification['state'] !== ReservationClassificationService::STATE_TEMPORARY_STALE_RELEASABLE) {
                        return null;
                    }

                    $qty = (int) $row->quantity;
                    $release = $this->inventoryRelease->releaseReservedQuantity(
                        (int) $row->product_id,
                        (int) $row->variant_id,
                        $qty,
                    );

                    if (! $release['released']) {
                        $warning = array_merge($release['context'], [
                            'reservation_id' => (int) $row->id,
                            'reason' => $release['reason'],
                            'audit_source' => 'automatic_reservation_cleanup',
                            'actor_type' => 'system',
                        ]);

                        throw new RuntimeException('reservation_cleanup_validation_failed');
                    }

                    $this->markReleasedOrDelete($row, 0, 'temporary_session_lost', 'Heartbeat رزرو موقت قطع شد و رزرو آزاد شد.');
                    ReservationSideEffects::touchProduct((int) $row->product_id);

                    $row->loadMissing([
                        'product:id,name',
                        'variant:id,variant_name,variety_name,variant_code,variety_code',
                    ]);

                    ActivityLogger::logForActor(
                        null,
                        'reservation_auto_release',
                        $row,
                        'رزرو موقت رهاشده به‌صورت خودکار آزاد شد.',
                        array_merge($release['context'], [
                            'reservation_id' => (int) $row->id,
                            'product' => $row->product?->name,
                            'variant' => $row->variant?->variant_name
                                ?? $row->variant?->variety_name
                                ?? $row->variant?->variant_code
                                ?? $row->variant?->variety_code,
                            'quantity' => $qty,
                            'reason' => 'temporary_session_lost',
                            'audit_source' => 'automatic_reservation_cleanup',
                            'actor_type' => 'system',
                            'before' => $release['before'],
                            'after' => $release['after'],
                        ]),
                    );

                    return $row->fresh()->load([
                        'product:id,name',
                        'variant:id,variant_name,variety_name',
                        'user:id,name',
                    ]);
                });
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== 'reservation_cleanup_validation_failed' || $warning === null) {
                    throw $exception;
                }

                $warningReservation = PreinvoiceDraftReservation::query()->find($warning['reservation_id']);
                if ($warningReservation) {
                    ActivityLogger::logForActor(
                        null,
                        'reservation_cleanup_warning',
                        $warningReservation,
                        'آزادسازی خودکار رزرو به دلیل ناسازگاری موجودی انجام نشد.',
                        $warning,
                    );
                }

                $warningCount++;

                continue;
            }

            if ($releasedReservation) {
                $releasedReservations->push($releasedReservation);
            }
        }

        return [
            'released_reservations' => $releasedReservations->count(),
            'released_quantity' => (int) $releasedReservations->sum('quantity'),
            'reservations' => $releasedReservations,
            'warnings' => $warningCount,
            'dry_run' => false,
        ];
    }

    public function staleTemporaryReservationsQuery(
        int $onlineMinutes = PreinvoiceDraftReservation::DEFAULT_ONLINE_STALE_MINUTES,
        int $inPersonMinutes = PreinvoiceDraftReservation::DEFAULT_IN_PERSON_STALE_MINUTES,
    ): Builder {
        return PreinvoiceDraftReservation::query()
            ->cleanupCandidates(max(1, $onlineMinutes), max(1, $inPersonMinutes))
            ->whereRaw(
                'COALESCE(last_seen_at, created_at) > ?',
                [now()->subHours(PreinvoiceDraftReservation::LEGACY_STALE_HOURS)],
            )
            ->orderBy('id');
    }

    public function releaseExpiredDraftReservations(?string $token = null, ?int $userId = null): void
    {
        ReservationSideEffects::transaction(function () use ($token, $userId) {
            $expiredRows = PreinvoiceDraftReservation::query()
                ->whereNull('converted_at')
                ->whereNull('preinvoice_order_id')
                ->where('reservation_scope', 'temporary_online')
                ->when($token, fn ($query) => $query->where('token', $token))
                ->when($userId, fn ($query) => $query->where('user_id', $userId))
                ->whereNull('released_at')
                ->whereNull('release_reason')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->lockForUpdate()
                ->get();

            foreach ($expiredRows as $row) {
                $row->load(['order.invoice', 'activeDrafts']);
                $classification = $this->classification->classify($row, now());
                if ($classification['state'] !== ReservationClassificationService::STATE_TEMPORARY_STALE_RELEASABLE) {
                    continue;
                }

                $this->releaseVariantDelta((int) $row->product_id, (int) $row->variant_id, (int) $row->quantity);
                $this->markReleasedOrDelete($row, (int) ($row->user_id ?? 0), 'temporary_online_expired', 'رزرو موقت آنلاین منقضی شد.');
                ReservationSideEffects::touchProduct((int) $row->product_id);
            }
        });
    }

    private function activeRowsQuery(string $token, int $userId)
    {
        return PreinvoiceDraftReservation::query()
            ->where('token', $token)
            ->where('user_id', $userId)
            ->whereNull('converted_at')
            ->whereNull('preinvoice_order_id')
            ->whereIn('reservation_scope', ['temporary_online', 'temporary_in_person'])
            ->whereNull('released_at')
            ->whereNull('release_reason');
    }

    private function markReleasedOrDelete(PreinvoiceDraftReservation $row, int $userId, string $reason, ?string $note): void
    {
        $row->forceFill([
            'released_at' => now(),
            'released_by' => $userId > 0 ? $userId : null,
            'release_reason' => $reason,
            'release_note' => $note,
            'expires_at' => null,
        ])->save();
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

    /**
     * Mirrors the reservation loop without writing: collects every key whose
     * positive delta exceeds central stock and throws them together. The
     * loop's own check in reserveVariantDelta stays as the safety net.
     */
    private function assertAllDeltasReservable(array $keys, array $existing, array $committedQty, array $desired): void
    {
        $itemErrors = [];

        foreach ($keys as $key) {
            [$productId, $variantId] = array_map('intval', explode(':', $key));
            $newQty = (int) ($desired[$key]['quantity'] ?? 0);
            if ($newQty <= 0) {
                continue;
            }

            $variant = ProductVariant::query()
                ->with('product')
                ->whereKey($variantId)
                ->where('product_id', $productId)
                ->where('is_active', true)
                ->first();
            if (! $variant) {
                // The loop reports an invalid variant itself; keep earlier shortfalls first.
                break;
            }

            $held = (int) (($existing[$key] ?? null)?->quantity ?? 0) + (int) ($committedQty[$key] ?? 0);
            $delta = $newQty - $held;
            if ($delta <= 0) {
                continue;
            }

            $available = $this->centralAvailableQuantity($productId, $variantId);
            if ($delta > $available) {
                $itemErrors[] = $this->shortfallItemError($variant, $productId, $variantId, $available, $delta, $held);
            }
        }

        if ($itemErrors !== []) {
            throw ValidationException::withMessages([
                'items' => array_column($itemErrors, 'message'),
                'item_errors' => $itemErrors,
                'suggested_items' => $this->suggestedItems($desired, $itemErrors),
            ]);
        }
    }

    private function centralAvailableQuantity(int $productId, int $variantId): int
    {
        $quantity = WarehouseStock::query()
            ->where('warehouse_id', WarehouseStockService::centralWarehouseId())
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->value('quantity');

        return max(0, (int) ($quantity ?? 0));
    }

    private function shortfallItemError(ProductVariant $variant, int $productId, int $variantId, int $available, int $delta, int $held): array
    {
        $product = $variant->product;

        return [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'product_name' => $product?->name ?? $product?->title ?? '',
            'product_code' => $product?->code ?? '',
            'variant_name' => $variant->variant_name ?? $variant->name ?? '',
            'variant_code' => $variant->code ?? '',
            'available_quantity' => $available,
            'requested_quantity' => $delta,
            // Largest total quantity this line can hold: what it already holds plus free central stock.
            'max_allowed' => $held + $available,
            'message' => "موجودی «" . ($variant->variant_name ?: $variant->name ?: $variantId) . "» (" . ($product?->name ?? 'نامشخص') . ") کافی نیست. موجودی: {$available} | درخواست: {$delta}",
        ];
    }

    /** The submitted list with each short line lowered to max_allowed, or flagged for removal at zero. */
    private function suggestedItems(array $desired, array $itemErrors): array
    {
        $maxByKey = [];
        foreach ($itemErrors as $error) {
            $maxByKey[$this->reservationKey((int) $error['product_id'], (int) $error['variant_id'])] = (int) $error['max_allowed'];
        }

        $suggested = [];
        foreach ($desired as $key => $row) {
            $quantity = array_key_exists($key, $maxByKey) ? max(0, $maxByKey[$key]) : (int) $row['quantity'];
            $suggested[] = [
                'product_id' => (int) $row['product_id'],
                'variant_id' => (int) $row['variant_id'],
                'requested_quantity' => (int) $row['quantity'],
                'quantity' => $quantity,
                'remove' => $quantity <= 0,
            ];
        }

        return $suggested;
    }

    private function reserveVariantDelta(int $productId, int $variantId, int $delta, int $held = 0): void
    {
        if ($delta <= 0) {
            return;
        }

        $variant = ProductVariant::query()->with('product')->whereKey($variantId)->lockForUpdate()->firstOrFail();
        // The central warehouse row is the canonical, lockable sellable-stock source.
        $centralStock = WarehouseStock::query()
            ->where('warehouse_id', WarehouseStockService::centralWarehouseId())
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->lockForUpdate()
            ->first();
        $available = max(0, (int) ($centralStock?->quantity ?? 0));

        if ($delta > $available) {
            $itemError = $this->shortfallItemError($variant, $productId, $variantId, $available, $delta, $held);
            throw ValidationException::withMessages([
                'items' => [$itemError['message']],
                'item_errors' => [$itemError],
            ]);
        }

        WarehouseStockService::change(WarehouseStockService::centralWarehouseId(), $productId, -$delta, $variantId);

    }

    private function releaseVariantDelta(int $productId, int $variantId, int $delta): void
    {
        if ($delta <= 0) {
            return;
        }

        $release = $this->inventoryRelease->releaseReservedQuantity($productId, $variantId, $delta);

        if (! $release['released']) {
            throw ValidationException::withMessages([
                'items' => $release['reason'] === 'reserved_cache_mismatch'
                    ? 'مقدار رزرو ثبت‌شده با موجودی هم‌خوان نیست و موجودی آزاد نشد.'
                    : 'ارتباط کالا یا تنوع رزرو معتبر نیست و موجودی آزاد نشد.',
            ]);
        }
    }

    private function reservationKey(int $productId, int $variantId): string
    {
        return $productId.':'.$variantId;
    }

    private function hasCommittedCentralStock(PreinvoiceOrder $order): bool
    {
        return $order->stock_frozen_until !== null
            && $order->stock_released_at === null;
    }

    private function cleanupResult(Collection $reservations, bool $changed): array
    {
        return [
            'released_reservations' => $reservations->count(),
            'released_quantity' => (int) $reservations->sum('quantity'),
            'reservations' => $reservations,
            'warnings' => 0,
            'dry_run' => ! $changed,
        ];
    }
}
