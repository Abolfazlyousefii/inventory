<?php

namespace App\Services\Commissions;

use App\Models\Category;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPeriod;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Carbon\Carbon;

class CommissionRateAuditService
{
    public function __construct(private readonly CommissionRateResolver $resolver) {}

    public function audit(CommissionPeriod $period, Category $category, ?int $sellerId = null): array
    {
        $rangeStart = Carbon::parse($period->start_at)->min(now());
        $rangeEnd = Carbon::parse($period->end_at)->max(now()->addSecond());
        $this->resolver->warm($rangeStart, $rangeEnd);
        $categoryIds = Category::selfAndDescendantIds($category->id);
        $categories = Category::query()->get(['id', 'name', 'parent_id'])->keyBy('id');
        $products = Product::query()->with('variants')->whereIn('category_id', $categoryIds)->orderBy('id')->get();
        $items = InvoiceItem::query()->with(['invoice.preinvoiceOrder', 'product', 'variant'])
            ->whereIn('product_id', $products->pluck('id'))
            ->whereHas('invoice', fn ($query) => $query
                ->whereRaw('COALESCE(document_date, created_at) >= ?', [$period->start_at])
                ->whereRaw('COALESCE(document_date, created_at) < ?', [$period->end_at]))
            ->get()
            ->when($sellerId, fn ($collection) => $collection->filter(fn ($item) => (int) $item->invoice->effective_seller_id === $sellerId))
            ->groupBy('product_id');
        $missingCounts = CommissionLedgerEntry::query()
            ->where('commission_period_id', $period->id)->where('active_marker', 1)->where('missing_rate', true)
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))
            ->get()->groupBy(fn ($entry) => ((int) $entry->product_id).':'.((int) ($entry->product_variant_id ?? 0)));

        $rows = [];
        foreach ($products as $product) {
            $productItems = $items->get($product->id, collect());
            $variants = collect([null])->concat($product->variants);
            foreach ($variants as $variant) {
                $matchingItems = $variant
                    ? $productItems->where('variant_id', $variant->id)
                    : $productItems->whereNull('variant_id');
                $current = $this->resolver->resolve($product, $variant, now());
                $historical = $matchingItems->map(function ($item) use ($product, $variant) {
                    $at = $item->invoice->display_document_date;
                    $result = $this->resolver->resolve($product, $variant, $at);

                    return ['invoice_id' => $item->invoice_id, 'invoice_item_id' => $item->id, 'at' => $at->toDateTimeString(), 'rate' => $result->percentage, 'source_type' => $result->sourceType, 'source_id' => $result->sourceId, 'missing' => $result->isMissing];
                })->values();
                $classification = $this->classification($product, $variant, $current, $historical);
                $rows[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'category_id' => $product->category_id,
                    'category_path' => $this->categoryPath((int) $product->category_id, $categories),
                    'variant_id' => $variant?->id,
                    'current_effective_rate' => $current->isMissing ? null : $current->percentage,
                    'current_source' => $current->sourceType ? $current->sourceType.':'.$current->sourceId : null,
                    'rates_at_invoice_dates' => $historical->all(),
                    'invoice_ids' => $historical->pluck('invoice_id')->unique()->values()->all(),
                    'missing_rate_ledger_count' => $missingCounts->get($product->id.':'.((int) ($variant?->id ?? 0)), collect())->count(),
                    'classification' => $classification,
                ];
            }
        }

        $brokenReferences = CommissionLedgerEntry::query()->where('commission_period_id', $period->id)
            ->where('active_marker', 1)->where('missing_rate', true)->whereNull('product_id')
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))->count();

        $countKeys = ['NO_CATEGORY', 'NO_RATE_AT_INVOICE_DATE', 'CURRENT_RATE_EXISTS_BUT_STARTED_LATE', 'DIRECT_ZERO_RATE', 'PRODUCT_OVERRIDE', 'VARIANT_OVERRIDE', 'CATEGORY_INHERITED', 'MISSING_PRODUCT_REFERENCE', 'OTHER'];
        $counts = collect($countKeys)->mapWithKeys(fn ($key) => [$key => 0]);
        collect($rows)->countBy('classification')->each(fn ($count, $key) => $counts[$key] = $count);
        $counts['MISSING_PRODUCT_REFERENCE'] = $brokenReferences;

        return [
            'period_id' => $period->id,
            'category_id' => $category->id,
            'seller_id' => $sellerId,
            'counts' => $counts->all(),
            'rows' => $rows,
        ];
    }

    private function classification(Product $product, ?ProductVariant $variant, $current, $historical): string
    {
        if (! $product->category_id) return 'NO_CATEGORY';
        if ($historical->contains('missing', true) && ! $current->isMissing) return 'CURRENT_RATE_EXISTS_BUT_STARTED_LATE';
        if ($current->isMissing) return 'NO_RATE_AT_INVOICE_DATE';
        if ($current->isExplicitZero && in_array($current->sourceType, ['product', 'variant'], true)) return 'DIRECT_ZERO_RATE';
        if ($variant && $current->sourceType === 'variant') return 'VARIANT_OVERRIDE';
        if ($current->sourceType === 'product') return 'PRODUCT_OVERRIDE';
        if ($current->sourceType === 'category') return 'CATEGORY_INHERITED';

        return 'OTHER';
    }

    private function categoryPath(int $categoryId, $categories): string
    {
        $parts = [];
        $visited = [];
        while (($category = $categories->get($categoryId)) && ! isset($visited[$categoryId])) {
            $visited[$categoryId] = true;
            array_unshift($parts, $category->name);
            $categoryId = (int) ($category->parent_id ?? 0);
        }

        return implode(' > ', $parts);
    }
}
