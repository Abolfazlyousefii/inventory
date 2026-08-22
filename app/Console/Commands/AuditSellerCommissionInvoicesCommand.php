<?php

namespace App\Console\Commands;

use App\Models\CommissionPeriod;
use App\Models\User;
use App\Services\Commissions\CommissionReportService;
use Illuminate\Console\Command;

class AuditSellerCommissionInvoicesCommand extends Command
{
    protected $signature = 'commissions:audit-seller {--period= : Commission period ID} {--seller= : Seller user ID}';

    protected $description = 'Read-only reconciliation of seller invoice commissions against active ledger snapshots';

    public function handle(CommissionReportService $reports): int
    {
        $period = CommissionPeriod::query()->find($this->option('period'));
        $seller = User::query()->find($this->option('seller'));
        if (! $period || ! $seller) {
            $this->error('Both --period and --seller must reference existing records.');

            return self::INVALID;
        }

        $audit = $reports->sellerAudit($period, $seller);
        $this->table(['Metric', 'Value'], [
            ['Seller', $seller->name.' (#'.$seller->id.')'],
            ['Period', $period->label.' (#'.$period->id.')'],
            ['Invoice count', $audit['invoice_count']],
            ['Ledger item count', $audit['ledger_item_count']],
            ['Missing rates', $audit['missing_rate_count']],
            ['Invoice commission sum', $audit['invoice_commission_sum']],
            ['Sales-return adjustments', $audit['return_adjustments']],
            ['Reassignment adjustments', $audit['reassignment_adjustments']],
            ['Manual adjustments', $audit['manual_adjustments']],
            ['Final expected', $audit['final_expected']],
            ['Displayed total', $audit['displayed_total']],
            ['Difference', $audit['difference']],
        ]);
        $this->table(['Invoice ID', 'Number', 'Items', 'Missing', 'Commission'], $audit['invoices']->map(fn ($row) => [
            $row->invoice_id, $row->invoice_number_snapshot, $row->items_count,
            $row->missing_rate_count, $row->total_commission_amount,
        ])->all());

        if ($audit['conflicting_invoice_ids'] !== []) {
            $this->warn('Integrity warning: active ledger belongs to multiple sellers for invoice IDs '.implode(', ', $audit['conflicting_invoice_ids']));
        }
        if ($audit['difference'] !== 0) {
            $this->error('Reconciliation difference is non-zero. No data was changed.');

            return self::FAILURE;
        }

        $this->info('Audit completed read-only; no database rows were changed.');

        return self::SUCCESS;
    }
}
