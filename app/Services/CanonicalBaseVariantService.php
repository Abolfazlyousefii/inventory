<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CanonicalBaseVariantService
{
    public const AVAILABLE = 'available';

    public const CREATED = 'created';

    public const NOT_SIMPLE = 'not_simple';

    public const BLOCKED_FOR_REVIEW = 'blocked_for_base_variant_review';

    public function __construct(
        private readonly ProductVariantStructureService $structure,
        private readonly VariantUsageAuditService $usage,
    ) {}

    /** @return array{state:string,variant:?ProductVariant,created:bool,blocking_reasons:array<int,string>} */
    public function inspect(Product $product): array
    {
        $product = $product->fresh() ?? $product;
        $code = $this->baseCode($product);
        $existing = ProductVariant::query()
            ->where('product_id', $product->id)
            ->whereNull('model_list_id')
            ->where('variety_code', '0000')
            ->where('variant_code', $code)
            ->first();

        if ($existing) {
            if (! $existing->is_active) {
                return $this->result(self::BLOCKED_FOR_REVIEW, $existing, false, ['inactive_base_variant']);
            }

            return $this->result(self::AVAILABLE, $existing, false, []);
        }

        $shape = $this->structure->structure($product);
        if ($shape['uses_models'] || $shape['uses_designs'] || $shape['has_colors']) {
            return $this->result(self::NOT_SIMPLE, null, false, ['product_is_not_simple']);
        }

        $blocking = [];
        if (WarehouseStock::query()->where('product_id', $product->id)->whereNull('product_variant_id')->exists()) {
            $blocking[] = 'ambiguous_product_level_warehouse_row';
        }
        if (ProductVariant::query()->where('variant_code', $code)->exists()) {
            $blocking[] = 'conflicting_base_variant_code';
        }

        foreach ($product->variants()->get() as $variant) {
            $audit = $this->usage->audit($variant);
            foreach ($audit['blocking_reasons'] as $reason) {
                $blocking[] = 'existing_variant_'.$variant->id.':'.$reason;
            }
        }

        foreach (VariantReferenceDiscoveryService::KNOWN_REFERENCES as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'product_id')) {
                continue;
            }
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)
                    && DB::table($table)->where('product_id', $product->id)->whereNull($column)->exists()) {
                    $blocking[] = 'ambiguous_product_history:'.$table.'.'.$column;
                }
            }
        }

        $blocking = array_values(array_unique($blocking));

        return $this->result(
            $blocking === [] ? self::AVAILABLE : self::BLOCKED_FOR_REVIEW,
            null,
            false,
            $blocking,
        );
    }

    /**
     * Return product IDs matching the exact summary predicate from inspect():
     * state is not NOT_SIMPLE and no canonical variant was returned.
     *
     * @param  Collection<int,int>  $productIds
     * @return Collection<int,int>
     */
    public function missingBaseProductIds(Collection $productIds): Collection
    {
        $missing = collect();
        $productIds->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->chunk(200)
            ->each(function (Collection $idChunk) use ($missing): void {
                $products = Product::query()->whereKey($idChunk->all())->get()->keyBy('id');
                if ($products->isEmpty()) {
                    return;
                }

                $canonicalProductIds = [];
                ProductVariant::query()
                    ->select(['id', 'product_id', 'variant_code'])
                    ->whereIn('product_id', $products->keys()->all())
                    ->whereNull('model_list_id')
                    ->where('variety_code', '0000')
                    ->orderBy('id')
                    ->chunkById(500, function (Collection $variants) use ($products, &$canonicalProductIds): void {
                        foreach ($variants as $variant) {
                            $product = $products->get((int) $variant->product_id);
                            if ($product && (string) $variant->variant_code === $this->baseCode($product)) {
                                $canonicalProductIds[(int) $product->id] = true;
                            }
                        }
                    });

                $withoutCanonical = $products->reject(
                    fn (Product $product): bool => isset($canonicalProductIds[(int) $product->id]),
                );
                $emptyMetadataIds = $withoutCanonical
                    ->filter(fn (Product $product): bool => empty(is_array($product->models) ? $product->models : []))
                    ->keys()
                    ->map(fn ($id): int => (int) $id)
                    ->values();
                $inferredModelProducts = $emptyMetadataIds->isEmpty()
                    ? collect()
                    : ProductVariant::query()
                        ->whereIn('product_id', $emptyMetadataIds->all())
                        ->whereNotNull('model_list_id')
                        ->where('model_list_id', '<>', 0)
                        ->distinct()
                        ->pluck('product_id')
                        ->mapWithKeys(fn ($id): array => [(int) $id => true]);
                $inferredDesignProducts = $emptyMetadataIds->isEmpty()
                    ? collect()
                    : ProductVariant::query()
                        ->whereIn('product_id', $emptyMetadataIds->all())
                        ->whereNotNull('variety_code')
                        ->where('variety_code', '<>', '0000')
                        ->groupBy('product_id')
                        ->selectRaw('product_id, MAX(CAST(variety_code AS UNSIGNED)) as max_design')
                        ->pluck('max_design', 'product_id')
                        ->filter(fn ($maximum): bool => (int) $maximum > 0)
                        ->mapWithKeys(fn ($maximum, $productId): array => [(int) $productId => true]);

                foreach ($withoutCanonical as $product) {
                    $meta = is_array($product->models) ? $product->models : [];
                    if ($meta === []) {
                        $notSimple = (bool) $product->has_colors
                            || $inferredModelProducts->has((int) $product->id)
                            || $inferredDesignProducts->has((int) $product->id);
                    } else {
                        $structure = $this->structure->structure($product);
                        $notSimple = $structure['uses_models'] || $structure['uses_designs'] || $structure['has_colors'];
                    }

                    if (! $notSimple) {
                        $missing->push((int) $product->id);
                    }
                }
            });

        return $missing->unique()->values();
    }

    /** @return array{state:string,variant:?ProductVariant,created:bool,blocking_reasons:array<int,string>} */
    public function createIfSafe(Product $product): array
    {
        return DB::transaction(function () use ($product): array {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $inspection = $this->inspect($locked);
            if ($inspection['variant'] || $inspection['state'] !== self::AVAILABLE) {
                return $inspection;
            }

            $variant = ProductVariant::query()->create([
                'product_id' => $locked->id,
                'model_list_id' => null,
                'variant_name' => trim($locked->name.' — Base'),
                'variety_name' => '—',
                'variety_code' => '0000',
                'variant_code' => $this->baseCode($locked),
                'sell_price' => max(0, (int) $locked->price),
                'buy_price' => null,
                'stock' => 0,
                'reserved' => 0,
                'is_active' => true,
                'sales_enabled' => (bool) $locked->is_sellable,
            ]);

            return $this->result(self::CREATED, $variant, true, []);
        });
    }

    private function baseCode(Product $product): string
    {
        return (string) $product->code.'00000';
    }

    /** @return array{state:string,variant:?ProductVariant,created:bool,blocking_reasons:array<int,string>} */
    private function result(string $state, ?ProductVariant $variant, bool $created, array $blocking): array
    {
        return [
            'state' => $state,
            'variant' => $variant,
            'created' => $created,
            'blocking_reasons' => $blocking,
        ];
    }
}
