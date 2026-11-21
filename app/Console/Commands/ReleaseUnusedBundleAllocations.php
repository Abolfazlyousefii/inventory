<?php

namespace App\Console\Commands;

use App\Services\UnusedBundleAllocationReleaseService;
use Illuminate\Console\Command;
use Throwable;

/** Maintenance entrypoint uses the same checked, audited release as the browser UI. */
class ReleaseUnusedBundleAllocations extends Command
{
    protected $signature = 'bundle:release-unused
        {bundle_variant_id : Selling variant ID of the automatic bundle}
        {--keep= : Number of complete uncommitted bundles to retain in allocations}
        {--apply : Commit the displayed stock return; omitted means report only}';

    protected $description = 'Audit free component allocations and optionally release explicitly chosen surplus';

    public function handle(UnusedBundleAllocationReleaseService $service): int
    {
        $id = (string) $this->argument('bundle_variant_id');
        $input = $this->option('keep');
        if (!ctype_digit($id) || (int) $id < 1 || ($input !== null && (!ctype_digit((string) $input) || (int) $input > 1000000))) {
            $this->error('Use a valid variant ID and --keep as a non-negative number <= 1000000.');
            return self::FAILURE;
        }
        $keep = $input === null ? null : (int) $input;
        if ($this->option('apply') && $keep === null) {
            $this->error('--apply requires --keep=N.');
            return self::FAILURE;
        }
        try {
            $plan = $this->option('apply')
                ? $service->apply((int) $id, $keep, null, null, 'Operator-confirmed CLI bundle:release-unused --apply')
                : $service->preview((int) $id, $keep);
        } catch (Throwable $e) {
            $this->error('No stock released: '.$e->getMessage());
            return self::FAILURE;
        }
        $this->info('Bundle variant: '.$id.' | central warehouse: '.$plan['warehouse_id']);
        $this->table(['Component', 'Per bundle', 'Ordinary stock', 'Free allocation', 'Keep', 'Return'], $plan['rows']);
        $this->comment($this->option('apply')
            ? 'Released '.$plan['released_total'].' units; event: '.($plan['event_id'] ?? 'no stock movement')
            : 'Preview only: '.$plan['released_total'].' units would return. Review and repeat with --apply.');
        return self::SUCCESS;
    }
}
