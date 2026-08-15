<?php

namespace App\Services\Commissions;

use App\Models\CommissionCorrectionEntry;
use App\Models\CommissionDocumentEvent;
use App\Models\CommissionDocumentItem;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPeriod;
use App\Models\CommissionReconciliationWarning;
use App\Models\Invoice;
use App\Models\SalesReturnDocument;
use App\Models\SellerReassignmentAudit;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;

class CommissionReconciliationService
{
    public function __construct(
        private readonly CommissionCorrectionPeriodResolver $periods,
    ) {}

    public function reconcileReturn(SalesReturnDocument $return, ?int $actorId = null): int
    {
        return DB::transaction(function () use ($return, $actorId) {
            $return = SalesReturnDocument::query()->with(['items.invoiceItem'])->lockForUpdate()->findOrFail($return->id);
            if (! $return->isInternal() || ! $return->commission_effect_type) {
                $this->warning('return_item_unmatched', 'return:'.$return->id, $return, 'نوع اثر پورسانت یا اتصال فاکتور داخلی برای برگشت مشخص نیست.');

                return 0;
            }
            if (in_array($return->commission_effect_type, [SalesReturnDocument::COMMISSION_WARRANTY, SalesReturnDocument::COMMISSION_SERVICE, SalesReturnDocument::COMMISSION_REPLACEMENT], true)) {
                ActivityLogger::log('return_excluded_warranty', $return, 'برگشت خدماتی/گارانتی از محاسبه پورسانت مستثنا شد.', ['commission_effect_type' => $return->commission_effect_type]);

                return $this->appendReturnDeltas($return, [], $actorId, 'return_commission_reversal_cancelled');
            }

            $desired = [];
            if ($return->isApplied()) {
                foreach ($return->items as $item) {
                    if (! $item->invoice_item_id) {
                        $this->warning('return_item_unmatched', 'return-item:'.$item->id, $return, 'ردیف برگشت به ردیف فاکتور اصلی متصل نیست.');

                        continue;
                    }
                    $source = CommissionLedgerEntry::query()->where('invoice_item_id', $item->invoice_item_id)
                        ->where('status', CommissionLedgerEntry::STATUS_ACTIVE)->latest('commission_period_id')->first();
                    if (! $source || $source->quantity_snapshot <= 0) {
                        $this->warning('return_item_unmatched', 'return-item:'.$item->id, $return, 'محاسبه فروش اصلی برای ردیف برگشت پیدا نشد.');

                        continue;
                    }
                    $before = min((int) $item->previously_returned_quantity_snapshot, (int) $source->quantity_snapshot);
                    $after = min($before + (int) $item->return_quantity, (int) $source->quantity_snapshot);
                    $portion = fn (int $amount, int $quantity) => $quantity === (int) $source->quantity_snapshot ? $amount : intdiv($amount * $quantity, (int) $source->quantity_snapshot);
                    $desired[$source->id] = [
                        'source' => $source, 'item' => $item, 'quantity' => -($after - $before),
                        'net' => -($portion((int) $source->net_amount_snapshot, $after) - $portion((int) $source->net_amount_snapshot, $before)),
                        'base' => -($portion((int) $source->base_commission_amount, $after) - $portion((int) $source->base_commission_amount, $before)),
                        'campaign' => -($portion((int) $source->campaign_commission_amount, $after) - $portion((int) $source->campaign_commission_amount, $before)),
                        'total' => -($portion((int) $source->total_commission_amount, $after) - $portion((int) $source->total_commission_amount, $before)),
                    ];
                }
            }

            return $this->appendReturnDeltas($return, $desired, $actorId, $return->isCancelled() ? 'return_commission_reversal_cancelled' : 'return_commission_reversal_updated');
        });
    }

