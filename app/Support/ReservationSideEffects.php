<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Scope integration deferral to reservation mutations, including nested transactions. */
final class ReservationSideEffects
{
    private static int $depth = 0;

    public static function run(callable $callback): mixed
    {
        self::$depth++;
        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function transaction(callable $callback, int $attempts = 3): mixed
    {
        // Reservation sync/release touches several locked stock rows. Under
        // concurrent heartbeat/sync/expiry requests MySQL can legitimately
        // choose one transaction as a deadlock victim. Laravel retries only
        // when the transaction is configured with more than one attempt.
        return DB::transaction(fn () => self::run($callback), max(1, $attempts));
    }

    public static function dispatch(callable $callback): void
    {
        if (self::$depth > 0 && DB::transactionLevel() > 0) {
            DB::afterCommit($callback);
            return;
        }

        $callback();
    }
}
