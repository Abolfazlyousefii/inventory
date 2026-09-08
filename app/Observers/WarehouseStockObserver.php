<?php

namespace App\Observers;

use App\Models\WarehouseStock;
use App\Services\InventoryWebhookService;
use App\Support\ReservationSideEffects;

class WarehouseStockObserver
{
    public function updated(WarehouseStock $stock): void
    {
        if (!$stock->wasChanged(['quantity'])) {
            return;
        }

        $payload = [
            'warehouse_id' => $stock->warehouse_id,
            'product_id' => $stock->product_id,
            'quantity' => $stock->quantity,
            'changed' => $stock->getChanges(),
        ];
        ReservationSideEffects::dispatch(fn () => InventoryWebhookService::send('warehouse_stock.updated', $payload));
    }
}
