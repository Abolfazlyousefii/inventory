<?php

namespace App\Events;

use LogicException;

/**
 * Synchronous, transactional stock instruction for one complete Site hold.
 * No queue, broadcast, or after-commit dispatch: the stock must change before
 * the surrounding reservation transaction can be committed.
 */
final class SiteStockHoldChangeRequested
{
    public const RESERVE = 'reserve';
    public const RELEASE = 'release';

    private bool $handled = false;

    public function __construct(
        public readonly int $holdId,
        public readonly string $operation,
    ) {
        if ($holdId < 1 || ! in_array($operation, [self::RESERVE, self::RELEASE], true)) {
            throw new LogicException('Invalid Site hold stock event.');
        }
    }

    public function assertNotHandled(): void
    {
        if ($this->handled) {
            throw new LogicException('Site stock hold event has already been handled.');
        }
    }

    public function markHandled(): void
    {
        $this->assertNotHandled();
        $this->handled = true;
    }

    /** Missing or asynchronous listeners must never silently complete a hold. */
    public function assertHandled(): void
    {
        if (! $this->handled) {
            throw new LogicException('Site stock hold event had no synchronous stock listener.');
        }
    }
}
