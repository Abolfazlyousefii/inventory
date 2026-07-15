<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Services\SalesHavalehStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncInvoiceOperationalStatuses extends Command
{
    protected $signature = 'invoices:sync-operational-statuses {--dry-run : Show changes without writing them}';

    protected $description = 'Safely moves converted/finance-approved invoices out of automatic approval statuses into an operational status.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = Invoice::query()
            ->whereIn('status', [
                Invoice::STATUS_PENDING_WAREHOUSE_APPROVAL,
                Invoice::STATUS_PENDING_FINANCE_REAPPROVAL,
                Invoice::STATUS_FINANCE_APPROVED,
                'processing',
            ])
            ->whereHas('preinvoiceOrder', function ($query) {
                $query->whereIn('status', [
                    PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
                    'finance_approved',
                ]);
            });

        $count = (clone $query)->count();
        $this->info("Invoices to sync: {$count}");

        if ($dryRun || $count === 0) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($query) {
            $query->lockForUpdate()->chunkById(100, function ($invoices) {
                foreach ($invoices as $invoice) {
                    $invoice->forceFill([
                        'status' => SalesHavalehStatusService::COLLECTING,
                        'status_changed_at' => $invoice->status_changed_at ?: now(),
                    ])->save();
                }
            });
        });

        $this->info('Invoice operational statuses synced.');

        return self::SUCCESS;
    }
}
