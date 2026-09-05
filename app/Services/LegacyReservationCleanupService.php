<?php

namespace App\Services;

use App\Models\PreinvoiceDraftReservation;
use App\Support\ActivityLogger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4-B — Safe Legacy Reservation Cleanup.
 *
 * This service closes the lifecycle of reservations that
 * ReservationClassificationService classifies as "legacy_candidate" and
 * repairs the reserved cache columns. It is deliberately NOT a release:
 *
 * - It never calls InventoryReservationReleaseService.
 * - It never calls WarehouseStockService::change().
 * - It never creates a stock movement.
 * - It never increases warehouse quantity.
 *
 * A normal release (InventoryReservationReleaseService::releaseDraftReservation)
 * assumes the reservation's held stock should return to central warehouse
 * availability. Legacy cleanup assumes the opposite: these rows are stale
 * bookkeeping left over from an abandoned/orphaned flow whose reserved
 * quantity was, in practice, never really backed by held stock in a way the
 * business still cares about — so cleanup only closes the reservation
 * lifecycle and brings the cached reserved counters back in line with
 * reality (via ReservationQueryService::rebuildForProducts(), which reads
 * the current set of still-active reservations and does not touch physical
 * stock tables at all).
 */
class LegacyReservationCleanupService
{
    public const RELEASE_REASON = 'legacy_cleanup';

    public const RELEASE_NOTE = 'Legacy reservation cleanup without stock return';

    public const ACTIVITY_ACTION = 'legacy_reservation_cleanup';

    public const ACTION_CLOSED = 'CLOSED';

    public const ACTION_SKIPPED = 'SKIPPED';

    public function __construct(
        private readonly ReservationQueryService $quantities,
        private readonly ReservationClassificationService $classification,
    ) {
    }

    public function candidatesQuery(
        int $staleHours = PreinvoiceDraftReservation::LEGACY_STALE_HOURS,
        ?CarbonInterface $at = null,
    ): Builder {
        return PreinvoiceDraftReservation::query()->legacyCleanupCandidates($staleHours, $at);
    }

    public function reportRows(
        int $staleHours = PreinvoiceDraftReservation::LEGACY_STALE_HOURS,
        ?CarbonInterface $at = null,
        ?int $orderId = null,
        ?int $variantId = null,
    ): Collection {
        $at ??= now();

        return $this->candidatesQuery($staleHours, $at)
            ->when($orderId !== null, fn (Builder $query) => $query->where('preinvoice_order_id', $orderId))
            ->when($variantId !== null, fn (Builder $query) => $query->where('variant_id', $variantId))
            ->with(['product:id,name', 'variant:id,product_id,variant_name,variant_code', 'order:id,status'])
            ->oldest('id')
            ->get()
            ->map(fn (PreinvoiceDraftReservation $reservation): array => [
                'reservation_id' => (int) $reservation->id,
                'product_id' => (int) $reservation->product_id,
                'product_name' => (string) ($reservation->product?->name ?? ''),
                'variant_id' => (int) $reservation->variant_id,
                'variant_name' => (string) ($reservation->variant?->variant_name ?? ''),
                'quantity' => (int) $reservation->quantity,
                'token' => (string) $reservation->token,
                'age_hours' => max(0, (int) ($reservation->last_seen_at ?? $reservation->created_at)?->diffInHours($at)),
                'preinvoice_order_id' => $reservation->preinvoice_order_id,
                'preinvoice_status' => $reservation->order?->status,
                'legacy_reason' => $reservation->legacyCleanupReason(),
            ]);
    }

