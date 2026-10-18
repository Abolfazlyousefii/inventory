<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ProductVariantStructureService
{
    public function structure(Product $product): array
    {
        $meta = is_array($product->models) ? $product->models : [];

        $modelIds = collect($meta['model_list_ids'] ?? $meta['ids'] ?? $meta)
            ->filter(fn ($id, $key) => is_numeric($id) && ! is_string($key))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($modelIds->isEmpty() && array_key_exists('model_list_ids', $meta)) {
            $modelIds = collect($meta['model_list_ids'])->map(fn ($id) => (int) $id)->filter()->unique()->values();
        }

        if ($modelIds->isEmpty() && empty($meta)) {
            if ($product->relationLoaded('variants')) {
                $modelIds = $product->getRelation('variants')
                    ->pluck('model_list_id')
                    ->filter(fn ($id) => $id !== null)
                    ->map(fn ($id) => (int) $id)
                    ->filter()
                    ->unique()
                    ->values();
            } else {
                $modelIds = $product->variants()
                    ->whereNotNull('model_list_id')
                    ->distinct()
                    ->pluck('model_list_id')
                    ->map(fn ($id) => (int) $id)
                    ->filter()
                    ->values();
            }
        }

        $designCount = isset($meta['design_count']) ? (int) $meta['design_count'] : null;

        if ($designCount === null && empty($meta)) {
            if ($product->relationLoaded('variants')) {
                $designCount = $product->getRelation('variants')
                    ->filter(
                        fn (ProductVariant $variant) =>
                            $variant->variety_code !== null
                            && (string) $variant->variety_code !== '0000'
                    )
                    ->map(
                        fn (ProductVariant $variant) =>
                        (int) $variant->variety_code
                    )
                    ->max();

                $designCount = $designCount && $designCount > 0
                    ? (int) $designCount
                    : null;
            } else {
                $designCount = (int) $product->variants()
                    ->whereNotNull('variety_code')
                    ->where('variety_code', '<>', '0000')
                    ->selectRaw(
                        'MAX(CAST(variety_code AS UNSIGNED)) as max_design'
                    )
                    ->value('max_design');

                $designCount = $designCount > 0
                    ? $designCount
                    : null;
            }
        }

        if ($designCount !== null) {
            $designCount = max(0, min(99, $designCount));
        }

        return [
            'uses_models' => (bool) ($meta['use_models'] ?? $modelIds->isNotEmpty()),
            'model_ids' => $modelIds->all(),
            'uses_designs' => (bool) ($meta['use_designs'] ?? (($designCount ?? 0) > 0)),
            'design_count' => $designCount,
            'has_colors' => (bool) ($product->has_colors ?? false),
        ];
    }

    public function applyValidConstraints($query, Product $product, bool $activeOnly = true)
    {
        $structure = $this->structure($product);

        $query->where('product_id', $product->id);

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        if ($structure['uses_models']) {
            $modelIds = $structure['model_ids'];
            if (empty($modelIds)) {
                return $query->whereRaw('1 = 0');
            }

            $query->whereIn('model_list_id', $modelIds);
        } else {
            $query->whereNull('model_list_id');
        }

        if ($structure['uses_designs'] && $structure['design_count'] !== null) {
            if ($structure['design_count'] <= 0) {
                $query->where('variety_code', '0000');
            } else {
                $validCodes = collect(range(1, $structure['design_count']))
                    ->map(fn ($index) => str_pad((string) $index, 4, '0', STR_PAD_LEFT))
                    ->all();

                $query->whereIn('variety_code', $validCodes);
            }
        } else {
            $query->where('variety_code', '0000');
        }

        return $query;
    }

    public function validVariants(Product $product, bool $activeOnly = true): Collection
    {
        return $this->applyValidConstraints($product->variants()->getQuery(), $product, $activeOnly)
            ->with(['modelList:id,model_name,code', 'color:id,name,code'])
            ->orderBy('variant_code')
            ->orderBy('id')
            ->get();
    }

    public function validLoadedVariants(
        Product $product,
        bool $activeOnly = true
    ): Collection {
        if (! $product->relationLoaded('variants')) {
            return $this->validVariants($product, $activeOnly);
        }

        $structure = $this->structure($product);

        $modelIds = array_map(
            'intval',
            $structure['model_ids'] ?? []
        );

        if (
            $structure['uses_designs']
            && $structure['design_count'] !== null
        ) {
            if ($structure['design_count'] <= 0) {
                $validDesignCodes = ['0000'];
            } else {
                $validDesignCodes = collect(
                    range(1, $structure['design_count'])
                )
                    ->map(
                        fn ($index) =>
                        str_pad(
                            (string) $index,
                            4,
                            '0',
                            STR_PAD_LEFT
                        )
                    )
                    ->all();
            }
        } else {
            $validDesignCodes = ['0000'];
        }

        return $product->getRelation('variants')
            ->filter(function (
                ProductVariant $variant
            ) use (
                $product,
                $activeOnly,
                $structure,
                $modelIds,
                $validDesignCodes
            ): bool {

                if (
                    (int) $variant->product_id
                    !== (int) $product->id
                ) {
                    return false;
                }

                if (
                    $activeOnly
                    && ! (bool) $variant->is_active
                ) {
                    return false;
                }

                if ($structure['uses_models']) {

                    if (
                        $modelIds === []
                        || ! in_array(
                            (int) $variant->model_list_id,
                            $modelIds,
                            true
                        )
                    ) {
                        return false;
                    }

                } elseif ($variant->model_list_id !== null) {
                    return false;
                }

                return in_array(
                    (string) $variant->variety_code,
                    $validDesignCodes,
                    true
                );
            })
            ->sort(function (
                ProductVariant $a,
                ProductVariant $b
            ): int {

                $codeCompare = strcmp(
                    (string) $a->variant_code,
                    (string) $b->variant_code
                );

                return $codeCompare !== 0
                    ? $codeCompare
                    : ((int) $a->id <=> (int) $b->id);
            })
            ->values();
    }

    public function invalidVariants(Product $product): Collection
    {
        $validIds = $this->validVariants($product, false)->pluck('id')->map(fn ($id) => (int) $id);

        return $product->variants()
            ->with(['modelList:id,model_name,code', 'color:id,name,code'])
            ->when($validIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $validIds->all()))
            ->when($validIds->isEmpty(), fn ($query) => $query)
            ->orderBy('variant_code')
            ->orderBy('id')
            ->get();
    }

    public function audit(Product $product): array
    {
        $valid = $this->validVariants($product);
        $invalid = $this->invalidVariants($product);
        $invalidWithStock = $invalid->filter(fn (ProductVariant $variant) => (int) ($variant->stock ?? 0) > 0 || (int) ($variant->reserved ?? 0) > 0);

        return [
            'product' => $product,
            'structure' => $this->structure($product),
            'total_variants' => (int) $product->variants()->count(),
            'active_variants' => (int) $product->variants()->where('is_active', true)->count(),
            'valid_variants' => $valid->count(),
            'invalid_variants' => $invalid->count(),
            'invalid_variants_with_stock' => $invalidWithStock->count(),
            'invalid_stock_total' => (int) $invalid->sum('stock'),
            'invalid_reserved_total' => (int) $invalid->sum('reserved'),
            'invalid_with_stock' => $invalidWithStock->values(),
        ];
    }

    /**
     * Bulk `is_active => false` for variants outside the product structure.
     *
     * Never runs implicitly. Without `$confirmed` it writes nothing and only
     * reports what it *would* deactivate, so a structure change can never
     * silently hide real (possibly stocked or sold) variants. Callers must
     * name an explicit `$reason`.
     *
     * @return array{product_id:int,reason:string,confirmed:bool,variant_ids:array<int,int>,count:int,deactivated:int,invalid_with_stock_or_reserved:int,caller:string}
     */
    public function deactivateInvalidVariants(Product $product, string $reason, bool $confirmed = false): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('deactivateInvalidVariants() requires an explicit reason.');
        }

        $activeBefore = (int) $product->variants()->where('is_active', true)->count();
        $validAfter = $this->validVariants($product)->count();
        $invalid = $this->invalidVariants($product);
        $invalidActiveIds = $invalid
            ->filter(fn (ProductVariant $variant) => (bool) $variant->is_active)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
        $invalidWithStock = $invalid->filter(fn (ProductVariant $variant) => (int) ($variant->stock ?? 0) > 0 || (int) ($variant->reserved ?? 0) > 0);
        $caller = $this->callerSummary();

        $deactivated = 0;

        if ($confirmed && ! empty($invalidActiveIds)) {
            $deactivated = ProductVariant::query()
                ->where('product_id', $product->id)
                ->whereIn('id', $invalidActiveIds)
                ->update(['is_active' => false]);
        }

        $report = [
            'product_id' => (int) $product->id,
            'reason' => $reason,
            'confirmed' => $confirmed,
            'variant_ids' => $invalidActiveIds,
            'count' => count($invalidActiveIds),
            'deactivated' => $deactivated,
            'invalid_with_stock_or_reserved' => $invalidWithStock->count(),
            'caller' => $caller,
        ];

        $context = $report + ['active_before' => $activeBefore, 'valid_after' => $validAfter];
        if ($confirmed) {
            Log::info('Product variant structure deactivation applied', $context);
        } else {
            Log::warning('Product variant structure deactivation skipped (not confirmed); nothing was written', $context);
        }

        return $report;
    }

    private function callerSummary(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4) as $frame) {
            $class = $frame['class'] ?? null;
            if ($class !== null && $class !== self::class) {
                return $class.'::'.($frame['function'] ?? '?');
            }
        }

        return 'unknown';
    }

    public function recalculateProductSummary(Product $product): void
    {
        // Canonical Phase 5 commercial projection. Do not substitute the
        // legacy WarehouseStockService compatibility summary here.
        $valid = $this->validVariants($product);
        $minimumUsablePrice = $valid
            ->where('sales_enabled', true)
            ->where('sell_price', '>', 0)
            ->min('sell_price');

        // Legacy electrical products can still carry old design metadata while
        // purchases correctly use their canonical Base. Keep its usable price.
        if ($minimumUsablePrice === null && app(PurchaseSyntheticVariantGuard::class)->isElectric($product)) {
            $base = app(CanonicalBaseVariantService::class)->existingAvailableBase($product);
            if ($base?->sales_enabled && (int) $base->sell_price > 0) {
                $minimumUsablePrice = (int) $base->sell_price;
            }
        }

        // Commercial eligibility is not an inventory balance. In particular,
        // excluding a legacy/inactive design must not erase its stock/reserves.
        // WarehouseStockService and ReservationProjectionService own those fields.
        $product->forceFill([
            'price' => $product->uniform_sale_price ?? max(0, (int) ($minimumUsablePrice ?? 0)),
        ]);

        if ($product->isDirty('price')) {
            $product->save();
        }
    }

    public function metadata(bool $useModels, array $modelIds, bool $useDesigns, ?int $designCount, array $designNotes = []): array
    {
        return [
            'use_models' => $useModels,
            'model_list_ids' => collect($modelIds)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all(),
            'use_designs' => $useDesigns,
            'design_count' => $useDesigns ? max(1, min(99, (int) $designCount)) : 0,
            'design_notes' => collect($designNotes)->map(fn ($note) => trim((string) $note))->values()->all(),
        ];
    }
}
