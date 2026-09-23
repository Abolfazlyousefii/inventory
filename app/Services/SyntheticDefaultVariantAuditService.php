<?php

namespace App\Services;

use App\Models\ProductVariant;
use Closure;
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
    public function rows(?int $productId = null, ?int $variantId = null, ?Closure $progress = null): Collection
    {
        $rows = collect();
        $variantProducts = [];
        $scopeComplete = ProductVariant::query()
            ->select(['id', 'product_id'])
            ->when($productId !== null, fn ($query) => $query->where('product_id', $productId))
            ->when($variantId !== null, fn ($query) => $query->whereKey($variantId))
            ->orderBy('id')
            ->chunkById(1000, function ($variants) use (&$variantProducts): void {
                foreach ($variants as $variant) {
                    $variantProducts[(int) $variant->id] = (int) $variant->product_id;
                }
            });
        if (! $scopeComplete) {
            throw new \RuntimeException('ProductVariant audit scope scan did not complete.');
        }
        $total = count($variantProducts);
        $progress?->__invoke('Scanning activity evidence...', ['scanned' => 0, 'total' => $total, 'synthetic' => 0]);
        $evidence = $this->evidence->loadScope($variantProducts);
        $progress?->__invoke('Classifying variants...', ['scanned' => 0, 'total' => $total, 'synthetic' => 0]);
        $scanned = 0;
        $synthetic = 0;
        $processedVariantProducts = [];

        $variantScanComplete = ProductVariant::query()
            ->with('product.category.parent')
            ->when($productId !== null, fn ($query) => $query->where('product_id', $productId))
            ->when($variantId !== null, fn ($query) => $query->whereKey($variantId))
            ->orderBy('id')
            ->chunkById(200, function ($variants) use ($rows, $evidence, $progress, $total, &$scanned, &$synthetic, &$processedVariantProducts, $variantProducts): void {
                $candidates = collect();
                $classifications = [];
                foreach ($variants as $variant) {
                    $expectedProductId = $variantProducts[(int) $variant->id] ?? null;
                    if ($expectedProductId !== (int) $variant->product_id) {
                        throw new \RuntimeException('ProductVariant audit scope changed during the audit.');
                    }
                    $processedVariantProducts[(int) $variant->id] = (int) $variant->product_id;
                    $classification = $this->classifier->classifyWithEvidence($variant, $evidence);
                    if ($classification['class'] === SyntheticDefaultVariantClassifier::NOT_SYNTHETIC) {
                        continue;
                    }
                    $candidates->push($variant);
                    $classifications[(int) $variant->id] = $classification;
                }
                $synthetic += $candidates->count();
                $progress?->__invoke('Auditing usage...', [
                    'scanned' => $scanned,
                    'total' => $total,
                    'synthetic' => $synthetic,
                ]);
                $usageByVariant = $this->usage->auditManyWithEvidence($candidates, $evidence);
                foreach ($candidates as $variant) {
                    $classification = $classifications[(int) $variant->id];
                    $usage = $usageByVariant->get((int) $variant->id);
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
                $scanned += $variants->count();
                $progress?->__invoke('progress', [
                    'scanned' => $scanned,
                    'total' => $total,
                    'synthetic' => $synthetic,
                ]);
            });
        if (! $variantScanComplete || $processedVariantProducts !== $variantProducts) {
            throw new \RuntimeException('ProductVariant audit scope changed during the audit.');
        }

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
