<?php

namespace App\Services;

use App\Models\PreinvoiceDraftReservation;
use App\Support\ActivityLogger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LegacyReservationCleanupService
{
    public const RELEASE_REASON = 'legacy_cleanup';

    public const RELEASE_NOTE = 'Legacy reservation cleanup without stock return';

    public function __construct(private readonly ReservationQueryService $quantities)
    {
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

    /** @return array{cleaned: int, quantity: int, products_rebuilt: int, variants_rebuilt: int} */
    public function cleanup(array $reservationIds, int $staleHours, CarbonInterface $at): array
    {
        return DB::transaction(function () use ($reservationIds, $staleHours, $at): array {
            $reservations = $this->candidatesQuery($staleHours, $at)
                ->whereKey(array_values(array_unique(array_map('intval', $reservationIds))))
                ->with('order:id,status')
                ->lockForUpdate()
                ->get();

            if ($reservations->isEmpty()) {
                return ['cleaned' => 0, 'quantity' => 0, 'products_rebuilt' => 0, 'variants_rebuilt' => 0];
            }

            foreach ($reservations as $reservation) {
                $oldState = $reservation->only([
                    'preinvoice_order_id', 'converted_at', 'released_at', 'release_reason', 'release_note',
                ]);
                $reason = $reservation->legacyCleanupReason();

                $reservation->forceFill([
                    'released_at' => $at,
                    'released_by' => null,
                    'release_reason' => self::RELEASE_REASON,
                    'release_note' => self::RELEASE_NOTE,
                ])->save();

                ActivityLogger::logForActor(
                    null,
                    'reservation_legacy_cleanup',
                    $reservation,
                    'پاکسازی رزرو Legacy بدون بازگشت موجودی',
                    [
                        'reservation_id' => (int) $reservation->id,
                        'product_id' => (int) $reservation->product_id,
                        'variant_id' => (int) $reservation->variant_id,
                        'quantity' => (int) $reservation->quantity,
                        'old_state' => $oldState,
                        'reason' => $reason,
                    ],
                );
            }

            $cache = $this->quantities->rebuildForProducts(
                $reservations->pluck('product_id')->map(fn (mixed $id): int => (int) $id)->all(),
                $at,
            );

            return [
                'cleaned' => $reservations->count(),
                'quantity' => (int) $reservations->sum('quantity'),
                'products_rebuilt' => $cache['products'],
                'variants_rebuilt' => $cache['variants'],
            ];
        }, 3);
    }
}
