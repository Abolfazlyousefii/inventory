<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarehouseInboundReceiptItem;
use App\Models\WarehouseStock;
use App\Services\Sync\SiteStockChangeMarker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Reclassify one already-received, physically verified finished bundle into
 * protected components for automatic-bundle sales. This is NOT stock creation:
 * -1 finished SKU, +the current recipe in bundle allocations, ordinary stock unchanged.
 *
 * Never infer that a historically returned pack contains the current recipe.
 */
final class ReturnedAutomaticBundleRecoveryService
{
    public function preview(int $bundleVariantId, int $sourceMovementId): array
    {
        return $this->inspect($bundleVariantId, $sourceMovementId, false);
    }

    public function apply(
        int $bundleVariantId,
        int $sourceMovementId,
        string $confirmedRecipeHash,
        bool $physicallyVerified,
        int $operatorUserId,
    ): array {
        if ($operatorUserId < 1 || ! User::query()->whereKey($operatorUserId)->exists()) {
            throw new InvalidArgumentException('Supply a valid operator user ID for the audit trail.');
        }
        if (! $physicallyVerified) {
            throw new InvalidArgumentException('A physical count of every component in the returned pack is required.');
        }
        if (! preg_match('/^[0-9a-f]{64}$/', $confirmedRecipeHash)) {
            throw new InvalidArgumentException('Supply the exact SHA-256 recipe hash displayed by the preview.');
        }

        return DB::transaction(function () use ($bundleVariantId, $sourceMovementId, $confirmedRecipeHash, $operatorUserId): array {
            $variant = ProductVariant::query()->findOrFail($bundleVariantId);
            // Same product-level lock as allocation editing and automatic checkout.
            Product::query()->whereKey($variant->product_id)->lockForUpdate()->firstOrFail();

            $plan = $this->inspect($bundleVariantId, $sourceMovementId, true);
            if (! hash_equals($plan['recipe_hash'], $confirmedRecipeHash)) {
                throw new InvalidArgumentException('Recipe changed since preview; recount the physical pack before retrying.');
            }

            if ((int) app(ReservationQueryService::class)
                ->quantitiesByVariant(variantIds: [$bundleVariantId])->get($bundleVariantId, 0) > 0) {
                throw new InvalidArgumentException('Active preinvoice reservations exist for this bundle.');
            }
            if (Schema::hasTable('site_stock_holds')
                && DB::table('site_stock_holds')
                    ->whereIn('status', ['held', 'payment_confirmed'])
                    ->whereRaw('JSON_CONTAINS_PATH(bundle_allocated_lines, \'one\', ?) = 1', ['$."' . $bundleVariantId . '"'])
                    ->exists()) {
                throw new InvalidArgumentException('Active Site holds exist for this bundle.');
            }

            $freeze = app(InventoryCountFreezeService::class);
            $freeze->assertProduct($plan['product_id'], $plan['warehouse_id']);
            foreach ($plan['component_product_ids'] as $productId) {
                $freeze->assertProduct($productId, $plan['warehouse_id']);
            }

            // Account for the SAME physical packed units exactly once.
            // Do not call setAllocations(): it would incorrectly subtract ordinary stock.
            WarehouseStockService::change($plan['warehouse_id'], $plan['product_id'], -1, $bundleVariantId);
            foreach ($plan['recipe'] as $componentId => $quantity) {
                $existing = DB::table('bundle_component_allocations')
                    ->where('bundle_variant_id', $bundleVariantId)
                    ->where('component_variant_id', $componentId)
                    ->first(['id']);
                if ($existing) {
                    DB::table('bundle_component_allocations')->where('id', $existing->id)
                        ->update(['remaining' => $quantity, 'updated_at' => now()]);
                } else {
                    DB::table('bundle_component_allocations')->insert([
                        'bundle_variant_id' => $bundleVariantId,
                        'component_variant_id' => $componentId,
                        'remaining' => $quantity,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            SiteStockChangeMarker::mark($plan['product_id'], $bundleVariantId);

            StockMovement::create([
                'product_id' => $plan['product_id'],
                'product_variant_id' => $bundleVariantId,
                'warehouse_id' => $plan['warehouse_id'],
                'user_id' => $operatorUserId,
                'type' => StockMovement::TYPE_OUT,
                'reason' => 'bundle_return_reclassified',
                'transaction_type' => 'bundle_return_reclassification',
                'quantity' => 1, 'stock_before' => 1, 'stock_after' => 0,
                'reference' => $plan['source_reference'],
                'reference_type' => StockMovement::class,
                'reference_id' => $sourceMovementId,
                'note' => 'Reclassified verified finished returned pack into automatic allocation, '
                    .'without touching ordinary components; recipe='.$confirmedRecipeHash
                    .'; components='.json_encode($plan['recipe'], JSON_UNESCAPED_UNICODE),
            ]);

            return $plan;
        }, 3);
    }

    private function inspect(int $bundleVariantId, int $sourceMovementId, bool $lock): array
    {
        if ($bundleVariantId < 1 || $sourceMovementId < 1) {
            throw new InvalidArgumentException('Valid bundle variant and original return movement IDs are required.');
        }

        $bundle = ProductVariant::query()->with('product')->findOrFail($bundleVariantId);
        if (! $bundle->is_active || ! $bundle->sales_enabled || (int) $bundle->sell_price <= 0
            || ! $bundle->product?->is_bundle || ! $bundle->product?->is_sellable
            || $bundle->product->bundle_stock_mode !== 'automatic'
            || $bundle->product->variants()->count() !== 1) {
            throw new InvalidArgumentException('Bundle must be active, sellable, automatic and have exactly one variant.');
        }

        $warehouseId = WarehouseStockService::centralWarehouseId();
        $original = StockMovement::query()->findOrFail($sourceMovementId);
        if ((int) $original->product_variant_id !== $bundleVariantId
            || (int) $original->product_id !== (int) $bundle->product_id
            || (int) $original->warehouse_id !== $warehouseId
            || $original->type !== StockMovement::TYPE_IN
            || $original->reason !== 'invoice_cancel_return'
            || $original->reference_type !== WarehouseInboundReceiptItem::class
            || (int) $original->quantity !== 1) {
            throw new InvalidArgumentException('Source must be a verified one-unit returned invoice receipt for this exact bundle.');
        }

        // One return movement may never be reclassified twice, even if later stock arrives.
        if (StockMovement::query()->where('reason', 'bundle_return_reclassified')
            ->where('reference_type', StockMovement::class)
            ->where('reference_id', $sourceMovementId)->exists()) {
            throw new InvalidArgumentException('This returned pack has already been reclassified.');
        }

        $stockQuery = WarehouseStock::query()->where('warehouse_id', $warehouseId)
            ->where('product_id', $bundle->product_id)
            ->where('product_variant_id', $bundleVariantId);
        $stocks = ($lock ? $stockQuery->lockForUpdate() : $stockQuery)->get();
        if ($stocks->count() !== 1 || (int) $stocks->first()->quantity !== 1) {
            throw new InvalidArgumentException('Expected exactly one central finished bundle; stop and reconcile stock.');
        }

        $recipe = DB::table('product_bundle_components')
            ->where('bundle_variant_id', $bundleVariantId)
            ->orderBy('component_variant_id')
            ->pluck('quantity', 'component_variant_id')
            ->mapWithKeys(fn ($quantity, $id) => [(int) $id => (int) $quantity])->all();
        if ($recipe === [] || count($recipe) > 1000) {
            throw new InvalidArgumentException('The saved bundle recipe is empty or unexpectedly large.');
        }
        $parts = ProductVariant::query()->with('product')
            ->whereIn('id', array_keys($recipe))->get()->keyBy('id');
        if ($parts->count() !== count($recipe)) {
            throw new InvalidArgumentException('A component variant no longer exists.');
        }
        $productIds = [];
        foreach ($recipe as $partId => $quantity) {
            $part = $parts->get($partId);
            if ($quantity < 1 || $quantity > 1000000 || ! $part->is_active
                || ! $part->sales_enabled || ! $part->product?->is_sellable
                || $part->product->is_bundle) {
                throw new InvalidArgumentException('A component is invalid or not currently sellable.');
            }
            $productIds[$partId] = (int) $part->product_id;
        }

        $allocationQuery = DB::table('bundle_component_allocations')
            ->where('bundle_variant_id', $bundleVariantId)
            ->orderBy('component_variant_id');
        $allocations = ($lock ? $allocationQuery->lockForUpdate() : $allocationQuery)->get();
        if ($allocations->contains(fn ($row) => (int) $row->remaining !== 0)) {
            throw new InvalidArgumentException('Nonzero or inconsistent allocations exist; manual reconciliation is required.');
        }
        if ($allocations->contains(fn ($row) => ! array_key_exists((int) $row->component_variant_id, $recipe))) {
            throw new InvalidArgumentException('Orphan allocation row exists; manual reconciliation is required.');
        }

        return [
            'bundle_variant_id' => $bundleVariantId,
            'product_id' => (int) $bundle->product_id,
            'warehouse_id' => $warehouseId,
            'finished_stock' => 1,
            'recipe' => $recipe,
            'component_product_ids' => $productIds,
            'recipe_hash' => hash('sha256', json_encode($recipe, JSON_THROW_ON_ERROR)),
            'source_movement_id' => $sourceMovementId,
            'source_reference' => (string) $original->reference,
        ];
    }
}
