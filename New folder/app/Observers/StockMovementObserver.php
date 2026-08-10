<?php

namespace App\Observers;

use App\Models\StockMovement;
use App\Services\InventoryWebhookService;

class StockMovementObserver
{
    public function created(StockMovement $movement): void
    {
        InventoryWebhookService::send('stock_movement.created', [
            'movement_id' => $movement->id,
            'product_id' => $movement->product_id,
            'warehouse_id' => $movement->warehouse_id,
            'type' => $movement->type,
            'reason' => $movement->reason,
            'transaction_type' => $movement->transaction_type,
            'quantity' => $movement->quantity,
            'stock_before' => $movement->stock_before,
            'stock_after' => $movement->stock_after,
            'reference_type' => $movement->reference_type,
            'reference_id' => $movement->reference_id,
        ]);
    }
}
