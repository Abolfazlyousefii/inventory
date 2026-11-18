<?php

namespace App\Services;

use App\Events\StockOperationRecorded;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Transactional boundary for a sourced, variant-level warehouse quantity change.
 *
 * Business services must validate that the change is authorized and physically
 * justified BEFORE calling this method. A hold being consumed is a state change,
 * not an additional warehouse quantity change, and must not call this service.
 *
 * The operation key identifies ONE source operation, not an arbitrary request.
 * Replaying it with identical stock inputs returns the original movement without
 * touching warehouse stock; reusing it with different stock inputs is rejected.
 */
final class StockOperationService
{
    /**
     * @param array{
     *     reason:string,
     *     transaction_type:string,
     *     reference:string,
     *     reference_type:class-string|string,
     *     reference_id:int,
     *     user_id?:int|null,
     *     note?:string|null
     * } $movementAttributes
     */
    public function change(
        string $operationKey,
        int $warehouseId,
        int $productId,
        int $variantId,
        int $delta,
        array $movementAttributes,
    ): StockMovement {
        $operationKey = trim($operationKey);
        $reason = trim((string) ($movementAttributes['reason'] ?? ''));
        $transactionType = trim((string) ($movementAttributes['transaction_type'] ?? ''));
        $reference = trim((string) ($movementAttributes['reference'] ?? ''));
        $referenceType = trim((string) ($movementAttributes['reference_type'] ?? ''));
        $referenceId = (int) ($movementAttributes['reference_id'] ?? 0);

        if ($operationKey === '' || strlen($operationKey) > 160
            || $warehouseId < 1 || $productId < 1 || $variantId < 1
            || $delta === 0 || $delta === PHP_INT_MIN
            || $reason === '' || $transactionType === '' || $reference === ''
            || $referenceType === '' || $referenceId < 1) {
            throw new InvalidArgumentException('A stock operation requires a stable key, nonzero delta, valid variant and source document.');
        }

        // Only immutable stock inputs determine replay identity. The reviewing
        // actor and display note may vary between retries; they are recorded on
        // the FIRST committed StockMovement and never changed on replay.
        $fingerprint = hash('sha256', json_encode([
            $warehouseId, $productId, $variantId, $delta,
            $reason, $transactionType, $reference, $referenceType, $referenceId,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $operationKey, $warehouseId, $productId, $variantId, $delta,
            $movementAttributes, $reason, $transactionType, $reference,
            $referenceType, $referenceId, $fingerprint
        ): StockMovement {
            // The unique operation_key index serializes competing retries.
            // The incomplete row is NEVER committed: stock, movement and record
            // are all finalized or rolled back in this single DB transaction.
            $created = DB::table('stock_operation_records')->insertOrIgnore([
                'operation_key' => $operationKey,
                'request_hash' => $fingerprint,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'quantity_delta' => $delta,
                'source_type' => $referenceType,
                'source_id' => $referenceId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $record = DB::table('stock_operation_records')
                ->where('operation_key', $operationKey)
                ->lockForUpdate()
                ->first();

            if (! $record) {
                throw new LogicException('Stock operation record could not be acquired.');
            }

            if (! hash_equals((string) $record->request_hash, $fingerprint)) {
                throw new LogicException('Stock operation key was reused for different stock inputs.');
            }

            if (! $created) {
                if ($record->stock_movement_id === null
                    || $record->stock_before === null
                    || $record->stock_after === null) {
                    throw new LogicException('Incomplete stock operation record; refusing a second stock change.');
                }

                return StockMovement::query()->findOrFail((int) $record->stock_movement_id);
            }

            // Retain the existing freeze, row locking, available-stock checks,
            // variant/product projections, and observer/outbox behavior.
            $stock = WarehouseStockService::change(
                $warehouseId, $productId, $delta, $variantId
            );
            $after = (int) $stock->quantity;
            $before = $after - $delta;
            if ($before < 0) {
                throw new LogicException('Stock operation calculated an invalid starting balance.');
            }

            $movement = StockMovement::query()->create([
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'user_id' => $movementAttributes['user_id'] ?? null,
                'type' => $delta > 0 ? StockMovement::TYPE_IN : StockMovement::TYPE_OUT,
                'reason' => $reason,
                'transaction_type' => $transactionType,
                'quantity' => abs($delta),
                'stock_before' => $before,
                'stock_after' => $after,
                'note' => $movementAttributes['note'] ?? null,
                'reference' => $reference,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            DB::table('stock_operation_records')->where('id', $record->id)->update([
                'stock_movement_id' => (int) $movement->id,
                'stock_before' => $before,
                'stock_after' => $after,
                'updated_at' => now(),
            ]);

            // This event has no stock-writing listener. Laravel dispatches it
            // only after commit; existing movement/warehouse observers remain
            // responsible for current notifications and Site synchronization.
            event(new StockOperationRecorded((int) $record->id, (int) $movement->id, $operationKey));

            return $movement;
        }, 3);
    }
}
