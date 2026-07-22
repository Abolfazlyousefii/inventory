<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

class ProductCatalogGroupingService
{
    public function group(Product $product, Collection $variants): array
    {
        if ($variants->isEmpty()) {
            $price = (int) ($product->price ?? 0) > 0 ? (int) $product->price : null;
            return [
                'variant_count' => 0,
                'model_count' => 1,
                'color_count' => 0,
                'price_summary' => $price ? $this->priceLabel($price) : 'بدون قیمت',
                'groups' => [[
                    'price' => $price,
                    'price_label' => $this->priceLabel($price),
                    'models' => ['مدل عمومی'],
                    'colors' => [],
                ]],
                'price_list_rows' => [[
                    'model' => 'مدل عمومی',
                    'colors_label' => '',
                    'price' => $price,
                    'price_label' => $this->priceLabel($price),
                ]],
                'has_price' => $price !== null,
            ];
        }

        $modelBuckets = [];
        $seenVariants = [];

        foreach ($variants as $variant) {
            if (! $variant instanceof ProductVariant) continue;

            $model = $this->modelName($variant);
            $price = $this->effectivePrice($variant, $product);
            $color = $this->colorData($variant);
            $variantKey = implode('|', [$this->normalize($model), $price ?? 'null', $color ? $this->normalize($color['name']).'@'.($color['hex'] ?? '') : '']);
            if (isset($seenVariants[$variantKey])) continue;
            $seenVariants[$variantKey] = true;

            $bucketKey = $this->normalize($model).'|'.($price ?? 'null');
            $modelBuckets[$bucketKey] ??= ['model' => $model, 'price' => $price, 'colors' => [], 'color_keys' => []];
            if ($color) {
                $colorKey = $this->normalize($color['name']).'|'.($color['hex'] ?? '');
                if (! isset($modelBuckets[$bucketKey]['color_keys'][$colorKey])) {
                    $modelBuckets[$bucketKey]['color_keys'][$colorKey] = true;
                    $modelBuckets[$bucketKey]['colors'][] = $color;
                }
            }
        }

        foreach ($modelBuckets as &$bucket) {
            usort($bucket['colors'], fn ($a, $b) => strnatcasecmp($this->normalize($a['name']), $this->normalize($b['name'])));
        }
        unset($bucket);

        $groups = [];
        foreach ($modelBuckets as $bucket) {
            $colorSignature = collect($bucket['colors'])->map(fn ($color) => $this->normalize($color['name']).'|'.($color['hex'] ?? ''))->implode('||');
            $groupKey = ($bucket['price'] ?? 'null').'::'.$colorSignature;
            $groups[$groupKey] ??= ['price' => $bucket['price'], 'price_label' => $this->priceLabel($bucket['price']), 'models' => [], 'colors' => $bucket['colors']];
            $groups[$groupKey]['models'][] = $bucket['model'];
        }

        $groups = array_values($groups);
        foreach ($groups as &$group) {
            $group['models'] = collect($group['models'])->unique(fn ($m) => $this->normalize($m))->values()->all();
            usort($group['models'], fn ($a, $b) => strnatcasecmp($a, $b));
        }
        unset($group);
        usort($groups, fn ($a, $b) => ($a['price'] ?? PHP_INT_MAX) <=> ($b['price'] ?? PHP_INT_MAX) ?: strnatcasecmp($a['models'][0] ?? '', $b['models'][0] ?? ''));

        $models = collect($modelBuckets)->pluck('model')->unique(fn ($m) => $this->normalize($m))->values();
        $colors = collect($modelBuckets)->flatMap(fn ($b) => $b['colors'])->unique(fn ($c) => $this->normalize($c['name']).'|'.($c['hex'] ?? ''))->values();
        $prices = collect($modelBuckets)->pluck('price')->filter(fn ($p) => $p !== null && $p > 0)->unique()->sort()->values();

        return [
            'variant_count' => count($seenVariants),
            'model_count' => $models->count(),
            'color_count' => $colors->count(),
            'price_summary' => $this->priceSummary($prices),
            'groups' => $groups,
            'price_list_rows' => $this->priceListRows($modelBuckets),
            'has_price' => $prices->isNotEmpty(),
        ];
    }

    public function modelName(ProductVariant $variant): string
    {
        foreach ([$variant->modelList?->model_name, $variant->variant_name, $variant->variety_code, $variant->variant_code] as $value) {
            $text = $this->cleanText($value);
            if ($text !== '') return $text;
        }
        return 'مدل عمومی';
    }

    public function colorData(ProductVariant $variant): ?array
    {
        $name = $this->cleanText($variant->color?->name ?: $variant->variety_name);
        if ($name === '') return null;
        return ['name' => $name, 'hex' => $this->validHex($variant->color?->hex_code)];
    }

    public function effectivePrice(ProductVariant $variant, Product $product): ?int
    {
        $variantPrice = (int) ($variant->sell_price ?? 0);
        if ($variantPrice > 0) return $variantPrice;
        $productPrice = (int) ($product->price ?? 0);
        return $productPrice > 0 ? $productPrice : null;
    }

    public function priceLabel(?int $price): string { return $price ? number_format($price).' ریال' : 'قیمت ثبت نشده'; }

    private function priceSummary(Collection $prices): string
    {
        if ($prices->isEmpty()) return 'بدون قیمت';
        if ($prices->count() === 1) return $this->priceLabel($prices->first());
        return 'از '.$this->priceLabel($prices->first()).' تا '.$this->priceLabel($prices->last());
    }

    private function priceListRows(array $modelBuckets): array
    {
        return collect($modelBuckets)->map(function ($bucket) {
            return ['model' => $bucket['model'], 'colors_label' => collect($bucket['colors'])->pluck('name')->implode('، '), 'price' => $bucket['price'], 'price_label' => $this->priceLabel($bucket['price'])];
        })->sort(fn ($a, $b) => strnatcasecmp($a['model'], $b['model']) ?: (($a['price'] ?? PHP_INT_MAX) <=> ($b['price'] ?? PHP_INT_MAX)))->values()->all();
    }

    private function cleanText(mixed $value): string { return trim(preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '', str_replace(['ي','ك'], ['ی','ک'], strip_tags((string) $value)))); }
    private function normalize(string $value): string { return mb_strtolower(preg_replace('/\s+/u', ' ', str_replace("\u{200C}", ' ', $this->cleanText($value)))); }
    private function validHex(?string $hex): ?string { $hex = trim((string) $hex); return preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', $hex) ? $hex : null; }
}
