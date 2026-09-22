<?php

namespace App\Services;

use App\Models\PreinvoiceDraftReservation;
use App\Support\ActivityLogger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HistoricalReservationArchiveService
{
    public const RELEASE_REASON = 'historical_reconciliation_stock_neutral';
    public const RELEASE_NOTE = 'Historical reconciliation archive without warehouse-stock adjustment.';
    public const ACTIVITY_ACTION = 'historical_reservation_archive';
    public const ACTION_ARCHIVED = 'ARCHIVED';
    public const ACTION_SKIPPED = 'SKIPPED';

    public function __construct(private readonly ReservationClassificationService $classification)
    {
    }

    public function candidatesQuery(): Builder
    {
        return PreinvoiceDraftReservation::query()
            ->whereNull('released_at')
            ->whereNull('release_reason');
    }

    public function reportRows(array $ids, CarbonInterface $at): Collection
    {
        $normalized = $this->normalizeIds($ids);

        return PreinvoiceDraftReservation::query()
            ->when($normalized !== [], fn (Builder $query) => $query->whereKey($normalized))
            ->with(['order.invoice', 'activeDrafts', 'product:id,name', 'variant:id,product_id,variant_name,variant_code'])
            ->orderBy('id')
            ->get()
            ->map(function (PreinvoiceDraftReservation $reservation) use ($at): array {
                $classification = $this->classification->classify($reservation, $at);

                return [
                    'reservation_id' => (int) $reservation->id,
                    'product_id' => (int) $reservation->product_id,
                    'variant_id' => (int) $reservation->variant_id,
                    'quantity' => (int) $reservation->quantity,
                    'classification' => $classification['state'],
                    'eligible' => $classification['state'] === ReservationClassificationService::STATE_HISTORICAL_AMBIGUOUS,
                ];
            });
    }

    public function archive(array $ids, CarbonInterface $at, ?int $actorId = null): array
    {
        $normalized = $this->normalizeIds($ids);

        return DB::transaction(function () use ($normalized, $at, $actorId): array {
            $reservations = PreinvoiceDraftReservation::query()
                ->whereKey($normalized)
                ->lockForUpdate()
                ->get();
            $reservations->load(['order.invoice', 'activeDrafts']);

            $rows = [];
            $archived = 0;
            $quantityArchived = 0;

            foreach ($reservations as $reservation) {
                $classification = $this->classification->classify($reservation, $at);
                if ($classification['state'] !== ReservationClassificationService::STATE_HISTORICAL_AMBIGUOUS) {
                    $rows[] = $this->resultRow($reservation, $classification['state'], self::ACTION_SKIPPED);
                    continue;
                }

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
                    'پاکسازی تاریخی رزرو — بدون تغییر موجودی',
                    [
                        'reservation_id' => (int) $reservation->id,
                        'product_id' => (int) $reservation->product_id,
                        'variant_id' => (int) $reservation->variant_id,
                        'quantity' => (int) $reservation->quantity,
                        'classification' => ReservationClassificationService::STATE_HISTORICAL_AMBIGUOUS,
                        'reason' => self::RELEASE_REASON,
                        'warehouse_stock_changed' => false,
                        'stock_movement_created' => false,
                        'reserved_projection_changed' => false,
                    ],
                );

                $archived++;
                $quantityArchived += (int) $reservation->quantity;
                $rows[] = $this->resultRow($reservation, $classification['state'], self::ACTION_ARCHIVED);
            }

            foreach (array_diff($normalized, $reservations->modelKeys()) as $missingId) {
                $rows[] = [
                    'reservation_id' => (int) $missingId,
                    'product_id' => 0,
                    'variant_id' => 0,
                    'quantity' => 0,
                    'classification' => 'not_found',
                    'action' => self::ACTION_SKIPPED,
                ];
            }

            return [
                'requested' => count($normalized),
                'processed' => $reservations->count(),
                'archived' => $archived,
                'skipped' => count($normalized) - $archived,
                'quantity_archived' => $quantityArchived,
                'rows' => $rows,
            ];
        }, 3);
    }

    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
    }

    private function resultRow(PreinvoiceDraftReservation $reservation, string $classification, string $action): array
    {
        return [
            'reservation_id' => (int) $reservation->id,
            'product_id' => (int) $reservation->product_id,
            'variant_id' => (int) $reservation->variant_id,
            'quantity' => (int) $reservation->quantity,
            'classification' => $classification,
            'action' => $action,
        ];
    }
}
