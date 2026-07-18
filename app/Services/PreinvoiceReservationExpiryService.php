<?php

namespace App\Services;

use App\Models\PreinvoiceOrder;
use App\Models\User;

class PreinvoiceReservationExpiryService
{
    public function __construct(private readonly PreinvoiceReservationService $reservations) {}

    public function expireIfNeeded(PreinvoiceOrder $order, ?User $actor = null, string $source = 'scheduler'): array
    {
        if ($order->status === PreinvoiceOrder::STATUS_RESERVATION_EXPIRED) {
            return ['expired' => false, 'already_expired' => true, 'released_reservations' => 0, 'released_quantity' => 0];
        }

        if ($order->stock_frozen_until === null || $order->stock_frozen_until->isFuture()) {
            return ['expired' => false, 'released_reservations' => 0, 'released_quantity' => 0];
        }

        return $this->reservations->expirePreinvoiceReservations($order, $actor, 'reservation_expired_'.$source);
    }
}
