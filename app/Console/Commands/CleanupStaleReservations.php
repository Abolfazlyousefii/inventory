<?php

namespace App\Console\Commands;

use App\Models\PreinvoiceDraftReservation;
use App\Services\PreinvoiceDraftReservationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupStaleReservations extends Command
{
    protected $signature = 'inventory:cleanup-stale-reservations
                            {--dry-run : Preview lifecycle changes only}
                            {--apply : Mark stale temporary rows released}
                            {--online-minutes=5 : Online heartbeat timeout}
                            {--in-person-minutes=15 : In-person heartbeat timeout}
                            {--output=reports/stale-reservation-cleanup : Report directory}';

    protected $description = 'Deprecated compatibility command delegating to canonical stale reservation release.';

    public function handle(PreinvoiceDraftReservationService $service): int
    {
        if ((bool) $this->option('dry-run') === (bool) $this->option('apply')) {
            $this->error('Specify exactly one of --dry-run or --apply.');

            return self::FAILURE;
        }

        $at = now();
        $online = max(1, (int) $this->option('online-minutes'));
        $inPerson = max(1, (int) $this->option('in-person-minutes'));
        $this->warn('Deprecated: delegates to canonical reservations:cleanup semantics.');
        $result = $service->cleanupStaleTemporaryReservations($online, $inPerson, (bool) $this->option('dry-run'));
        $reservations = collect($result['reservations']);
        $rows = $reservations->map(fn (PreinvoiceDraftReservation $reservation): array => [
            'reservation_id' => $reservation->id,
            'product_id' => $reservation->product_id,
            'variant_id' => $reservation->variant_id,
            'quantity' => $reservation->quantity,
            'age_minutes' => max(0, (int) $reservation->created_at?->diffInMinutes($at)),
            'preinvoice_status' => $reservation->order?->status ?? 'none',
            'invoice_status' => $reservation->order?->invoice ? 'exists' : 'none',
            'reason' => 'stale_temporary_without_valid_heartbeat',
        ])->all();

        $base = trim((string) $this->option('output'), '/');
        Storage::disk('local')->put("{$base}/legacy-reservation-audit.csv", $this->csv($rows));
        Storage::disk('local')->put("{$base}/summary.json", json_encode([
            'mode' => $this->option('apply') ? 'apply' : 'dry-run',
            'evaluated_at' => $at->toISOString(),
            'reservations' => count($rows),
            'quantity' => array_sum(array_column($rows, 'quantity')),
            'warehouse_stock_changed' => (bool) $this->option('apply') && count($rows) > 0,
            'reserved_cache_changed' => (bool) $this->option('apply') && count($rows) > 0,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->info(sprintf('%s %d stale temporary reservations (%d units).', $this->option('apply') ? 'Released' : 'Found', count($rows), array_sum(array_column($rows, 'quantity'))));
        $this->line($this->option('apply') ? 'Canonical normal release returned held stock and updated reserved caches.' : 'No data changed.');

        return self::SUCCESS;
    }

    private function csv(array $rows): string
    {
        $columns = ['reservation_id', 'product_id', 'variant_id', 'quantity', 'age_minutes', 'preinvoice_status', 'invoice_status', 'reason'];
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $columns);
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn (string $column) => $row[$column] ?? '', $columns));
        }
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
