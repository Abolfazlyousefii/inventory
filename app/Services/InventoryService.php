<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function adjustCentralStock(
        int $productId,
        int $productVariantId,
        int $quantityDelta,
        string $reference,
        string $note = '',
        array $movementAttributes = []
    ): void {
        if ($quantityDelta === 0) {
            return;
        }

        DB::transaction(function () use (
            $productId,
            $productVariantId,
            $quantityDelta,
            $reference,
            $note,
            $movementAttributes
        ): void {
            $variantExists = ProductVariant::query()
                ->whereKey($productVariantId)
                ->where('product_id', $productId)
                ->exists();

            if (! $variantExists) {
                abort(422, 'تغییر موجودی کالای دارای تنوع بدون تنوع معتبر مجاز نیست.');
            }

            $warehouseId = WarehouseStockService::centralWarehouseId();

            // WarehouseStockService::change() locks the canonical warehouse stock row.
            // Keep that lock order as the serialization boundary for concurrent stock
            // changes instead of locking ProductVariant first and risking lock inversion.
            $stock = WarehouseStockService::change(
                $warehouseId,
                $productId,
                $quantityDelta,
                $productVariantId
            );

            $after = (int) $stock->quantity;
            $before = $after - $quantityDelta;

            $movementPayload = array_merge([
                'product_id' => $productId,
                'product_variant_id' => $productVariantId,
                'warehouse_id' => $warehouseId,
                'user_id' => auth()->id(),
                'type' => $quantityDelta > 0 ? StockMovement::TYPE_IN : StockMovement::TYPE_OUT,
                'reason' => $quantityDelta > 0 ? StockMovement::REASON_ADJUSTMENT : StockMovement::REASON_SALE,
                'quantity' => abs($quantityDelta),
                'stock_before' => $before,
                'stock_after' => $after,
                'reference' => $reference,
                'note' => $note,
            ], $movementAttributes);

            StockMovement::create($movementPayload);
        }, 3);
    }
}
