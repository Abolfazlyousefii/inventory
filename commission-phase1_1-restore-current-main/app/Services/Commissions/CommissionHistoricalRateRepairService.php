<?php

namespace App\Services\Commissions;

use App\Models\Category;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPeriod;
use App\Models\CommissionRateRevision;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CommissionHistoricalRateRepairService
{
    public function __construct(
        private readonly CommissionRateResolver $resolver,
        private readonly CommissionRateService $rates,
        private readonly CommissionCalculationService $calculation,
    ) {}

    /**
     * Build a read-only, tree-aware and timeline-aware repair plan.
     *
     * Safety rule:
     * - The current hierarchy tells us which exact target (variant/product/category)
     *   owns the rate today.
     * - If that target has multiple revisions, we NEVER drag the newest revision
     *   backwards across older intentional history.
     * - We only fill the leading gap of the selected period by extending the
     *   FIRST revision that overlaps/starts inside the period back to period start.
     * - Later revisions and their transition timestamps stay untouched.
     */
    public function plan(CommissionPeriod $period, Category $rootCategory, ?int $sellerId = null): array
    {
        $this->assertMutablePeriod($period);

        $referenceAt = $this->referenceAt($period);
        $categoryIds = Category::selfAndDescendantIds($rootCategory->id);

        $this->resolver->warm($period->start_at, $period->end_at);

        $revisions = CommissionRateRevision::query()
            ->where('effective_from', '<', $period->end_at)
            ->where(function ($query) use ($period) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>', $period->start_at);
            })
            ->orderBy('target_key')
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get();

        $revisionsById = $revisions->keyBy('id');
        $timelines = $revisions->groupBy('target_key');

        $items = InvoiceItem::query()
            ->with(['invoice.preinvoiceOrder', 'product.category', 'variant'])
            ->whereHas('product', fn ($query) => $query->whereIn('category_id', $categoryIds))
            ->whereHas('invoice', function ($query) use ($period) {
                $query->whereRaw('COALESCE(document_date, created_at) >= ?', [$period->start_at])
                    ->whereRaw('COALESCE(document_date, created_at) < ?', [$period->end_at])
                    ->whereNotIn('status', Invoice::cancelledStatuses());
            })
            ->orderBy('invoice_id')
            ->orderBy('id')
            ->get()
            ->when($sellerId, fn (Collection $collection) => $collection->filter(
                fn (InvoiceItem $item) => (int) $item->invoice->effective_seller_id === $sellerId
            )->values());

        $targets = [];
        $expectations = [];
        $unresolved = [];
        $historicallyMissing = 0;
        $historicalFallback = 0;

        foreach ($items as $item) {
            $invoice = $item->invoice;
            $invoiceDate = $invoice->display_document_date;
            $seller = $invoice->effective_seller_id;
            $reference = $this->resolver->resolve($item->product, $item->variant, $referenceAt);
            $historical = $this->resolver->resolve($item->product, $item->variant, $invoiceDate);

            if ($reference->isAmbiguous || $historical->isAmbiguous) {
                $unresolved[] = $this->unresolvedRow($item, 'AMBIGUOUS_RATE_TIMELINE', $historical, $reference);

                continue;
            }

            if ($reference->isMissing) {
                $unresolved[] = $this->unresolvedRow($item, 'NO_CURRENT_RATE', $historical, $reference);

                continue;
            }

            $referenceRevision = $reference->ruleId ? $revisionsById->get($reference->ruleId) : null;
            if (! $referenceRevision) {
                $unresolved[] = $this->unresolvedRow($item, 'REFERENCE_RATE_REVISION_NOT_FOUND', $historical, $reference);

                continue;
            }

            $timeline = $timelines->get($referenceRevision->target_key, collect());
            $leadingRevision = $this->leadingRevisionForPeriod($timeline, $period);
            if (! $leadingRevision) {
                $unresolved[] = $this->unresolvedRow($item, 'TARGET_TIMELINE_NOT_FOUND_FOR_PERIOD', $historical, $reference);

                continue;
            }

            // The exact target already had a revision covering period start.
            // Any later revision changes are intentional history and must not be
            // rewritten by a historical-gap repair.
            if ($leadingRevision->effective_from->lte($period->start_at)) {
                if ($historical->isMissing) {
                    $unresolved[] = $this->unresolvedRow($item, 'TIMELINE_COVERS_PERIOD_BUT_RESOLVER_IS_MISSING', $historical, $reference);
                }

                continue;
            }

            // Only invoices in the leading gap are repair candidates. Once the
            // first revision started, subsequent revisions/fallbacks belong to the
            // recorded timeline and are not auto-rewritten here.
            if ($invoiceDate->gte($leadingRevision->effective_from)) {
                if ($historical->isMissing) {
                    $unresolved[] = $this->unresolvedRow($item, 'MISSING_AFTER_FIRST_TARGET_REVISION', $historical, $reference);
                }

                continue;
            }

            if (! $seller) {
                $unresolved[] = $this->unresolvedRow($item, 'MISSING_SELLER', $historical, $reference);

                continue;
            }

            if ($this->sameResolutionAsRevision($historical, $leadingRevision)) {
                continue;
            }

            // Seeing another revision of the exact same target before the leading
            // revision means the timeline query and resolver disagree. Fail closed.
            if (! $historical->isMissing
                && (string) $historical->sourceType === (string) $leadingRevision->target_type
                && (int) $historical->sourceId === (int) $leadingRevision->target_id) {
                $unresolved[] = $this->unresolvedRow($item, 'EARLIER_SAME_TARGET_RULE_EXISTS', $historical, $reference);

                continue;
            }

            $targetKey = $leadingRevision->target_key;
            $targets[$targetKey] ??= [
                'revision_id' => (int) $leadingRevision->id,
                'target_type' => (string) $leadingRevision->target_type,
                'target_id' => (int) $leadingRevision->target_id,
                'target_key' => (string) $leadingRevision->target_key,
                'percentage' => (string) $leadingRevision->percentage,
                'created_by' => (int) $leadingRevision->created_by,
                'revision_is_active' => (int) $leadingRevision->active_marker === 1,
                'current_effective_from' => $leadingRevision->effective_from->toDateTimeString(),
                'current_effective_to' => $leadingRevision->effective_to?->toDateTimeString(),
                'requested_effective_from' => $period->start_at->toDateTimeString(),
                'invoice_ids' => [],
                'item_ids' => [],
                'seller_ids' => [],
                'historically_missing_items' => 0,
                'historical_fallback_items' => 0,
                'blocked' => false,
                'block_reason' => null,
            ];

            $targets[$targetKey]['invoice_ids'][] = (int) $invoice->id;
            $targets[$targetKey]['item_ids'][] = (int) $item->id;
            $targets[$targetKey]['seller_ids'][] = (int) $seller;

            if ($historical->isMissing) {
                $targets[$targetKey]['historically_missing_items']++;
                $historicallyMissing++;
            } else {
                $targets[$targetKey]['historical_fallback_items']++;
                $historicalFallback++;
            }

            $expectations[(int) $item->id] = [
                'source_type' => (string) $leadingRevision->target_type,
                'source_id' => (int) $leadingRevision->target_id,
                'rule_id' => (int) $leadingRevision->id,
                'percentage' => (string) $leadingRevision->percentage,
                'invoice_id' => (int) $invoice->id,
            ];
        }

        ksort($targets, SORT_NATURAL);
        foreach ($targets as &$target) {
            $target['invoice_ids'] = array_values(array_unique($target['invoice_ids']));
            $target['item_ids'] = array_values(array_unique($target['item_ids']));
            $target['seller_ids'] = array_values(array_unique($target['seller_ids']));
            $target['affected_invoices'] = count($target['invoice_ids']);
            $target['affected_items'] = count($target['item_ids']);
            $target['affected_sellers'] = count($target['seller_ids']);

            $revision = $revisionsById->get($target['revision_id']) ?? CommissionRateRevision::query()->find($target['revision_id']);
            $blockReason = $revision ? $this->preflightBlockReason($revision, $period) : 'RATE_REVISION_NOT_FOUND';
            if ($blockReason) {
                $target['blocked'] = true;
                $target['block_reason'] = $blockReason;
            }
        }
        unset($target);

        $targetRows = array_values($targets);
        $candidateItemIds = collect($targetRows)->flatMap(fn (array $target) => $target['item_ids'])->unique()->values();
        $candidateInvoiceIds = collect($targetRows)->flatMap(fn (array $target) => $target['invoice_ids'])->unique()->values();
        $candidateSellerIds = collect($targetRows)->flatMap(fn (array $target) => $target['seller_ids'])->unique()->values();

        return [
            'period_id' => (int) $period->id,
            'root_category_id' => (int) $rootCategory->id,
            'seller_id' => $sellerId,
            'reference_at' => $referenceAt->toDateTimeString(),
            'targets' => $targetRows,
            'unresolved' => $unresolved,
            'expectations' => $expectations,
            'summary' => [
                'scanned_items' => $items->count(),
                'repair_targets' => count($targetRows),
                'blocked_targets' => collect($targetRows)->where('blocked', true)->count(),
                'candidate_items' => $candidateItemIds->count(),
                'candidate_invoices' => $candidateInvoiceIds->count(),
                'candidate_sellers' => $candidateSellerIds->count(),
                'historically_missing_items' => $historicallyMissing,
                'historical_fallback_items' => $historicalFallback,
                'unresolved_items' => count($unresolved),
            ],
        ];
    }

    /**
     * Apply the exact leading-gap repair and reconcile the whole mutable period.
     * Rate mutations + recalculation + verification share one outer transaction.
     */
    public function repair(CommissionPeriod $period, Category $rootCategory, ?int $sellerId = null): array
    {
        if ($sellerId) {
            throw ValidationException::withMessages([
                'seller' => 'Repair نرخ یک تغییر سراسری است؛ --seller فقط برای Dry-run مجاز است و با --apply قابل استفاده نیست.',
            ]);
        }

        $plan = $this->plan($period, $rootCategory);

        if ($plan['summary']['unresolved_items'] > 0) {
            throw ValidationException::withMessages([
                'repair' => 'Repair متوقف شد؛ ابتدا ردیف‌های unresolved گزارش Dry-run را بررسی کنید.',
            ]);
        }

        if ($plan['summary']['blocked_targets'] > 0) {
            throw ValidationException::withMessages([
                'repair' => 'Repair متوقف شد؛ حداقل یک revision به دلیل تداخل تاریخچه، کاربر نامعتبر یا وضعیت نهایی‌شده قابل Backdate نیست.',
            ]);
        }

        if ($plan['targets'] === []) {
            return [
                'changed' => false,
                'plan' => $plan,
                'period' => $period->fresh(),
            ];
        }

        return DB::transaction(function () use ($period, $rootCategory, $plan) {
            foreach ($plan['targets'] as $target) {
                $actor = User::query()->find($target['created_by']);
                if (! $actor) {
                    throw ValidationException::withMessages([
                        'repair' => 'کاربر ثبت‌کننده '.$target['target_key'].' وجود ندارد؛ هیچ تغییری اعمال نشد.',
                    ]);
                }

                $this->rates->backdateRevision(
                    $target['revision_id'],
                    $period->start_at,
                    $actor,
                    $target['current_effective_from'],
                );
            }

            $recalculated = $this->calculation->recalculate($period->fresh());
            $this->assertPostRepair($recalculated, $rootCategory, $plan);

            return [
                'changed' => true,
                'plan' => $plan,
                'period' => $recalculated->fresh(),
            ];
        });
    }

    private function referenceAt(CommissionPeriod $period): CarbonInterface
    {
        $periodEnd = Carbon::parse($period->end_at)->subSecond();
        $reference = now()->lt($periodEnd) ? now() : $periodEnd;

        return $reference->lt($period->start_at) ? Carbon::parse($period->start_at) : $reference;
    }

    private function leadingRevisionForPeriod(Collection $timeline, CommissionPeriod $period): ?CommissionRateRevision
    {
        return $timeline
            ->filter(fn (CommissionRateRevision $revision) => $revision->effective_from->lt($period->end_at)
                && ($revision->effective_to === null || $revision->effective_to->gt($period->start_at)))
            ->sort(function (CommissionRateRevision $a, CommissionRateRevision $b) {
                $dateCompare = $a->effective_from->getTimestamp() <=> $b->effective_from->getTimestamp();

                return $dateCompare !== 0 ? $dateCompare : ((int) $a->id <=> (int) $b->id);
            })
            ->first();
    }

    private function sameResolutionAsRevision(object $historical, CommissionRateRevision $revision): bool
    {
        if ($historical->isMissing) {
            return false;
        }

        return (string) $historical->sourceType === (string) $revision->target_type
            && (int) $historical->sourceId === (int) $revision->target_id
            && (int) $historical->ruleId === (int) $revision->id;
    }

    private function preflightBlockReason(CommissionRateRevision $revision, CommissionPeriod $period): ?string
    {
        if ($revision->effective_from->lte($period->start_at)) {
            return 'REVISION_ALREADY_COVERS_PERIOD_START';
        }

        if (! User::query()->whereKey($revision->created_by)->exists()) {
            return 'REVISION_CREATOR_MISSING';
        }

        try {
            CommissionTarget::resolve($revision->target_type, (int) $revision->target_id);
        } catch (Throwable) {
            return 'TARGET_MISSING';
        }

        // Only an EARLIER revision overlapping the proposed extension is a
        // conflict. Later revisions are intentional and must remain untouched.
        $earlierOverlap = CommissionRateRevision::query()
            ->where('target_key', $revision->target_key)
            ->whereKeyNot($revision->id)
            ->where('effective_from', '<', $revision->effective_from)
            ->where(function ($query) use ($period) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>', $period->start_at);
            })
            ->exists();
        if ($earlierOverlap) {
            return 'EARLIER_REVISION_OVERLAP';
        }

        $finalizedConflict = CommissionPeriod::query()
            ->whereKeyNot($period->id)
            ->whereIn('status', [CommissionPeriod::STATUS_CLOSED, CommissionPeriod::STATUS_PAID])
            ->where('start_at', '<', $revision->effective_from)
            ->where('end_at', '>', $period->start_at)
            ->exists();

        return $finalizedConflict ? 'FINALIZED_PERIOD_CONFLICT' : null;
    }

    private function assertPostRepair(CommissionPeriod $period, Category $rootCategory, array $plan): void
    {
        $itemIds = array_keys($plan['expectations']);
        if ($itemIds === []) {
            return;
        }

        $freshResolver = app(CommissionRateResolver::class);
        $freshResolver->warm($period->start_at, $period->end_at);

        $items = InvoiceItem::query()
            ->with(['invoice.preinvoiceOrder', 'product.category', 'variant'])
            ->whereIn('id', $itemIds)
            ->get()
            ->keyBy('id');

        $ledger = CommissionLedgerEntry::query()
            ->where('commission_period_id', $period->id)
            ->where('active_marker', 1)
            ->whereIn('invoice_item_id', $itemIds)
            ->get()
            ->keyBy('invoice_item_id');

        foreach ($plan['expectations'] as $itemId => $expected) {
            $item = $items->get($itemId);
            if (! $item) {
                throw new RuntimeException('Commission repair verification failed: invoice item '.$itemId.' disappeared.');
            }

            $resolved = $freshResolver->resolve($item->product, $item->variant, $item->invoice->display_document_date);
            if ($resolved->isMissing
                || (string) $resolved->sourceType !== $expected['source_type']
                || (int) $resolved->sourceId !== $expected['source_id']
                || (int) $resolved->ruleId !== $expected['rule_id']) {
                throw new RuntimeException('Commission repair verification failed: historical resolver mismatch for invoice item '.$itemId.'.');
            }

            $entry = $ledger->get($itemId);
            if (! $entry
                || $entry->missing_rate
                || (string) $entry->rate_source_type !== $expected['source_type']
                || (int) $entry->rate_source_id !== $expected['source_id']
                || (int) $entry->rate_rule_id !== $expected['rule_id']
                || (string) $entry->base_rate_snapshot !== $expected['percentage']) {
                throw new RuntimeException('Commission repair verification failed: active ledger mismatch for invoice item '.$itemId.'.');
            }
        }

        $categoryIds = Category::selfAndDescendantIds($rootCategory->id);
        $outOfScope = $items->contains(fn (InvoiceItem $item) => ! in_array((int) $item->product->category_id, $categoryIds, true));
        if ($outOfScope) {
            throw new RuntimeException('Commission repair verification failed: an impacted item moved outside the selected category tree.');
        }
    }

    private function unresolvedRow(InvoiceItem $item, string $reason, object $historical, object $desired): array
    {
        return [
            'reason' => $reason,
            'invoice_id' => (int) $item->invoice_id,
            'invoice_item_id' => (int) $item->id,
            'product_id' => (int) $item->product_id,
            'variant_id' => $item->variant_id ? (int) $item->variant_id : null,
            'seller_id' => $item->invoice->effective_seller_id ? (int) $item->invoice->effective_seller_id : null,
            'historical_source' => $historical->isMissing ? 'MISSING' : $historical->sourceType.':'.$historical->sourceId,
            'desired_source' => $desired->isMissing ? 'MISSING' : $desired->sourceType.':'.$desired->sourceId,
        ];
    }

    private function assertMutablePeriod(CommissionPeriod $period): void
    {
        if (! in_array($period->status, [CommissionPeriod::STATUS_OPEN, CommissionPeriod::STATUS_REVIEW], true)) {
            throw ValidationException::withMessages([
                'period' => 'Repair فقط برای دوره باز یا در حال بررسی مجاز است.',
            ]);
        }
    }
}
