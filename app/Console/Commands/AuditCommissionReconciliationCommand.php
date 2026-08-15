<?php

namespace App\Console\Commands;

use App\Models\CommissionCorrectionEntry;
use App\Models\CommissionDocumentItem;
use App\Models\CommissionLedgerEntry;
use App\Models\SalesReturnDocument;
use App\Services\Commissions\CommissionReconciliationService;
use Illuminate\Console\Command;

class AuditCommissionReconciliationCommand extends Command
{
    protected $signature = 'commissions:audit-reconciliation {--apply : Apply only deterministic return reconciliation}';

    protected $description = 'Dry-run audit of return reversals, correction lineage, and document seller claims';

    public function handle(CommissionReconciliationService $service): int
    {
        $returns = SalesReturnDocument::query()->where('status', SalesReturnDocument::STATUS_APPLIED)
            ->where('source_type', SalesReturnDocument::SOURCE_INTERNAL_INVOICE)->with('items')->get();
        $missing = $returns->filter(fn ($return) => ! CommissionCorrectionEntry::query()->where('sales_return_document_id', $return->id)->exists());
        $duplicates = CommissionCorrectionEntry::query()->selectRaw('identity_key, count(*) as aggregate')->groupBy('identity_key')->havingRaw('count(*) > 1')->count();
        $wrongClaims = CommissionDocumentItem::query()->join('commission_documents', 'commission_documents.id', '=', 'commission_document_items.commission_document_id')
            ->join('commission_ledger_entries', fn ($join) => $join->on('commission_ledger_entries.invoice_id', '=', 'commission_document_items.invoice_id')->where('commission_ledger_entries.status', CommissionLedgerEntry::STATUS_ACTIVE))
            ->whereNotNull('commission_document_items.active_invoice_id')->whereColumn('commission_documents.seller_id', '<>', 'commission_ledger_entries.seller_id')->distinct()->count('commission_document_items.id');
        $pending = CommissionCorrectionEntry::query()->where('status', CommissionCorrectionEntry::STATUS_PENDING_PERIOD)->count();

        $this->table(['Check', 'Count'], [['finalized_returns_without_reversal', $missing->count()], ['duplicate_identity', $duplicates], ['active_claim_wrong_seller', $wrongClaims], ['correction_without_period', $pending]]);
        if ($this->option('apply')) {
            $applied = 0;
            foreach ($missing as $return) {
                $applied += $service->reconcileReturn($return, $return->applied_by);
            }
            $this->info("Applied deterministic correction entries: {$applied}");
        } else {
            $this->comment('Dry-run only; no data was changed.');
        }

        return self::SUCCESS;
    }
}
