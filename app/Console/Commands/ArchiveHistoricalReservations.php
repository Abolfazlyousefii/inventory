<?php

namespace App\Console\Commands;

use App\Services\HistoricalReservationArchiveService;
use Illuminate\Console\Command;

class ArchiveHistoricalReservations extends Command
{
    protected $signature = 'inventory:archive-historical-reservations
                            {--dry-run : Report eligible historical rows without writing}
                            {--apply : Apply the stock-neutral archive}
                            {--confirm : Required acknowledgement for apply mode}
                            {--ids= : Comma-separated reviewed reservation IDs}
                            {--all-historical : Select every currently historical ambiguous row}';

    protected $description = 'Archive canonical historical ambiguous reservations without changing stock or projections.';

    public function handle(HistoricalReservationArchiveService $service): int
    {
        $apply = (bool) $this->option('apply');
        $confirm = (bool) $this->option('confirm');
        $dryRunFlag = (bool) $this->option('dry-run');
        $all = (bool) $this->option('all-historical');
        $idsOption = trim((string) $this->option('ids'));

        if (($all && $idsOption !== '') || (! $all && $idsOption === '')) {
            $this->error('Select exactly one of --ids or --all-historical.');
            return self::INVALID;
        }
        if (($apply && (! $confirm || $dryRunFlag)) || ($confirm && ! $apply)) {
            $this->error('Apply mode requires --apply --confirm and cannot be combined with --dry-run.');
            return self::INVALID;
        }

        $ids = $all ? [] : $this->parseIds($idsOption);
        if (! $all && $ids === []) {
            $this->error('--ids must contain at least one positive integer ID.');
            return self::INVALID;
        }

        $at = now();
        $report = $service->reportRows($ids, $at);
        $eligibleIds = $report->where('eligible', true)->pluck('reservation_id')->map(fn ($id): int => (int) $id)->all();
        $this->line('Selected: '.($all ? $report->count() : count($ids)));
        $this->line('Eligible: '.count($eligibleIds));
        $this->line('Eligible quantity: '.(int) $report->where('eligible', true)->sum('quantity'));

        if (! $apply) {
            $this->warn('NO DATA CHANGED');
            return self::SUCCESS;
        }

        $result = $service->archive($all ? $eligibleIds : $ids, $at);
        $this->info('Archived: '.$result['archived']);
        $this->line('Skipped: '.$result['skipped']);
        $this->line('Archived quantity: '.$result['quantity_archived']);
        $this->line('Warehouse stock changed: NO');
        $this->line('Stock movements created: 0');
        $this->line('Reserved projection changed: NO');

        return self::SUCCESS;
    }

    private function parseIds(string $value): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn (string $id): int => (int) trim($id), explode(',', $value)),
            fn (int $id): bool => $id > 0,
        )));
    }
}
