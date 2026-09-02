<?php

namespace App\Console\Commands;

use App\Services\PreinvoiceDraftReservationService;
use Illuminate\Console\Command;

class ReservationCleanupCommand extends Command
{
    protected $signature = 'reservations:cleanup
                            {--online-minutes=5 : Minutes without an online heartbeat}
                            {--in-person-minutes=15 : Minutes without an in-person heartbeat}
                            {--dry-run : Show stale reservations without changing data}';

    protected $aliases = ['reservations:cleanup-temporary'];

    protected $description = 'Release stale temporary preinvoice reservations that stopped sending heartbeat.';

    public function handle(PreinvoiceDraftReservationService $reservations): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $reservations->cleanupStaleTemporaryReservations(
            max(1, (int) $this->option('online-minutes')),
            max(1, (int) $this->option('in-person-minutes')),
            $dryRun,
        );

        $this->info(sprintf(
            '%s temporary reservations: %d | Reserved quantity: %d | Warnings: %d',
            $dryRun ? 'Stale' : 'Released',
            (int) $result['released_reservations'],
            (int) $result['released_quantity'],
            (int) ($result['warnings'] ?? 0),
        ));

        if ($dryRun) {
            $this->newLine();
            foreach ($result['reservations'] as $reservation) {
                $lastActivity = $reservation->last_seen_at ?? $reservation->created_at;
                $this->line(sprintf(
                    "#%d\n%s\n%s\nqty: %d\nuser: %s\nlast activity: %s",
                    (int) $reservation->id,
                    $reservation->product?->name ?? '—',
                    $reservation->variant?->variant_name ?? $reservation->variant?->variety_name ?? '—',
                    (int) $reservation->quantity,
                    $reservation->user?->name ?? '—',
                    $lastActivity ? $this->age($lastActivity) : '—',
                ));
                $this->newLine();
            }

            $this->warn('NO DATA CHANGED');
        }

        return self::SUCCESS;
    }

    private function age($lastActivity): string
    {
        $minutes = max(0, (int) $lastActivity->diffInMinutes(now()));

        if ($minutes < 60) {
            return $minutes.'m';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($hours < 24) {
            return $hours.'h '.($remainingMinutes > 0 ? $remainingMinutes.'m' : '');
        }

        return intdiv($hours, 24).'d '.($hours % 24 > 0 ? ($hours % 24).'h' : '');
    }
}
