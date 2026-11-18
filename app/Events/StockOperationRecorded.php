<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A verified stock operation has committed, with an immutable audit record.
 * Does not mutate stock; listeners must be idempotent, read-only or outbound.
 */
final class StockOperationRecorded implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly int $operationRecordId,
        public readonly int $stockMovementId,
        public readonly string $operationKey,
    ) {}
}
