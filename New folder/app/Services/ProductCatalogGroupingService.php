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
                'price_min' => $price,
                'price_max' => $price,
                'price_summary' => $price ? $this->priceLabel($price) : 'قیمت ثبت نشده',
                'groups' => [[
                    'price' => $price,
                    'price_label' => $this->priceLabel($price),
                    'models' => ['مدل عمومی'],
                    'colors' => [],
                    'color_columns' => [],
                ]],
                'has_price' => $price !== null,
            ];
        }

        $modelBuckets = [];
        $seenVariants = [];

        foreach ($variants as $variant) {
            if (! $variant instanceof ProductVariant) {
                continue;
            }

            $model = $this->modelName($variant);
            $price = $this->effectivePrice($variant, $product);
            $color = $this->colorData($variant);
            $variantKey = implode('|', [$this->normalize($model), $price ?? 'null', $color ? $this->normalize($color['name']).'@'.($color['hex'] ?? '') : '']);

            if (isset($seenVariants[$variantKey])) {
                continue;
            }

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
            $group['color_columns'] = $this->colorColumns($group['colors']);
        }
        unset($group);

        usort($groups, fn ($a, $b) => strnatcasecmp($a['models'][0] ?? '', $b['models'][0] ?? '') ?: (($a['price'] ?? PHP_INT_MAX) <=> ($b['price'] ?? PHP_INT_MAX)));

        $models = collect($modelBuckets)->pluck('model')->unique(fn ($m) => $this->normalize($m))->values();
        $colors = collect($modelBuckets)->flatMap(fn ($b) => $b['colors'])->unique(fn ($c) => $this->normalize($c['name']).'|'.($c['hex'] ?? ''))->values();
        $prices = collect($modelBuckets)->pluck('price')->filter(fn ($p) => $p !== null && $p > 0)->unique()->sort()->values();

        return [
            'variant_count' => count($seenVariants),
            'model_count' => $models->count(),
            'color_count' => $colors->count(),
            'price_min' => $prices->first(),
            'price_max' => $prices->last(),
            'price_summary' => $this->priceSummary($prices),
            'groups' => $groups,
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
        if ($prices->isEmpty()) return 'قیمت ثبت نشده';
        if ($prices->count() === 1) return $this->priceLabel($prices->first());
        return 'از '.number_format($prices->first()).' تا '.number_format($prices->last()).' ریال';
    }

    private function colorColumns(array $colors): array
    {
        $count = count($colors);
        if ($count <= 12) return [$colors];
        $columns = $count > 36 ? 3 : 2;
        return collect($colors)->chunk((int) ceil($count / $columns))->map(fn ($chunk) => $chunk->values()->all())->values()->all();
    }

    private function cleanText(mixed $value): string
    {
        $text = strip_tags((string) $value);
        $text = str_replace(['ي', 'ك', "\u{200C}"], ['ی', 'ک', ' '], $text);
        return trim(preg_replace('/[\s\x{00A0}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', $text));
    }

    private function normalize(string $value): string { return mb_strtolower($this->cleanText($value)); }
    private function validHex(?string $hex): ?string { $hex = trim((string) $hex); return preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', $hex) ? $hex : null; }
}
