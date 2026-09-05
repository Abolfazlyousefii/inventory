<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LegacyReservationCleanupService;
use App\Support\PermissionCatalog;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 4-B — Safe Legacy Reservation Cleanup command.
 *
 * Modes:
 *   --dry-run                         Preview candidates only. Never writes.
 *   --apply --confirm --ids=1,2,3     Close the lifecycle of exactly those
 *                                      reservation IDs, but only the ones
 *                                      that are genuine legacy candidates.
 *   --apply --confirm (no --ids)      Reports candidates only — same as
 *                                      --dry-run. Nothing is ever cleaned up
 *                                      without an explicit --ids list.
 *   --apply (no --confirm)            Refused outright. No query that could
 *                                      write is ever executed.
 */
class CleanupLegacyReservations extends Command
{
    protected $signature = 'inventory:cleanup-legacy-reservations
                            {--dry-run : Report candidates without changing data}
                            {--apply : Close legacy lifecycles for --ids (requires --confirm)}
                            {--confirm : Required together with --apply to actually persist changes}
                            {--ids= : Comma-separated reservation IDs to clean up, e.g. --ids=12,15,18}
                            {--stale-hours=72 : Minimum age since reservation activity}
                            {--user= : ID of the acting user; checked against inventory.reservation.legacy_cleanup and used for released_by/activity log attribution}
                            {--output=reports/legacy-reservation-cleanup : Report output directory}';

    protected $description = 'Close legacy reservations and repair reserved caches without returning physical stock.';

    private const WRITE_VERBS = 'insert|update|delete|replace|truncate|alter|drop|create|rename|grant|revoke';

    private const CSV_HEAD = ['reservation_id', 'product', 'variant', 'quantity', 'classification', 'action', 'timestamp'];

    private static bool $writeGuardEnabled = false;

    private static ?\WeakMap $guardedConnections = null;

    public function handle(LegacyReservationCleanupService $service): int
    {
        if ((bool) $this->option('dry-run') === (bool) $this->option('apply')) {
            $this->error('Specify exactly one of --dry-run or --apply.');

            return self::FAILURE;
        }

        if ($this->option('apply') && ! $this->option('confirm')) {
            $this->error('Refusing to apply without --confirm. Re-run with --apply --confirm.');

            return self::FAILURE;
        }

        $actorId = null;
        if ($this->option('user') !== null) {
            $actor = User::query()->find((int) $this->option('user'));
            if ($actor === null) {
                $this->error('The specified --user does not exist.');

                return self::FAILURE;
            }

            if (! PermissionCatalog::userHasPermission($actor, 'inventory.reservation.legacy_cleanup')) {
                $this->error('The specified user does not have the inventory.reservation.legacy_cleanup permission.');

                return self::FAILURE;
            }

            $actorId = $actor->id;
        }

        $at = now();
        $staleHours = max(1, (int) $this->option('stale-hours'));
        $ids = $this->parseIds();

        $shouldApply = $this->option('apply') && $ids !== [];
        if (! $shouldApply) {
            return $this->preview($service, $staleHours, $at, (bool) $this->option('apply'));
        }

        return $this->apply($service, $ids, $staleHours, $at, $actorId);
    }

    private function preview(LegacyReservationCleanupService $service, int $staleHours, $at, bool $viaApply): int
    {
        $this->installWriteQueryGuard();
        try {
            $rows = $service->reportRows($staleHours, $at);
        } finally {
            $this->disableWriteQueryGuard();
        }

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

        if ($viaApply) {
            $this->warn('No --ids provided; nothing was cleaned up. Re-run with --ids=<comma separated reservation ids> to close specific legacy reservations.');
        } else {
            $this->warn('No data changed');
        }

        $this->line(json_encode([
            'candidates' => $rows->count(),
            'quantity' => $quantity,
            'warehouse_stock_changed' => false,
            'stock_changed' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    private function apply(LegacyReservationCleanupService $service, array $ids, int $staleHours, $at, ?int $actorId): int
    {
        $result = $service->cleanup($ids, $staleHours, $at, $actorId);

        $paths = $this->writeReport($result, $at);

        $this->info('Legacy reservations cleaned: '.$result['closed']);
        $this->line('Skipped (not a legacy candidate): '.$result['skipped']);
        $this->line('Cleaned quantity: '.$result['quantity_closed']);
        $this->line('Reserved cache rebuilt for '.$result['products_rebuilt'].' products and '.$result['variants_rebuilt'].' variants.');
        $this->line('Warehouse stock changed: no');

        $report = [
            'processed' => $result['processed'],
            'closed' => $result['closed'],
            'skipped' => $result['skipped'],
            'quantity_closed' => $result['quantity_closed'],
            'warehouse_stock_changed' => false,
            'stock_movement_created' => false,
        ];
        $this->line(json_encode($report + ['paths' => $paths], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    /** @return array<int, int> */
    private function parseIds(): array
    {
        $raw = (string) ($this->option('ids') ?? '');
        if (trim($raw) === '') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn (string $id): string => trim($id))
            ->filter(fn (string $id): bool => $id !== '' && ctype_digit($id))
            ->map(fn (string $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array{csv: string, summary: string} */
    private function writeReport(array $result, $at): array
    {
        $base = trim((string) $this->option('output'), '/');
        $csvPath = "{$base}/legacy-reservation-cleanup.csv";
        $summaryPath = "{$base}/summary.json";

        Storage::disk('local')->put($csvPath, $this->csv($result['rows']));
        Storage::disk('local')->put($summaryPath, json_encode([
            'executed_at' => $at->toISOString(),
            'processed' => $result['processed'],
            'closed' => $result['closed'],
            'skipped' => $result['skipped'],
            'quantity_closed' => $result['quantity_closed'],
            'products_rebuilt' => $result['products_rebuilt'],
            'variants_rebuilt' => $result['variants_rebuilt'],
            'warehouse_stock_changed' => false,
            'stock_movement_created' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return ['csv' => $csvPath, 'summary' => $summaryPath];
    }

    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::CSV_HEAD);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $column) => $row[$column] ?? '', self::CSV_HEAD));
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function installWriteQueryGuard(): void
    {
        self::$writeGuardEnabled = true;
        $connection = DB::connection();
        self::$guardedConnections ??= new \WeakMap;
        if (isset(self::$guardedConnections[$connection])) {
            return;
        }

        $connection->beforeExecuting(function (string $query, array $bindings, Connection $connection): void {
            if (! self::$writeGuardEnabled) {
                return;
            }

            $normalized = ltrim(preg_replace('/^(?:\s|\/\*.*?\*\/|--[^\r\n]*(?:\r?\n|$)|#[^\r\n]*(?:\r?\n|$))+/s', ' ', $query) ?? $query);
            if (preg_match('/^('.self::WRITE_VERBS.')\b/i', $normalized)) {
                throw new \RuntimeException('Unsafe write query blocked before execution during legacy reservation cleanup preview.');
            }
        });
        self::$guardedConnections[$connection] = true;
    }

    private function disableWriteQueryGuard(): void
    {
        self::$writeGuardEnabled = false;
    }
}
