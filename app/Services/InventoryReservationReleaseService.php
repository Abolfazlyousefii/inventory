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
    public function releaseDraftReservation(PreinvoiceDraftReservation $reservation, User $user, string $reason, ?string $note = null): void
    {
        DB::transaction(function () use ($reservation, $user, $reason, $note): void {
            $lockedReservation = PreinvoiceDraftReservation::query()
                ->whereKey($reservation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedReservation->canBeManuallyReleased()) {
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

            $variant = ProductVariant::query()
                ->whereKey($lockedReservation->variant_id)
                ->where('product_id', $lockedReservation->product_id)
                ->lockForUpdate()
                ->first();
            $product = Product::query()->whereKey($lockedReservation->product_id)->lockForUpdate()->first();

            if (! $variant || ! $product) {
                throw ValidationException::withMessages([
                    'reservation' => 'ارتباط کالا یا تنوع این رزرو معتبر نیست. ابتدا گزارش یکپارچگی موجودی را بررسی کنید.',
                ]);
            }

            if ((int) $variant->reserved < $quantity || (int) $product->reserved < $quantity) {
                throw ValidationException::withMessages([
                    'reservation' => 'مقدار رزرو ثبت‌شده با موجودی هم‌خوان نیست. هیچ تغییری انجام نشد؛ ابتدا گزارش یکپارچگی موجودی را بررسی کنید.',
                ]);
            }

            $before = [
                'variant_stock' => (int) ($variant->stock ?? 0),
                'variant_reserved' => (int) ($variant->reserved ?? 0),
                'product_reserved' => $product ? (int) ($product->reserved ?? 0) : null,
            ];

            $variant->reserved = (int) $variant->reserved - $quantity;
            $variant->save();

            $product->reserved = (int) $product->reserved - $quantity;
            $product->save();

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

            ActivityLogger::log(
                'reservation_manual_release',
                $lockedReservation,
                'آزادسازی دستی رزرو موجودی',
                $properties,
            );

            Log::info('MANUAL_INVENTORY_RESERVATION_RELEASED', $properties);
        });
    }
}
