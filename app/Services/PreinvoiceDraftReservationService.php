<?php

namespace App\Services;

use App\Models\PreinvoiceDraftReservation;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PreinvoiceDraftReservationService
{
    public function syncReservationRows(string $token, int $userId, array $items): array
    {
        $desired = $this->normalizeReservationItems($items);

        return DB::transaction(function () use ($token, $userId, $desired) {
            $this->releaseExpiredDraftReservations();

            $existingRows = $this->activeRowsQuery($token, $userId)
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
                    $reservationAttributes = [
                        'user_id' => $userId,
                        'quantity' => $newQty,
                        'expires_at' => $expiresAt,
                        'converted_at' => null,
                        'preinvoice_order_id' => null,
                    ];

                    if (Schema::hasColumn('preinvoice_draft_reservations', 'released_at')) {
                        $reservationAttributes += [
                            'released_at' => null,
                            'released_by' => null,
                            'release_reason' => null,
                            'release_note' => null,
                        ];
                    }

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
                'expires_at' => $expiresAt->toIso8601String(),
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

    public function releaseExpiredDraftReservations(): void
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
                $this->markReleasedOrDelete($row, (int) ($row->user_id ?? 0), 'expired', 'رزرو موقت منقضی شد.');
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
            ->when(Schema::hasColumn('preinvoice_draft_reservations', 'released_at'), fn ($query) => $query->whereNull('released_at'));
    }

    private function markReleasedOrDelete(PreinvoiceDraftReservation $row, int $userId, string $reason, ?string $note): void
    {
        if (Schema::hasColumn('preinvoice_draft_reservations', 'released_at')) {
            $row->forceFill([
                'released_at' => now(),
                'released_by' => $userId > 0 ? $userId : null,
                'release_reason' => $reason,
                'release_note' => $note,
                'expires_at' => null,
            ])->save();

            return;
        }

        $row->delete();
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

    private function reservationKey(int $productId, int $variantId): string
    {
        return $productId . ':' . $variantId;
    }
}
