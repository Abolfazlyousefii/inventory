<?php

namespace App\StockListeners;

use App\Events\StockChangeRequested;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseInboundReceiptItem;
use App\Services\WarehouseInboundService;
use App\Services\WarehouseStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * One synchronous stock-mutating listener. Register explicitly once in
 * AppServiceProvider (outside Laravel's app/Listeners auto-discovery path).
 * Source-specific validation can be extended when another workflow migrates.
 */
final class ApplyStockChange
{
    public function handle(StockChangeRequested $event): void
    {
        $event->assertNotHandled();

        if (DB::transactionLevel() < 1) {
            throw new LogicException('Stock changes must be inside the source document transaction.');
        }

        if ($event->delta === 0 || $event->warehouseId < 1 || $event->productId < 1
            || $event->variantId < 1 || $event->referenceId < 1) {
            throw new LogicException('Invalid stock change event dimensions.');
        }

        // Inbound receiving must be attributable to a reviewer. The existing
        // stock_movements.user_id column is NOT NULL: reject invalid events
        // before any warehouse quantity is changed.
        if ($event->actorId === null || $event->actorId < 1) {
            throw new LogicException('An inbound stock event requires a valid actor ID.');
        }

        // Phase 1 supports warehouse inbound receipts only. Fail closed for any
        // other source until its locking and idempotency rules are implemented.
        if ($event->referenceType !== WarehouseInboundReceiptItem::class
            || $event->transactionType !== WarehouseInboundService::TRANSACTION_TYPE
            || $event->delta < 1) {
            throw new LogicException('Unsupported stock event source or direction.');
        }

        $item = WarehouseInboundReceiptItem::query()
            ->whereKey($event->referenceId)
            ->lockForUpdate()
            ->firstOrFail();

        if ((int) $item->product_id !== $event->productId
            || (int) $item->product_variant_id !== $event->variantId
        ) {
            throw ValidationException::withMessages(['items' => 'Receipt item stock identity does not match.']);
        }

        if (! $item->receipt || ! $item->receipt->isPending() || $item->stock_movement_id !== null) {
            throw ValidationException::withMessages(['receipt' => 'The receipt item is no longer pending.']);
        }

        if (! Warehouse::query()->whereKey($event->warehouseId)
            ->where('is_active', true)->whereIn('type', ['central', 'return'])->exists()) {
            throw ValidationException::withMessages(['items' => 'The target warehouse is not eligible for inbound stock.']);
        }

        // Existing StockMovement rows provide the audit/idempotency marker.
        // No new operations table is required for this workflow.
        if (StockMovement::query()
            ->where('transaction_type', $event->transactionType)
            ->where('reference_type', $event->referenceType)
            ->where('reference_id', $event->referenceId)
            ->exists()) {
            throw ValidationException::withMessages(['receipt' => 'Stock was already received for this item.']);
        }

        $stock = WarehouseStockService::change(
            $event->warehouseId,
            $event->productId,
            $event->delta,
            $event->variantId,
        );

        $after = (int) $stock->quantity;
        $movement = StockMovement::query()->create([
            'product_id' => $event->productId,
            'product_variant_id' => $event->variantId,
            'warehouse_id' => $event->warehouseId,
            'user_id' => $event->actorId,
            'type' => StockMovement::TYPE_IN,
            'reason' => $event->reason,
            'transaction_type' => $event->transactionType,
            'quantity' => $event->delta,
            'stock_before' => $after - $event->delta,
            'stock_after' => $after,
            'note' => $event->note,
            'reference' => $event->reference,
            'reference_type' => $event->referenceType,
            'reference_id' => $event->referenceId,
        ]);

        $event->markHandled($movement);
    }
}
