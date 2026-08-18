<?php

namespace App\Console\Commands;

use App\Models\CommissionAdjustment;
use App\Models\CommissionDocument;
use App\Models\CommissionPayment;
use App\Models\CommissionPeriod;
use App\Models\CommissionSettlement;
use App\Services\Commissions\CommissionPaymentService;
use App\Services\Commissions\CommissionPeriodWorkflowService;
use Illuminate\Console\Command;

class AuditCommissionSettlementsCommand extends Command
{
    protected $signature = 'commissions:audit-settlements {--apply : Repair deterministic cached payment statuses only}';

    protected $description = 'Audit commission document, settlement, payment and carry-forward consistency';

    public function handle(CommissionPeriodWorkflowService $workflow, CommissionPaymentService $payments): int
    {
        $issues = collect();
        CommissionSettlement::query()->with(['document', 'period', 'payments'])->orderBy('id')->each(function ($settlement) use ($issues, $payments) {
            $validPaid = (int) $settlement->payments->where('status', CommissionPayment::STATUS_RECORDED)->sum('amount');
            if ($settlement->document && (int) $settlement->document->final_commission_total !== (int) $settlement->net_payable) {
                $issues->push("settlement_document_mismatch:{$settlement->id}");
            }
            if ($validPaid !== (int) $settlement->paid_amount || max(0, $settlement->net_payable - $validPaid) !== (int) $settlement->remaining_amount) {
                $issues->push("payment_cache_mismatch:{$settlement->id}");
                if ($this->option('apply')) {
                    $payments->sync($settlement);
                }
            }
            if ($validPaid > max(0, $settlement->net_payable)) {
                $issues->push("overpayment:{$settlement->id}");
            }
            if ($settlement->net_payable < 0) {
                $carryCount = CommissionAdjustment::query()->where('identity_key', "carry-forward:settlement:{$settlement->id}")->count();
                if ($carryCount !== 1) {
                    $issues->push("carry_forward_count:{$settlement->id}:{$carryCount}");
                }
            }
        });
        CommissionPeriod::query()->whereIn('status', [CommissionPeriod::STATUS_CLOSED, CommissionPeriod::STATUS_PAID])->each(function ($period) use ($issues) {
            $documents = (int) CommissionDocument::query()->where('commission_period_id', $period->id)->where('status', CommissionDocument::STATUS_FINALIZED)->sum('final_commission_total');
            $settlements = (int) CommissionSettlement::query()->where('commission_period_id', $period->id)->sum('net_payable');
            if ($documents !== $settlements) {
                $issues->push("period_total_mismatch:{$period->id}:{$documents}:{$settlements}");
            }
            if ($period->status === CommissionPeriod::STATUS_PAID && CommissionSettlement::query()->where('commission_period_id', $period->id)->where('net_payable', '>', 0)->where('status', '!=', CommissionSettlement::STATUS_PAID)->exists()) {
                $issues->push("paid_period_unpaid_settlement:{$period->id}");
            }
        });
        foreach ($issues as $issue) {
            $this->warn($issue);
        }
        $this->info('Settlement audit: '.$issues->count().' issue(s). Mode: '.($this->option('apply') ? 'apply-cache-only' : 'dry-run'));

        return self::SUCCESS;
    }
}
