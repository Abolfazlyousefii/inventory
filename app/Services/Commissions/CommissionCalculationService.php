<?php

namespace App\Services\Commissions;

use App\Models\CommissionCalculationWarning;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPeriod;
use App\Models\Invoice;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommissionCalculationService
{
    public function __construct(private readonly CommissionItemCalculator $calculator) {}

    public function recalculate(CommissionPeriod $period): CommissionPeriod
    {
        return DB::transaction(function () use ($period) {
            $period = CommissionPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if (! in_array($period->status, [CommissionPeriod::STATUS_OPEN, CommissionPeriod::STATUS_REVIEW], true)) {
                throw ValidationException::withMessages(['period' => 'دوره بسته یا پرداخت‌شده قابل محاسبه مجدد نیست.']);
            }

            CommissionCalculationWarning::query()->where('commission_period_id', $period->id)->delete();
            $this->calculator->warm($period->start_at, $period->end_at);
            $seenItemIds = [];
            $invoices = Invoice::query()
                ->with(['preinvoiceOrder', 'items.product.category.parent', 'items.variant'])
                ->whereRaw('COALESCE(document_date, created_at) >= ?', [$period->start_at])
                ->whereRaw('COALESCE(document_date, created_at) < ?', [$period->end_at])
                ->whereNotIn('status', Invoice::cancelledStatuses())
                ->orderBy('id')->get();

            foreach ($invoices as $invoice) {
                $sellerId = $invoice->effective_seller_id;
                if (! $sellerId) {
                    $this->warning($period, $invoice, null, 'missing_seller', 'فاکتور فروشنده معتبر ندارد.');

                    continue;
                }
                $invoiceDate = $invoice->display_document_date;
                foreach ($invoice->items as $item) {
                    $seenItemIds[] = $item->id;
                    $calculation = $this->calculator->calculate($invoice, $item, $sellerId, $invoiceDate);
                    $attributes = $calculation->ledgerAttributes;
                    $fingerprint = hash('sha256', json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
                    $active = CommissionLedgerEntry::query()
                        ->where('commission_period_id', $period->id)->where('invoice_item_id', $item->id)
                        ->where('active_marker', 1)->lockForUpdate()->first();
                    if ($attributes['missing_rate']) {
                        $this->warning($period, $invoice, $item->id, 'missing_rate', 'ردیف فاکتور فاقد نرخ پورسانت است.');
                    }
                    if ($active?->calculation_fingerprint === $fingerprint) {
                        continue;
                    }
                    if ($active) {
                        $active->update(['status' => CommissionLedgerEntry::STATUS_SUPERSEDED, 'active_marker' => null]);
                    }
                    CommissionLedgerEntry::query()->create($attributes + [
                        'commission_period_id' => $period->id, 'calculation_fingerprint' => $fingerprint,
                        'status' => CommissionLedgerEntry::STATUS_ACTIVE, 'active_marker' => 1,
                        'calculated_at' => now(), 'metadata' => ['audit' => $calculation->audit],
                    ]);
                }
            }

            CommissionLedgerEntry::query()->where('commission_period_id', $period->id)->where('active_marker', 1)
                ->when($seenItemIds !== [], fn ($query) => $query->where(fn ($missing) => $missing->whereNull('invoice_item_id')->orWhereNotIn('invoice_item_id', $seenItemIds)))
                ->update(['status' => CommissionLedgerEntry::STATUS_SUPERSEDED, 'active_marker' => null]);
            $period->update(['needs_recalculation' => false]);
            app(CommissionDocumentService::class)->markStaleForPeriod($period->fresh());
            ActivityLogger::log('commission_period.recalculated', $period, 'محاسبات پورسانت دوره به‌روزرسانی شد.', ['active_items' => count($seenItemIds)]);

            return $period->fresh();
        });
    }

    private function warning(CommissionPeriod $period, Invoice $invoice, ?int $itemId, string $code, string $message): void
    {
        CommissionCalculationWarning::query()->create([
            'commission_period_id' => $period->id, 'invoice_id' => $invoice->id, 'invoice_item_id' => $itemId,
            'code' => $code, 'message' => $message, 'context' => ['invoice_number' => $invoice->uuid],
        ]);
    }
}
