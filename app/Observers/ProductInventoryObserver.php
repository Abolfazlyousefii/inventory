<?php

namespace App\Observers;

use App\Models\Product;
use App\Services\InventoryWebhookService;
use App\Services\AriyajanebiSyncService;
use App\Services\Sync\SiteStockChangeMarker;
use App\Support\ReservationSideEffects;

class ProductInventoryObserver
{
    public function created(Product $product): void
    {
        SiteStockChangeMarker::mark((int) $product->id);
    }
    public function updated(Product $product): void
    {
        if ($product->wasChanged(['name','sku','price','stock','reserved','is_bundle',
            'is_sellable','bundle_stock_mode','bundle_discount_type',
            'bundle_discount_value','bundle_site_auto_publish'])) {
            SiteStockChangeMarker::mark((int) $product->id);
        }
        if (!$product->wasChanged(['price', 'stock', 'reserved'])) {
            return;
        }

        $payload = [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'price' => $product->price,
            'stock' => $product->stock,
            'reserved' => $product->reserved,
            'changed' => $product->getChanges(),
        ];
        ReservationSideEffects::dispatch(fn () => InventoryWebhookService::send('product.updated', $payload));

        // Sync all variants only when the product price changes.
        // Variant stock updates are handled by ProductVariantSyncObserver to avoid
        // applying one variant stock movement to all variants in external API.
        if ($product->wasChanged(['price'])) {
            ReservationSideEffects::dispatch(fn () => AriyajanebiSyncService::syncProduct($product->fresh() ?? $product));
        }
    }
}
