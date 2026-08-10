<?php

namespace App\Observers;

use App\Models\WarehouseStock;
use App\Services\InventoryWebhookService;

class WarehouseStockObserver
{
    public function updated(WarehouseStock $stock): void
    {
        if (!$stock->wasChanged(['quantity'])) {
            return;
        }

        InventoryWebhookService::send('warehouse_stock.updated', [
            'warehouse_id' => $stock->warehouse_id,
            'product_id' => $stock->product_id,
            'quantity' => $stock->quantity,
            'changed' => $stock->getChanges(),
        ]);
    }
}
