<?php

namespace App\Services;

use App\Models\PreinvoiceDraftReservation;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
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
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($productIds === []) {
            return ['products' => 0, 'variants' => 0];
        }

        $products = DB::table('products')->whereIn('id', $productIds)->lockForUpdate()->get(['id']);
        $variants = DB::table('product_variants')->whereIn('product_id', $productIds)->lockForUpdate()->get(['id', 'product_id']);
        $expected = $this->quantitiesByVariant(
            variantIds: $variants->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            at: $at,
        );
        $productTotals = [];

        foreach ($variants as $variant) {
            $quantity = (int) ($expected[(int) $variant->id] ?? 0);
            DB::table('product_variants')->where('id', $variant->id)->update([
                'reserved' => $quantity,
                'updated_at' => now(),
            ]);
            $productTotals[(int) $variant->product_id] = ($productTotals[(int) $variant->product_id] ?? 0) + $quantity;
        }

        foreach ($products as $product) {
            DB::table('products')->where('id', $product->id)->update([
                'reserved' => (int) ($productTotals[(int) $product->id] ?? 0),
                'updated_at' => now(),
            ]);
        }

        return ['products' => $products->count(), 'variants' => $variants->count()];
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
        $releasable = $this->aggregate((clone $visible)->abandonedTemporary(
            PreinvoiceDraftReservation::DEFAULT_ONLINE_STALE_MINUTES,
            PreinvoiceDraftReservation::DEFAULT_IN_PERSON_STALE_MINUTES,
            $at,
        ));

        // Official preinvoice reservations: visible rows tied to a preinvoice order.
        $official = $this->aggregate((clone $visible)->whereNotNull('preinvoice_order_id'));

        // Temporary reservations: visible rows with no preinvoice order.
        $temporary = $this->aggregate((clone $visible)->whereNull('preinvoice_order_id'));

        // Critical: official preinvoice reservations without an invoice for
        // longer than PREINVOICE_CRITICAL_AFTER_HOURS (existing business rule).
        $critical = $this->aggregate(PreinvoiceDraftReservation::query()->criticalPreinvoice($at));

        // Legacy candidates: rows the legacy cleanup workflow would consider
        // (existing scope used by LegacyReservationCleanupService/its audit command).
        $legacyCandidates = $this->aggregate(PreinvoiceDraftReservation::query()->legacyCleanupCandidates(
            PreinvoiceDraftReservation::LEGACY_STALE_HOURS,
            $at,
        ));

        return [
            'active' => $active,
            'needs_review' => $needsReview,
            'releasable' => $releasable,
            'official' => $official,
            'temporary' => $temporary,
            'critical' => $critical,
            'legacy_candidates' => $legacyCandidates,
        ];
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
                'order:id,uuid,created_at,updated_at,customer_id,customer_name,customer_mobile',
                'order.invoice:id,preinvoice_order_id',
                'releasedBy:id,name',
            ]);

        if ($showReleased) {
            $query->whereNotNull('released_at');
        } else {
            $query->visibleInWarehouseManagement()
                ->excludeOrphaned(
                    PreinvoiceDraftReservation::DEFAULT_ONLINE_STALE_MINUTES,
                    PreinvoiceDraftReservation::DEFAULT_IN_PERSON_STALE_MINUTES,
                    $at,
                );
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
