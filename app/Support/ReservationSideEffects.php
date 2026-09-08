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

    public static function transaction(callable $callback, int $attempts = 1): mixed
    {
        return DB::transaction(fn () => self::run($callback), $attempts);
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
