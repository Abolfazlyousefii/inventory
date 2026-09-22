<?php

namespace App\Console\Commands;

use App\Services\SyntheticDefaultVariantAuditService;
use App\Services\SyntheticDefaultVariantClassifier;
use App\Services\SyntheticDefaultVariantCleanupService;
use Illuminate\Console\Command;

class CleanupSyntheticDefaultVariants extends Command
{
    protected $signature = 'inventory:cleanup-synthetic-default-variants
        {--ids= : Comma-separated explicitly reviewed variant IDs}
        {--all-safe : Select all proven and currently safe candidates}
        {--apply : Apply deletion instead of dry-run}
        {--confirm : Required confirmation paired with --apply}';

    protected $description = 'Dry-run-first cleanup for unused legacy synthetic default variants';

    public function handle(
        SyntheticDefaultVariantAuditService $audit,
        SyntheticDefaultVariantCleanupService $cleanup,
    ): int {
        $hasIds = $this->option('ids') !== null && trim((string) $this->option('ids')) !== '';
        $allSafe = (bool) $this->option('all-safe');
        $apply = (bool) $this->option('apply');
        $confirm = (bool) $this->option('confirm');

        if (($hasIds ? 1 : 0) + ($allSafe ? 1 : 0) !== 1) {
            $this->error('Choose exactly one selector: --ids or --all-safe.');
            return self::FAILURE;
        }
        if ($apply !== $confirm) {
            $this->error('Mutation requires both --apply and --confirm.');
            return self::FAILURE;
        }

        $explicit = $hasIds;
        if ($explicit) {
            $parts = array_filter(array_map('trim', explode(',', (string) $this->option('ids'))), fn ($id) => $id !== '');
            if ($parts === [] || collect($parts)->contains(fn ($id) => ! ctype_digit($id) || (int) $id <= 0)) {
                $this->error('--ids must contain positive integer IDs only.');
                return self::FAILURE;
            }
            $ids = collect($parts)->map(fn ($id) => (int) $id)->unique()->values();
        } else {
            $ids = $audit->rows()
                ->filter(fn (array $row) => $row['synthetic_class'] === SyntheticDefaultVariantClassifier::PROVEN_SYNTHETIC
                    && $row['safe_to_remove'])
                ->pluck('variant_id')->map(fn ($id) => (int) $id)->values();
        }

        $results = $ids->map(fn (int $id) => $apply
            ? $cleanup->cleanup($id, $explicit)
            : $cleanup->assess($id, $explicit));

        foreach ($results as $result) {
            $this->line('id='.$result['variant_id']);
            $this->line('status='.$result['status']);
            $this->line('class='.$result['synthetic_class']);
            $this->line('blockers='.implode(',', $result['blocking_reasons']));
        }
        $this->info($apply ? 'Apply complete.' : 'Dry-run only; no data was changed.');

        return self::SUCCESS;
    }
}
