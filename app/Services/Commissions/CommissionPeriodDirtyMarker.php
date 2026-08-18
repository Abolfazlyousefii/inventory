<?php

namespace App\Services\Commissions;

use App\Models\CommissionPeriod;
use App\Models\Invoice;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

class CommissionPeriodDirtyMarker
{
    public function markAllMutable(): int
    {
        if (! Schema::hasTable('commission_periods')) {
            return 0;
        }

        return $this->mutable()->update(['needs_recalculation' => true]);
    }

    public function markDate(CarbonInterface|string|null $date): int
    {
        if (! $date || ! Schema::hasTable('commission_periods')) {
            return 0;
        }

        return $this->mutable()->where('start_at', '<=', $date)->where('end_at', '>', $date)->update(['needs_recalculation' => true]);
    }

    public function markInvoice(Invoice $invoice): int
    {
        $marked = $this->markDate($invoice->display_document_date);
        $originalDate = $invoice->getOriginal('document_date') ?: $invoice->getOriginal('created_at');

        return $marked + $this->markDate($originalDate);
    }

    public function markInvoiceId(?int $invoiceId): int
    {
        if (! $invoiceId) {
            return 0;
        }

        $invoice = Invoice::query()->find($invoiceId);

        return $invoice ? $this->markInvoice($invoice) : 0;
    }

    private function mutable()
    {
        return CommissionPeriod::query()->whereIn('status', [CommissionPeriod::STATUS_OPEN, CommissionPeriod::STATUS_REVIEW]);
    }
}