    /**
     * Close the lifecycle of exactly the given reservation IDs, but only the
     * ones that both:
     *   (a) match the SQL legacyCleanupCandidates() scope, AND
     *   (b) independently classify as ReservationClassificationService's
     *       "legacy_candidate" label.
     * Guard (b) is a defense-in-depth check — the two are already meant to
     * agree (see ReservationClassificationService and
     * PreinvoiceDraftReservation::scopeManagementLabel() for the mirror) —
     * but this method never trusts the ID list alone: any ID that is not a
     * genuine legacy candidate (official/active/critical/consumed/invoice
     * linked) is skipped, never touched.
     *
     * @return array{
     *     processed: int, closed: int, skipped: int, quantity_closed: int,
     *     products_rebuilt: int, variants_rebuilt: int,
     *     rows: list<array{reservation_id:int, product:string, variant:string, quantity:int, classification:string, action:string, timestamp:string}>,
     * }
     */
    public function cleanup(array $reservationIds, int $staleHours, CarbonInterface $at, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($reservationIds, $staleHours, $at, $actorId): array {
            $ids = array_values(array_unique(array_map('intval', $reservationIds)));
            if ($ids === []) {
                return ['processed' => 0, 'closed' => 0, 'skipped' => 0, 'quantity_closed' => 0, 'products_rebuilt' => 0, 'variants_rebuilt' => 0, 'rows' => []];
            }

            $reservations = PreinvoiceDraftReservation::query()
                ->whereKey($ids)
                ->whereNull('released_at')
                ->with(['order.invoice', 'activeDrafts', 'product:id,name', 'variant:id,product_id,variant_name,variant_code'])
                ->lockForUpdate()
                ->get();

            $rows = [];
            $closedProductIds = [];
            $closed = 0;
            $quantityClosed = 0;

            foreach ($reservations as $reservation) {
                $classification = $this->classification->classify($reservation, $at);
                $isLegacyCandidate = $classification['label'] === ReservationClassificationService::LABEL_LEGACY_CANDIDATE
                    // Belt-and-braces: also confirm the row still matches the
                    // authoritative SQL scope at this exact instant, in case
                    // its state moved between listing and locking.
                    && $this->candidatesQuery($staleHours, $at)->whereKey($reservation->id)->exists();

                $variantLabel = trim(($reservation->variant?->variant_name ?? '').' '.($reservation->variant?->variant_code ?? ''));

                if (! $isLegacyCandidate) {
                    $rows[] = [
                        'reservation_id' => (int) $reservation->id,
                        'product' => (string) ($reservation->product?->name ?? ''),
                        'variant' => $variantLabel,
                        'quantity' => (int) $reservation->quantity,
                        'classification' => $classification['label'],
                        'action' => self::ACTION_SKIPPED,
                        'timestamp' => $at->toDateTimeString(),
                    ];

                    continue;
                }

                $oldState = $reservation->only([
                    'preinvoice_order_id', 'converted_at', 'released_at', 'release_reason', 'release_note',
                ]);

                $reservation->forceFill([
                    'released_at' => $at,
                    'released_by' => $actorId,
                    'release_reason' => self::RELEASE_REASON,
                    'release_note' => self::RELEASE_NOTE,
                ])->save();

                ActivityLogger::logForActor(
                    $actorId,
                    self::ACTIVITY_ACTION,
                    $reservation,
                    'پاکسازی رزرو Legacy بدون بازگشت موجودی',
                    [
                        'reservation_id' => (int) $reservation->id,
                        'product_id' => (int) $reservation->product_id,
                        'variant_id' => (int) $reservation->variant_id,
                        'quantity' => (int) $reservation->quantity,
                        'reason' => self::RELEASE_REASON,
                        'stock_return' => false,
                        'legacy_reason' => $reservation->legacyCleanupReason(),
                        'old_state' => $oldState,
                    ],
                );

                $closed++;
                $quantityClosed += (int) $reservation->quantity;
                $closedProductIds[] = (int) $reservation->product_id;

                $rows[] = [
                    'reservation_id' => (int) $reservation->id,
                    'product' => (string) ($reservation->product?->name ?? ''),
                    'variant' => $variantLabel,
                    'quantity' => (int) $reservation->quantity,
                    'classification' => $classification['label'],
                    'action' => self::ACTION_CLOSED,
                    'timestamp' => $at->toDateTimeString(),
                ];
            }

            $cache = $this->quantities->rebuildForProducts(array_values(array_unique($closedProductIds)), $at);

            return [
                'processed' => $reservations->count(),
                'closed' => $closed,
                'skipped' => $reservations->count() - $closed,
                'quantity_closed' => $quantityClosed,
                'products_rebuilt' => $cache['products'],
                'variants_rebuilt' => $cache['variants'],
                'rows' => $rows,
            ];
        }, 3);
    }
}
