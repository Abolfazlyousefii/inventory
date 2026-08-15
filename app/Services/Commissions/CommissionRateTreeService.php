<?php

namespace App\Services\Commissions;

use App\Models\Category;
use App\Models\CommissionRateRevision;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

class CommissionRateTreeService
{
    public function roots(): array
    {
        return $this->serializeCategories(Category::query()->whereNull('parent_id')->orderBy('name')->get(['id', 'name', 'parent_id']));
    }

    public function children(string $type, int $id, string $search = '', int $page = 1): array
    {
        if ($type === 'product') {
            $product = Product::query()->findOrFail($id);
            $variants = $product->variants()->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested->where('variant_name', 'like', "%{$search}%")->orWhere('variant_code', 'like', "%{$search}%")))->orderBy('variant_name')->paginate(30, ['id', 'product_id', 'variant_name', 'variant_code'], 'page', $page);

            return ['items' => $this->serializeVariants($variants->getCollection(), $product), 'has_more' => $variants->hasMorePages()];
        }

        $category = Category::query()->findOrFail($id);
        $categories = $category->children()->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))->get(['id', 'name', 'parent_id']);
        $products = $category->products()->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))->orderBy('name')->paginate(30, ['id', 'name', 'sku', 'code', 'category_id'], 'page', $page);

        return ['items' => [...$this->serializeCategories($categories), ...$this->serializeProducts($products->getCollection(), $category)], 'has_more' => $products->hasMorePages()];
    }

    public function search(string $search): array
    {
        $search = trim($search);
        if (mb_strlen($search) < 2) {
            return ['items' => [], 'has_more' => false];
        }

        $rules = $this->activeRules();
        $allCategories = Category::query()->get(['id', 'parent_id'])->keyBy('id');
        $categories = Category::query()->where('name', 'like', "%{$search}%")
            ->orderBy('name')->limit(15)->get(['id', 'name', 'parent_id']);
        $products = Product::query()->where(fn ($query) => $query
            ->where('name', 'like', "%{$search}%")
            ->orWhere('sku', 'like', "%{$search}%")
            ->orWhere('code', 'like', "%{$search}%"))
            ->orderBy('name')->limit(15)->get(['id', 'name', 'category_id']);
        $variants = ProductVariant::query()->with('product:id,category_id')
            ->where(fn ($query) => $query
                ->where('variant_name', 'like', "%{$search}%")
                ->orWhere('variant_code', 'like', "%{$search}%"))
            ->orderBy('variant_name')->limit(15)->get(['id', 'product_id', 'variant_name', 'variant_code']);

        $categoryNodes = $categories->map(function (Category $category) use ($rules, $allCategories) {
            $result = $this->categoryResult($category->id, $rules, $allCategories);
            $inherited = $this->categoryResult((int) ($allCategories->get($category->id)?->parent_id ?? 0), $rules, $allCategories);

            return $this->node('category', $category->id, $category->name, $result, $inherited, true);
        });
        $productNodes = $products->map(function (Product $product) use ($rules, $allCategories) {
            $inherited = $this->categoryResult((int) $product->category_id, $rules, $allCategories);
            $result = $rules->get("product:{$product->id}") ?: $inherited;

            return $this->node('product', $product->id, $product->name, $result, $inherited, true);
        });
        $variantNodes = $variants->map(function (ProductVariant $variant) use ($rules, $allCategories) {
            $product = $variant->product;
            $inherited = $product
                ? $rules->get("product:{$product->id}") ?: $this->categoryResult((int) $product->category_id, $rules, $allCategories)
                : null;
            $result = $rules->get("variant:{$variant->id}") ?: $inherited;

            return $this->node('variant', $variant->id, $variant->variant_name ?: $variant->variant_code ?: 'تنوع اصلی', $result, $inherited, false);
        });

        return [
            'items' => $categoryNodes->concat($productNodes)->concat($variantNodes)->values()->all(),
            'has_more' => false,
        ];
    }

    private function serializeCategories(Collection $categories): array
    {
        $rules = $this->activeRules();
        $allCategories = Category::query()->get(['id', 'parent_id'])->keyBy('id');

        return $categories->map(function (Category $category) use ($rules, $allCategories) {
            $result = $this->categoryResult($category->id, $rules, $allCategories);
            $inherited = $this->categoryResult((int) ($allCategories->get($category->id)?->parent_id ?? 0), $rules, $allCategories);

            return $this->node('category', $category->id, $category->name, $result, $inherited, true);
        })->all();
    }

    private function serializeProducts(Collection $products, Category $category): array
    {
        $rules = $this->activeRules();
        $allCategories = Category::query()->get(['id', 'parent_id'])->keyBy('id');

        return $products->map(function (Product $product) use ($category, $rules, $allCategories) {
            $inherited = $this->categoryResult($category->id, $rules, $allCategories);
            $result = $rules->get("product:{$product->id}") ?: $inherited;

            return $this->node('product', $product->id, $product->name, $result, $inherited, true);
        })->all();
    }

    private function serializeVariants(Collection $variants, Product $product): array
    {
        $rules = $this->activeRules();
        $allCategories = Category::query()->get(['id', 'parent_id'])->keyBy('id');
        $fallback = $rules->get("product:{$product->id}") ?: $this->categoryResult((int) $product->category_id, $rules, $allCategories);

        return $variants->map(function (ProductVariant $variant) use ($rules, $fallback) {
            $result = $rules->get("variant:{$variant->id}") ?: $fallback;

            return $this->node('variant', $variant->id, $variant->variant_name ?: $variant->variant_code ?: 'تنوع اصلی', $result, $fallback, false);
        })->all();
    }

    private function activeRules(): Collection
    {
        return CommissionRateRevision::query()->where('active_marker', 1)->get()->keyBy('target_key');
    }

    private function categoryResult(int $categoryId, Collection $rules, Collection $categories): ?CommissionRateRevision
    {
        $visited = [];
        while ($categoryId > 0 && ! isset($visited[$categoryId])) {
            $visited[$categoryId] = true;
            if ($rule = $rules->get("category:{$categoryId}")) {
                return $rule;
            }
            $categoryId = (int) ($categories->get($categoryId)?->parent_id ?? 0);
        }

        return null;
    }

    private function node(string $type, int $id, string $label, ?CommissionRateRevision $rule, ?CommissionRateRevision $inheritedRule, bool $hasChildren): array
    {
        $own = $rule && $rule->target_type === $type && (int) $rule->target_id === $id;

        return [
            'type' => $type, 'id' => $id, 'label' => $label, 'has_children' => $hasChildren,
            'percentage' => $rule?->percentage ?? '0.0000', 'own_rate' => $own ? $rule->percentage : null,
            'inherited_rate' => $inheritedRule?->percentage,
            'source_type' => $rule?->target_type, 'source_id' => $rule?->target_id,
            'source_label' => match ($rule?->target_type) {
                'category' => $own ? 'همین دسته' : 'دسته بالادست',
                'product' => $own ? 'همین کالا' : 'کالای والد',
                'variant' => 'همین تنوع',
                default => 'تعیین نشده',
            },
            'is_missing' => $rule === null, 'is_explicit_zero' => $rule && (float) $rule->percentage === 0.0,
        ];
    }
}
