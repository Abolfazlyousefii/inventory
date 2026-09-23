<?php

namespace App\Services;

use App\Models\ProductVariant;
use Illuminate\Support\Collection;

class SyntheticDefaultVariantAuditService
{
    public function __construct(
        private readonly SyntheticDefaultVariantClassifier $classifier,
        private readonly VariantUsageAuditService $usage,
        private readonly CanonicalBaseVariantService $canonicalBaseVariants,
        private readonly SyntheticDefaultVariantEvidenceService $evidence,
    ) {}

    /** @return Collection<int,array<string,mixed>> */
    public function rows(?int $productId = null, ?int $variantId = null): Collection
    {
        $rows = collect();
        ProductVariant::query()
            ->with('product.category.parent')
            ->when($productId, fn ($query) => $query->where('product_id', $productId))
            ->when($variantId, fn ($query) => $query->whereKey($variantId))
            ->orderBy('id')
            ->chunkById(200, function ($variants) use ($rows): void {
                $evidence = $this->evidence->load($variants);
                foreach ($variants as $variant) {
                    $classification = $this->classifier->classifyWithEvidence($variant, $evidence);
                    if ($classification['class'] === SyntheticDefaultVariantClassifier::NOT_SYNTHETIC) {
                        continue;
                    }
                    $usage = $this->usage->auditWithEvidence($variant, $evidence);
                    $rows->push([
                        'product_id' => (int) $variant->product_id,
                        'product_name' => (string) $variant->product->name,
                        'variant_id' => (int) $variant->id,
                        'variant_name' => (string) $variant->variant_name,
                        'variant_code' => (string) $variant->variant_code,
                        'synthetic_class' => $classification['class'],
                        'synthetic_evidence' => implode(',', $classification['reasons']),
                        'warehouse_stock' => $usage['warehouse_stock'],
                        'reserved' => $usage['reserved'],
                        'purchase_refs' => $usage['purchase_refs'],
                        'invoice_refs' => $usage['invoice_refs'],
                        'preinvoice_refs' => $usage['preinvoice_refs'],
                        'reservation_refs' => $usage['reservation_refs'],
                        'stock_movement_refs' => $usage['stock_movement_refs'],
                        'other_reference_tables' => implode(',', $usage['other_reference_tables']),
                        'site_mapping' => $usage['site_mapping'] ?? '',
                        'safe_to_remove' => $usage['safe_to_remove'],
                        'blocking_reasons' => implode(',', $usage['blocking_reasons']),
                        '_is_active' => (bool) $variant->is_active,
                        '_sales_enabled' => (bool) $variant->sales_enabled,
                        '_product_sellable' => (bool) $variant->product->is_sellable,
                        '_sell_price' => (int) $variant->sell_price,
                    ]);
                }
            });

        return $rows;
    }

    /** @param Collection<int,array<string,mixed>> $rows @return array<string,int> */
    public function summary(Collection $rows): array
    {
        $missingBase = $this->canonicalBaseVariants
            ->missingBaseProductIds($rows->pluck('product_id'))
            ->count();

        return [
            'proven' => $rows->where('synthetic_class', SyntheticDefaultVariantClassifier::PROVEN_SYNTHETIC)->count(),
            'probable' => $rows->where('synthetic_class', SyntheticDefaultVariantClassifier::PROBABLE_SYNTHETIC)->count(),
            'safe' => $rows->where('safe_to_remove', true)->count(),
            'history_protected' => $rows->filter(fn (array $row) => ! $row['safe_to_remove']
                && ((int) $row['purchase_refs'] + (int) $row['invoice_refs'] + (int) $row['preinvoice_refs'] + (int) $row['stock_movement_refs']) > 0)->count(),
            'stock_protected' => $rows->filter(fn (array $row) => str_contains((string) $row['blocking_reasons'], 'stock'))->count(),
            'reservation_protected' => $rows->filter(fn (array $row) => str_contains((string) $row['blocking_reasons'], 'reserv'))->count(),
            'other_reference_protected' => $rows->filter(fn (array $row) => (string) $row['other_reference_tables'] !== '')->count(),
            'missing_base' => $missingBase,
            'zero_price_risk' => $rows->filter(fn (array $row) => $row['_is_active'] && $row['_sales_enabled']
                && (int) $row['_sell_price'] <= 0)->count(),
            'unexplained_inactive' => $rows->filter(fn (array $row) => ! $row['_is_active']
                && $row['synthetic_class'] !== SyntheticDefaultVariantClassifier::PROVEN_SYNTHETIC)->count(),
            'sellable_zero_price_positive_stock' => $rows->filter(fn (array $row) => $row['_product_sellable']
                && $row['_is_active'] && $row['_sales_enabled'] && (int) $row['_sell_price'] <= 0
                && (int) $row['warehouse_stock'] > 0)->count(),
        ];
    }
}
