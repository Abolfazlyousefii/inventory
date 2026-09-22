<?php

namespace App\Services;

use App\Models\PreinvoiceDraftReservation;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single read/query layer for reservation calculations.
 *
 * This service is the shared source for the numbers shown on the warehouse
 * reservations dashboard, the product/variant stock pages, and the audit
 * commands. It intentionally does not merge distinct business concepts:
 * the "reserved cache" definition (activeForReservedCache) stays narrower
 * than the "health monitoring" definition (healthMonitoredQuery), because
 * health monitoring is meant to also surface stale/abandoned rows that the
 * cache definition deliberately excludes. Nothing here writes to physical
 * stock, warehouse tables, or stock movements.
 */
class ReservationQueryService
{
    public function __construct(private readonly ReservationClassificationService $classification)
    {
    }

    /**
     * Base query for reservations counted toward the reserved cache
     * (product_variants.reserved / products.reserved).
     */
    public function activeQuery(?CarbonInterface $at = null): Builder
    {
        return PreinvoiceDraftReservation::query()->activeForReservedCache($at);
    }

    public function quantitiesByVariant(
        ?int $productId = null,
        array $variantIds = [],
        ?bool $official = null,
        ?CarbonInterface $at = null,
    ): Collection {
        return $this->activeQuery($at)
            ->when($productId !== null, fn (Builder $query) => $query->where('product_id', $productId))
            ->when($variantIds !== [], fn (Builder $query) => $query->whereIn('variant_id', $variantIds))
            ->when($official === true, fn (Builder $query) => $query->whereNotNull('preinvoice_order_id'))
            ->when($official === false, fn (Builder $query) => $query->whereNull('preinvoice_order_id'))
            ->groupBy('variant_id')
            ->selectRaw('variant_id, SUM(quantity) as quantity')
            ->pluck('quantity', 'variant_id')
            ->map(fn (mixed $quantity): int => (int) $quantity);
    }

    /**
     * Rebuild both cache levels for every variant belonging to the affected products.
     * Physical stock tables and stock movements are deliberately outside this method.
     *
     * @return array{products: int, variants: int}
     */
    public function rebuildForProducts(array $productIds, ?CarbonInterface $at = null): array
    {
        $report = app(ReservationProjectionService::class)->rebuild($productIds, $at);

        return [
            'products' => count($report['products']),
            'variants' => count($report['variants']),
        ];
    }

