<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs electrical products whose `use_designs` metadata stayed true.
 *
 * On those products structure validation treated the empty synthetic
 * black/white shells as the only valid variants, so the real Base (`0000`),
 * which holds all stock, price and history, was deactivated and the product
 * looked out of stock and unpriced.
 *
 * Every eligibility condition is re-evaluated at run time (and again under row
 * locks inside heal()); a list of IDs from an earlier dry run is never trusted.
 * Nothing is deleted and no history row is touched: only `models` metadata and
 * `is_active` flags change, followed by the canonical summary recalculation.
 */
class ElectricProductStructureHealService
{
    public const ELIGIBLE = 'ELIGIBLE';

    public const HEALED = 'HEALED';

    public const NOTHING_TO_DO = 'NOTHING_TO_DO';

    public const BLOCKED = 'BLOCKED';

    /** Non-Base variants must have zero rows in every one of these. */
    public const EMPTY_SHELL_REFERENCES = [
        'purchase_items' => 'product_variant_id',
        'invoice_items' => 'variant_id',
        'invoice_collection_revision_items' => 'product_variant_id',
        'preinvoice_order_items' => 'variant_id',
        'preinvoice_draft_reservations' => 'variant_id',
        'stock_movements' => 'product_variant_id',
    ];

    public function __construct(
        private readonly DefaultProductDesignService $design,
        private readonly ProductVariantStructureService $structure,
    ) {}

    /** Read-only: reports what heal() would do, never writes. */
    public function assess(int $productId): array
    {
        $product = Product::query()->with('category.parent')->find($productId);
        if (! $product) {
            return $this->notFound($productId);
        }

        return $this->evaluate($product, ProductVariant::query()->where('product_id', $product->id)->orderBy('id')->get());
    }

    public function heal(int $productId): array
    {
        return DB::transaction(function () use ($productId): array {
            $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
            if (! $product) {
                return $this->notFound($productId);
            }
            $product->load('category.parent');
            $variants = ProductVariant::query()
                ->where('product_id', $product->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // Final revalidation on the locked rows.
            $assessment = $this->evaluate($product, $variants);
            if ($assessment['status'] !== self::ELIGIBLE) {
                return $assessment;
            }

            $base = $variants->firstWhere('id', $assessment['base_variant_id']);
            $shellIds = $variants->where('id', '!=', $base->id)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
            $modelsBefore = $this->models($product);

            // saveQuietly: the explicit audit row below is the single record of
            // this repair; no generic observer rows are manufactured.
            $product->forceFill(['models' => $assessment['models_after']])->saveQuietly();

            // Query-builder writes: an Eloquent update would make the
            // ActivityObserver log `updated` rows on the variants, which the
            // synthetic classifier treats as business evidence.
            ProductVariant::query()->whereKey($base->id)->update(['is_active' => true]);
            if ($shellIds !== []) {
                ProductVariant::query()->whereKey($shellIds)->update(['is_active' => false]);
            }

            $product = $product->fresh();
            $this->structure->recalculateProductSummary($product);
            $product->refresh();

            $properties = [
                'product_id' => (int) $product->id,
                'base_variant_id' => (int) $base->id,
                'deactivated_variant_ids' => $assessment['deactivating'],
                'stock_before' => $assessment['stock_before'],
                'stock_after' => (int) $product->stock,
                'price_before' => $assessment['price_before'],
                'price_after' => (int) $product->price,
                'models_before' => $modelsBefore,
                'models_after' => $this->models($product),
            ];

            if (Schema::hasTable('activity_logs')) {
                ActivityLog::query()->create([
                    'user_id' => Auth::id(),
                    'action' => 'electric_product_structure_healed',
                    'subject_type' => Product::class,
                    'subject_id' => $product->id,
                    'description' => 'ساختار کالای برقی اصلاح شد: تنوع پایه فعال و تنوع‌های خالی خودکار غیرفعال شدند.',
                    'properties' => $properties,
                    'occurred_at' => Carbon::now(),
                ]);
            }

            return array_merge($assessment, [
                'status' => self::HEALED,
                'stock_after' => $properties['stock_after'],
                'price_after' => $properties['price_after'],
                'models_after' => $properties['models_after'],
            ]);
        });
    }

    /** @param Collection<int,ProductVariant> $variants */
    private function evaluate(Product $product, Collection $variants): array
    {
        $blocking = [];
        $baseCode = (string) $product->code.'00000';
        $baseCandidates = $variants->filter(fn (ProductVariant $variant): bool => (string) $variant->variety_code === '0000');
        $base = $baseCandidates->count() === 1 ? $baseCandidates->first() : null;

        // 1. Electrical category.
        if (! $this->design->isElectricCategory($product->category)) {
            $blocking[] = 'not_electrical_category';
        }

        // 2. Exactly one Base, carrying the canonical code.
        if ($baseCandidates->isEmpty()) {
            $blocking[] = 'base_variant_missing';
        } elseif ($baseCandidates->count() > 1) {
            $blocking[] = 'multiple_base_variants';
        } elseif ((string) $base->variant_code !== $baseCode) {
            $blocking[] = 'base_variant_code_mismatch';
        }

        // 3. The Base carries a price.
        if ($base && (int) $base->sell_price <= 0) {
            $blocking[] = 'base_variant_without_price';
        }

        // 4. No model list anywhere.
        if ($variants->contains(fn (ProductVariant $variant): bool => ! in_array($variant->model_list_id, [null, 0, '0'], true))) {
            $blocking[] = 'product_uses_model_list';
        }

        // 5. Every non-Base variant is a completely empty shell.
        $shells = $variants->reject(fn (ProductVariant $variant): bool => $base !== null && (int) $variant->id === (int) $base->id)
            ->reject(fn (ProductVariant $variant): bool => $base === null && (string) $variant->variety_code === '0000')
            ->values();
        $shellIds = $shells->pluck('id')->map(fn ($id): int => (int) $id)->all();
        foreach ($shells as $shell) {
            if ((int) $shell->stock !== 0) {
                $blocking[] = 'variant_'.$shell->id.':nonzero_stock';
            }
            if ((int) $shell->reserved !== 0) {
                $blocking[] = 'variant_'.$shell->id.':nonzero_reserved';
            }
            if ((int) $shell->sell_price !== 0) {
                $blocking[] = 'variant_'.$shell->id.':positive_sell_price';
            }
        }

        $uncheckedReferences = [];
        if ($shellIds !== []) {
            // 6. Guard every table and column before querying it.
            foreach (self::EMPTY_SHELL_REFERENCES as $table => $column) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                    $uncheckedReferences[] = $table.'.'.$column;

                    continue;
                }
                foreach (DB::table($table)->whereIn($column, $shellIds)->distinct()->pluck($column) as $variantId) {
                    $blocking[] = 'variant_'.$variantId.':'.$table.'_reference';
                }
            }

            if (Schema::hasTable('warehouse_stocks') && Schema::hasColumn('warehouse_stocks', 'product_variant_id')) {
                $sums = DB::table('warehouse_stocks')
                    ->whereIn('product_variant_id', $shellIds)
                    ->groupBy('product_variant_id')
                    ->selectRaw('product_variant_id, SUM(quantity) as total_quantity')
                    ->pluck('total_quantity', 'product_variant_id');
                foreach ($sums as $variantId => $total) {
                    if ((int) $total !== 0) {
                        $blocking[] = 'variant_'.$variantId.':nonzero_warehouse_stock';
                    }
                }
            } else {
                $uncheckedReferences[] = 'warehouse_stocks.product_variant_id';
            }
        }

