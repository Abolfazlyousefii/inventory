<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\PreinvoiceDraftReservation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class InventoryReservationReleaseService
{
    public function releaseDraftReservation(PreinvoiceDraftReservation $reservation, User $user, string $reason, ?string $note = null): void
    {
        DB::transaction(function () use ($reservation, $user, $reason, $note): void {
            $lockedReservation = PreinvoiceDraftReservation::query()
                ->whereKey($reservation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedReservation->preinvoice_order_id !== null || $lockedReservation->converted_at !== null || $lockedReservation->released_at !== null) {
                throw ValidationException::withMessages([
                    'reservation' => 'این رزرو به سند ثبت‌شده متصل است و از این صفحه قابل آزادسازی نیست.',
                ]);
            }

            $quantity = (int) $lockedReservation->quantity;
            if ($quantity <= 0) {
                throw ValidationException::withMessages([
                    'reservation' => 'تعداد رزرو برای آزادسازی معتبر نیست.',
                ]);
            }

            $variant = ProductVariant::query()->whereKey($lockedReservation->variant_id)->lockForUpdate()->firstOrFail();
            $product = Product::query()->whereKey($lockedReservation->product_id)->lockForUpdate()->first();

            $before = [
                'variant_stock' => (int) ($variant->stock ?? 0),
                'variant_reserved' => (int) ($variant->reserved ?? 0),
                'product_reserved' => $product ? (int) ($product->reserved ?? 0) : null,
            ];

            $variant->reserved = max(0, (int) $variant->reserved - $quantity);
            $variant->save();

            if ($product) {
                $product->reserved = max(0, (int) $product->reserved - $quantity);
                $product->save();
            }

            WarehouseStockService::change(
                WarehouseStockService::centralWarehouseId(),
                (int) $lockedReservation->product_id,
                $quantity,
                (int) $lockedReservation->variant_id
            );

            $variant->refresh();
            $product?->refresh();

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
                'before' => $before,
                'after' => [
                    'variant_stock' => (int) ($variant->stock ?? 0),
                    'variant_reserved' => (int) ($variant->reserved ?? 0),
                    'product_reserved' => $product ? (int) ($product->reserved ?? 0) : null,
                ],
            ];

            ActivityLog::query()->create([
                'user_id' => $user->id,
                'action' => 'manual_inventory_reservation_released',
                'subject_type' => PreinvoiceDraftReservation::class,
                'subject_id' => $lockedReservation->id,
                'description' => 'آزادسازی دستی رزرو موجودی',
                'properties' => $properties,
                'occurred_at' => now(),
            ]);

            Log::info('MANUAL_INVENTORY_RESERVATION_RELEASED', $properties);
        });
    }
}
