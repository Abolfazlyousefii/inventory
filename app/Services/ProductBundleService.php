<?php

namespace App\Services;

use App\Models\BundleAssembly;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use App\Support\BundleRecipe;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Converts real component stock to finished package stock (and back).
 * This service never runs in the Site app. All operations are transaction-scoped.
 */
class ProductBundleService
{
    /**
     * Create/change a fixed recipe BEFORE the first physical assembly.
     * @param array<int,array{variant_id:int,quantity:int}> $components
     */
    public function defineRecipe(int $bundleVariantId, array $components): void
    {
        try {
            $recipe = BundleRecipe::normalize($bundleVariantId, $components);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['components' => $exception->getMessage()]);
        }

        DB::transaction(function () use ($bundleVariantId, $recipe, $components): void {
            $bundle = ProductVariant::query()->with('product')->lockForUpdate()->findOrFail($bundleVariantId);
            if (!$bundle->is_active || !$bundle->product?->is_sellable) {
                throw ValidationException::withMessages(['bundle_variant_id' => 'The finished product must be active and sellable.']);
            }
            if ($bundle->product->variants()->count() !== 1) {
                throw ValidationException::withMessages(['bundle_variant_id' => 'A physical bundle must have exactly one selling variant.']);
            }
            if ($bundle->product->is_bundle && $bundle->product->bundle_stock_mode === 'automatic'
                && DB::connection('site')->table('order_items')->where('price_id', $bundleVariantId)->exists()) {
                throw ValidationException::withMessages(['components' => 'برای این بسته سفارش ثبت شده است و دستور ساخت نباید تغییر کند.']);
            }
            if ($bundle->product->is_bundle && $bundle->product->bundle_stock_mode === 'automatic'
                && Schema::connection('site')->hasColumn('products', 'published')
                && DB::connection('site')->table('products')->where('id', $bundle->product_id)->where('published', 1)->exists()) {
                throw ValidationException::withMessages(['components' => 'برای تغییر ترکیب بسته خودکار ابتدا آن را از انتشار سایت خارج کنید. سفارش‌های در جریان را نیز بررسی کنید.']);
            }
            if ($bundle->product->is_bundle && $bundle->product->bundle_stock_mode === 'automatic'
                && Schema::hasTable('site_stock_holds')
                && DB::table('site_stock_holds')->whereIn('status', ['held', 'payment_confirmed'])
                    ->whereRaw('JSON_CONTAINS_PATH(bundle_allocated_lines, \'one\', ?) = 1', ['$.' . '"' . $bundleVariantId . '"'])
                    ->exists()) {
                throw ValidationException::withMessages(['components' => 'این بسته سفارش رزرو شده در جریان دارد. ترکیب بسته فعلاً قابل تغییر نیست.']);
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('bundle_component_allocations')
                && DB::table('bundle_component_allocations')->where('bundle_variant_id', $bundleVariantId)->where('remaining', '>', 0)->exists()) {
                throw ValidationException::withMessages(['components' => 'ابتدا سهمیه قطعات این بسته را به صفر برسانید تا بتوانید ترکیب آن را تغییر دهید.']);
            }
            if (BundleAssembly::query()->where('bundle_variant_id', $bundleVariantId)->exists()
                || WarehouseStock::query()->where('product_variant_id', $bundleVariantId)->where('quantity', '>', 0)->exists()) {
                throw ValidationException::withMessages(['bundle_variant_id' => 'Once assembled, a bundle recipe cannot be modified.']);
            }

            $variants = ProductVariant::query()->with('product')
                ->whereIn('id', array_keys($recipe))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($variants->count() !== count($recipe)) {
                throw ValidationException::withMessages(['components' => 'Unknown component variant.']);
            }
            foreach ($variants as $component) {
                if (!$component->is_active || $component->product_id === $bundle->product_id
                    || $component->product?->is_bundle) {
                    throw ValidationException::withMessages(['components' => 'Component must be an active ordinary product variant.']);
                }
            }

            if (\Illuminate\Support\Facades\Schema::hasTable('bundle_component_allocations')) {
                DB::table('bundle_component_allocations')->where('bundle_variant_id', $bundleVariantId)->where('remaining', 0)->delete();
            }
            DB::table('product_bundle_components')->where('bundle_variant_id', $bundleVariantId)->delete();
            $now = now();
            $discounts = [];
            foreach ($components as $component) {
                $discounts[(int) $component['variant_id']] = [
                    'type' => (string) ($component['discount_type'] ?? 'percent'),
                    'value' => (int) ($component['discount_value'] ?? 0),
                ];
            }
            foreach ($recipe as $componentVariantId => $quantity) {
                DB::table('product_bundle_components')->insert([
                    'bundle_variant_id' => $bundleVariantId,
                    'component_variant_id' => $componentVariantId,
                    'quantity' => $quantity,
                    'discount_type' => $discounts[$componentVariantId]['type'],
                    'discount_value' => $discounts[$componentVariantId]['value'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            Product::query()->whereKey($bundle->product_id)->update(['is_bundle' => true]);
        }, 3);
    }

    public function assemble(int $bundleVariantId, int $packages, int $warehouseId, int $actorId): BundleAssembly
    {
        return $this->convert($bundleVariantId, $packages, $warehouseId, $actorId, true);
    }

    public function disassemble(int $bundleVariantId, int $packages, int $warehouseId, int $actorId): BundleAssembly
    {
        return $this->convert($bundleVariantId, $packages, $warehouseId, $actorId, false);
    }

    private function convert(int $bundleVariantId, int $packages, int $warehouseId, int $actorId, bool $assemble): BundleAssembly
    {
        if ($actorId < 1 || $warehouseId !== WarehouseStockService::centralWarehouseId()) {
            throw ValidationException::withMessages(['warehouse_id' => 'Use an authorized actor and the configured central warehouse.']);
        }

        return DB::transaction(function () use ($bundleVariantId, $packages, $warehouseId, $actorId, $assemble) {
            $bundle = ProductVariant::query()->with('product')->lockForUpdate()->findOrFail($bundleVariantId);
            if (!$bundle->product?->is_bundle || $bundle->product->bundle_stock_mode === 'automatic') {
                throw ValidationException::withMessages(['bundle_variant_id' => 'این بسته در حالت خودکار است؛ نیازی به مونتاژ دستی ندارد.']);
            }
            $recipe = DB::table('product_bundle_components')
                ->where('bundle_variant_id', $bundleVariantId)
                ->orderBy('component_variant_id')
                ->pluck('quantity', 'component_variant_id')
                ->mapWithKeys(fn ($qty, $id) => [(int) $id => (int) $qty])->all();
            try {
                $required = BundleRecipe::requirements($recipe, $packages);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['quantity' => $exception->getMessage()]);
            }

            $productIds = ProductVariant::query()->whereIn('id', array_keys($required))
                ->pluck('product_id', 'id')->mapWithKeys(fn ($id, $variant) => [(int) $variant => (int) $id])->all();
            if (count($productIds) !== count($required)) {
                throw ValidationException::withMessages(['components' => 'Recipe refers to a missing variant.']);
            }

            // Lock all stock rows in deterministic variant order, including the finished SKU.
            $quantities = $required;
            $quantities[$bundleVariantId] = $packages;
            ksort($quantities, SORT_NUMERIC);
            $rows = WarehouseStock::query()->where('warehouse_id', $warehouseId)
                ->whereIn('product_variant_id', array_keys($quantities))
                ->orderBy('product_variant_id')->lockForUpdate()->get()->keyBy('product_variant_id');
            if ($assemble) {
                foreach ($required as $variantId => $quantity) {
                    if ((int) ($rows->get($variantId)?->quantity ?? 0) < $quantity) {
                        throw ValidationException::withMessages(['components' => "Insufficient stock for variant {$variantId}."]);
                    }
                }
            } elseif ((int) ($rows->get($bundleVariantId)?->quantity ?? 0) < $packages) {
                throw ValidationException::withMessages(['quantity' => 'Insufficient finished bundle stock for disassembly.']);
            }

            // WarehouseStockService owns the source-of-truth stock and its Site projections.
            // Nested DB transactions are safe; an exception rolls back every component movement.
            foreach ($required as $variantId => $quantity) {
                WarehouseStockService::change($warehouseId, $productIds[$variantId], $assemble ? -$quantity : $quantity, $variantId);
            }
            WarehouseStockService::change($warehouseId, (int) $bundle->product_id,
                $assemble ? $packages : -$packages, $bundleVariantId);

            return BundleAssembly::query()->create([
                'bundle_variant_id' => $bundleVariantId,
                'warehouse_id' => $warehouseId,
                'performed_by' => $actorId,
                'operation' => $assemble ? 'assemble' : 'disassemble',
                'quantity' => $packages,
                'components_snapshot' => $required,
            ]);
        }, 3);
    }
}
