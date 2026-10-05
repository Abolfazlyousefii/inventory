<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockThresholdRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StockThresholdService
{
    public const CACHE_KEY = 'stock-threshold-alerts-v1';

    public function alerts(bool $cached = true): Collection
    {
        if (! Schema::hasTable('stock_threshold_rules')) {
            return collect();
        }

        return $cached
            ? collect(Cache::remember(self::CACHE_KEY, 60, fn () => $this->calculate()->all()))
            : $this->calculate();
    }

    private function calculate(): Collection
    {
        $rules = StockThresholdRule::query()->get();
        if ($rules->isEmpty()) {
            return collect();
        }

        $indexed = $rules->keyBy(fn (StockThresholdRule $rule) => "{$rule->target_type}:{$rule->target_id}:{$rule->measure}");
        $categoryRules = $rules->where('target_type', 'category');
        $categories = $categoryRules->isNotEmpty()
            ? Category::query()->get(['id', 'name', 'parent_id'])->keyBy('id')
            : collect();

        $categoryScopeIds = $this->categoryScopeIds($categories, $categoryRules);
        $directProductIds = $rules->where('target_type', 'product')->pluck('target_id')->map(fn ($id) => (int) $id);
        $variantRuleIds = $rules->where('target_type', 'variant')->pluck('target_id')->map(fn ($id) => (int) $id);

        $variantProductIds = $variantRuleIds->isEmpty()
            ? collect()
            : ProductVariant::query()->whereIn('id', $variantRuleIds)->pluck('product_id')->map(fn ($id) => (int) $id);

        $candidateProductIds = $directProductIds
            ->merge($variantProductIds)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $productsQuery = Product::query()->select(['id', 'name', 'code', 'category_id']);
        $productsQuery->where(function ($query) use ($candidateProductIds, $categoryScopeIds) {
            $hasScope = false;

            if ($candidateProductIds !== []) {
                $query->whereIn('id', $candidateProductIds);
                $hasScope = true;
            }

            if ($categoryScopeIds !== []) {
                if ($hasScope) {
                    $query->orWhereIn('category_id', $categoryScopeIds);
                } else {
                    $query->whereIn('category_id', $categoryScopeIds);
                }
                $hasScope = true;
            }

            if (! $hasScope) {
                $query->whereRaw('1 = 0');
            }
        });

        $products = $productsQuery->get();
        if ($products->isEmpty()) {
            return collect();
        }

        if ($categories->isEmpty()) {
            $categories = Category::query()
                ->whereIn('id', $products->pluck('category_id')->filter()->unique())
                ->get(['id', 'name', 'parent_id'])
                ->keyBy('id');
        }

        $productIds = $products->pluck('id')->map(fn ($id) => (int) $id)->all();

        $variants = ProductVariant::query()
            ->whereIn('product_id', $productIds)
            ->get(['id', 'product_id', 'variant_name', 'variety_name', 'variant_code', 'variety_code'])
            ->groupBy('product_id');

        $stock = DB::table('warehouse_stocks as s')
            ->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->where('w.type', 'central')
            ->whereIn('s.product_id', $productIds)
            ->selectRaw('s.product_id, s.product_variant_id, SUM(s.quantity) as quantity')
            ->groupBy('s.product_id', 's.product_variant_id')
            ->get();

        $variantStock = $stock->whereNotNull('product_variant_id')->keyBy('product_variant_id');
        $simpleStock = $stock->whereNull('product_variant_id')->keyBy('product_id');
        $alerts = collect();

        foreach ($products as $product) {
            $family = $variants->get($product->id, collect());
            $available = $family->isEmpty()
                ? max(0, (int) ($simpleStock[$product->id]->quantity ?? 0))
                : $family->sum(fn ($variant) => max(0, (int) ($variantStock[$variant->id]->quantity ?? 0)));

            $rule = $this->resolve($indexed, $categories, $product, null, 'product');
            if ($rule && $available <= $rule->minimum) {
                $alerts->push($this->row($product, null, $rule, $available, $categories));
            }

            foreach ($family as $variant) {
                $rule = $this->resolve($indexed, $categories, $product, $variant, 'variant');
                $available = max(0, (int) ($variantStock[$variant->id]->quantity ?? 0));

                if ($rule && $available <= $rule->minimum) {
                    $alerts->push($this->row($product, $variant, $rule, $available, $categories));
                }
            }
        }

        return $alerts
            ->sortBy([['available', 'asc'], ['shortfall', 'desc'], ['name', 'asc']])
            ->values();
    }

    private function categoryScopeIds(Collection $categories, Collection $rules): array
    {
        if ($categories->isEmpty() || $rules->isEmpty()) {
            return [];
        }

        $children = $categories->groupBy(fn (Category $category) => (int) ($category->parent_id ?? 0));
        $stack = $rules->pluck('target_id')->map(fn ($id) => (int) $id)->filter()->values()->all();
        $seen = [];

        while ($stack !== []) {
            $id = array_pop($stack);

            if (isset($seen[$id]) || ! $categories->has($id)) {
                continue;
            }

            $seen[$id] = true;

            foreach ($children->get($id, collect()) as $child) {
                $stack[] = (int) $child->id;
            }
        }

        return array_map('intval', array_keys($seen));
    }

    private function resolve(Collection $rules, Collection $categories, Product $product, ?ProductVariant $variant, string $measure): ?StockThresholdRule
    {
        if ($variant && ($rule = $rules->get("variant:{$variant->id}:variant"))) {
            return $rule;
        }

        if ($rule = $rules->get("product:{$product->id}:{$measure}")) {
            return $rule;
        }

        $id = $product->category_id;
        $seen = [];

        while ($id && ! isset($seen[$id])) {
            $seen[$id] = true;

            if ($rule = $rules->get("category:{$id}:{$measure}")) {
                return $rule;
            }

            $id = $categories->get($id)?->parent_id;
        }

        return null;
    }

    private function row(Product $product, ?ProductVariant $variant, StockThresholdRule $rule, int $available, Collection $categories): array
    {
        return [
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'name' => $product->name,
            'variant_name' => $variant?->variant_name ?: $variant?->variety_name,
            'code' => $variant ? ($variant->variant_code ?: $variant->variety_code) : $product->code,
            'category_id' => $product->category_id,
            'category' => $categories->get($product->category_id)?->name,
            'available' => $available,
            'minimum' => $rule->minimum,
            'shortfall' => max(0, $rule->minimum - $available),
            'rule_id' => $rule->id,
        ];
    }
}