    public function reconcileSellerReassignment(Invoice $invoice, User $newSeller, SellerReassignmentAudit $audit): void
    {
        DB::transaction(function () use ($invoice, $newSeller, $audit) {
            $sourcePeriod = CommissionPeriod::query()->whereHas('ledgerEntries', fn ($query) => $query->where('invoice_id', $invoice->id)->where('status', CommissionLedgerEntry::STATUS_ACTIVE))->first();
            if (! $sourcePeriod) {
                $this->warning('seller_ledger_missing', 'reassignment:'.$audit->id, null, 'محاسبه فعال فاکتور برای انتقال فروشنده پیدا نشد.', $audit);

                return;
            }
            if (in_array($sourcePeriod->status, [CommissionPeriod::STATUS_OPEN, CommissionPeriod::STATUS_REVIEW], true)) {
                $this->invalidateWrongSellerClaims($invoice, $newSeller, $audit);
                $sourcePeriod->update(['needs_recalculation' => true]);
                ActivityLogger::log('seller_commission_reassigned', $audit, 'انتقال مالکیت پورسانت در دوره باز در انتظار محاسبه مجدد قرار گرفت.', ['period_id' => $sourcePeriod->id]);

                return;
            }

            $targetPeriod = $this->periods->firstEligibleAfter($audit->changed_at);
            $entries = CommissionLedgerEntry::query()->where('commission_period_id', $sourcePeriod->id)->where('invoice_id', $invoice->id)->where('status', CommissionLedgerEntry::STATUS_ACTIVE)->get();
            foreach ($entries as $entry) {
                foreach ([['seller' => (int) $audit->old_seller_id, 'sign' => -1, 'side' => 'debit'], ['seller' => $newSeller->id, 'sign' => 1, 'side' => 'credit']] as $side) {
                    if (! $side['seller']) {
                        continue;
                    }
                    CommissionCorrectionEntry::query()->firstOrCreate(['identity_key' => "reassignment:{$audit->id}:{$entry->id}:{$side['side']}"], [
                        'event_type' => 'seller_reassignment_correction', 'commission_period_id' => $targetPeriod?->id,
                        'source_period_id' => $sourcePeriod->id, 'seller_id' => $side['seller'], 'source_seller_id' => $audit->old_seller_id,
                        'target_seller_id' => $newSeller->id, 'invoice_id' => $invoice->id, 'invoice_item_id' => $entry->invoice_item_id,
                        'source_ledger_entry_id' => $entry->id, 'seller_reassignment_audit_id' => $audit->id,
                        'net_amount' => $side['sign'] * (int) $entry->net_amount_snapshot,
                        'base_commission_amount' => $side['sign'] * (int) $entry->base_commission_amount,
                        'campaign_commission_amount' => $side['sign'] * (int) $entry->campaign_commission_amount,
                        'total_commission_amount' => $side['sign'] * (int) $entry->total_commission_amount,
                        'status' => $targetPeriod ? CommissionCorrectionEntry::STATUS_ASSIGNED : CommissionCorrectionEntry::STATUS_PENDING_PERIOD,
                        'reason' => $audit->reason, 'created_by' => $audit->changed_by,
                    ]);
                }
            }
            if (! $targetPeriod) {
                $this->warning('correction_without_period', 'reassignment-period:'.$audit->id, null, 'اصلاح انتقال فروشنده در انتظار تخصیص دوره است.', $audit);
            } else {
                $this->dirtyDocuments($targetPeriod);
            }
            ActivityLogger::log('historical_commission_correction_created', $audit, 'اصلاح تاریخی انتقال فروشنده ایجاد شد.', ['source_period_id' => $sourcePeriod->id, 'target_period_id' => $targetPeriod?->id]);
        });
    }

