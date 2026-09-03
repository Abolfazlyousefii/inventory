<?php

namespace App\Services;

use App\Models\PreinvoiceDraftReservation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class InventoryReservationReleaseService
{
    /**
     * Finish a reservation consumed by an invoice without returning stock.
     *
     * Normal conversion decrements the reserved caches. Historical repair only
     * completes the lifecycle because caches are rebuilt separately.
     *
     * @return array{released: bool, quantity: int}
     */
    public function releaseConvertedReservation(
        PreinvoiceDraftReservation $reservation,
        ?User $actor = null,
        bool $decrementReservedCache = true,
    ): array {
        return DB::transaction(function () use ($reservation, $actor, $decrementReservedCache): array {
            $lockedReservation = PreinvoiceDraftReservation::query()
                ->whereKey($reservation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedReservation->released_at !== null) {
                return ['released' => false, 'quantity' => 0];
            }

            $quantity = (int) $lockedReservation->quantity;
            if ($lockedReservation->preinvoice_order_id === null || $quantity <= 0) {
                return ['released' => false, 'quantity' => 0];
            }

            if ($decrementReservedCache) {
                $variant = ProductVariant::query()
                    ->whereKey((int) $lockedReservation->variant_id)
                    ->where('product_id', (int) $lockedReservation->product_id)
                    ->lockForUpdate()
                    ->first();
                $product = Product::query()
                    ->whereKey((int) $lockedReservation->product_id)
                    ->lockForUpdate()
                    ->first();

                if (! $variant || ! $product) {
                    throw ValidationException::withMessages([
                        'reservation' => 'The converted reservation has an invalid product or variant relation.',
                    ]);
                }

                $variant->forceFill([
                    'reserved' => max(0, (int) $variant->reserved - $quantity),
                ])->save();
                $product->forceFill([
                    'reserved' => max(0, (int) $product->reserved - $quantity),
                ])->save();
            }

            $convertedAt = $lockedReservation->converted_at ?? now();
            $lockedReservation->forceFill([
                'converted_at' => $convertedAt,
                'released_at' => $convertedAt,
                'released_by' => $actor?->id,
                'release_reason' => 'consumed',
                'release_note' => $decrementReservedCache
                    ? 'Reservation consumed during final invoice conversion.'
                    : 'Historical converted reservation lifecycle repaired.',
            ])->save();

            return ['released' => true, 'quantity' => $quantity];
        });
    }

    public function releaseDraftReservation(PreinvoiceDraftReservation $reservation, User $user, string $reason, ?string $note = null): void
    {
        DB::transaction(function () use ($reservation, $user, $reason, $note): void {
            $lockedReservation = PreinvoiceDraftReservation::query()
                ->whereKey($reservation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedReservation->release_reason !== null
                || (! $lockedReservation->canBeManuallyReleased() && ! $lockedReservation->isOrphaned())) {
                throw ValidationException::withMessages([
                    'reservation' => 'این رزرو دیگر قابل آزادسازی نیست؛ وضعیت آن در همین لحظه تغییر کرده است.',
                ]);
            }

            $quantity = (int) $lockedReservation->quantity;
            if ($quantity <= 0) {
                throw ValidationException::withMessages([
                    'reservation' => 'تعداد رزرو برای آزادسازی معتبر نیست.',
                ]);
            }

            $release = $this->releaseReservedQuantity(
                (int) $lockedReservation->product_id,
                (int) $lockedReservation->variant_id,
                $quantity,
            );

            if (! $release['released'] && $release['reason'] === 'invalid_reservation_relation') {
                throw ValidationException::withMessages([
                    'reservation' => 'ارتباط کالا یا تنوع این رزرو معتبر نیست. ابتدا گزارش یکپارچگی موجودی را بررسی کنید.',
                ]);
            }

            if (! $release['released']) {
                throw ValidationException::withMessages([
                    'reservation' => 'مقدار رزرو ثبت‌شده با موجودی هم‌خوان نیست. هیچ تغییری انجام نشد؛ ابتدا گزارش یکپارچگی موجودی را بررسی کنید.',
                ]);
            }

            $lockedReservation->forceFill([
                'released_at' => now(),
                'released_by' => $user->id,
                'release_reason' => $reason,
                'release_note' => $note,
            ])->save();

            $properties = [
                'reservation_id' => $lockedReservation->id,
                'product_id' => $lockedReservation->product_id,
                'variant_id' => $lockedReservation->variant_id,
                'quantity' => $quantity,
                'token' => (string) $lockedReservation->token,
                'reservation_user_id' => $lockedReservation->user_id,
                'released_by' => $user->id,
                'release_reason' => $reason,
                'release_note' => $note,
                'audit_source' => 'manual_release',
                'actor_type' => 'user',
                'before' => $release['before'],
                'after' => $release['after'],
            ];

            ActivityLogger::logForActor(
                (int) $user->id,
                'reservation_manual_release',
                $lockedReservation,
                'آزادسازی دستی رزرو موجودی',
                $properties,
            );

            Log::info('MANUAL_INVENTORY_RESERVATION_RELEASED', $properties);
        });
    }

    /**
     * Release reserved cache and central sellable stock for an already locked workflow.
     * The caller must run this method inside a database transaction.
     *
     * @return array{released: bool, reason: ?string, context: array<string, mixed>, before: array<string, int|null>, after: array<string, int|null>}
     */
    public function releaseReservedQuantity(int $productId, int $variantId, int $quantity): array
    {
        $variant = ProductVariant::query()
            ->whereKey($variantId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();
        $product = Product::query()
            ->whereKey($productId)
            ->lockForUpdate()
            ->first();

        $context = [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'release_quantity' => $quantity,
            'current_product_reserved' => $product === null ? null : (int) $product->reserved,
            'current_variant_reserved' => $variant === null ? null : (int) $variant->reserved,
        ];

        if ($quantity <= 0 || ! $variant || ! $product) {
            return [
                'released' => false,
                'reason' => 'invalid_reservation_relation',
                'context' => $context,
                'before' => [],
                'after' => [],
            ];
        }

        if ((int) $variant->reserved < $quantity || (int) $product->reserved < $quantity) {
            return [
                'released' => false,
                'reason' => 'reserved_cache_mismatch',
                'context' => $context,
                'before' => [],
                'after' => [],
            ];
        }

        $before = [
            'variant_stock' => (int) ($variant->stock ?? 0),
            'variant_reserved' => (int) $variant->reserved,
            'product_reserved' => (int) $product->reserved,
        ];

        $variant->reserved = (int) $variant->reserved - $quantity;
        $variant->save();

        $product->reserved = (int) $product->reserved - $quantity;
        $product->save();

        WarehouseStockService::change(
            WarehouseStockService::centralWarehouseId(),
            $productId,
            $quantity,
            $variantId,
        );

        $variant->refresh();
        $product->refresh();

        return [
            'released' => true,
            'reason' => null,
            'context' => $context,
            'before' => $before,
            'after' => [
                'variant_stock' => (int) ($variant->stock ?? 0),
                'variant_reserved' => (int) $variant->reserved,
                'product_reserved' => (int) $product->reserved,
            ],
        ];
    }
}
