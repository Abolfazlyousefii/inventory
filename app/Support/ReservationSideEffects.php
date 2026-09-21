<?php

namespace App\Support;

use App\Services\ReservationProjectionService;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/** Scope integration deferral to reservation mutations, including nested transactions. */
final class ReservationSideEffects
{
    private static int $depth = 0;
    /** @var array<int, true>|null */
    private static ?array $affectedProducts = null;

    public static function run(callable $callback): mixed
    {
        $snapshot = self::$affectedProducts;
        self::$depth++;
        try {
            return $callback();
        } catch (Throwable $exception) {
            self::$affectedProducts = $snapshot;
            throw $exception;
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
        return DB::transaction(function () use ($callback): mixed {
            $outermost = self::$affectedProducts === null;
            $previous = self::$affectedProducts;
            if ($outermost) {
                self::$affectedProducts = [];
            }

            try {
                $result = self::run($callback);
                if ($outermost && self::$affectedProducts !== []) {
                    $ids = array_keys(self::$affectedProducts);
                    sort($ids, SORT_NUMERIC);
                    app(ReservationProjectionService::class)->rebuild($ids);
                }

                return $result;
            } finally {
                if ($outermost) {
                    // Laravel may call this closure again on a deadlock retry. No
                    // attempt-local IDs survive either success or failure.
                    self::$affectedProducts = $previous;
                }
            }
        }, max(1, $attempts));
    }

    public static function touchProduct(int $productId): void
    {
        if (self::$affectedProducts === null || self::$depth < 1 || DB::transactionLevel() < 1) {
            throw new LogicException('Reservation projection changes require ReservationSideEffects::transaction().');
        }
        if ($productId > 0) {
            self::$affectedProducts[$productId] = true;
        }
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