        $blocking = array_values(array_unique($blocking));
        $modelsBefore = $this->models($product);
        $modelsAfter = array_merge($modelsBefore, ['use_designs' => false, 'design_count' => 0, 'design_notes' => []]);
        $activeShellIds = $shells->filter(fn (ProductVariant $variant): bool => (bool) $variant->is_active)
            ->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

        $alreadyHealed = $blocking === []
            && (bool) $base->is_active
            && $activeShellIds === []
            && ! (bool) ($modelsBefore['use_designs'] ?? false)
            && (int) ($modelsBefore['design_count'] ?? 0) === 0
            && ($modelsBefore['design_notes'] ?? []) === [];
        $willHeal = $blocking === [] && ! $alreadyHealed;

        return [
            'product_id' => (int) $product->id,
            'code' => (string) $product->code,
            'name' => (string) $product->name,
            'status' => $blocking !== [] ? self::BLOCKED : ($alreadyHealed ? self::NOTHING_TO_DO : self::ELIGIBLE),
            'base_variant_id' => $base ? (int) $base->id : null,
            'base_will_activate' => $willHeal && ! (bool) $base->is_active,
            'deactivating' => $willHeal ? $activeShellIds : [],
            'stock_before' => (int) $product->stock,
            // Projection of recalculateProductSummary() once the Base is the
            // only valid variant.
            'stock_after' => $willHeal ? max(0, (int) $base->stock) : (int) $product->stock,
            'price_before' => (int) $product->price,
            'price_after' => $willHeal
                ? ((bool) $base->sales_enabled && (int) $base->sell_price > 0 ? (int) $base->sell_price : 0)
                : (int) $product->price,
            'models_before' => $modelsBefore,
            'models_after' => $modelsAfter,
            'unchecked_references' => $uncheckedReferences,
            'blocking_reasons' => $blocking,
        ];
    }

    /** @return array<string,mixed> */
    private function models(Product $product): array
    {
        return is_array($product->models) ? $product->models : [];
    }

    private function notFound(int $productId): array
    {
        return [
            'product_id' => $productId,
            'code' => '',
            'name' => '',
            'status' => self::BLOCKED,
            'base_variant_id' => null,
            'base_will_activate' => false,
            'deactivating' => [],
            'stock_before' => 0,
            'stock_after' => 0,
            'price_before' => 0,
            'price_after' => 0,
            'models_before' => [],
            'models_after' => [],
            'unchecked_references' => [],
            'blocking_reasons' => ['product_not_found'],
        ];
    }
}
