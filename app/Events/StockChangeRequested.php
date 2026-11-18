<?php

namespace App\Events;

use App\Models\StockMovement;
use LogicException;

/**
 * Command-like synchronous event, NOT a notification or queued event.
 * Dispatch only while holding the authoritative source document's DB lock
 * inside the transaction that finalizes that document.
 */
final class StockChangeRequested
{
    private ?StockMovement $movement = null;

    public function __construct(
        public readonly int $warehouseId,
        public readonly int $productId,
        public readonly int $variantId,
        public readonly int $delta,
        public readonly string $transactionType,
        public readonly string $reason,
        public readonly string $referenceType,
        public readonly int $referenceId,
        public readonly ?string $reference = null,
        public readonly ?int $actorId = null,
        public readonly ?string $note = null,
    ) {}

    public function assertNotHandled(): void
    {
        if ($this->movement !== null) {
            throw new LogicException('The same stock event was dispatched more than once.');
        }
    }

    public function markHandled(StockMovement $movement): void
    {
        $this->assertNotHandled();
        $this->movement = $movement;
    }

    /** Fail closed if no synchronous listener was registered. */
    public function movement(): StockMovement
    {
        return $this->movement ?? throw new LogicException('Stock change event had no synchronous stock listener.');
    }
}
