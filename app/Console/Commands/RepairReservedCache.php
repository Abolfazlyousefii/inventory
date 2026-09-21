<?php

namespace App\Console\Commands;

use App\Services\ReservationProjectionService;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RepairReservedCache extends Command
{
    protected $signature = 'inventory:repair-reserved-cache
        {--dry-run : Preview canonical reserved projection differences}
        {--apply : Persist canonical reserved projections}
        {--confirm : Confirm an apply operation}
        {--output=reports/reserved-cache-repair : Report directory on the local disk}';

    protected $description = 'Inspect or rebuild reserved projections from canonical active reservation rows.';

    private const WRITE_VERBS = 'insert|update|delete|replace|truncate|alter|drop|create|rename|grant|revoke';
    private static bool $writeGuardEnabled = false;
    private static ?\WeakMap $guardedConnections = null;

    public function handle(ReservationProjectionService $projections): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');

        if ($dryRun === $apply) {
            $this->error('Choose exactly one mode: --dry-run or --apply --confirm.');
            return self::FAILURE;
        }
        if ($apply && ! (bool) $this->option('confirm')) {
            $this->error('Apply requires --confirm.');
            return self::FAILURE;
        }
        if ($dryRun && (bool) $this->option('confirm')) {
            $this->error('--confirm is valid only with --apply.');
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->installWriteQueryGuard();
        }

        try {
            // Apply deliberately does not reuse dry-run values: rebuild locks first,
            // then obtains a fresh canonical reservation snapshot.
            $report = $apply ? $projections->rebuild($this->allProductIds()) : $projections->inspect();
        } finally {
            $this->disableWriteQueryGuard();
        }

        $report['summary']['mode'] = $apply ? 'apply' : 'dry-run';
        $report['summary']['finished_at'] = now()->toISOString();
        $paths = $this->writeReports($report);
        $this->line(json_encode(['mode' => $report['summary']['mode'], 'summary' => $report['summary'], 'paths' => $paths], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    private function allProductIds(): array
    {
        return DB::table('products')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    private function writeReports(array $report): array
    {
        $base = trim((string) $this->option('output'), '/');
        $variants = array_values(array_filter($report['variants'], fn (array $row): bool => $row['difference'] !== 0));
        $products = array_values(array_filter($report['products'], fn (array $row): bool => $row['difference'] !== 0));
        $paths = ["$base/summary.json", "$base/reserved-cache-changes.csv", "$base/product-reserved-cache-changes.csv"];
        Storage::disk('local')->put($paths[0], json_encode($report['summary'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        Storage::disk('local')->put($paths[1], $this->csv($variants, ['product_id', 'variant_id', 'variant_name', 'variant_code', 'reserved_before', 'expected_reserved', 'difference']));
        Storage::disk('local')->put($paths[2], $this->csv($products, ['product_id', 'product_name', 'reserved_before', 'expected_reserved', 'difference']));

        return $paths;
    }

    private function csv(array $rows, array $head): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $head);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $key): mixed => $row[$key] ?? '', $head));
        }
        rewind($handle);

        return stream_get_contents($handle);
    }

    private function installWriteQueryGuard(): void
    {
        self::$writeGuardEnabled = true;
        $connection = DB::connection();
        self::$guardedConnections ??= new \WeakMap();
        if (isset(self::$guardedConnections[$connection])) {
            return;
        }
        $connection->beforeExecuting(function (string $query, array $bindings, Connection $connection): void {
            $normalized = ltrim(preg_replace('/^(?:\s|\/\*.*?\*\/|--[^\r\n]*(?:\r?\n|$)|#[^\r\n]*(?:\r?\n|$))+/s', ' ', $query) ?? $query);
            if (self::$writeGuardEnabled && preg_match('/^('.self::WRITE_VERBS.')\b/i', $normalized)) {
                throw new \RuntimeException('Unsafe write query blocked before execution during reserved cache dry run.');
            }
        });
        self::$guardedConnections[$connection] = true;
    }

    private function disableWriteQueryGuard(): void
    {
        self::$writeGuardEnabled = false;
    }
}
