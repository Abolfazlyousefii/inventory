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
     * Explain why a stopped bundle cannot resume selling. Read-only: stock and
     * historical reservations are never modified by this check.
     * @return list<array{step:string,message:string}>
     */
    public function resumeReadinessIssues(Product $product, ProductVariant $variant): array
    {
        if ($product->bundle_archived_at !== null) {
            return [['step' => 'actions', 'message' => 'سبد بایگانی شده است؛ ابتدا از بایگانی بازگردانید.']];
        }

        $issues = [];
        $recipe = DB::table('product_bundle_components')->where('bundle_variant_id', $variant->id)
            ->pluck('quantity', 'component_variant_id')->all();
        if (!$recipe) {
            $issues[] = ['step' => 'recipe', 'message' => 'دستور ساخت قطعه‌ای ندارد؛ ابتدا قطعات را ثبت کنید.'];
        }
        if (!$variant->is_active || !$variant->sales_enabled) {
            $issues[] = ['step' => 'variant', 'message' => 'تنوع فروش بسته غیرفعال است؛ وضعیت فعال و مجاز برای فروش را در اطلاعات کالا بررسی کنید.'];
        }
        if ((int) $variant->sell_price < 1) {
            $issues[] = ['step' => 'price', 'message' => 'قیمت فروش ذخیره‌شده صفر یا نامعتبر است؛ قیمت بسته را ذخیره کنید.'];
        }

        if ($product->bundle_stock_mode === 'automatic') {
            $freePacks = $recipe ? app(BundleComponentAllocationService::class)->available((int) $variant->id, $recipe) : 0;
            if ($recipe && $freePacks < 1) {
                $issues[] = ['step' => 'stock', 'message' => 'سهمیه آزاد قطعات برای یک بسته کامل کافی نیست؛ در بخش موجودی حداقل یک بسته اختصاص دهید.'];
            }
            if (WarehouseStock::query()->where('product_variant_id', $variant->id)->where('quantity', '>', 0)->exists()) {
                $issues[] = ['step' => 'stock', 'message' => 'بسته آماده فیزیکی و سهمیه خودکار همزمان ثبت شده‌اند؛ موجودی را با مسئول انبار تطبیق دهید.'];
            }
        } elseif (!WarehouseStock::query()->where('product_variant_id', $variant->id)->where('quantity', '>', 0)->exists()) {
            $issues[] = ['step' => 'stock', 'message' => 'بسته آماده‌ای موجود نیست؛ ابتدا موجودی بسته فیزیکی را مطابق فرایند مونتاژ ثبت کنید.'];
        }

        if ($recipe) {
            $disabled = DB::table('product_bundle_components as c')
                ->join('product_variants as v', 'v.id', '=', 'c.component_variant_id')
                ->join('products as p', 'p.id', '=', 'v.product_id')
                ->where('c.bundle_variant_id', $variant->id)
                ->where(fn ($q) => $q->where('v.is_active', 0)->orWhere('v.sales_enabled', 0)->orWhere('p.is_sellable', 0))
                ->first(['p.name as product_name', 'v.variant_name']);
            if ($disabled) {
                $issues[] = ['step' => 'recipe', 'message' => 'قطعه «'.$disabled->product_name.' / '.$disabled->variant_name.'» برای فروش فعال نیست؛ قطعه را اصلاح یا جایگزین کنید.'];
            }
            if ($product->bundle_stock_mode === 'automatic'
                && $product->bundle_discount_type !== null && $product->bundle_discount_value !== null) {
                try {
                    $price = app(BundleDiscountService::class)->quoteForBundle(
                        (int) $variant->id, (string) $product->bundle_discount_type,
                        (int) $product->bundle_discount_value
                    )['final'];
                    if ((int) $variant->sell_price !== $price) {
                        $issues[] = ['step' => 'price', 'message' => 'قیمت ذخیره‌شده با محاسبه فعلی قطعات یکسان نیست؛ قیمت را دوباره ذخیره کنید.'];
                    }
                } catch (ValidationException $exception) {
                    $issues[] = ['step' => 'price', 'message' => 'قیمت و تخفیف فعلی قطعات قابل محاسبه نیست؛ ابتدا قیمت و تخفیف‌ها را اصلاح کنید.'];
                }
            }
        }

        return $issues;
    }
    /**
     * Create/change a recipe without rewriting finished-bundle inventory.
     * Existing finished units may have been assembled using an earlier recipe;
     * operators must reconcile their actual contents when changing composition.
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
            if (!$bundle->is_active || (!$bundle->product?->is_sellable && !$bundle->product?->is_bundle)
                || $bundle->product?->bundle_archived_at !== null) {
                throw ValidationException::withMessages(['bundle_variant_id' => 'The finished product must be active and sellable.']);
            }
            if ($bundle->product->variants()->count() !== 1) {
                throw ValidationException::withMessages(['bundle_variant_id' => 'A physical bundle must have exactly one selling variant.']);
            }
            // A historic assembly or completed Site order is not a permanent recipe lock.
            // Compare actual composition separately from component-level discounts: price
            // changes and resubmitting the same recipe do not change stock requirements.
            $currentRows = DB::table('product_bundle_components')
                ->where('bundle_variant_id', $bundleVariantId)
                ->orderBy('component_variant_id')
                ->get(['component_variant_id', 'quantity', 'discount_type', 'discount_value']);
            $currentRecipe = $currentRows->mapWithKeys(fn ($row) => [
                (int) $row->component_variant_id => (int) $row->quantity,
            ])->all();
            $compositionChanged = $currentRecipe !== $recipe;

            $discounts = [];
            foreach ($components as $component) {
                $discounts[(int) $component['variant_id']] = [
                    'type' => (string) ($component['discount_type'] ?? 'percent'),
                    'value' => (int) ($component['discount_value'] ?? 0),
                ];
            }
            $discountsChanged = $currentRows->count() !== count($discounts);
            foreach ($currentRows as $row) {
                $requested = $discounts[(int) $row->component_variant_id] ?? null;
                if (!$requested || $requested['type'] !== (string) $row->discount_type
                    || $requested['value'] !== (int) $row->discount_value) {
                    $discountsChanged = true;
                    break;
                }
            }
            if (!$compositionChanged && !$discountsChanged && $bundle->product->is_bundle) {
                return;
            }
            // Do not silently republish a changed recipe with a stale saved price.
            // Creating the first recipe is still allowed to use automatic publication.
            if ($currentRows->isNotEmpty() && $bundle->product->is_bundle
                && $bundle->product->bundle_stock_mode === 'automatic') {
                if (DB::connection('site')->table('products')->where('id', $bundle->product_id)
                    ->where('published', 1)->exists()) {
                    throw ValidationException::withMessages(['components' => 'برای ویرایش دستور ساخت یا تخفیف قطعات ابتدا انتشار سایت را متوقف کنید.']);
                }
                Product::query()->whereKey($bundle->product_id)->update(['bundle_site_auto_publish' => false]);
            }

            if ($compositionChanged) {
                if ($bundle->product->is_bundle && $bundle->product->bundle_stock_mode === 'automatic') {
                    // Serialize recipe changes with allocation edits and bundle checkout.
                    Product::query()->whereKey($bundle->product_id)->lockForUpdate()->firstOrFail();
                    // Both current and legacy Site holds must keep their original recipe.
                    try {
                        app(UnusedBundleAllocationReleaseService::class)->assertNoActiveReservations($bundleVariantId);
                    } catch (InvalidArgumentException $exception) {
                        throw ValidationException::withMessages(['components' => 'سفارش یا رزرو فعال این بسته باید پیش از تغییر ترکیب تعیین تکلیف شود.']);
                    }
                }
                if ($bundle->product->is_bundle && $bundle->product->bundle_stock_mode === 'automatic'
                    && Schema::connection('site')->hasColumn('products', 'published')
                    && DB::connection('site')->table('products')->where('id', $bundle->product_id)->where('published', 1)->exists()) {
                    throw ValidationException::withMessages(['components' => 'برای تغییر ترکیب بسته خودکار ابتدا آن را از انتشار سایت خارج کنید. سفارش‌های در جریان را نیز بررسی کنید.']);
                }
                // Positive finished stock does not lock the catalog recipe. This edit
                // must NOT consume component stock or change the already-made units:
                // they may still physically contain the previous recipe. The UI asks
                // for confirmation of that inventory reconciliation responsibility.
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

            // Discount-only edits preserve each component row and all allocation data.
            if (!$compositionChanged) {
                $currentDiscountRows = $currentRows->keyBy('component_variant_id');
                foreach ($discounts as $componentVariantId => $discount) {
                    $previous = $currentDiscountRows->get($componentVariantId);
                    if ($previous && $discount['type'] === (string) $previous->discount_type
                        && $discount['value'] === (int) $previous->discount_value) {
                        continue;
                    }
                    DB::table('product_bundle_components')
                        ->where('bundle_variant_id', $bundleVariantId)
                        ->where('component_variant_id', $componentVariantId)
                        ->update([
                            'discount_type' => $discount['type'],
                            'discount_value' => $discount['value'],
                            'updated_at' => now(),
                        ]);
                }
                if (!$bundle->product->is_bundle) {
                    Product::query()->whereKey($bundle->product_id)->update(['is_bundle' => true]);
                }
                return;
            }

            if (Schema::hasTable('bundle_component_allocations')) {
                // Preserve allocations for retained components. Automatically release only
                // allocations whose component is no longer in the recipe, so no protected
                // warehouse units become orphaned when a recipe is edited.
                $removed = DB::table('bundle_component_allocations')
                    ->where('bundle_variant_id', $bundleVariantId)
                    ->whereNotIn('component_variant_id', array_keys($recipe))
                    ->orderBy('component_variant_id')->lockForUpdate()->get();
                $partProductIds = ProductVariant::query()->whereIn('id', $removed->pluck('component_variant_id'))
                    ->pluck('product_id', 'id');
                foreach ($removed as $row) {
                    $variantId = (int) $row->component_variant_id;
                    $quantity = (int) $row->remaining;
                    if ($quantity > 0) {
                        $productId = $partProductIds->get($variantId);
                        if (!$productId) {
                            throw ValidationException::withMessages(['components' => 'تنوع قطعه حذف‌شده یافت نشد.']);
                        }
                        WarehouseStockService::change(WarehouseStockService::centralWarehouseId(),
                            (int) $productId, $quantity, $variantId);
                    }
                    DB::table('bundle_component_allocations')->where('id', $row->id)->delete();
                }

                // A retained component can also release free stock when its
                // quantity per bundle decreases. Previously these unused units
                // stayed allocated, preventing ordinary sales and other bundles
                // from using them until the allocations form was saved again.
                // Only FREE (remaining) allocations are adjusted here. Active
                // preinvoice / Site holds are guarded above, and no new stock is
                // reserved automatically if a per-bundle quantity increases.
                if ($bundle->product->bundle_stock_mode === 'automatic') {
                    $retained = DB::table('bundle_component_allocations')
                        ->where('bundle_variant_id', $bundleVariantId)
                        ->whereIn('component_variant_id', array_keys($recipe))
                        ->orderBy('component_variant_id')->lockForUpdate()->get();
                    $retainedProductIds = ProductVariant::query()
                        ->whereIn('id', $retained->pluck('component_variant_id'))
                        ->pluck('product_id', 'id');
                    foreach ($retained as $row) {
                        $variantId = (int) $row->component_variant_id;
                        $oldPerBundle = (int) ($currentRecipe[$variantId] ?? 0);
                        $newPerBundle = (int) $recipe[$variantId];
                        if ($oldPerBundle <= $newPerBundle || $oldPerBundle < 1) {
                            continue;
                        }
                        $remaining = (int) $row->remaining;
                        $allocatedPackages = intdiv($remaining, $oldPerBundle);
                        $released = $allocatedPackages * ($oldPerBundle - $newPerBundle);
                        if ($released <= 0) {
                            continue;
                        }
                        $productId = $retainedProductIds->get($variantId);
                        if (!$productId) {
                            throw ValidationException::withMessages(['components' => 'تنوع قطعه یافت نشد.']);
                        }
                        WarehouseStockService::change(WarehouseStockService::centralWarehouseId(),
                            (int) $productId, $released, $variantId);
                        DB::table('bundle_component_allocations')->where('id', $row->id)
                            ->update(['remaining' => $remaining - $released, 'updated_at' => now()]);
                    }
                }
                DB::table('bundle_component_allocations')->where('bundle_variant_id', $bundleVariantId)->where('remaining', 0)->delete();
            }
            DB::table('product_bundle_components')->where('bundle_variant_id', $bundleVariantId)->delete();
            $now = now();
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
