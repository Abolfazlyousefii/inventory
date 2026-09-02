<?php

namespace App\Services;

use App\Models\PreinvoiceDraftReservation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PreinvoiceDraftReservationService
{
    public function __construct(private InventoryReservationReleaseService $inventoryRelease) {}

    public function syncReservationRows(string $token, int $userId, array $items, bool $isInPerson = false): array
    {
        $desired = $this->normalizeReservationItems($items);

        return DB::transaction(function () use ($token, $userId, $desired, $isInPerson) {
            $this->releaseExpiredDraftReservations($token, $userId);

            $existingRows = $this->activeRowsQuery($token, $userId)
                ->lockForUpdate()
                ->get();

            $existing = [];
            foreach ($existingRows as $row) {
                $existing[$this->reservationKey((int) $row->product_id, (int) $row->variant_id)] = $row;
            }

            $allKeys = array_unique(array_merge(array_keys($existing), array_keys($desired)));
            $expiresAt = $isInPerson ? null : now()->addHour();
            $reservationScope = $isInPerson ? 'temporary_in_person' : 'temporary_online';

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
                    $reservationAttributes = [
                        'user_id' => $userId,
                        'quantity' => $newQty,
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
                    $this->markReleasedOrDelete($existing[$key], $userId, 'manual_release', null);
                }
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
        return DB::transaction(function () use ($token, $userId, $reason, $note) {
            $rows = $this->activeRowsQuery($token, $userId)
                ->lockForUpdate()
                ->get();

            $released = [];
            foreach ($rows as $row) {
                $quantity = (int) $row->quantity;
                $this->releaseVariantDelta((int) $row->product_id, (int) $row->variant_id, $quantity);
                $this->markReleasedOrDelete($row, $userId, $reason, $note);
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
            return $this->cleanupResult(
                $this->staleTemporaryReservationsQuery($onlineMinutes, $inPersonMinutes)
                    ->with([
                        'product:id,name',
                        'variant:id,variant_name,variety_name',
                        'user:id,name',
                    ])
                    ->get(),
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
                $releasedReservation = DB::transaction(function () use ($reservationId, $onlineMinutes, $inPersonMinutes, &$warning) {
                    $row = PreinvoiceDraftReservation::query()
                        ->whereKey($reservationId)
                        ->lockForUpdate()
                        ->first();

                    if (! $row || ! $row->isCleanupCandidate(now(), max(1, $onlineMinutes), max(1, $inPersonMinutes))) {
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
            ->orderBy('id');
    }

    public function releaseExpiredDraftReservations(?string $token = null, ?int $userId = null): void
    {
        DB::transaction(function () use ($token, $userId) {
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
                $this->releaseVariantDelta((int) $row->product_id, (int) $row->variant_id, (int) $row->quantity);
                $this->markReleasedOrDelete($row, (int) ($row->user_id ?? 0), 'temporary_online_expired', 'رزرو موقت آنلاین منقضی شد.');
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
