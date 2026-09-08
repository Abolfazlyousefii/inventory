<?php

namespace App\Observers;

use App\Models\ProductVariant;
use App\Services\AriyajanebiSyncService;
use App\Support\ReservationSideEffects;

class ProductVariantSyncObserver
{
    public function updated(ProductVariant $variant): void
    {
        if (!$variant->wasChanged(['sell_price', 'stock'])) {
            return;
        }

        ReservationSideEffects::dispatch(fn () => AriyajanebiSyncService::syncVariant($variant->fresh() ?? $variant));
    }

    public function created(ProductVariant $variant): void
    {
        AriyajanebiSyncService::syncVariant($variant);
    }
}
