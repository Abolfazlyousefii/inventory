<?php

namespace App\Services\Commissions;

use App\Models\CommissionAdjustment;
use App\Models\CommissionCalculationWarning;
use App\Models\CommissionCorrectionEntry;
use App\Models\CommissionDocument;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPayment;
use App\Models\CommissionPeriod;
use App\Models\CommissionPeriodEvent;
use App\Models\CommissionReconciliationWarning;
use App\Models\CommissionSettlement;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommissionPeriodWorkflowService
{
    public function __construct(private readonly CommissionAdjustmentService $adjustments) {}

    public function reviewBlockers(CommissionPeriod $period): array
    {
        $blockers = [];
        if ($period->status !== CommissionPeriod::STATUS_OPEN) {
            $blockers[] = 'فقط دوره باز می‌تواند وارد بررسی شود.';
        }
        if ($period->needs_recalculation) {
            $blockers[] = 'دوره نیازمند محاسبه مجدد است.';
        }
        $criticalWarnings = CommissionCalculationWarning::query()->where('commission_period_id', $period->id)
            ->whereNotIn('code', ['missing_rate'])->count();
        if ($criticalWarnings > 0) {
            $blockers[] = "{$criticalWarnings} خطای محاسباتی بحرانی حل‌نشده وجود دارد.";
        }
        if (! CommissionLedgerEntry::query()->where('commission_period_id', $period->id)->exists()
            && $this->activitySellerIds($period)->isNotEmpty()) {
            $blockers[] = 'محاسبات پورسانت دوره کامل تولید نشده است.';
        }

        return $blockers;
    }

    public function startReview(CommissionPeriod $period, User $actor): CommissionPeriod
    {
        return DB::transaction(function () use ($period, $actor) {
            $period = CommissionPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if ($period->status === CommissionPeriod::STATUS_REVIEW) {
                return $period;
            }
            $this->rejectIfBlocked($this->reviewBlockers($period), 'period');
            $period->update(['status' => CommissionPeriod::STATUS_REVIEW, 'review_started_by' => $actor->id, 'review_started_at' => now()]);
            $this->event($period, 'period_moved_to_review', $actor);
            ActivityLogger::log('period_moved_to_review', $period, 'دوره پورسانت وارد مرحله بررسی شد.');

            return $period->fresh();
        }, 3);
    }

    public function closeBlockers(CommissionPeriod $period): array
    {
        $blockers = [];
        if ($period->status !== CommissionPeriod::STATUS_REVIEW) {
            $blockers[] = 'بستن دوره فقط از وضعیت بررسی مجاز است.';
        }
        if ($period->needs_recalculation) {
            $blockers[] = 'دوره نیازمند محاسبه مجدد است.';
        }
        foreach ($this->activitySellerIds($period) as $sellerId) {
            $document = CommissionDocument::query()->where('commission_period_id', $period->id)->where('seller_id', $sellerId)->first();
            $sellerName = User::query()->whereKey($sellerId)->value('name') ?? "#{$sellerId}";
            if (! $document) {
                $blockers[] = "فروشنده {$sellerName} فعالیت پورسانتی دارد اما سند ندارد.";
            } elseif ($document->status !== CommissionDocument::STATUS_FINALIZED) {
                $blockers[] = "سند فروشنده {$sellerName} نهایی نشده است.";
            }
        }
        $documents = CommissionDocument::query()->where('commission_period_id', $period->id)->get();
        if ($documents->isEmpty() && $this->activitySellerIds($period)->isEmpty()) {
            $blockers[] = 'دوره فاقد فعالیت پورسانتی و سند نهایی است و قابل بستن نیست.';
        }
        foreach ($documents as $document) {
            if ($document->needs_recalculation) {
                $blockers[] = "سند {$document->document_number} نیازمند محاسبه مجدد است.";
            }
            $pendingItems = $document->items()->where('status', 'pending')->count();
            $staleItems = $document->items()->where('is_stale', true)->count();
            $pendingCorrections = $document->corrections()->where('status', 'pending')->count();
            $pendingAdjustments = $document->adjustments()->where('status', 'pending')->count();
            if ($pendingItems) {
                $blockers[] = "سند {$document->document_number}: {$pendingItems} فاکتور pending.";
            }
            if ($staleItems) {
                $blockers[] = "سند {$document->document_number}: {$staleItems} ردیف stale.";
            }
            if ($pendingCorrections) {
                $blockers[] = "سند {$document->document_number}: {$pendingCorrections} اصلاح pending.";
            }
            if ($pendingAdjustments) {
                $blockers[] = "سند {$document->document_number}: {$pendingAdjustments} تعدیل pending.";
            }
        }
        $unassigned = CommissionCorrectionEntry::query()->where('status', CommissionCorrectionEntry::STATUS_PENDING_PERIOD)->count();
        if ($unassigned) {
            $blockers[] = "{$unassigned} اصلاح حیاتی بدون دوره وجود دارد.";
        }
        $warnings = CommissionReconciliationWarning::query()->whereNull('resolved_at')->where('code', 'like', '%period%')->count();
        if ($warnings) {
            $blockers[] = "{$warnings} هشدار reconciliation مربوط به دوره حل‌نشده است.";
        }
        foreach ($documents->where('status', CommissionDocument::STATUS_FINALIZED) as $document) {
            if ((int) $document->final_commission_total !== $this->documentSourceTotal($document)) {
                $blockers[] = "جمع نهایی سند {$document->document_number} با منابع تأییدشده سازگار نیست.";
            }
            if ((int) $document->final_commission_total < 0 && ! $this->nextMutablePeriod($period)) {
                $blockers[] = "برای مانده منفی سند {$document->document_number} دوره مقصد باز/بررسی وجود ندارد.";
            }
        }

        return array_values(array_unique($blockers));
    }

    public function close(CommissionPeriod $period, User $actor): CommissionPeriod
    {
        return DB::transaction(function () use ($period, $actor) {
            $period = CommissionPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if (in_array($period->status, [CommissionPeriod::STATUS_CLOSED, CommissionPeriod::STATUS_PAID], true)) {
                return $period;
            }
            $this->rejectIfBlocked($this->closeBlockers($period), 'period');
            $documents = CommissionDocument::query()->where('commission_period_id', $period->id)
                ->where('status', CommissionDocument::STATUS_FINALIZED)->lockForUpdate()->get();
            $settlements = collect();
            foreach ($documents as $document) {
                $settlement = CommissionSettlement::query()->firstOrCreate(
                    ['seller_id' => $document->seller_id, 'commission_period_id' => $period->id],
                    $this->settlementAttributes($document, $actor),
                );
                if (! $settlement->settlement_number) {
                    $settlement->update(['settlement_number' => sprintf('SET-COM-%06d', $settlement->id)]);
                }
                $settlements->push($settlement->fresh());
                ActivityLogger::log('settlement_created', $settlement, 'تسویه نهایی پورسانت ایجاد شد.', ['net_payable' => $settlement->net_payable]);
            }
            if ((int) $documents->sum('final_commission_total') !== (int) $settlements->sum('net_payable')) {
                throw ValidationException::withMessages(['period' => 'جمع اسناد نهایی با جمع تسویه‌ها برابر نیست.']);
            }
            foreach ($settlements->where('net_payable', '<', 0) as $settlement) {
                $target = $this->nextMutablePeriod($period);
                $this->adjustments->createCarryForward($settlement->seller_id, $period, $target, $settlement->net_payable, $settlement->id, $actor->id);
                $settlement->update(['carry_forward_created' => true, 'status' => CommissionSettlement::STATUS_CREDIT_CARRIED, 'remaining_amount' => 0]);
            }
            foreach ($settlements->where('net_payable', 0) as $settlement) {
                $settlement->update(['status' => CommissionSettlement::STATUS_ZERO, 'remaining_amount' => 0]);
            }
            $snapshot = $this->periodSnapshot($documents);
            $period->update($snapshot + ['status' => CommissionPeriod::STATUS_CLOSED, 'closed_by' => $actor->id, 'closed_at' => now(),
                'close_fingerprint' => hash('sha256', json_encode([$snapshot, $settlements->pluck('source_fingerprint', 'id')->all()]))]);
            $this->event($period, 'period_closed', $actor, ['settlement_count' => $settlements->count(), 'approved_total' => $snapshot['approved_commission_snapshot']]);
            ActivityLogger::log('period_closed', $period, 'دوره پورسانت به‌صورت تراکنشی بسته شد.', $snapshot);

            return $period->fresh();
        }, 3);
    }

    public function paidBlockers(CommissionPeriod $period): array
    {
        $blockers = [];
        if ($period->status !== CommissionPeriod::STATUS_CLOSED) {
            $blockers[] = 'فقط دوره بسته قابل پرداخت‌شده کردن است.';
        }
        $settlements = $period->settlements()->with('payments')->get();
        if ($settlements->isEmpty()) {
            $blockers[] = 'دوره فاقد تسویه است و نمی‌تواند پرداخت‌شده شود.';
        }
        foreach ($settlements as $settlement) {
            $paid = (int) $settlement->payments->where('status', CommissionPayment::STATUS_RECORDED)->sum('amount');
            if ($paid !== (int) $settlement->paid_amount) {
                $blockers[] = "کش پرداخت تسویه {$settlement->settlement_number} ناسازگار است.";
            }
            if ($paid > max(0, $settlement->net_payable)) {
                $blockers[] = "تسویه {$settlement->settlement_number} دارای اضافه‌پرداخت است.";
            }
            if ($settlement->net_payable > 0 && ($settlement->status !== CommissionSettlement::STATUS_PAID || $settlement->remaining_amount !== 0)) {
                $blockers[] = "تسویه {$settlement->settlement_number} هنوز کامل پرداخت نشده است.";
            }
            if ($settlement->net_payable < 0 && ! $settlement->carry_forward_created) {
                $blockers[] = "مانده منفی {$settlement->settlement_number} منتقل نشده است.";
            }
        }
        $documentTotal = (int) CommissionDocument::query()->where('commission_period_id', $period->id)->where('status', CommissionDocument::STATUS_FINALIZED)->sum('final_commission_total');
        $settlementTotal = (int) $period->settlements()->sum('net_payable');
        if ($documentTotal !== $settlementTotal) {
            $blockers[] = 'جمع اسناد نهایی با تسویه‌های دوره مغایرت دارد.';
        }

        return $blockers;
    }

    public function markPaid(CommissionPeriod $period, User $actor): CommissionPeriod
    {
        return DB::transaction(function () use ($period, $actor) {
            $period = CommissionPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if ($period->status === CommissionPeriod::STATUS_PAID) {
                return $period;
            }
            $this->rejectIfBlocked($this->paidBlockers($period), 'period');
            $period->update(['status' => CommissionPeriod::STATUS_PAID, 'paid_by' => $actor->id, 'paid_at' => now()]);
            $this->event($period, 'period_marked_paid', $actor);
            ActivityLogger::log('period_marked_paid', $period, 'دوره پورسانت پرداخت‌شده و قفل نهایی شد.');

            return $period->fresh();
        }, 3);
    }

    public function documentSourceTotal(CommissionDocument $document): int
    {
        return (int) $document->items()->where('status', 'approved')->sum('total_commission_snapshot')
            + (int) $document->corrections()->where('status', 'approved')->sum('total_amount')
            + (int) $document->adjustments()->where('status', 'approved')->sum('amount_snapshot');
    }

    private function settlementAttributes(CommissionDocument $document, User $actor): array
    {
        $returns = (int) $document->corrections()->where('status', 'approved')->whereIn('type', ['return_reversal', 'return_reversal_cancelled'])->sum('total_amount');
        $sellerCorrections = (int) $document->corrections()->where('status', 'approved')->where('type', 'seller_reassignment_correction')->sum('total_amount');
        $net = (int) $document->final_commission_total;

        return [
            'commission_document_id' => $document->id, 'net_sales_snapshot' => (int) $document->final_net_sales,
            'base_commission_snapshot' => (int) $document->final_base_commission, 'campaign_commission_snapshot' => (int) $document->final_campaign_commission,
            'return_reversal_snapshot' => $returns, 'seller_correction_snapshot' => $sellerCorrections,
            'manual_adjustment_snapshot' => (int) $document->final_adjustment_amount, 'net_payable' => $net,
            'paid_amount' => 0, 'remaining_amount' => max(0, $net),
            'status' => $net > 0 ? CommissionSettlement::STATUS_UNPAID : ($net < 0 ? CommissionSettlement::STATUS_CREDIT_CARRIED : CommissionSettlement::STATUS_ZERO),
            'carry_forward_created' => false, 'source_fingerprint' => (string) $document->final_fingerprint,
            'created_by' => $actor->id, 'settled_at' => now(),
        ];
    }

    private function periodSnapshot(Collection $documents): array
    {
        return [
            'total_net_sales_snapshot' => (int) $documents->sum('final_net_sales'),
            'base_commission_snapshot' => (int) $documents->sum('final_base_commission'),
            'campaign_commission_snapshot' => (int) $documents->sum('final_campaign_commission'),
            'return_reversal_snapshot' => (int) $documents->sum(fn ($d) => $d->corrections()->where('status', 'approved')->whereIn('type', ['return_reversal', 'return_reversal_cancelled'])->sum('total_amount')),
            'seller_correction_snapshot' => (int) $documents->sum(fn ($d) => $d->corrections()->where('status', 'approved')->where('type', 'seller_reassignment_correction')->sum('total_amount')),
            'manual_adjustment_snapshot' => (int) $documents->sum('final_adjustment_amount'),
            'approved_commission_snapshot' => (int) $documents->sum('final_commission_total'),
            'seller_count_snapshot' => $documents->pluck('seller_id')->unique()->count(), 'document_count_snapshot' => $documents->count(),
        ];
    }

    private function activitySellerIds(CommissionPeriod $period): Collection
    {
        return CommissionLedgerEntry::query()->where('commission_period_id', $period->id)->pluck('seller_id')
            ->merge(CommissionCorrectionEntry::query()->where('commission_period_id', $period->id)->pluck('seller_id'))
            ->merge(CommissionAdjustment::query()->where('commission_period_id', $period->id)->pluck('seller_id'))->unique()->values();
    }

    private function nextMutablePeriod(CommissionPeriod $period): ?CommissionPeriod
    {
        return CommissionPeriod::query()->where('start_at', '>=', $period->end_at)
            ->whereIn('status', [CommissionPeriod::STATUS_OPEN, CommissionPeriod::STATUS_REVIEW])->oldest('start_at')->first();
    }

    private function rejectIfBlocked(array $blockers, string $key): void
    {
        if ($blockers) {
            throw ValidationException::withMessages([$key => $blockers]);
        }
    }

    private function event(CommissionPeriod $period, string $type, User $actor, array $metadata = []): void
    {
        CommissionPeriodEvent::query()->create(['commission_period_id' => $period->id, 'actor_id' => $actor->id,
            'event_type' => $type, 'metadata' => $metadata ?: null, 'created_at' => now()]);
    }
}
