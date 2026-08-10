<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\StockMovement;

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

        $variant = ProductVariant::query()
            ->whereKey($productVariantId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        if (! $variant) {
            abort(422, 'تغییر موجودی کالای دارای تنوع بدون تنوع معتبر مجاز نیست.');
        }

        $warehouseId = WarehouseStockService::centralWarehouseId();
        $before = (int) $variant->stock;

        WarehouseStockService::change($warehouseId, $productId, $quantityDelta, $productVariantId);

        $variant->refresh();
        $after = (int) $variant->stock;

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
    }
}
