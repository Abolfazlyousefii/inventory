<?php

namespace App\Console\Commands;

use App\Services\PreinvoiceDraftReservationService;
use Illuminate\Console\Command;

class ReservationCleanupCommand extends Command
{
    protected $signature = 'reservations:cleanup-temporary {--online-minutes=5} {--in-person-minutes=15}';

    protected $description = 'Release stale temporary preinvoice reservations that stopped sending heartbeat.';

    public function handle(PreinvoiceDraftReservationService $reservations): int
    {
        $result = $reservations->cleanupStaleTemporaryReservations(
            max(1, (int) $this->option('online-minutes')),
            max(1, (int) $this->option('in-person-minutes')),
        );

        $this->info(sprintf(
            'Released temporary reservations: %d | Released quantity: %d',
            (int) $result['released_reservations'],
            (int) $result['released_quantity']
        ));

        return self::SUCCESS;
    }
}
