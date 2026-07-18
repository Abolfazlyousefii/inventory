<?php

namespace App\Console\Commands;

use App\Services\PreinvoiceReservationService;
use Illuminate\Console\Command;

class ExpirePreinvoiceReservationsCommand extends Command
{
    protected $signature = 'preinvoices:expire-reservations';

    protected $aliases = ['reservations:expire'];

    protected $description = 'Expire overdue official preinvoice reservations and release reserved stock.';

    public function handle(PreinvoiceReservationService $reservations): int
    {
        $result = $reservations->expireOverdueReservations();

        $this->info(sprintf(
            'Expired preinvoices: %d | Released reservations: %d | Released quantity: %d',
            (int) $result['expired_orders'],
            (int) $result['released_reservations'],
            (int) $result['released_quantity']
        ));

        return self::SUCCESS;
    }
}
