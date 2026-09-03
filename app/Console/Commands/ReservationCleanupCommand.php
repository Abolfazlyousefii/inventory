<?php

namespace App\Console\Commands;

use App\Services\PreinvoiceDraftReservationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        $onlineMinutes = max(1, (int) $this->option('online-minutes'));
        $inPersonMinutes = max(1, (int) $this->option('in-person-minutes'));

        try {
            $result = $reservations->cleanupStaleTemporaryReservations(
                $onlineMinutes,
                $inPersonMinutes,
                $dryRun,
            );
        } catch (Throwable $exception) {
            Log::error('WAREHOUSE_RESERVATION_CLEANUP_FAILED', [
                'dry_run' => $dryRun,
                'online_minutes' => $onlineMinutes,
                'in_person_minutes' => $inPersonMinutes,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->error('Reservation cleanup failed safely. See application log for details.');

            return self::FAILURE;
        }

        Log::info('WAREHOUSE_RESERVATION_CLEANUP_COMPLETED', [
            'dry_run' => $dryRun,
            'online_minutes' => $onlineMinutes,
            'in_person_minutes' => $inPersonMinutes,
            'released_reservations' => (int) $result['released_reservations'],
            'released_quantity' => (int) $result['released_quantity'],
            'warnings' => (int) ($result['warnings'] ?? 0),
        ]);

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
