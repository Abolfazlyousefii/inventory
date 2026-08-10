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
            $modelIds = $product->variants()
                ->whereNotNull('model_list_id')
                ->distinct()
                ->pluck('model_list_id')
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->values();
        }

        $designCount = isset($meta['design_count']) ? (int) $meta['design_count'] : null;

        if ($designCount === null && empty($meta)) {
            $designCount = (int) $product->variants()
                ->whereNotNull('variety_code')
                ->where('variety_code', '<>', '0000')
                ->selectRaw('MAX(CAST(variety_code AS UNSIGNED)) as max_design')
                ->value('max_design');
            $designCount = $designCount > 0 ? $designCount : null;
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

    public function deactivateInvalidVariants(Product $product): int
    {
        $activeBefore = (int) $product->variants()->where('is_active', true)->count();
        $validAfter = $this->validVariants($product)->count();
        $invalid = $this->invalidVariants($product);
        $invalidActiveIds = $invalid
            ->filter(fn (ProductVariant $variant) => (bool) $variant->is_active)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $invalidWithStock = $invalid->filter(fn (ProductVariant $variant) => (int) ($variant->stock ?? 0) > 0 || (int) ($variant->reserved ?? 0) > 0);

        $deactivated = 0;

        if (! empty($invalidActiveIds)) {
            $deactivated = ProductVariant::query()
                ->where('product_id', $product->id)
                ->whereIn('id', $invalidActiveIds)
                ->update(['is_active' => false]);
        }

        Log::info('Product variant structure sync audit', [
            'product_id' => (int) $product->id,
            'active_before' => $activeBefore,
            'valid_after' => $validAfter,
            'deactivated' => $deactivated,
            'invalid_with_stock_or_reserved' => $invalidWithStock->count(),
        ]);

        return $deactivated;
    }

    public function recalculateProductSummary(Product $product): void
    {
        $valid = $this->validVariants($product);

        $product->forceFill([
            'stock' => max(0, (int) $valid->sum('stock')),
            'reserved' => max(0, (int) $valid->sum('reserved')),
            'price' => max(0, (int) ($valid->where('sell_price', '>', 0)->min('sell_price') ?? 0)),
        ])->save();
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
