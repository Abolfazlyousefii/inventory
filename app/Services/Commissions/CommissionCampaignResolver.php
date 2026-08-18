<?php

namespace App\Services\Commissions;

use App\Data\CommissionCampaignResult;
use App\Models\Category;
use App\Models\CommissionCampaign;
use App\Models\Product;
use App\Models\ProductVariant;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class CommissionCampaignResolver
{
    private ?Collection $warmedCampaigns = null;

    private ?Collection $warmedCategories = null;

    public function warm(CarbonInterface|string $start, CarbonInterface|string $end): void
    {
        $this->warmedCampaigns = CommissionCampaign::query()->with('targets')->where('start_at', '<', $end)
            ->where('end_at', '>', $start)->orderByDesc('id')->get();
        $this->warmedCategories = Category::query()->get(['id', 'parent_id'])->keyBy('id');
    }

    public function resolve(Product $product, ?ProductVariant $variant = null, CarbonInterface|string|null $at = null): ?CommissionCampaignResult
    {
        if ($variant && (int) $variant->product_id !== (int) $product->id) {
            throw new \InvalidArgumentException('Variant does not belong to the supplied product.');
        }
        $at = $at ? Carbon::parse($at) : now();
        $campaign = $this->campaignAt($at);
        if (! $campaign) {
            return null;
        }

        $categoryIds = $product->category_id ? $this->ancestorIds((int) $product->category_id) : [];
        $matches = $campaign->targets->filter(fn ($target) => match ($target->target_type) {
            'category' => in_array((int) $target->target_id, $categoryIds, true),
            'product' => (int) $target->target_id === (int) $product->id,
            'variant' => $variant && (int) $target->target_id === (int) $variant->id,
            default => false,
        })->values();
        if ($matches->isEmpty()) {
            return null;
        }
        $first = $matches->first();

        return new CommissionCampaignResult($campaign->id, $campaign->bonus_percentage, $first->target_type, (int) $first->target_id, $matches->map(fn ($target) => ['type' => $target->target_type, 'id' => (int) $target->target_id])->all(), $campaign->name);
    }

    private function ancestorIds(int $categoryId): array
    {
        $ids = [];
        $visited = [];
        while ($categoryId > 0 && ! isset($visited[$categoryId])) {
            $visited[$categoryId] = true;
            $ids[] = $categoryId;
            $categoryId = $this->warmedCategories
                ? (int) ($this->warmedCategories->get($categoryId)?->parent_id ?? 0)
                : (int) (Category::query()->find($categoryId)?->parent_id ?? 0);
        }

        return $ids;
    }

    private function campaignAt(CarbonInterface $at): ?CommissionCampaign
    {
        if ($this->warmedCampaigns) {
            return $this->warmedCampaigns->filter(fn ($campaign) => $campaign->start_at->lte($at) && $campaign->end_at->gt($at) && (! $campaign->archived_at || $campaign->archived_at->gt($at)))
                ->sortBy(fn ($campaign) => [$campaign->archived_at ? 1 : 0, -$campaign->id])->first();
        }

        return CommissionCampaign::query()->with('targets')
            ->where(fn ($query) => $query->whereNull('archived_at')->orWhere('archived_at', '>', $at))
            ->where('start_at', '<=', $at)->where('end_at', '>', $at)
            ->orderByRaw('CASE WHEN archived_at IS NULL THEN 0 ELSE 1 END')->latest('id')->first();
    }
}
