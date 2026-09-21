<?php

namespace Tests\Unit;

use App\Services\ReservationProjectionService;
use App\Support\ReservationSideEffects;
use Illuminate\Database\QueryException;
use Mockery;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class ReservationSideEffectsTest extends TestCase
{
    public function test_nested_calls_coalesce_sorted_product_ids(): void
    {
        $projection = Mockery::mock(ReservationProjectionService::class);
        $projection->shouldReceive('rebuild')->once()->with([1, 2, 3])->andReturn([]);
        $this->app->instance(ReservationProjectionService::class, $projection);

        ReservationSideEffects::transaction(function (): void {
            ReservationSideEffects::touchProduct(3);
            ReservationSideEffects::run(fn () => ReservationSideEffects::touchProduct(1));
            ReservationSideEffects::transaction(fn () => ReservationSideEffects::touchProduct(2));
            ReservationSideEffects::touchProduct(3);
        });
    }

    public function test_exception_discards_attempt_state_and_separate_transactions_do_not_share_it(): void
    {
        $projection = Mockery::mock(ReservationProjectionService::class);
        $projection->shouldReceive('rebuild')->once()->with([2])->andReturn([]);
        $this->app->instance(ReservationProjectionService::class, $projection);

        try {
            ReservationSideEffects::transaction(function (): void {
                ReservationSideEffects::touchProduct(9);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        ReservationSideEffects::transaction(fn () => ReservationSideEffects::touchProduct(2));
    }

    public function test_deadlock_retry_uses_only_successful_attempt_ids(): void
    {
        $projection = Mockery::mock(ReservationProjectionService::class);
        $projection->shouldReceive('rebuild')->once()->with([4])->andReturn([]);
        $this->app->instance(ReservationProjectionService::class, $projection);
        $attempt = 0;

        ReservationSideEffects::transaction(function () use (&$attempt): void {
            $attempt++;
            ReservationSideEffects::touchProduct($attempt === 1 ? 8 : 4);
            if ($attempt === 1) {
                $previous = new PDOException('Deadlock found when trying to get lock', 40001);
                throw new QueryException('sqlite', 'update products set reserved = 1', [], $previous);
            }
        }, 2);

        $this->assertSame(2, $attempt);
    }
}
