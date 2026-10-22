<?php

namespace App\Observers;

use App\Models\WarehouseStock;
use App\Services\InventoryWebhookService;
use App\Services\Sync\SiteStockChangeMarker;
use App\Support\ReservationSideEffects;

class WarehouseStockObserver
{
    public function created(WarehouseStock $stock): void
    {
        SiteStockChangeMarker::mark((int) $stock->product_id, (int) ($stock->product_variant_id ?? 0));
    }
    public function updated(WarehouseStock $stock): void
    {
        if (!$stock->wasChanged(['quantity'])) {
            return;
        }
        SiteStockChangeMarker::mark((int) $stock->product_id, (int) ($stock->product_variant_id ?? 0));

        $payload = [
            'warehouse_id' => $stock->warehouse_id,
            'product_id' => $stock->product_id,
            'quantity' => $stock->quantity,
            'changed' => $stock->getChanges(),
        ];
        ReservationSideEffects::dispatch(fn () => InventoryWebhookService::send('warehouse_stock.updated', $payload));
    }
}
