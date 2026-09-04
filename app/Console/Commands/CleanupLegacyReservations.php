<?php

namespace App\Console\Commands;

use App\Models\PreinvoiceDraftReservation;
use App\Services\LegacyReservationCleanupService;
use Illuminate\Console\Command;

class CleanupLegacyReservations extends Command
{
    protected $signature = 'inventory:cleanup-legacy-reservations
                            {--dry-run : Report candidates without changing data}
                            {--apply : Close legacy lifecycles and rebuild reserved caches}
                            {--stale-hours=72 : Minimum age since reservation activity}';

    protected $description = 'Close legacy reservations and repair reserved caches without returning physical stock.';

    public function handle(LegacyReservationCleanupService $service): int
    {
        if ((bool) $this->option('dry-run') === (bool) $this->option('apply')) {
            $this->error('Specify exactly one of --dry-run or --apply.');

            return self::FAILURE;
        }

        $at = now();
        $staleHours = max(1, (int) $this->option('stale-hours'));
        $rows = $service->reportRows($staleHours, $at);
        $quantity = (int) $rows->sum('quantity');

        $this->line('Legacy reservations found: '.$rows->count());
        $this->line('Total quantity: '.$quantity);
        if ($rows->isNotEmpty()) {
            $this->table(
                ['ID', 'Product', 'Variant', 'Quantity', 'Token', 'Age (hours)', 'Reason'],
                $rows->map(fn (array $row): array => [
                    $row['reservation_id'],
                    $row['product_id'].' '.$row['product_name'],
                    $row['variant_id'].' '.$row['variant_name'],
                    $row['quantity'],
                    $row['token'],
                    $row['age_hours'],
                    $row['legacy_reason'],
                ])->all(),
            );
        }

        if ($this->option('dry-run')) {
            $this->warn('No data changed');

            return self::SUCCESS;
        }

        $result = $service->cleanup($rows->pluck('reservation_id')->all(), $staleHours, $at);
        $this->info('Legacy reservations cleaned: '.$result['cleaned']);
        $this->line('Cleaned quantity: '.$result['quantity']);
        $this->line('Reserved cache rebuilt for '.$result['products_rebuilt'].' products and '.$result['variants_rebuilt'].' variants.');
        $this->line('Warehouse stock changed: no');

        return self::SUCCESS;
    }
}
