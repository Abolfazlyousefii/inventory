<?php

namespace App\Services\Commissions;

use App\Data\CommissionRateResult;
use App\Models\Category;
use App\Models\CommissionRateRevision;
use App\Models\Product;
use App\Models\ProductVariant;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class CommissionRateResolver
{
    private ?Collection $warmedRules = null;

    private ?Collection $warmedCategories = null;

    public function warm(CarbonInterface|string $start, CarbonInterface|string $end): void
    {
        $this->warmedRules = CommissionRateRevision::query()->where('effective_from', '<', $end)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $start))
            ->orderByDesc('effective_from')->orderByDesc('id')->get()->groupBy('target_key');
        $this->warmedCategories = Category::query()->get(['id', 'parent_id'])->keyBy('id');
    }

    public function resolve(Product $product, ?ProductVariant $variant = null, CarbonInterface|string|null $at = null): CommissionRateResult
    {
        if ($variant && (int) $variant->product_id !== (int) $product->id) {
            throw new \InvalidArgumentException('Variant does not belong to the supplied product.');
        }
        $at = $at ? Carbon::parse($at) : now();
        $targets = [];
        if ($variant) {
            $targets[] = ['variant', $variant->id];
        }
        $targets[] = ['product', $product->id];

        $categoryId = (int) $product->category_id;
        $visited = [];
        while ($categoryId > 0 && ! isset($visited[$categoryId])) {
            $visited[$categoryId] = true;
            $targets[] = ['category', $categoryId];
            $categoryId = $this->warmedCategories
                ? (int) ($this->warmedCategories->get($categoryId)?->parent_id ?? 0)
                : (int) (Category::query()->find($categoryId)?->parent_id ?? 0);
        }

        foreach ($targets as [$type, $id]) {
            $rule = $this->ruleAt(CommissionTarget::key($type, $id), $at);
            if ($rule) {
                return new CommissionRateResult(
                    percentage: $rule->percentage,
                    sourceType: $type,
                    sourceId: $id,
                    ruleId: $rule->id,
                    isExplicitZero: (float) $rule->percentage === 0.0,
                    isMissing: false,
                );
            }
        }

        return new CommissionRateResult('0.0000', null, null, null, false, true);
    }

    private function ruleAt(string $targetKey, CarbonInterface $at): ?CommissionRateRevision
    {
        if ($this->warmedRules) {
            return $this->warmedRules->get($targetKey, collect())->first(fn ($rule) => $rule->effective_from->lte($at) && (! $rule->effective_to || $rule->effective_to->gt($at)));
        }

        return CommissionRateRevision::query()->where('target_key', $targetKey)
            ->where('effective_from', '<=', $at)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $at))
            ->latest('effective_from')->latest('id')->first();
    }

    public function resolveCategory(Category $category, CarbonInterface|string|null $at = null): CommissionRateResult
    {
        $at = $at ? Carbon::parse($at) : now();
        for ($cursor = $category; $cursor; $cursor = $cursor->parent) {
            $rule = CommissionRateRevision::query()
                ->where('target_key', CommissionTarget::key('category', $cursor->id))
                ->where('effective_from', '<=', $at)
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $at))
                ->latest('effective_from')->latest('id')->first();
            if ($rule) {
                return new CommissionRateResult($rule->percentage, 'category', $cursor->id, $rule->id, (float) $rule->percentage === 0.0, false);
            }
        }

        return new CommissionRateResult('0.0000', null, null, null, false, true);
    }
}