    private function appendReturnDeltas(SalesReturnDocument $return, array $desired, ?int $actorId, string $activity): int
    {
        $existing = CommissionCorrectionEntry::query()->where('sales_return_document_id', $return->id)->get()->groupBy('source_ledger_entry_id');
        $sourceIds = collect(array_keys($desired))->merge($existing->keys())->unique();
        $effectiveAt = $return->isCancelled()
            ? ($return->cancelled_at ?? $return->updated_at)
            : ($return->applied_at ?? $return->updated_at);
        $period = $this->periods->forMoment($effectiveAt);
        $created = 0;
        foreach ($sourceIds as $sourceId) {
            $target = $desired[$sourceId] ?? null;
            $prior = $existing->get($sourceId, collect());
            $delta = [
                'quantity' => ($target['quantity'] ?? 0) - (int) $prior->sum('quantity_delta'),
                'net' => ($target['net'] ?? 0) - (int) $prior->sum('net_amount'),
                'base' => ($target['base'] ?? 0) - (int) $prior->sum('base_commission_amount'),
                'campaign' => ($target['campaign'] ?? 0) - (int) $prior->sum('campaign_commission_amount'),
                'total' => ($target['total'] ?? 0) - (int) $prior->sum('total_commission_amount'),
            ];
            if (collect($delta)->every(fn ($value) => $value === 0)) {
                continue;
            }
            $source = $target['source'] ?? CommissionLedgerEntry::query()->find($sourceId);
            if (! $source) {
                continue;
            }
            $hash = hash('sha256', json_encode([$return->id, $sourceId, $delta, $return->status, $return->updated_at?->format('U.u')]));
            CommissionCorrectionEntry::query()->firstOrCreate(['identity_key' => 'return:'.$hash], [
                'event_type' => $delta['total'] > 0 ? 'return_reversal_cancelled' : 'return_reversal',
                'commission_period_id' => $period?->id, 'source_period_id' => $source->commission_period_id,
                'seller_id' => $this->economicOwnerId($source), 'invoice_id' => $source->invoice_id, 'invoice_item_id' => $source->invoice_item_id,
                'source_ledger_entry_id' => $source->id, 'sales_return_document_id' => $return->id,
                'sales_return_item_id' => $target['item']->id ?? null, 'quantity_delta' => $delta['quantity'], 'net_amount' => $delta['net'],
                'base_commission_amount' => $delta['base'], 'campaign_commission_amount' => $delta['campaign'], 'total_commission_amount' => $delta['total'],
                'status' => $period ? CommissionCorrectionEntry::STATUS_ASSIGNED : CommissionCorrectionEntry::STATUS_PENDING_PERIOD,
                'reason' => $return->commission_effect_type, 'created_by' => $actorId,
            ]);
            $created++;
        }
        if (! $period && $sourceIds->isNotEmpty()) {
            $this->warning('correction_without_period', 'return-period:'.$return->id, $return, 'اثر پورسانت برگشت در انتظار تخصیص دوره است.');
        } elseif ($period && $created > 0) {
            $this->dirtyDocuments($period);
        }
        if ($created > 0) {
            ActivityLogger::log($activity, $return, 'اثر پورسانت برگشت از فروش reconcile شد.', ['entries_created' => $created, 'period_id' => $period?->id]);
        }

        return $created;
    }

    private function economicOwnerId(CommissionLedgerEntry $source): int
    {
        $correctedOwner = CommissionCorrectionEntry::query()->where('event_type', 'seller_reassignment_correction')
            ->where('source_ledger_entry_id', $source->id)->where('total_commission_amount', '>', 0)
            ->latest('created_at')->latest('id')->value('seller_id');

        return (int) ($correctedOwner ?: $source->seller_id);
    }

    private function invalidateWrongSellerClaims(Invoice $invoice, User $newSeller, SellerReassignmentAudit $audit): void
    {
        $items = CommissionDocumentItem::query()->with('document')->where('active_invoice_id', $invoice->id)->get();
        foreach ($items as $item) {
            if ((int) $item->document->seller_id === (int) $newSeller->id) {
                continue;
            }
            $item->update(['status' => CommissionDocumentItem::STATUS_REMOVED, 'active_invoice_id' => null, 'removed_by' => $audit->changed_by,
                'removed_at' => now(), 'removal_reason' => 'seller_reassigned']);
            CommissionDocumentEvent::query()->create(['actor_id' => $audit->changed_by, 'commission_document_id' => $item->document->id,
                'commission_document_item_id' => $item->id, 'event_type' => 'document_invoice_invalidated_by_reassignment',
                'reason' => $audit->reason, 'metadata' => ['reassignment_audit_id' => $audit->id, 'new_seller_id' => $newSeller->id], 'created_at' => now()]);
        }
    }

    private function dirtyDocuments(CommissionPeriod $period): void
    {
        $period->commissionDocuments()->where('status', 'draft')->update(['needs_recalculation' => true]);
    }

    private function warning(string $code, string $identity, ?SalesReturnDocument $return, string $message, ?SellerReassignmentAudit $audit = null): void
    {
        CommissionReconciliationWarning::query()->updateOrCreate(['identity_key' => $identity], ['code' => $code,
            'invoice_id' => $return?->invoice_id ?? $audit?->invoice_id, 'sales_return_document_id' => $return?->id,
            'seller_reassignment_audit_id' => $audit?->id, 'message' => $message, 'context' => null, 'resolved_at' => null]);
    }
}
