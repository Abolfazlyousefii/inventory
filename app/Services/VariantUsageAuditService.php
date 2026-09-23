<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\DB;

class VariantUsageAuditService
{
    public function __construct(
        private readonly VariantReferenceDiscoveryService $references,
        private readonly ReservationQueryService $reservations,
    ) {}

    /** @return array<string,mixed> */
    public function audit(ProductVariant $variant): array
    {
        $variantId = (int) $variant->id;
        $activityReferences = ActivityLog::query()
            ->whereNotIn('action', ['created', 'electric_default_color_created'])
            ->where(function ($query) use ($variantId): void {
                $query->where(function ($subject) use ($variantId): void {
                    $subject->where('subject_type', ProductVariant::class)
                        ->where('subject_id', $variantId);
                })->orWhereJsonContains('properties->variant_id', $variantId);
            })
            ->count();

        return $this->auditWithActivityReferenceCount($variant, (int) $activityReferences);
    }

    /** @return array<string,mixed> */
    public function auditWithEvidence(
        ProductVariant $variant,
        SyntheticDefaultVariantEvidenceSnapshot $evidence,
    ): array {
        return $this->auditWithActivityReferenceCount(
            $variant,
            $evidence->activityReferenceCount((int) $variant->id),
        );
    }

    /** @return array<string,mixed> */
    private function auditWithActivityReferenceCount(ProductVariant $variant, int $activityReferences): array
    {
        $variant->loadMissing('product');
        $variantId = (int) $variant->id;
        $warehouseStock = (int) WarehouseStock::query()
            ->where('product_variant_id', $variantId)
            ->sum('quantity');
        $hasNonzeroWarehouseRow = WarehouseStock::query()
            ->where('product_variant_id', $variantId)
            ->where('quantity', '<>', 0)
            ->exists();
        $reserved = (int) $this->reservations
            ->quantitiesByVariant((int) $variant->product_id, [$variantId])
            ->get($variantId, 0);
        $referenceCounts = [];
        $unknownReferences = [];

        foreach ($this->references->discover() as $reference) {
            $key = $reference['table'].'.'.$reference['column'];
            $count = (int) DB::table($reference['table'])
                ->where($reference['column'], $variantId)
                ->count();
            $referenceCounts[$key] = $count;
            if (! $reference['known'] && $count > 0) {
                $unknownReferences[] = $key;
            }
        }

        $counts = [
            'purchase_refs' => $this->countGroup($referenceCounts, ['purchase_items.product_variant_id']),
            'invoice_refs' => $this->countGroup($referenceCounts, ['invoice_items.variant_id', 'invoice_collection_revision_items.product_variant_id']),
            'preinvoice_refs' => $this->countGroup($referenceCounts, ['preinvoice_order_items.variant_id']),
            'reservation_refs' => $this->countGroup($referenceCounts, ['preinvoice_draft_reservations.variant_id']),
            'stock_movement_refs' => $this->countGroup($referenceCounts, ['stock_movements.product_variant_id']),
        ];

        $coreReferenceKeys = [
            'warehouse_stocks.product_variant_id',
            'purchase_items.product_variant_id',
            'invoice_items.variant_id',
            'invoice_collection_revision_items.product_variant_id',
            'preinvoice_order_items.variant_id',
            'preinvoice_draft_reservations.variant_id',
            'stock_movements.product_variant_id',
        ];
        $otherReferences = collect($referenceCounts)
            ->filter(fn (int $count, string $key) => $count > 0 && ! in_array($key, $coreReferenceKeys, true))
            ->keys()
            ->merge($unknownReferences)
            ->unique()
            ->values()
            ->all();

        $blockingReasons = [];
        if ($hasNonzeroWarehouseRow) {
            $blockingReasons[] = 'nonzero_warehouse_stock';
        }
        if ((int) $variant->stock !== 0) {
            $blockingReasons[] = 'nonzero_cached_stock';
        }
        if ($reserved !== 0) {
            $blockingReasons[] = 'canonical_reservations';
        }
        if ((int) $variant->reserved !== 0 || (int) $variant->product->reserved !== 0) {
            $blockingReasons[] = 'nonzero_cached_reserved';
        }
        if ($variant->variety_id !== null) {
            $blockingReasons[] = 'external_mapping';
        }
        if ($activityReferences > 0) {
            $blockingReasons[] = 'audit_or_business_evidence';
        }
        foreach ($counts as $key => $count) {
            if ($count > 0) {
                $blockingReasons[] = match ($key) {
                    'purchase_refs' => 'purchase_references',
                    'invoice_refs' => 'invoice_references',
                    'preinvoice_refs' => 'preinvoice_references',
                    'reservation_refs' => 'reservation_references',
                    default => 'stock_movement_references',
                };
            }
        }

        foreach ($referenceCounts as $key => $count) {
            if ($count > 0 && ! in_array($key, $coreReferenceKeys, true)) {
                $blockingReasons[] = 'business_reference:'.$key;
            }
        }
        foreach ($unknownReferences as $key) {
            $blockingReasons[] = 'unknown_reference:'.$key;
        }
        $blockingReasons = array_values(array_unique($blockingReasons));

        return array_merge($counts, [
            'warehouse_stock' => $warehouseStock,
            'reserved' => $reserved,
            'cached_stock' => (int) $variant->stock,
            'cached_reserved' => (int) $variant->reserved,
            'site_mapping' => $variant->variety_id,
            'activity_refs' => (int) $activityReferences,
            'reference_counts' => $referenceCounts,
            'other_reference_tables' => $otherReferences,
            'blocking_reasons' => $blockingReasons,
            'safe_to_remove' => $blockingReasons === [],
        ]);
    }

    /** @param array<string,int> $counts */
    private function countGroup(array $counts, array $keys): int
    {
        return array_sum(array_map(fn (string $key): int => (int) ($counts[$key] ?? 0), $keys));
    }
}
