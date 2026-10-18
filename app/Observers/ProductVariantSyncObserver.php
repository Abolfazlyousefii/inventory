<?php

namespace App\Observers;

use App\Models\ProductVariant;
use App\Services\AriyajanebiSyncService;
use App\Services\ProductVariantPriceNormalizationService;
use App\Support\ReservationSideEffects;

class ProductVariantSyncObserver
{
    public function updated(ProductVariant $variant): void
    {
        if ($variant->wasChanged(['sell_price', 'product_id'])) {
            // Wait for the whole transaction (including price-change documents) to finish.
            ReservationSideEffects::dispatch(fn () => app(ProductVariantPriceNormalizationService::class)->normalize((int) $variant->product_id));
        }

        if (!$variant->wasChanged(['sell_price', 'stock'])) {
            return;
        }

        ReservationSideEffects::dispatch(fn () => AriyajanebiSyncService::syncVariant($variant->fresh() ?? $variant));
    }

    public function created(ProductVariant $variant): void
    {
        ReservationSideEffects::dispatch(fn () => app(ProductVariantPriceNormalizationService::class)->normalize((int) $variant->product_id));
        ReservationSideEffects::dispatch(fn () => AriyajanebiSyncService::syncVariant($variant->fresh() ?? $variant));
    }
}
