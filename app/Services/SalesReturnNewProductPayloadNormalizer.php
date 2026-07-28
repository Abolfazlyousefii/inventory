<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ModelList;
use Illuminate\Support\Str;

class SalesReturnNewProductPayloadNormalizer
{
    public function normalize(?array $payload): array
    {
        $payload = $payload ?: [];
        if ((int) ($payload['schema_version'] ?? 0) === 2) {
            $payload['schema_version'] = 2;
            $payload['is_sellable'] = array_key_exists('is_sellable', $payload)
                ? ($this->normalizeBoolean($payload['is_sellable']) ?? false)
                : true;
            $payload['use_models'] = $this->normalizeBoolean($payload['use_models'] ?? null) ?? false;
            $payload['use_designs'] = $this->normalizeBoolean($payload['use_designs'] ?? null) ?? false;
            $payload['sales_enabled'] = $payload['is_sellable'];
            return $this->hydrateSnapshots($payload);
        }

        $name = (string) ($payload['product_name'] ?? $payload['name'] ?? '');
        $modelId = $payload['model_list_id'] ?? null;
        $designName = (string) ($payload['variety_name'] ?? '');

        $normalized = [
            'schema_version' => 2,
            'temporary_product_uuid' => (string) ($payload['temporary_product_uuid'] ?? Str::uuid()),
            'name' => $name,
            'product_name' => $name,
            'category_id' => $payload['category_id'] ?? null,
            'category_path_snapshot' => [],
            'is_sellable' => (bool) ($payload['sales_enabled'] ?? true),
            'sales_enabled' => (bool) ($payload['sales_enabled'] ?? true),
            'unit' => $payload['unit'] ?? 'عدد',
            'use_models' => filled($modelId),
            'model_brand_group' => '',
            'model_list_ids' => filled($modelId) ? [(int) $modelId] : [],
            'selected_models' => [],
            'use_designs' => filled($designName),
            'designs' => filled($designName) ? [['index' => 1, 'name' => $designName]] : [],
            'purchase_price' => (int) ($payload['purchase_price'] ?? 0),
            'sell_price' => (int) ($payload['sell_price'] ?? 0),
            'refund_unit_price_default' => (int) ($payload['refund_unit_price_default'] ?? 0),
            'selected_variants' => [[
                'temporary_variant_uuid' => (string) ($payload['temporary_variant_uuid'] ?? Str::uuid()),
                'model_list_id' => filled($modelId) ? (int) $modelId : null,
                'design_index' => filled($designName) ? 1 : 0,
                'display_name' => (string) ($payload['variant_name'] ?? $name),
                'preview_code' => (string) ($payload['sku'] ?? ''),
            ]],
        ];

        return $this->hydrateSnapshots($normalized);
    }

    private function hydrateSnapshots(array $payload): array
    {
        if (! empty($payload['category_id'])) {
            $category = Category::query()->find((int) $payload['category_id']);
            if ($category) {
                $path = [];
                while ($category) {
                    array_unshift($path, ['id' => $category->id, 'name' => $category->name, 'code' => $category->code]);
                    $category = $category->parent_id ? Category::query()->find($category->parent_id) : null;
                }
                $payload['category_path_snapshot'] = $payload['category_path_snapshot'] ?: $path;
            }
        }

        $ids = collect($payload['model_list_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isNotEmpty()) {
            $models = ModelList::query()->whereIn('id', $ids)->get(['id', 'brand', 'model_name', 'code']);
            $payload['selected_models'] = $models->map(fn ($model) => [
                'id' => $model->id,
                'brand' => $model->brand,
                'name' => $model->model_name,
                'code' => $model->code,
            ])->values()->all();
            $payload['model_brand_group'] = $payload['model_brand_group'] ?: (string) $models->pluck('brand')->filter()->first();
        }

        return $payload;
    }

    private function normalizeBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') return null;
        if (is_bool($value)) return $value;
        if ($value === 1 || $value === '1') return true;
        if ($value === 0 || $value === '0') return false;
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                'true', 'yes', 'on' => true,
                'false', 'no', 'off' => false,
                default => null,
            };
        }

        return null;
    }
}
