<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ModelList;
use Illuminate\Support\Str;

class SalesReturnNewProductPayloadNormalizer
{
    public function normalize(?array $payload): array
    {
        $payload ??= [];

        if ((int) ($payload['schema_version'] ?? 0) !== 2) {
            $payload = $this->upgradeLegacyPayload($payload);
        }

        $isSellableWasProvided = array_key_exists('is_sellable', $payload);
        $providedIsSellable = $payload['is_sellable'] ?? null;
        $payload = array_replace($this->defaults(), $payload);
        $payload['schema_version'] = 2;
        $payload['temporary_product_uuid'] = $this->string($payload['temporary_product_uuid']) ?: (string) Str::uuid();
        $payload['name'] = trim($this->string($payload['name'] ?: $payload['product_name']));
        $payload['product_name'] = $payload['name'];
        $payload['category_id'] = $this->positiveInteger($payload['category_id']);
        $payload['category_path_snapshot'] = $this->array($payload['category_path_snapshot']);
        $payload['is_sellable'] = $isSellableWasProvided
            ? ($this->nullableBoolean($providedIsSellable) ?? false)
            : true;
        $payload['sales_enabled'] = $payload['is_sellable'];
        $payload['unit'] = trim($this->string($payload['unit'])) ?: 'عدد';
        $payload['use_models'] = $this->boolean($payload['use_models']);
        $payload['use_designs'] = $this->boolean($payload['use_designs']);
        $payload['purchase_price'] = $this->integer($payload['purchase_price']);
        $payload['sell_price'] = $this->integer($payload['sell_price']);
        $payload['refund_unit_price_default'] = $this->integer($payload['refund_unit_price_default']);

        $payload['model_list_ids'] = collect($this->array($payload['model_list_ids']))
            ->map(fn ($id) => $this->positiveInteger($id))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $payload['selected_models'] = $this->array($payload['selected_models']);
        $payload['model_brand_group'] = trim($this->string($payload['model_brand_group']));

        $payload['designs'] = collect($this->array($payload['designs']))
            ->filter(fn ($design) => is_array($design))
            ->map(fn (array $design, int $index) => [
                'index' => $index + 1,
                'name' => trim($this->string($design['name'] ?? '')),
            ])
            ->values()
            ->all();

        if (! $payload['use_models']) {
            $payload['model_brand_group'] = '';
            $payload['model_list_ids'] = [];
            $payload['selected_models'] = [];
        }
        if (! $payload['use_designs']) {
            $payload['designs'] = [];
        }

        $payload['selected_variants'] = collect($this->array($payload['selected_variants']))
            ->filter(fn ($variant) => is_array($variant))
            ->map(function (array $variant) use ($payload) {
                return [
                    'temporary_variant_uuid' => $this->string($variant['temporary_variant_uuid'] ?? '') ?: (string) Str::uuid(),
                    'model_list_id' => $payload['use_models'] ? $this->positiveInteger($variant['model_list_id'] ?? null) : null,
                    'design_index' => $payload['use_designs'] ? $this->integer($variant['design_index'] ?? 0) : 0,
                    'display_name' => trim($this->string($variant['display_name'] ?? $payload['name'])),
                    'preview_code' => trim($this->string($variant['preview_code'] ?? '')),
                ];
            })
            ->values()
            ->all();

        return $this->hydrateSnapshots($payload);
    }

    private function defaults(): array
    {
        return [
            'schema_version' => 2,
            'temporary_product_uuid' => '',
            'name' => '',
            'product_name' => '',
            'category_id' => null,
            'category_path_snapshot' => [],
            'is_sellable' => true,
            'sales_enabled' => true,
            'unit' => 'عدد',
            'use_models' => false,
            'model_brand_group' => '',
            'model_list_ids' => [],
            'selected_models' => [],
            'use_designs' => false,
            'designs' => [],
            'purchase_price' => 0,
            'sell_price' => 0,
            'refund_unit_price_default' => 0,
            'selected_variants' => [],
        ];
    }

    private function upgradeLegacyPayload(array $payload): array
    {
        $name = $this->string($payload['product_name'] ?? $payload['name'] ?? '');
        $modelId = $this->positiveInteger($payload['model_list_id'] ?? null);
        $designName = trim($this->string($payload['variety_name'] ?? ''));

        return array_replace($payload, [
            'schema_version' => 2,
            'temporary_product_uuid' => $this->string($payload['temporary_product_uuid'] ?? '') ?: (string) Str::uuid(),
            'name' => $name,
            'product_name' => $name,
            'is_sellable' => $this->boolean($payload['sales_enabled'] ?? true, true),
            'use_models' => $modelId !== null,
            'model_list_ids' => $modelId ? [$modelId] : [],
            'use_designs' => $designName !== '',
            'designs' => $designName !== '' ? [['index' => 1, 'name' => $designName]] : [],
            'selected_variants' => [[
                'temporary_variant_uuid' => $this->string($payload['temporary_variant_uuid'] ?? '') ?: (string) Str::uuid(),
                'model_list_id' => $modelId,
                'design_index' => $designName !== '' ? 1 : 0,
                'display_name' => $this->string($payload['variant_name'] ?? $name),
                'preview_code' => $this->string($payload['sku'] ?? ''),
            ]],
        ]);
    }

    private function hydrateSnapshots(array $payload): array
    {
        if ($payload['category_id']) {
            $category = Category::query()->find($payload['category_id']);
            if ($category && $payload['category_path_snapshot'] === []) {
                $path = [];
                $visited = [];
                $depth = 0;
                while ($category && $depth < 20 && ! isset($visited[$category->id])) {
                    $visited[$category->id] = true;
                    array_unshift($path, ['id' => $category->id, 'name' => $category->name, 'code' => $category->code]);
                    $category = $category->parent_id ? Category::query()->find($category->parent_id) : null;
                    $depth++;
                }
                $payload['category_path_snapshot'] = $path;
            }
        }

        if ($payload['use_models'] && $payload['model_list_ids'] !== []) {
            $models = ModelList::query()->whereIn('id', $payload['model_list_ids'])->get(['id', 'brand', 'model_name', 'code']);
            if ($payload['selected_models'] === []) {
                $payload['selected_models'] = $models->map(fn ($model) => [
                    'id' => $model->id,
                    'brand' => $model->brand,
                    'name' => $model->model_name,
                    'code' => $model->code,
                ])->values()->all();
            }
            if ($payload['model_brand_group'] === '') {
                $payload['model_brand_group'] = (string) $models->pluck('brand')->filter()->first();
            }
        }

        return $payload;
    }

    private function array(mixed $value): array { return is_array($value) ? $value : []; }
    private function string(mixed $value): string { return is_scalar($value) || $value instanceof \Stringable ? (string) $value : ''; }
    private function integer(mixed $value): int { return is_numeric($value) ? (int) $value : 0; }
    private function positiveInteger(mixed $value): ?int { $value = $this->integer($value); return $value > 0 ? $value : null; }

    private function boolean(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) return $value;
        if ($value === 1 || $value === '1') return true;
        if ($value === 0 || $value === '0') return false;
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                'true', 'yes', 'on' => true,
                'false', 'no', 'off' => false,
                default => $default,
            };
        }
        return $default;
    }

    private function nullableBoolean(mixed $value): ?bool
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
