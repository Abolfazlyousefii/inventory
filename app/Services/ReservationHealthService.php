<?php

namespace App\Services;

use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class ReservationHealthService
{
    public const ISSUE_ORPHANED = 'orphaned';

    public const ISSUE_OLD = 'old';

    public const ISSUE_CACHE_MISMATCH = 'cache_mismatch';

    /**
     * @return array{healthy: int, old: int, orphaned: int, cache_mismatch: int}
     */
    public function summary(?CarbonInterface $at = null): array
    {
        $at ??= now();

        return [
            'healthy' => $this->healthyReservations($at)->count(),
            'old' => $this->oldReservations($at)->count(),
            'orphaned' => PreinvoiceDraftReservation::query()->orphaned(
                PreinvoiceDraftReservation::DEFAULT_ONLINE_STALE_MINUTES,
                PreinvoiceDraftReservation::DEFAULT_IN_PERSON_STALE_MINUTES,
                $at,
            )->count(),
            'cache_mismatch' => $this->cacheMismatchQuery()->count(),
        ];
    }

    public function paginateIssues(
        int $perPage = 20,
        string $pageName = 'health_page',
        ?CarbonInterface $at = null,
    ): LengthAwarePaginator {
        return $this->issueQuery($at ?? now())
            ->orderBy('priority')
            ->orderBy('occurred_at')
            ->orderBy('product_name')
            ->paginate(max(1, min(100, $perPage)), ['*'], $pageName)
            ->withQueryString();
    }

    public function issueRows(?CarbonInterface $at = null): LazyCollection
    {
        return $this->issueQuery($at ?? now())
            ->orderBy('priority')
            ->orderBy('occurred_at')
            ->orderBy('product_name')
            ->cursor();
    }

    private function healthyReservations(CarbonInterface $at): EloquentBuilder
    {
        return $this->monitoredReservations()
            ->where(function (EloquentBuilder $query) use ($at): void {
                $query->whereHas('order', function (EloquentBuilder $order): void {
                    $order->whereIn('status', PreinvoiceOrder::reservationHoldingStatuses())
                        ->whereNull('stock_released_at');
                })->orWhere(function (EloquentBuilder $query) use ($at): void {
                    $query->whereIn('reservation_scope', [
                        PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE,
                        PreinvoiceDraftReservation::SCOPE_TEMPORARY_IN_PERSON,
                    ])->whereNotNull('token')
                        ->where('token', '!=', '');

                    $this->applyValidHeartbeat($query, $at);
                });
            });
    }

    private function oldReservations(CarbonInterface $at): EloquentBuilder
    {
        $table = (new PreinvoiceDraftReservation)->getTable();

        return $this->monitoredReservations()
            ->excludeOrphaned(
                PreinvoiceDraftReservation::DEFAULT_ONLINE_STALE_MINUTES,
                PreinvoiceDraftReservation::DEFAULT_IN_PERSON_STALE_MINUTES,
                $at,
            )
            ->whereRaw(
                "COALESCE({$table}.last_seen_at, {$table}.created_at) <= ?",
                [$at->copy()->subMinutes(PreinvoiceDraftReservation::OLD_RESERVATION_MINUTES)],
            );
    }

    private function monitoredReservations(): EloquentBuilder
    {
        $table = (new PreinvoiceDraftReservation)->getTable();

        return PreinvoiceDraftReservation::query()
            ->where("{$table}.quantity", '>', 0)
            ->whereNull("{$table}.released_at")
            ->whereNull("{$table}.release_reason")
            ->whereDoesntHave('order.invoice')
            ->where(function (EloquentBuilder $query) use ($table): void {
                $query->whereNull("{$table}.reservation_scope")
                    ->orWhere("{$table}.reservation_scope", '!=', 'official')
                    ->orWhereDoesntHave('order', function (EloquentBuilder $order): void {
                        $order->where('status', PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE)
                            ->orWhereNotNull('stock_released_at');
                    });
            });
    }

    private function applyValidHeartbeat(EloquentBuilder $query, CarbonInterface $at): void
    {
        $table = (new PreinvoiceDraftReservation)->getTable();
        $onlineCutoff = $at->copy()->subMinutes(PreinvoiceDraftReservation::DEFAULT_ONLINE_STALE_MINUTES);
        $inPersonCutoff = $at->copy()->subMinutes(PreinvoiceDraftReservation::DEFAULT_IN_PERSON_STALE_MINUTES);

        $query->whereNotNull("{$table}.last_seen_at")
            ->where(function (EloquentBuilder $query) use ($table, $onlineCutoff, $inPersonCutoff): void {
                $query->where(function (EloquentBuilder $query) use ($table, $onlineCutoff): void {
                    $query->where("{$table}.reservation_scope", PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE)
                        ->where("{$table}.last_seen_at", '>', $onlineCutoff);
                })->orWhere(function (EloquentBuilder $query) use ($table, $inPersonCutoff): void {
                    $query->where("{$table}.reservation_scope", PreinvoiceDraftReservation::SCOPE_TEMPORARY_IN_PERSON)
                        ->where("{$table}.last_seen_at", '>', $inPersonCutoff);
                });
            });
    }

    private function issueQuery(CarbonInterface $at): QueryBuilder
    {
        $issues = $this->orphanIssueQuery($at)
            ->unionAll($this->oldIssueQuery($at))
            ->unionAll($this->cacheMismatchIssueQuery());

        return DB::query()->fromSub($issues, 'reservation_health_issues');
    }

    private function orphanIssueQuery(CarbonInterface $at): EloquentBuilder
    {
        $table = (new PreinvoiceDraftReservation)->getTable();

        return PreinvoiceDraftReservation::query()
            ->orphaned(
                PreinvoiceDraftReservation::DEFAULT_ONLINE_STALE_MINUTES,
                PreinvoiceDraftReservation::DEFAULT_IN_PERSON_STALE_MINUTES,
                $at,
            )
            ->join('products as health_products', 'health_products.id', '=', "{$table}.product_id")
            ->join('product_variants as health_variants', 'health_variants.id', '=', "{$table}.variant_id")
            ->select([
                "{$table}.id as reservation_id",
                "{$table}.product_id",
                "{$table}.variant_id",
                'health_products.name as product_name',
                'health_variants.variant_name',
                'health_variants.variety_name',
                'health_variants.variant_code',
                'health_variants.variety_code',
                "{$table}.quantity",
                DB::raw('NULL as cached_quantity'),
                DB::raw("COALESCE({$table}.last_seen_at, {$table}.created_at) as occurred_at"),
                DB::raw("'orphaned' as issue_type"),
                DB::raw("'رزرو بدون مالک' as issue_label"),
                DB::raw("'needs_action' as status"),
                DB::raw("'نیاز اقدام' as status_label"),
                DB::raw('1 as priority'),
            ]);
    }

    private function oldIssueQuery(CarbonInterface $at): EloquentBuilder
    {
        $table = (new PreinvoiceDraftReservation)->getTable();

        return $this->oldReservations($at)
            ->join('products as health_products', 'health_products.id', '=', "{$table}.product_id")
            ->join('product_variants as health_variants', 'health_variants.id', '=', "{$table}.variant_id")
            ->select([
                "{$table}.id as reservation_id",
                "{$table}.product_id",
                "{$table}.variant_id",
                'health_products.name as product_name',
                'health_variants.variant_name',
                'health_variants.variety_name',
                'health_variants.variant_code',
                'health_variants.variety_code',
                "{$table}.quantity",
                DB::raw('NULL as cached_quantity'),
                DB::raw("COALESCE({$table}.last_seen_at, {$table}.created_at) as occurred_at"),
                DB::raw("'old' as issue_type"),
                DB::raw("'رزرو قدیمی' as issue_label"),
                DB::raw("'needs_review' as status"),
                DB::raw("'نیاز بررسی' as status_label"),
                DB::raw('2 as priority'),
            ]);
    }

    private function cacheMismatchIssueQuery(): QueryBuilder
    {
        return $this->cacheMismatchQuery()
            ->select([
                DB::raw('NULL as reservation_id'),
                'health_variants.product_id',
                'health_totals.variant_id',
                'health_products.name as product_name',
                'health_variants.variant_name',
                'health_variants.variety_name',
                'health_variants.variant_code',
                'health_variants.variety_code',
                'health_totals.reservation_quantity as quantity',
                'health_variants.reserved as cached_quantity',
                'health_totals.occurred_at',
                DB::raw("'cache_mismatch' as issue_type"),
                DB::raw("'اختلاف cache رزرو' as issue_label"),
                DB::raw("'mismatch' as status"),
                DB::raw("'نیاز بررسی' as status_label"),
                DB::raw('3 as priority'),
            ]);
    }

    private function cacheMismatchQuery(): QueryBuilder
    {
        $table = (new PreinvoiceDraftReservation)->getTable();
        $totals = $this->monitoredReservations()
            ->selectRaw("{$table}.variant_id, SUM({$table}.quantity) as reservation_quantity, MAX(COALESCE({$table}.last_seen_at, {$table}.created_at)) as occurred_at")
            ->groupBy("{$table}.variant_id");

        return DB::query()
            ->fromSub($totals, 'health_totals')
            ->join('product_variants as health_variants', 'health_variants.id', '=', 'health_totals.variant_id')
            ->join('products as health_products', 'health_products.id', '=', 'health_variants.product_id')
            ->whereColumn('health_totals.reservation_quantity', '!=', 'health_variants.reserved');
    }
}
