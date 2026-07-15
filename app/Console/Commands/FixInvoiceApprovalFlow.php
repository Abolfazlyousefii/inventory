<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Services\SalesHavalehStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixInvoiceApprovalFlow extends Command
{
    protected $signature = 'invoices:fix-approval-flow {--dry-run : Only report invoices that would be moved to collecting}';

    protected $description = 'Move finance-approved converted invoices that are stuck in an approval status into the collection queue.';

    public function handle(): int
    {
        $query = Invoice::query()
            ->whereIn('status', [
                Invoice::STATUS_PENDING_WAREHOUSE_APPROVAL,
                Invoice::STATUS_FINANCE_APPROVED,
                'processing',
            ])
            ->whereHas('preinvoiceOrder', function ($query) {
                $query->where('status', PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE);
            });

        $count = (clone $query)->count();
        $this->info("Invoices that will move to collecting: {$count}");

        if ($this->option('dry-run') || $count === 0) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($query) {
            $query->lockForUpdate()->chunkById(100, function ($invoices) {
                foreach ($invoices as $invoice) {
                    $invoice->forceFill([
                        'status' => SalesHavalehStatusService::COLLECTING,
                        'status_changed_at' => now(),
                    ])->save();
                }
            });
        });

        $this->info('Approval flow fixed. Affected invoices are now in collecting.');

        return self::SUCCESS;
    }
}
