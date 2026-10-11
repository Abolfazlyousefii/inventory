<?php

namespace App\Services;

use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\ReservationSideEffects;
use Carbon\CarbonInterface;
use RuntimeException;

class PreinvoiceDraftExpiryService {
    public function __construct( private readonly PreinvoiceDraftReservationService $draftReservations, private readonly PreinvoiceReservationService $officialReservations ) {}

    /**
     * Delete drafts once they reach the configured lifetime.
     * Any stock held by the draft is released in the same transaction before deletion.
     *
     * @return array{deleted_drafts:int,released_reservations:int,released_quantity:int}
     */
    public function deleteExpiredDrafts( ?CarbonInterface $at = null ): array {
        $at     ??= now();
        $cutoff = $at->copy()
            ->subHours(PreinvoiceOrder::DRAFT_RETENTION_HOURS);

        $candidates = PreinvoiceOrder::query()
            ->where('status', PreinvoiceOrder::STATUS_DRAFT)
            ->whereDoesntHave('invoice')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->get([ 'id', 'created_by' ]);

        $deletedDrafts        = 0;
        $releasedReservations = 0;
        $releasedQuantity     = 0;

        foreach ( $candidates as $candidate ) {
            $result = ReservationSideEffects::transaction(function () use ( $candidate, $cutoff ): ?array {
                User::query()
                    ->whereKey((int) $candidate->created_by)
                    ->lockForUpdate()
                    ->first();

                $order = PreinvoiceOrder::query()
                    ->whereKey((int) $candidate->id)
                    ->lockForUpdate()
                    ->first();

                if ( !$order
                     || $order->status !== PreinvoiceOrder::STATUS_DRAFT
                     || $order->invoice()
                         ->exists()
                     || $order->created_at === null
                     || $order->created_at->gt($cutoff) ) {
                    return null;
                }

                $official = $this->officialReservations->releaseOfficialReservationsForOrder($order, 'draft_expired_24h', 'پیش‌نویس پس از ۲۴ ساعت به‌صورت خودکار حذف شد.', null);

                $temporary = [ 'released' => [] ];
                $token     = trim((string) ( $order->draft_token ?? '' ));
                if ( $token !== '' ) {
                    $temporary = $this->draftReservations->releaseTokenReservations($token, (int) $order->created_by, 'draft_expired_24h', 'پیش‌نویس پس از ۲۴ ساعت به‌صورت خودکار حذف شد.');
                }

                $remaining = PreinvoiceDraftReservation::query()
                    ->whereNull('released_at')
                    ->whereNull('release_reason')
                    ->where('quantity', '>', 0)
                    ->where(function ( $query ) use ( $order, $token ): void {
                        $query->where('preinvoice_order_id', $order->id);
                        if ( $token !== '' ) {
                            $query->orWhere(function ( $tokenQuery ) use ( $order, $token ): void {
                                $tokenQuery->where('token', $token)
                                    ->where('user_id', $order->created_by);
                            });
                        }
                    })
                    ->exists();

                if ( $remaining ) {
                    throw new RuntimeException('Expired draft still has active stock reservations; deletion was rolled back.');
                }

                $temporaryRows = collect($temporary['released'] ?? []);
                $releasedCount = (int) ($official['released_reservations'] ?? 0) + $temporaryRows->count();
                $releasedQty = (int) ($official['released_quantity'] ?? 0) + (int) $temporaryRows->sum('quantity');

                ActivityLogger::logForActor(null, 'preinvoice_draft_auto_deleted', $order, 'پیش‌نویس پس از ۲۴ ساعت به‌صورت خودکار حذف شد.', [
                        'uuid'                  => $order->uuid,
                        'created_by'            => $order->created_by,
                        'released_reservations' => $releasedCount,
                        'released_quantity'     => $releasedQty,
                    ],);

                $order->items()
                    ->delete();
                $order->delete();

               return [
                   'released_reservations' => $releasedCount,
                   'released_quantity'     => $releasedQty,
               ];
          });

            if ( $result === null ) {
                continue;
            }

            $deletedDrafts++;
            $releasedReservations += (int) $result['released_reservations'];
            $releasedQuantity += (int) $result['released_quantity'];
        }
        return [
            'deleted_drafts'        => $deletedDrafts,
            'released_reservations' => $releasedReservations,
            'released_quantity'     => $releasedQuantity,
        ];
    }
}