    /**
     * Dashboard summary cards. All numbers come from the same base
     * (visibleInWarehouseManagement) plus one existing model scope each — no
     * new business rules, only the existing definitions already used
     * elsewhere (controller list, health tab, cleanup service) surfaced as
     * counters. Moved from WarehouseReservationController::index() and
     * extended with the Phase 1 dashboard metrics.
     *
     * @return array{
     *     active: array{count:int,quantity:int},
     *     needs_review: array{count:int,quantity:int},
     *     releasable: array{count:int,quantity:int},
     *     historical_ambiguous: array{count:int,quantity:int},
     *     official: array{count:int,quantity:int},
     *     temporary: array{count:int,quantity:int},
     *     critical: array{count:int,quantity:int},
     *     legacy_candidates: array{count:int,quantity:int},
     * }
     */
    public function dashboardStatistics(?CarbonInterface $at = null): array
    {
        $at ??= now();
        $visible = PreinvoiceDraftReservation::query()->visibleInWarehouseManagement();

        $active = $this->aggregate((clone $visible)->activeForReservedCache($at));
        $needsReview = $this->aggregate((clone $visible)->needsBusinessAttention($at));
        // SQL is only a conservative population prefilter. Final membership
        // in either counter is decided exclusively by canonical classification.
        // This avoids hydrating every healthy temporary row on each dashboard read.
        $classified = (clone $visible)
            ->where(function (Builder $query) use ($at): void {
                $query->where(function (Builder $temporary) use ($at): void {
                    $temporary->abandonedTemporary(
                        PreinvoiceDraftReservation::DEFAULT_ONLINE_STALE_MINUTES,
                        PreinvoiceDraftReservation::DEFAULT_IN_PERSON_STALE_MINUTES,
                        $at,
                    );
                })->orWhereNotNull('preinvoice_order_id');
            })
            ->with(['order.invoice', 'activeDrafts'])
            ->get()
            ->map(fn (PreinvoiceDraftReservation $reservation): array => [
                'reservation' => $reservation,
                'state' => $this->classification->classify($reservation, $at)['state'],
            ]);
        $releasable = $this->aggregateClassified(
            $classified,
            ReservationClassificationService::STATE_TEMPORARY_STALE_RELEASABLE,
        );
        $historicalAmbiguous = $this->aggregateClassified(
            $classified,
            ReservationClassificationService::STATE_HISTORICAL_AMBIGUOUS,
        );

        // Official preinvoice reservations: visible rows tied to a preinvoice order.
        $official = $this->aggregate((clone $visible)->whereNotNull('preinvoice_order_id'));

        // Temporary reservations: visible rows with no preinvoice order.
        $temporary = $this->aggregate((clone $visible)->whereNull('preinvoice_order_id'));

        // Critical: official preinvoice reservations without an invoice for
        // longer than PREINVOICE_CRITICAL_AFTER_HOURS (existing business rule).
        //
        // scopeCriticalPreinvoice() on its own only checks
        // preinvoiceWithoutInvoice() + age — it does NOT exclude released rows
        // or zero-quantity rows, because it is also used as an OR-branch inside
        // scopeNeedsBusinessAttention() where the caller has already applied the
        // visibility gate. Used bare here it counted every historically
        // released/legacy-cleaned old preinvoice reservation as "critical", so
        // the dashboard reported far more critical rows than are actually still
        // being held (on the production dataset, more than double). Every card
        // must describe the same population as the rest of the dashboard, so it
        // is composed on the shared $visible base like the others.
        $critical = $this->aggregate((clone $visible)->criticalPreinvoice($at));

        // Legacy candidates: rows the legacy cleanup workflow would consider
        // (existing scope used by LegacyReservationCleanupService/its audit
        // command), narrowed to the same visible base for the same reason.
        // legacyCleanupCandidates() already excludes released/zero-quantity
        // rows itself, so this only guarantees the card can never drift from
        // the population the rest of the dashboard describes.
        $legacyCandidates = $this->aggregate((clone $visible)->legacyCleanupCandidates(
            PreinvoiceDraftReservation::LEGACY_STALE_HOURS,
            $at,
        ));

        $allClassified = PreinvoiceDraftReservation::query()
            ->whereNull('released_at')
            ->whereNull('release_reason')
            ->with(['order.invoice', 'activeDrafts'])
            ->get()
            ->map(fn (PreinvoiceDraftReservation $reservation): array => [
                'reservation' => $reservation,
                'state' => $this->classification->classify($reservation, $at)['state'],
            ]);
        $forStates = fn (array $states): Collection => $allClassified->filter(
            fn (array $entry): bool => in_array($entry['state'], $states, true),
        );
        $canonicalAggregate = fn (Collection $entries): array => [
            'count' => $entries->count(),
            'quantity' => (int) $entries->sum(fn (array $entry): int => (int) $entry['reservation']->quantity),
        ];
        $active = $canonicalAggregate($forStates([ReservationClassificationService::STATE_ACTIVE_VALID, ReservationClassificationService::STATE_TEMPORARY_ACTIVE, ReservationClassificationService::STATE_OFFICIAL_ACTIVE]));
        $needsReview = $canonicalAggregate($forStates([ReservationClassificationService::STATE_HISTORICAL_AMBIGUOUS, ReservationClassificationService::STATE_INVALID_OFFICIAL, ReservationClassificationService::STATE_LEGACY_SAFE]));
        $releasable = $canonicalAggregate($forStates([ReservationClassificationService::STATE_TEMPORARY_STALE_RELEASABLE]));
        $historicalAmbiguous = $canonicalAggregate($forStates([ReservationClassificationService::STATE_HISTORICAL_AMBIGUOUS]));
        $official = $canonicalAggregate($forStates([ReservationClassificationService::STATE_OFFICIAL_ACTIVE]));
        $temporary = $canonicalAggregate($forStates([ReservationClassificationService::STATE_ACTIVE_VALID, ReservationClassificationService::STATE_TEMPORARY_ACTIVE, ReservationClassificationService::STATE_TEMPORARY_STALE_RELEASABLE, ReservationClassificationService::STATE_HISTORICAL_AMBIGUOUS]));
        $critical = $canonicalAggregate($forStates([ReservationClassificationService::STATE_INVALID_OFFICIAL]));
        $legacyCandidates = $canonicalAggregate($forStates([ReservationClassificationService::STATE_LEGACY_SAFE]));

        return [
            'active' => $active,
            'needs_review' => $needsReview,
            'releasable' => $releasable,
            'historical_ambiguous' => $historicalAmbiguous,
            'official' => $official,
            'temporary' => $temporary,
            'critical' => $critical,
            'legacy_candidates' => $legacyCandidates,
        ];
    }

