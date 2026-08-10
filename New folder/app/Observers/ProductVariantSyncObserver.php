<?php

namespace App\Observers;

use App\Models\ProductVariant;
use App\Services\AriyajanebiSyncService;

class ProductVariantSyncObserver
{
    public function updated(ProductVariant $variant): void
    {
        if (!$variant->wasChanged(['sell_price', 'stock'])) {
            return;
        }

        AriyajanebiSyncService::syncVariant($variant);
    }

    public function created(ProductVariant $variant): void
    {
        AriyajanebiSyncService::syncVariant($variant);
    }
}
