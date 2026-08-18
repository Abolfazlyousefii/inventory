<?php

namespace App\Services\Commissions;

use App\Models\CommissionAdjustment;
use App\Models\CommissionCampaign;
use App\Models\CommissionCorrectionEntry;
use App\Models\CommissionDocument;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPeriod;
use App\Models\CommissionSettlement;
use App\Models\CommissionTarget;
use App\Models\User;
use Illuminate\Support\Collection;

class CommissionDashboardService
{
    public function build(CommissionPeriod $period, ?int $sellerId = null, bool $includeTargets = true): array
    {
        $sellers = User::query()
            ->when($sellerId, fn ($query) => $query->whereKey($sellerId), fn ($query) => $query->activeSellers())
            ->orderBy('name')->get(['id', 'name', 'is_seller', 'is_active', 'can_access_erp']);

        if ($sellerId && ! $sellers->first()?->is_seller) {
            $sellers = collect();
        }

        $sellerIds = $sellers->pluck('id');
        $targets = $includeTargets
            ? CommissionTarget::query()->where('commission_period_id', $period->id)
                ->whereIn('seller_id', $sellerIds)->get()->keyBy('seller_id')
            : collect();
        $ledger = CommissionLedgerEntry::query()->where('commission_period_id', $period->id)
            ->where('status', CommissionLedgerEntry::STATUS_ACTIVE)->whereIn('seller_id', $sellerIds)
            ->groupBy('seller_id')
            ->selectRaw('seller_id, COUNT(*) as item_count, COUNT(DISTINCT invoice_id) as invoice_count')
            ->selectRaw('COALESCE(SUM(base_commission_amount), 0) as base_commission')
            ->selectRaw('COALESCE(SUM(campaign_commission_amount), 0) as campaign_commission')
            ->selectRaw('COALESCE(SUM(total_commission_amount), 0) as sales_commission')
            ->selectRaw('COALESCE(SUM(CASE WHEN missing_rate = 1 THEN 1 ELSE 0 END), 0) as missing_rate_count')
            ->selectRaw('MAX(calculated_at) as last_calculated_at')->get()->keyBy('seller_id');
        $corrections = CommissionCorrectionEntry::query()->where('commission_period_id', $period->id)
            ->whereIn('seller_id', $sellerIds)->groupBy('seller_id')
            ->selectRaw('seller_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN event_type IN ('return_reversal', 'return_reversal_cancelled') THEN total_commission_amount ELSE 0 END), 0) as return_reversal")
            ->selectRaw("COALESCE(SUM(CASE WHEN event_type = 'seller_reassignment_correction' THEN total_commission_amount ELSE 0 END), 0) as historical_corrections")
            ->get()->keyBy('seller_id');
        $adjustments = CommissionAdjustment::query()->where('commission_period_id', $period->id)
            ->whereIn('seller_id', $sellerIds)
            ->groupBy('seller_id')->selectRaw('seller_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) as amount', [CommissionAdjustment::STATUS_APPROVED])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as pending_count', [CommissionAdjustment::STATUS_PENDING])
            ->get()->keyBy('seller_id');
        $documents = CommissionDocument::query()->where('commission_period_id', $period->id)
            ->whereIn('seller_id', $sellerIds)
            ->withCount(['items as pending_item_count' => fn ($query) => $query->where('status', 'pending')])
            ->withSum(['items as approved_items_commission' => fn ($query) => $query->where('status', 'approved')], 'total_commission_snapshot')
            ->withSum(['corrections as approved_corrections_commission' => fn ($query) => $query->where('status', 'approved')], 'total_amount')
            ->withSum(['adjustments as approved_adjustments_commission' => fn ($query) => $query->where('status', 'approved')], 'amount_snapshot')
            ->get()->keyBy('seller_id');
        $settlements = CommissionSettlement::query()->where('commission_period_id', $period->id)
            ->whereIn('seller_id', $sellerIds)->get()->keyBy('seller_id');

        $periodEnded = now()->gte($period->end_at);
        $daysRemaining = $periodEnded
            ? 0
            : max(0, (int) now()->startOfDay()->diffInDays($period->end_at->copy()->startOfDay(), false));

        $rows = $sellers->map(function (User $seller) use ($targets, $ledger, $corrections, $adjustments, $documents, $settlements, $period, $periodEnded, $daysRemaining) {
            $target = $targets->get($seller->id);
            $sales = $ledger->get($seller->id);
            $correction = $corrections->get($seller->id);
            $document = $documents->get($seller->id);
            $settlement = $settlements->get($seller->id);
            $base = (int) ($sales?->base_commission ?? 0);
            $campaign = (int) ($sales?->campaign_commission ?? 0);
            $salesCommission = (int) ($sales?->sales_commission ?? 0);
            $returnReversal = (int) ($correction?->return_reversal ?? 0);
            $historicalCorrections = (int) ($correction?->historical_corrections ?? 0);
            $manualAdjustments = (int) ($adjustments->get($seller->id)?->amount ?? 0);
            $pendingAdjustments = (int) ($adjustments->get($seller->id)?->pending_count ?? 0);
            $calculated = $salesCommission + $returnReversal + $historicalCorrections + $manualAdjustments;
            $targetAmount = $target ? (int) $target->target_amount : null;
            $progress = $targetAmount ? round(($calculated * 100) / $targetAmount, 2) : null;
            $remaining = $targetAmount ? max(0, $targetAmount - $calculated) : null;
            $exceeded = $targetAmount ? max(0, $calculated - $targetAmount) : null;
            $requiredDaily = $remaining && $daysRemaining > 0
                ? intdiv($remaining + intdiv($daysRemaining, 2), $daysRemaining)
                : null;
            $approved = $document
                ? ($document->status === CommissionDocument::STATUS_FINALIZED
                    ? (int) ($document->final_commission_total ?? 0)
                    : (int) ($document->approved_items_commission ?? 0)
                        + (int) ($document->approved_corrections_commission ?? 0)
                        + (int) ($document->approved_adjustments_commission ?? 0))
                : 0;

            return [
                'seller_id' => $seller->id,
                'seller_name' => $seller->name,
                'period_id' => $period->id,
                'has_target' => $target !== null,
                'target_amount' => $targetAmount,
                'calculated_commission' => $calculated,
                'approved_commission' => $approved,
                'base_commission' => $base,
                'campaign_commission' => $campaign,
                'return_reversal' => $returnReversal,
                'historical_corrections' => $historicalCorrections,
                'returns_and_corrections' => $returnReversal + $historicalCorrections,
                'adjustments' => $manualAdjustments,
                'pending_adjustments' => $pendingAdjustments,
                'progress_percent' => $progress,
                'progress_bar_percent' => $progress === null ? null : max(0, min(100, $progress)),
                'remaining_amount' => $remaining,
                'exceeded_amount' => $exceeded,
                'days_remaining' => $daysRemaining,
                'required_daily_commission' => $requiredDaily,
                'is_target_reached' => $targetAmount !== null && $calculated >= $targetAmount,
                'period_ended' => $periodEnded,
                'is_stale' => (bool) $period->needs_recalculation,
                'has_commission' => (int) ($sales?->item_count ?? 0) > 0 || $calculated !== 0,
                'invoice_count' => (int) ($sales?->invoice_count ?? 0),
                'missing_rate_count' => (int) ($sales?->missing_rate_count ?? 0),
                'last_calculated_at' => $sales?->last_calculated_at,
                'pending_document_items' => (int) ($document?->pending_item_count ?? 0),
                'document_status' => $document?->status,
                'has_document' => $document !== null,
                'document_needs_recalculation' => (bool) ($document?->needs_recalculation ?? false),
                'settled_amount' => (int) ($settlement?->paid_amount ?? 0),
                'settlement_remaining' => (int) ($settlement?->remaining_amount ?? 0),
            ];
        })->values();

        $targeted = $rows->where('has_target', true);
        $totalTarget = (int) $targeted->sum('target_amount');
        $targetedCalculated = (int) $targeted->sum('calculated_commission');
        $activeCampaign = CommissionCampaign::query()->withCount('targets')
            ->whereNull('archived_at')->where('start_at', '<=', now())->where('end_at', '>', now())
            ->latest('id')->first();

        return [
            'period' => $period,
            'seller_rows' => $rows,
            'seller_summary' => $sellerId ? $rows->first() : null,
            'team_summary' => [
                'seller_count' => $rows->count(),
                'sellers_with_calculation_count' => $rows->where('has_commission', true)->count(),
                'targeted_seller_count' => $targeted->count(),
                'reached_target_count' => $targeted->where('is_target_reached', true)->count(),
                'total_target' => $totalTarget,
                'total_calculated_commission' => (int) $rows->sum('calculated_commission'),
                'targeted_calculated_commission' => $targetedCalculated,
                'total_approved_commission' => (int) $rows->sum('approved_commission'),
                'team_progress_percent' => $totalTarget > 0 ? round(($targetedCalculated * 100) / $totalTarget, 2) : null,
            ],
            'totals' => [
                'calculated_commission' => (int) $rows->sum('calculated_commission'),
                'approved_commission' => (int) $rows->sum('approved_commission'),
                'pending_review_count' => (int) $rows->sum('pending_document_items'),
                'returns_and_corrections' => (int) $rows->sum('returns_and_corrections'),
                'base_commission' => (int) $rows->sum('base_commission'),
                'campaign_commission' => (int) $rows->sum('campaign_commission'),
                'return_and_corrections' => (int) $rows->sum(fn (array $row) => $row['return_reversal'] + $row['historical_corrections'] + $row['adjustments']),
                'settled_amount' => (int) $rows->sum('settled_amount'),
            ],
            'alerts' => $this->alerts($rows, $period, $sellerIds, $includeTargets),
            'days_remaining' => $daysRemaining,
            'last_calculated_at' => $rows->pluck('last_calculated_at')->filter()->max(),
            'active_campaign' => $activeCampaign,
            'is_stale' => (bool) $period->needs_recalculation,
        ];
    }

    private function alerts(Collection $rows, CommissionPeriod $period, Collection $sellerIds, bool $includeTargets): array
    {
        $pendingCorrectionCount = CommissionCorrectionEntry::query()
            ->where('commission_period_id', $period->id)
            ->where('status', CommissionCorrectionEntry::STATUS_PENDING_PERIOD)
            ->whereIn('seller_id', $sellerIds)
            ->count();

        return array_values(array_filter([
            $period->needs_recalculation ? [
                'key' => 'dirty_period', 'label' => 'دوره نیازمند محاسبه مجدد است', 'count' => 1, 'variant' => 'danger',
            ] : null,
            $rows->sum('missing_rate_count') > 0 ? [
                'key' => 'missing_rates', 'label' => 'قلم فاقد نرخ پورسانت', 'count' => (int) $rows->sum('missing_rate_count'), 'variant' => 'warning',
            ] : null,
            $rows->where('pending_document_items', '>', 0)->count() > 0 ? [
                'key' => 'pending_documents', 'label' => 'سند در انتظار بررسی', 'count' => $rows->where('pending_document_items', '>', 0)->count(), 'variant' => 'warning',
            ] : null,
            $rows->where('document_needs_recalculation', true)->count() > 0 ? [
                'key' => 'stale_documents', 'label' => 'سند نیازمند بروزرسانی', 'count' => $rows->where('document_needs_recalculation', true)->count(), 'variant' => 'danger',
            ] : null,
            $pendingCorrectionCount > 0 ? [
                'key' => 'pending_corrections',
                'label' => 'اصلاح پورسانت در انتظار بررسی',
                'count' => $pendingCorrectionCount,
                'variant' => 'warning',
            ] : null,
            $rows->sum('pending_adjustments') > 0 ? [
                'key' => 'pending_adjustments', 'label' => 'تعدیل در انتظار بررسی', 'count' => (int) $rows->sum('pending_adjustments'), 'variant' => 'warning',
            ] : null,
            in_array($period->status, [CommissionPeriod::STATUS_CLOSED, CommissionPeriod::STATUS_PAID], true) && $rows->sum('settlement_remaining') > 0 ? [
                'key' => 'settlement_issues', 'label' => 'مانده تسویه دوره بسته', 'count' => $rows->where('settlement_remaining', '>', 0)->count(), 'variant' => 'danger',
            ] : null,
            $includeTargets && $rows->where('has_target', false)->count() > 0 ? [
                'key' => 'missing_targets', 'label' => 'فروشنده فاقد تارگت', 'count' => $rows->where('has_target', false)->count(), 'variant' => 'neutral',
            ] : null,
        ]));
    }
}