    public function paginateCanonicalManagement(
        array $filters,
        ReservationManagementPresentationService $presenter,
        int $perPage = 20,
        string $pageName = 'page',
        ?CarbonInterface $at = null,
    ): LengthAwarePaginator {
        $at ??= now();
        $query = $this->filteredManagementQuery(array_merge($filters, [
            'quick' => null,
            'status' => null,
            'classification' => null,
        ]), $at);
        $rows = $query->get()->map(function (PreinvoiceDraftReservation $reservation) use ($presenter, $at): PreinvoiceDraftReservation {
            $reservation->setAttribute('management_presentation', $presenter->present($reservation, $at));
            return $reservation;
        });
        $classification = match ($filters['classification'] ?? null) {
            'official_preinvoice' => ReservationClassificationService::STATE_OFFICIAL_ACTIVE,
            'temporary_orphan' => ReservationClassificationService::STATE_TEMPORARY_STALE_RELEASABLE,
            'legacy_candidate' => ReservationClassificationService::STATE_LEGACY_SAFE,
            'critical' => ReservationClassificationService::STATE_INVALID_OFFICIAL,
            default => $filters['classification'] ?? null,
        };
        $bucket = match ($filters['quick'] ?? null) {
            PreinvoiceDraftReservation::QUICK_ACTIONABLE => ReservationManagementPresentationService::BUCKET_ACTIONABLE,
            PreinvoiceDraftReservation::QUICK_REVIEW => ReservationManagementPresentationService::BUCKET_REVIEW,
            default => ReservationManagementPresentationService::BUCKET_CURRENT,
        };
        $bucket = match ($filters['status'] ?? null) {
            PreinvoiceDraftReservation::STATUS_RELEASABLE,
            PreinvoiceDraftReservation::STATUS_ABANDONED,
            PreinvoiceDraftReservation::STATUS_EXPIRED => ReservationManagementPresentationService::BUCKET_ACTIONABLE,
            PreinvoiceDraftReservation::STATUS_NEEDS_REVIEW,
            PreinvoiceDraftReservation::STATUS_CRITICAL => ReservationManagementPresentationService::BUCKET_REVIEW,
            PreinvoiceDraftReservation::STATUS_RELEASED => ReservationManagementPresentationService::BUCKET_HISTORY,
            default => $bucket,
        };
        if ($classification !== null) {
            $bucket = $presenter->presentClassification(['state' => $classification])['bucket'];
        } elseif (in_array($filters['lifecycle'] ?? null, [
            ReservationClassificationService::LIFECYCLE_CONSUMED,
            ReservationClassificationService::LIFECYCLE_RELEASED,
        ], true)) {
            $bucket = ReservationManagementPresentationService::BUCKET_HISTORY;
        }
        $rows = $rows->filter(fn (PreinvoiceDraftReservation $reservation): bool => $reservation->management_presentation['bucket'] === $bucket);
        if (($filters['status'] ?? null) === PreinvoiceDraftReservation::STATUS_PREINVOICE_ACTIVE) {
            $rows = $rows->filter(fn (PreinvoiceDraftReservation $reservation): bool =>
                $reservation->management_presentation['classification']['state'] === ReservationClassificationService::STATE_OFFICIAL_ACTIVE
            );
        }
        if ($classification !== null) {
            $rows = $rows->filter(fn (PreinvoiceDraftReservation $reservation): bool =>
                $reservation->management_presentation['classification']['state'] === $classification
            );
        }
        $rows = $rows->sortBy(fn (PreinvoiceDraftReservation $reservation): string => sprintf(
            '%02d-%020d', $reservation->management_presentation['priority'], PHP_INT_MAX - (int) $reservation->id,
        ))->values();
        $page = max(1, LengthAwarePaginator::resolveCurrentPage($pageName));

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => $pageName],
        );
    }

    /** @return array{count:int,quantity:int} */
    private function aggregate(Builder $query): array
    {
        $row = $query
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(quantity), 0) as aggregate_quantity')
            ->first();

        return [
            'count' => (int) $row->aggregate_count,
            'quantity' => (int) $row->aggregate_quantity,
        ];
    }

    /** @param Collection<int, array{reservation:PreinvoiceDraftReservation,state:string}> $classified */
    private function aggregateClassified(Collection $classified, string $state): array
    {
        $reservations = $classified
            ->filter(fn (array $entry): bool => $entry['state'] === $state)
            ->pluck('reservation');

        return [
            'count' => $reservations->count(),
            'quantity' => (int) $reservations->sum('quantity'),
        ];
    }

    /**
     * Base query for reservation-health monitoring. Deliberately broader than
     * activeQuery(): it includes abandoned/stale temporary reservations that
     * the reserved-cache definition excludes, because health monitoring exists
     * to surface exactly those stale rows. Moved from
     * ReservationHealthService::monitoredReservations() verbatim.
     */
    public function healthMonitoredQuery(): Builder
    {
        $table = (new PreinvoiceDraftReservation)->getTable();

        return PreinvoiceDraftReservation::query()
            ->where("{$table}.quantity", '>', 0)
            ->whereNull("{$table}.released_at")
            ->whereNull("{$table}.release_reason")
            ->whereDoesntHave('order.invoice')
            ->where(function (Builder $query) use ($table): void {
                $query->whereNull("{$table}.reservation_scope")
                    ->orWhere("{$table}.reservation_scope", '!=', 'official')
                    ->orWhereDoesntHave('order', function (Builder $order): void {
                        $order->where('status', \App\Models\PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE)
                            ->orWhereNotNull('stock_released_at');
                    });
            });
    }

    /**
     * Single filtering entry point for the reservation management table.
     * Every filter the controller/view accepts is composed here, as query
     * scopes — never as a Collection filter after the query runs, so
     * pagination totals stay SQL-accurate.
     *
     * $filters keys (all optional/nullable): status, quick, search,
     * date_from, date_to, classification, lifecycle, age_hours, user_id,
     * product_id, variant_id, customer_id, customer_search.
     *
     * Released reservations are excluded by default (matching the existing
     * "reservations" tab behavior, where released rows only ever appear in
     * the separate "history" tab) unless lifecycle=released is explicitly
     * requested, in which case the "still open" gate
     * (visibleInWarehouseManagement/excludeOrphaned) is skipped since it
     * does not apply to a terminal, released reservation.
     */
    public function filteredManagementQuery(array $filters, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();
        $lifecycle = $filters['lifecycle'] ?? null;
        $showReleased = $lifecycle === ReservationClassificationService::LIFECYCLE_RELEASED;

        $query = PreinvoiceDraftReservation::query()
            ->with([
                'product:id,name,sku,code',
                'variant:id,product_id,variant_name,variety_name,variant_code,variety_code',
                'user:id,name',
                'order:id,uuid,status,stock_released_at,created_at,updated_at,customer_id,customer_name,customer_mobile',
                'order.invoice:id,preinvoice_order_id',
                'releasedBy:id,name',
                // Classifying each row (ReservationClassificationService::classify(),
                // called once per rendered row in the table view) checks
                // hasActiveRelatedDraft() for temporary reservations, which runs a
                // fresh query per row unless this relation is already loaded —
                // eager-loading it here avoids that N+1 on the paginated listing.
                'activeDrafts:id,draft_token,status',
            ]);

        if ($showReleased) {
            $query->whereNotNull('released_at');
        } else {
            $query->whereNull('released_at')->whereNull('release_reason');
        }

        return $query
            ->forLifecycle($lifecycle)
            ->forManagementStatus($filters['status'] ?? null)
            ->forManagementQuickFilter($filters['quick'] ?? null)
            ->managementLabel($filters['classification'] ?? null, $at)
            ->olderThanHours($filters['age_hours'] ?? null, $at)
            ->forCreator($filters['user_id'] ?? null)
            ->forProduct($filters['product_id'] ?? null)
            ->forVariant($filters['variant_id'] ?? null)
            ->forCustomer($filters['customer_id'] ?? null, $filters['customer_search'] ?? null)
            ->managementSearch($filters['search'] ?? null)
            ->when($filters['date_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))
            ->orderByManagementPriority();
    }

    /**
     * Full display-only classification (type/lifecycle/health/label) for a
     * single reservation row. Delegates entirely to
     * ReservationClassificationService — see that class for the rules.
     *
     * @return array{type:string, lifecycle:string, health:string, label:string}
     */
    public function classify(PreinvoiceDraftReservation $reservation, ?CarbonInterface $at = null): array
    {
        return $this->classification->classify($reservation, $at);
    }
}
