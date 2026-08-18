<?php

namespace App\Services\Commissions;

use App\Models\CommissionCorrectionEntry;
use App\Models\CommissionDocument;
use App\Models\CommissionDocumentCorrection;
use App\Models\CommissionDocumentEvent;
use App\Models\CommissionDocumentItem;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPeriod;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommissionDocumentService
{
    public function __construct(
        private readonly CommissionDocumentSnapshotService $snapshots,
        private readonly CommissionAdjustmentService $adjustments,
    ) {}

    public function create(User $seller, CommissionPeriod $period, User $actor, ?string $notes = null): CommissionDocument
    {
        return DB::transaction(function () use ($seller, $period, $actor, $notes) {
            $period = CommissionPeriod::query()->lockForUpdate()->findOrFail($period->id);
            $this->assertPeriodEditable($period);
            if ($period->needs_recalculation) {
                throw ValidationException::withMessages(['period' => 'محاسبات پورسانت این دوره نیازمند به‌روزرسانی است. ابتدا محاسبات دوره را به‌روزرسانی کنید.']);
            }
            $existing = CommissionDocument::query()->where('seller_id', $seller->id)->where('commission_period_id', $period->id)->first();
            if ($existing) {
                throw ValidationException::withMessages(['document' => 'برای این فروشنده در این دوره قبلاً سند پورسانت ایجاد شده است.']);
            }

            try {
                $document = CommissionDocument::query()->create([
                    'seller_id' => $seller->id, 'commission_period_id' => $period->id,
                    'status' => CommissionDocument::STATUS_DRAFT, 'notes' => $notes,
                    'created_by' => $actor->id, 'updated_by' => $actor->id,
                ]);
            } catch (QueryException $exception) {
                if (CommissionDocument::query()->where('seller_id', $seller->id)->where('commission_period_id', $period->id)->exists()) {
                    throw ValidationException::withMessages(['document' => 'برای این فروشنده در این دوره قبلاً سند پورسانت ایجاد شده است.']);
                }
                throw $exception;
            }
            $document->update(['document_number' => sprintf('COM-%06d', $document->id)]);
            $this->event($document, 'document_created', $actor, null, null, ['notes' => $notes]);
            $this->refreshCandidates($document, $actor, false);
            $this->refreshCorrections($document, $actor);
            $this->adjustments->refreshDocument($document, $actor);

            return $document->fresh(['seller', 'period']);
        }, 3);
    }

    public function addInvoice(CommissionDocument $document, Invoice $invoice, User $actor, ?string $outsideReason = null): CommissionDocumentItem
    {
        return DB::transaction(function () use ($document, $invoice, $actor, $outsideReason) {
            $document = CommissionDocument::query()->with('period')->lockForUpdate()->findOrFail($document->id);
            $this->assertDraft($document);
            $invoice = Invoice::query()->with('preinvoiceOrder')->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->isCancelled()) {
                throw ValidationException::withMessages(['invoice' => 'فاکتور لغوشده قابل افزودن به سند پورسانت نیست.']);
            }
            if ((int) $invoice->effective_seller_id !== (int) $document->seller_id) {
                throw ValidationException::withMessages(['invoice' => 'فروشنده مؤثر فاکتور با فروشنده سند یکسان نیست.']);
            }
            $outside = ! $document->period->contains($invoice->display_document_date);
            if ($outside && trim((string) $outsideReason) === '') {
                throw ValidationException::withMessages(['outside_period_reason' => 'برای فاکتور خارج از دوره، دلیل الزامی است.']);
            }
            $sourcePeriod = $this->snapshots->sourcePeriod($invoice);
            $snapshot = $this->snapshots->forInvoice($invoice, $sourcePeriod);
            if (! $snapshot) {
                throw ValidationException::withMessages(['invoice' => 'محاسبات پورسانت این فاکتور آماده نیست. ابتدا دوره مربوط به فاکتور را به‌روزرسانی کنید.']);
            }
            $claim = CommissionDocumentItem::query()->with('document:id,document_number,seller_id,commission_period_id')
                ->where('active_invoice_id', $invoice->id)->lockForUpdate()->first();
            if ($claim && (int) $claim->commission_document_id !== (int) $document->id) {
                throw ValidationException::withMessages(['invoice' => "این فاکتور در حال حاضر در سند پورسانت {$claim->document->document_number} رزرو شده است."]);
            }
            $item = CommissionDocumentItem::query()->where('commission_document_id', $document->id)
                ->where('invoice_id', $invoice->id)->lockForUpdate()->first();
            if ($item && in_array($item->status, [CommissionDocumentItem::STATUS_PENDING, CommissionDocumentItem::STATUS_APPROVED], true)) {
                throw ValidationException::withMessages(['invoice' => 'این فاکتور قبلاً در همین سند فعال است.']);
            }

            $attributes = $this->snapshotAttributes($document, $invoice, $snapshot, $actor, $outside, $outsideReason);
            if ($item) {
                $item->update($attributes + ['status' => CommissionDocumentItem::STATUS_PENDING, 'active_invoice_id' => $invoice->id,
                    'approved_by' => null, 'approved_at' => null, 'rejected_by' => null, 'rejected_at' => null,
                    'rejection_reason' => null, 'removed_by' => null, 'removed_at' => null, 'removal_reason' => null, 'is_stale' => false]);
                $event = 'invoice_reactivated';
            } else {
                $item = $document->items()->create($attributes + ['invoice_id' => $invoice->id, 'active_invoice_id' => $invoice->id, 'status' => CommissionDocumentItem::STATUS_PENDING]);
                $event = $outside ? 'invoice_added_outside_period' : 'invoice_added';
            }
            $this->event($document, $event, $actor, $item, $outsideReason, ['invoice_number' => $invoice->uuid]);

            return $item->fresh();
        }, 3);
    }

    public function refreshCandidates(CommissionDocument $document, User $actor, bool $recordEvent = true): int
    {
        $document->loadMissing('period');
        $this->assertPeriodEditable($document->period);
        $this->assertDraft($document);
        if ($document->period->needs_recalculation) {
            throw ValidationException::withMessages(['period' => 'محاسبات پورسانت این دوره نیازمند به‌روزرسانی است. ابتدا محاسبات دوره را به‌روزرسانی کنید.']);
        }
        $invoiceIds = CommissionLedgerEntry::query()->where('commission_period_id', $document->commission_period_id)
            ->where('seller_id', $document->seller_id)->where('status', CommissionLedgerEntry::STATUS_ACTIVE)
            ->where('missing_rate', false)->whereNotNull('invoice_id')->distinct()->orderBy('invoice_id')->pluck('invoice_id');
        $historyIds = $document->items()->pluck('invoice_id')->filter()->all();
        $added = 0;
        foreach ($invoiceIds->diff($historyIds) as $invoiceId) {
            try {
                $this->addInvoice($document, Invoice::query()->findOrFail($invoiceId), $actor);
                $added++;
            } catch (ValidationException) {
                // A candidate can become unavailable between the bounded query and its claim transaction.
            }
        }
        if ($recordEvent) {
            $this->event($document, 'candidates_refreshed', $actor, null, null, ['added_count' => $added]);
        }

        return $added;
    }

    public function approve(CommissionDocumentItem $item, User $actor): CommissionDocumentItem
    {
        return $this->transition($item, $actor, CommissionDocumentItem::STATUS_APPROVED);
    }

    public function reject(CommissionDocumentItem $item, User $actor, string $reason): CommissionDocumentItem
    {
        return $this->transition($item, $actor, CommissionDocumentItem::STATUS_REJECTED, $reason);
    }

    public function remove(CommissionDocumentItem $item, User $actor, string $reason): CommissionDocumentItem
    {
        return $this->transition($item, $actor, CommissionDocumentItem::STATUS_REMOVED, $reason);
    }

    private function transition(CommissionDocumentItem $item, User $actor, string $status, ?string $reason = null): CommissionDocumentItem
    {
        return DB::transaction(function () use ($item, $actor, $status, $reason) {
            $item = CommissionDocumentItem::query()->with('document')->lockForUpdate()->findOrFail($item->id);
            $this->assertDraft($item->document);
            if (! in_array($item->status, [CommissionDocumentItem::STATUS_PENDING, CommissionDocumentItem::STATUS_APPROVED], true) || ! $item->active_invoice_id) {
                throw ValidationException::withMessages(['invoice' => 'فاکتور آزادشده ابتدا باید به‌صورت دستی دوباره به سند افزوده شود.']);
            }
            if (in_array($status, [CommissionDocumentItem::STATUS_REJECTED, CommissionDocumentItem::STATUS_REMOVED], true) && trim((string) $reason) === '') {
                throw ValidationException::withMessages(['reason' => 'ثبت دلیل الزامی است.']);
            }
            $attributes = ['status' => $status, 'active_invoice_id' => in_array($status, [CommissionDocumentItem::STATUS_PENDING, CommissionDocumentItem::STATUS_APPROVED], true) ? $item->invoice_id : null];
            if ($status === CommissionDocumentItem::STATUS_APPROVED) {
                $attributes += ['approved_by' => $actor->id, 'approved_at' => now()];
            } elseif ($status === CommissionDocumentItem::STATUS_REJECTED) {
                $attributes += ['rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => $reason];
            } else {
                $attributes += ['removed_by' => $actor->id, 'removed_at' => now(), 'removal_reason' => $reason];
            }
            $item->update($attributes);
            $this->event($item->document, 'invoice_'.$status, $actor, $item, $reason);

            return $item->fresh();
        }, 3);
    }

    public function refreshCalculations(CommissionDocument $document, User $actor): int
    {
        return DB::transaction(function () use ($document, $actor) {
            $document = CommissionDocument::query()->with(['period', 'items.invoice'])->lockForUpdate()->findOrFail($document->id);
            $this->assertDraft($document);
            $changed = 0;
            $unresolved = 0;
            foreach ($document->items->whereIn('status', [CommissionDocumentItem::STATUS_PENDING, CommissionDocumentItem::STATUS_APPROVED]) as $item) {
                if (! $item->invoice) {
                    $item->update(['is_stale' => true]);
                    $unresolved++;

                    continue;
                }
                $sourcePeriod = CommissionPeriod::query()->find($item->source_period_id);
                $snapshot = $this->snapshots->forInvoice($item->invoice, $sourcePeriod);
                if (! $snapshot) {
                    $item->update(['is_stale' => true]);
                    $unresolved++;

                    continue;
                }
                if ($snapshot['source_fingerprint'] === $item->source_fingerprint) {
                    $item->update(['is_stale' => false]);

                    continue;
                }
                $old = $item->only(['net_sales_snapshot', 'base_commission_snapshot', 'campaign_commission_snapshot', 'total_commission_snapshot', 'source_fingerprint']);
                $item->update($snapshot + ['status' => CommissionDocumentItem::STATUS_PENDING, 'is_stale' => false, 'approved_by' => null, 'approved_at' => null]);
                $this->event($document, 'invoice_calculation_changed', $actor, $item, null, ['old' => $old, 'new_total' => $snapshot['total_commission_snapshot']]);
                $changed++;
            }
            $changed += $this->refreshCorrections($document, $actor);
            $changed += $this->adjustments->refreshDocument($document, $actor);
            $document->update(['needs_recalculation' => $unresolved > 0, 'updated_by' => $actor->id]);
            $this->event($document, 'document_calculations_refreshed', $actor, null, null, ['changed_count' => $changed, 'unresolved_count' => $unresolved]);

            return $changed;
        });
    }

    public function markStaleForPeriod(CommissionPeriod $period): int
    {
        $items = CommissionDocumentItem::query()->with('invoice')->where('source_period_id', $period->id)
            ->whereIn('status', [CommissionDocumentItem::STATUS_PENDING, CommissionDocumentItem::STATUS_APPROVED])->get();
        $stale = 0;
        foreach ($items as $item) {
            $snapshot = $item->invoice ? $this->snapshots->forInvoice($item->invoice, $period) : null;
            if (! $snapshot || $snapshot['source_fingerprint'] !== $item->source_fingerprint) {
                $item->update(['is_stale' => true]);
                $item->document()->update(['needs_recalculation' => true]);
                $stale++;
            }
        }

        return $stale;
    }

    public function updateNotes(CommissionDocument $document, User $actor, ?string $notes): void
    {
        $this->assertDraft($document);
        $document->update(['notes' => $notes, 'updated_by' => $actor->id]);
        $this->event($document, 'document_note_updated', $actor);
    }

    public function totals(CommissionDocument $document): array
    {
        $base = $document->items();
        $sum = fn (string $status, string $column) => (int) (clone $base)->where('status', $status)->sum($column);

        $corrections = $document->corrections();
        $adjustments = $document->adjustments();

        return [
            'pending_count' => (clone $base)->where('status', 'pending')->count(), 'approved_count' => (clone $base)->where('status', 'approved')->count(),
            'rejected_count' => (clone $base)->where('status', 'rejected')->count(), 'removed_count' => (clone $base)->where('status', 'removed')->count(),
            'pending_commission' => $sum('pending', 'total_commission_snapshot'), 'approved_net_sales' => $sum('approved', 'net_sales_snapshot'),
            'approved_base_commission' => $sum('approved', 'base_commission_snapshot'), 'approved_campaign_commission' => $sum('approved', 'campaign_commission_snapshot'),
            'approved_total_commission' => $sum('approved', 'total_commission_snapshot'), 'rejected_commission' => $sum('rejected', 'total_commission_snapshot'),
            'pending_correction' => (int) (clone $corrections)->where('status', 'pending')->sum('total_amount'),
            'approved_correction' => (int) (clone $corrections)->where('status', 'approved')->sum('total_amount'),
            'pending_adjustment' => (int) (clone $adjustments)->where('status', 'pending')->sum('amount_snapshot'),
            'approved_adjustment' => (int) (clone $adjustments)->where('status', 'approved')->sum('amount_snapshot'),
            'pending_adjustment_count' => (clone $adjustments)->where('status', 'pending')->count(),
            'approved_net_total' => $sum('approved', 'total_commission_snapshot')
                + (int) (clone $corrections)->where('status', 'approved')->sum('total_amount')
                + (int) (clone $adjustments)->where('status', 'approved')->sum('amount_snapshot'),
        ];
    }

    public function finalize(CommissionDocument $document, User $actor): CommissionDocument
    {
        return DB::transaction(function () use ($document, $actor) {
            $document = CommissionDocument::query()->with('period')->lockForUpdate()->findOrFail($document->id);
            if ($document->status === CommissionDocument::STATUS_FINALIZED) {
                return $document;
            }
            if ($document->period->status !== CommissionPeriod::STATUS_REVIEW) {
                throw ValidationException::withMessages(['period' => 'نهایی‌سازی سند فقط در مرحله بررسی دوره مجاز است.']);
            }
            $totals = $this->totals($document);
            $blockers = [];
            if ($document->needs_recalculation) {
                $blockers[] = 'سند نیازمند به‌روزرسانی محاسبات است.';
            }
            if ($document->items()->where('status', 'pending')->exists()) {
                $blockers[] = 'فاکتور بررسی‌نشده وجود دارد.';
            }
            if ($document->items()->where('is_stale', true)->exists()) {
                $blockers[] = 'فاکتور stale وجود دارد.';
            }
            if ($document->corrections()->where('status', 'pending')->exists()) {
                $blockers[] = 'اصلاح سیستمی بررسی‌نشده وجود دارد.';
            }
            if ($document->corrections()->where('is_stale', true)->exists()) {
                $blockers[] = 'اصلاح سیستمی stale وجود دارد.';
            }
            if ($document->adjustments()->where('status', 'pending')->exists()) {
                $blockers[] = 'تعدیل بررسی‌نشده وجود دارد.';
            }
            if ($document->adjustments()->where('is_stale', true)->exists()) {
                $blockers[] = 'تعدیل stale وجود دارد.';
            }
            if ($blockers) {
                throw ValidationException::withMessages(['document' => $blockers]);
            }
            $fingerprint = hash('sha256', json_encode([$document->id, $totals, $document->items()->max('updated_at'), $document->corrections()->max('updated_at'), $document->adjustments()->max('updated_at')]));
            $document->update([
                'status' => CommissionDocument::STATUS_FINALIZED, 'final_net_sales' => $totals['approved_net_sales'],
                'final_base_commission' => $totals['approved_base_commission'], 'final_campaign_commission' => $totals['approved_campaign_commission'],
                'final_correction_amount' => $totals['approved_correction'], 'final_adjustment_amount' => $totals['approved_adjustment'],
                'final_commission_total' => $totals['approved_net_total'], 'final_fingerprint' => $fingerprint,
                'finalized_by' => $actor->id, 'finalized_at' => now(), 'updated_by' => $actor->id,
            ]);
            $this->event($document, 'document_finalized', $actor, null, null, ['final_total' => $totals['approved_net_total'], 'fingerprint' => $fingerprint]);

            return $document->fresh();
        }, 3);
    }

    public function reviewCorrection(CommissionDocumentCorrection $row, User $actor, bool $approved, ?string $reason = null): CommissionDocumentCorrection
    {
        return DB::transaction(function () use ($row, $actor, $approved, $reason) {
            $row = CommissionDocumentCorrection::query()->with('document')->lockForUpdate()->findOrFail($row->id);
            $this->assertDraft($row->document);
            if (! $approved && trim((string) $reason) === '') {
                throw ValidationException::withMessages(['reason' => 'ثبت دلیل رد اصلاح الزامی است.']);
            }
            $row->update($approved
                ? ['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'rejected_by' => null, 'rejected_at' => null, 'rejection_reason' => null]
                : ['status' => 'rejected', 'active_correction_entry_id' => null, 'rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);
            $this->event($row->document, $approved ? 'correction_approved' : 'correction_rejected', $actor, null, $reason, ['correction_id' => $row->commission_correction_entry_id]);

            return $row->fresh();
        });
    }

    private function refreshCorrections(CommissionDocument $document, User $actor): int
    {
        $entries = CommissionCorrectionEntry::query()->with(['invoice:id,uuid', 'sourcePeriod:id,label'])
            ->where('commission_period_id', $document->commission_period_id)->where('seller_id', $document->seller_id)->get();
        $changed = 0;
        foreach ($entries as $entry) {
            $fingerprint = hash('sha256', json_encode([$entry->id, $entry->updated_at?->format('U.u'), $entry->base_commission_amount, $entry->campaign_commission_amount, $entry->total_commission_amount]));
            $row = $document->corrections()->where('commission_correction_entry_id', $entry->id)->first();
            $attributes = ['type' => $entry->event_type, 'description' => $entry->event_type === 'seller_reassignment_correction' ? 'اصلاح انتقال فروشنده' : 'اثر برگشت از فروش',
                'source_invoice_number' => $entry->invoice?->uuid, 'source_period_label' => $entry->sourcePeriod?->label,
                'base_amount' => $entry->base_commission_amount, 'campaign_amount' => $entry->campaign_commission_amount,
                'total_amount' => $entry->total_commission_amount, 'source_fingerprint' => $fingerprint, 'is_stale' => false];
            if (! $row) {
                $row = $document->corrections()->create($attributes + ['commission_correction_entry_id' => $entry->id,
                    'active_correction_entry_id' => $entry->id, 'status' => 'pending', 'added_by' => $actor->id, 'added_at' => now()]);
                $this->event($document, 'correction_added_to_document', $actor, null, null, ['correction_row_id' => $row->id]);
                $changed++;
            } elseif ($row->source_fingerprint !== $fingerprint) {
                $row->update($attributes + ['status' => 'pending', 'active_correction_entry_id' => $entry->id, 'approved_by' => null, 'approved_at' => null]);
                $changed++;
            }
        }

        return $changed;
    }

    private function snapshotAttributes(CommissionDocument $document, Invoice $invoice, array $snapshot, User $actor, bool $outside, ?string $reason): array
    {
        return $snapshot + [
            'invoice_number_snapshot' => $invoice->uuid, 'invoice_date_snapshot' => $invoice->display_document_date,
            'customer_name_snapshot' => $invoice->customer_name ?: ($invoice->customer?->name ?? '—'),
            'seller_id_snapshot' => $document->seller_id, 'is_outside_period' => $outside,
            'outside_period_reason' => $outside ? trim((string) $reason) : null, 'added_by' => $actor->id, 'added_at' => now(),
        ];
    }

    private function event(CommissionDocument $document, string $type, User $actor, ?CommissionDocumentItem $item = null, ?string $reason = null, array $metadata = []): void
    {
        CommissionDocumentEvent::query()->create(['actor_id' => $actor->id, 'commission_document_id' => $document->id,
            'commission_document_item_id' => $item?->id, 'event_type' => $type, 'reason' => $reason,
            'metadata' => $metadata ?: null, 'created_at' => now()]);
    }

    private function assertDraft(CommissionDocument $document): void
    {
        if ($document->status !== CommissionDocument::STATUS_DRAFT) {
            throw ValidationException::withMessages(['document' => 'فقط سند پیش‌نویس قابل ویرایش است.']);
        }
    }

    private function assertPeriodEditable(CommissionPeriod $period): void
    {
        if (! in_array($period->status, [CommissionPeriod::STATUS_OPEN, CommissionPeriod::STATUS_REVIEW], true)) {
            throw ValidationException::withMessages(['period' => 'اسناد دوره بسته یا پرداخت‌شده immutable هستند.']);
        }
    }
}
