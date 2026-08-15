<?php

namespace App\Services\Commissions;

use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPeriod;
use App\Models\Invoice;
use Illuminate\Support\Collection;

class CommissionDocumentSnapshotService
{
    public function forInvoice(Invoice $invoice, ?CommissionPeriod $sourcePeriod = null): ?array
    {
        $sourcePeriod ??= $this->sourcePeriod($invoice);
        if (! $sourcePeriod || $sourcePeriod->needs_recalculation) {
            return null;
        }

        $entries = $this->entries($invoice, $sourcePeriod);
        if ($entries->isEmpty() || $entries->contains(fn (CommissionLedgerEntry $entry) => $entry->missing_rate)) {
            return null;
        }

        return [
            'source_period_id' => $sourcePeriod->id,
            'net_sales_snapshot' => (int) $entries->sum('net_amount_snapshot'),
            'base_commission_snapshot' => (int) $entries->sum('base_commission_amount'),
            'campaign_commission_snapshot' => (int) $entries->sum('campaign_commission_amount'),
            'total_commission_snapshot' => (int) $entries->sum('total_commission_amount'),
            'ledger_entry_count' => $entries->count(),
            'calculation_version' => (int) $entries->max('calculation_version'),
            'source_fingerprint' => $this->fingerprint($entries),
        ];
    }

    public function sourcePeriod(Invoice $invoice): ?CommissionPeriod
    {
        $date = $invoice->display_document_date;

        return CommissionPeriod::query()
            ->where('start_at', '<=', $date)->where('end_at', '>', $date)
            ->whereHas('ledgerEntries', fn ($query) => $query->where('invoice_id', $invoice->id)->where('status', CommissionLedgerEntry::STATUS_ACTIVE))
            ->latest('start_at')->first();
    }

    public function entries(Invoice $invoice, CommissionPeriod $period): Collection
    {
        return CommissionLedgerEntry::query()->where('commission_period_id', $period->id)
            ->where('invoice_id', $invoice->id)->where('status', CommissionLedgerEntry::STATUS_ACTIVE)
            ->with(['invoice', 'period'])->orderBy('id')->get();
    }

    private function fingerprint(Collection $entries): string
    {
        $source = $entries->map(fn (CommissionLedgerEntry $entry) => [
            $entry->id, $entry->calculation_version, $entry->updated_at?->format('Y-m-d H:i:s.u'),
            $entry->net_amount_snapshot, $entry->base_commission_amount,
            $entry->campaign_commission_amount, $entry->total_commission_amount,
        ])->values()->all();

        return hash('sha256', json_encode($source, JSON_PRESERVE_ZERO_FRACTION));
    }
}
