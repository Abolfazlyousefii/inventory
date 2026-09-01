<?php

namespace App\Services\Commissions;

use App\Models\CommissionCalculationWarning;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPeriod;
use App\Models\Invoice;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CommissionInvoiceSyncService
{
    public function __construct(
        private readonly CommissionItemCalculator $calculator,
        private readonly CommissionDocumentService $documents,
    ) {}

    /**
     * Incrementally reconcile commission ledger state for one invoice only.
     *
     * The full-period CommissionCalculationService remains the canonical
     * reconciliation/repair path. This service never clears a global dirty flag.
     */
    public function syncInvoice(
        int $invoiceId,
        CarbonInterface|string|null $oldDate = null,
        CarbonInterface|string|null $newDate = null,
        ?string $invoiceNumberSnapshot = null,
    ): array {
        $invoice = Invoice::query()->find($invoiceId);

        if ($invoice) {
            $newDate ??= $invoice->display_document_date;
            $invoiceNumberSnapshot ??= (string) $invoice->uuid;
        }

        $periodIds = $this->affectedPeriodIds(
            $invoiceId,
            $invoiceNumberSnapshot,
            [$oldDate, $newDate],
        );

        if ($periodIds === []) {
            return $this->emptyResult($invoiceId);
        }

        return DB::transaction(function () use ($invoiceId, $invoiceNumberSnapshot, $periodIds) {
            $periods = CommissionPeriod::query()
                ->whereIn('id', $periodIds)
                ->whereIn('status', [CommissionPeriod::STATUS_OPEN, CommissionPeriod::STATUS_REVIEW])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // Re-read after locking the affected period(s) so we calculate from
            // the latest committed invoice/item state.
            $invoice = Invoice::query()
                ->with([
                    'seller',
                    'preinvoiceOrder.seller',
                    'preinvoiceOrder.creator',
                    'items.product.category.parent',
                    'items.variant',
                ])
                ->find($invoiceId);

            $invoiceNumber = $invoice ? (string) $invoice->uuid : $invoiceNumberSnapshot;
            $result = $this->emptyResult($invoiceId);
            $result['period_ids'] = $periods->pluck('id')->map(fn ($id) => (int) $id)->all();

            foreach ($periods as $period) {
                $changed = false;
                $this->deleteWarningsForInvoice($period, $invoiceId);

                $existingForInvoice = $this->activeInvoiceEntries(
                    $period,
                    $invoiceId,
                    $invoiceNumber,
                );

                $belongsToPeriod = $invoice
                    && ! $invoice->isCancelled()
                    && $period->contains($invoice->display_document_date);

                if (! $belongsToPeriod) {
                    $count = $this->supersedeEntries($existingForInvoice);
                    $result['superseded'] += $count;
                    $this->finishPeriod($period, $count > 0, $result);

                    continue;
                }

                $sellerId = $invoice->commissionSellerId();
                if (! $sellerId) {
                    $this->warning(
                        $period,
                        $invoice,
                        null,
                        'missing_seller',
                        'فاکتور فروشنده معتبر ندارد.',
                    );
                    $result['warnings']++;

                    $count = $this->supersedeEntries($existingForInvoice);
                    $result['superseded'] += $count;
                    $this->finishPeriod($period, $count > 0, $result);

                    continue;
                }

                $this->calculator->warm($period->start_at, $period->end_at);
                $seenItemIds = [];
                $invoiceDate = $invoice->display_document_date;

                foreach ($invoice->items as $item) {
                    $seenItemIds[] = (int) $item->id;

                    $calculation = $this->calculator->calculate(
                        $invoice,
                        $item,
                        (int) $sellerId,
                        $invoiceDate,
                    );

                    $attributes = $calculation->ledgerAttributes;
                    $fingerprint = hash(
                        'sha256',
                        json_encode(
                            $attributes,
                            JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
                        ),
                    );

                    // Lock by invoice item so retries and item moves cannot leave
                    // two active commission rows for one item/period.
                    $active = CommissionLedgerEntry::query()
                        ->where('commission_period_id', $period->id)
                        ->where('invoice_item_id', $item->id)
                        ->where('active_marker', 1)
                        ->lockForUpdate()
                        ->first();

                    if ($attributes['missing_rate']) {
                        $this->warning(
                            $period,
                            $invoice,
                            (int) $item->id,
                            'missing_rate',
                            'ردیف فاکتور فاقد نرخ پورسانت است.',
                        );
                        $result['warnings']++;
                    }

                    if ($active?->calculation_fingerprint === $fingerprint) {
                        continue;
                    }

                    if ($active) {
                        $active->update([
                            'status' => CommissionLedgerEntry::STATUS_SUPERSEDED,
                            'active_marker' => null,
                        ]);
                        $result['superseded']++;
                    }

                    CommissionLedgerEntry::query()->create($attributes + [
                        'commission_period_id' => $period->id,
                        'calculation_fingerprint' => $fingerprint,
                        'status' => CommissionLedgerEntry::STATUS_ACTIVE,
                        'active_marker' => 1,
                        'calculated_at' => now(),
                        'metadata' => ['audit' => $calculation->audit],
                    ]);

                    $result['created']++;
                    $changed = true;
                }

                // Deleted invoice items retain their immutable ledger history but
                // cannot remain active. null invoice_item_id is expected because
                // the ledger FK is nullOnDelete().
                $stale = $this->activeInvoiceEntries($period, $invoiceId, $invoiceNumber)
                    ->filter(fn (CommissionLedgerEntry $entry) =>
                        $entry->invoice_item_id === null
                        || ! in_array((int) $entry->invoice_item_id, $seenItemIds, true)
                    );

                $count = $this->supersedeEntries($stale);
                $result['superseded'] += $count;
                $changed = $changed || $count > 0;

                $this->finishPeriod($period, $changed, $result);
            }

            $result['changed_period_ids'] = array_values(array_unique($result['changed_period_ids']));

            return $result;
        }, 3);
    }

    private function affectedPeriodIds(
        int $invoiceId,
        ?string $invoiceNumberSnapshot,
        array $dates,
    ): array {
        $ids = collect();

        foreach ($dates as $date) {
            if (! $date) {
                continue;
            }

            $ids = $ids->merge(
                CommissionPeriod::query()
                    ->whereIn('status', [CommissionPeriod::STATUS_OPEN, CommissionPeriod::STATUS_REVIEW])
                    ->where('start_at', '<=', $date)
                    ->where('end_at', '>', $date)
                    ->pluck('id'),
            );
        }

        // Existing active ledger rows are required for cancel/delete/date-move
        // cleanup. invoice_number_snapshot survives a hard invoice delete.
        $ledgerIds = CommissionLedgerEntry::query()
            ->where('active_marker', 1)
            ->where(function ($query) use ($invoiceId, $invoiceNumberSnapshot) {
                $query->where('invoice_id', $invoiceId);

                if ($invoiceNumberSnapshot !== null && $invoiceNumberSnapshot !== '') {
                    $query->orWhere('invoice_number_snapshot', $invoiceNumberSnapshot);
                }
            })
            ->whereHas('period', fn ($query) => $query->whereIn(
                'status',
                [CommissionPeriod::STATUS_OPEN, CommissionPeriod::STATUS_REVIEW],
            ))
            ->pluck('commission_period_id');

        return $ids
            ->merge($ledgerIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @return Collection<int, CommissionLedgerEntry> */
    private function activeInvoiceEntries(
        CommissionPeriod $period,
        int $invoiceId,
        ?string $invoiceNumberSnapshot,
    ): Collection {
        return CommissionLedgerEntry::query()
            ->where('commission_period_id', $period->id)
            ->where('active_marker', 1)
            ->where(function ($query) use ($invoiceId, $invoiceNumberSnapshot) {
                $query->where('invoice_id', $invoiceId);

                if ($invoiceNumberSnapshot !== null && $invoiceNumberSnapshot !== '') {
                    $query->orWhere('invoice_number_snapshot', $invoiceNumberSnapshot);
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** @param Collection<int, CommissionLedgerEntry> $entries */
    private function supersedeEntries(Collection $entries): int
    {
        $count = 0;

        foreach ($entries as $entry) {
            if ((int) $entry->active_marker !== 1) {
                continue;
            }

            $entry->update([
                'status' => CommissionLedgerEntry::STATUS_SUPERSEDED,
                'active_marker' => null,
            ]);
            $count++;
        }

        return $count;
    }

    private function finishPeriod(CommissionPeriod $period, bool $changed, array &$result): void
    {
        if (! $changed) {
            return;
        }

        $this->documents->markStaleForPeriod($period);
        $result['changed_period_ids'][] = (int) $period->id;
    }

    private function deleteWarningsForInvoice(CommissionPeriod $period, int $invoiceId): void
    {
        CommissionCalculationWarning::query()
            ->where('commission_period_id', $period->id)
            ->where('invoice_id', $invoiceId)
            ->delete();
    }

    private function warning(
        CommissionPeriod $period,
        Invoice $invoice,
        ?int $itemId,
        string $code,
        string $message,
    ): void {
        CommissionCalculationWarning::query()->create([
            'commission_period_id' => $period->id,
            'invoice_id' => $invoice->id,
            'invoice_item_id' => $itemId,
            'code' => $code,
            'message' => $message,
            'context' => [
                'invoice_number' => $invoice->uuid,
                'invoice_seller_id' => $invoice->seller_id,
                'preinvoice_seller_id' => $invoice->preinvoiceOrder?->seller_id,
                'operator_id' => $invoice->preinvoiceOrder?->created_by,
            ],
        ]);
    }

    private function emptyResult(int $invoiceId): array
    {
        return [
            'invoice_id' => $invoiceId,
            'period_ids' => [],
            'created' => 0,
            'superseded' => 0,
            'warnings' => 0,
            'changed_period_ids' => [],
        ];
    }
}
