<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductVariant;

class SyntheticDefaultVariantClassifier
{
    public const PROVEN_SYNTHETIC = 'proven_synthetic';
    public const PROBABLE_SYNTHETIC = 'probable_synthetic';
    public const NOT_SYNTHETIC = 'not_synthetic';

    /**
     * @return array{class:string,reasons:array<int,string>,evidence:array<string,mixed>}
     */
    public function classify(ProductVariant $variant): array
    {
        $variant->loadMissing('product.category.parent');
        $product = $variant->product;

        $creationLog = ActivityLog::query()
            ->where('action', 'electric_default_color_created')
            ->where('subject_type', Product::class)
            ->where('subject_id', $product->id)
            ->orderBy('id')
            ->get()
            ->first(function (ActivityLog $log) use ($product, $variant): bool {
                $properties = $log->properties;

                return is_array($properties)
                    && isset($properties['product_id'], $properties['variant_id'])
                    && is_int($properties['product_id'])
                    && is_int($properties['variant_id'])
                    && $properties['product_id'] === (int) $product->id
                    && $properties['variant_id'] === (int) $variant->id;
            });

        if ($creationLog) {
            return [
                'class' => self::PROVEN_SYNTHETIC,
                'reasons' => ['exact_electric_default_color_created_activity'],
                'evidence' => ['activity_log_id' => (int) $creationLog->id],
            ];
        }

        $signals = [
            'electrical_category' => app(DefaultProductDesignService::class)->isElectricCategory($product->category),
            'null_model' => $variant->model_list_id === null,
            'default_color_name' => $this->isDefaultColor($variant),
            'legacy_code_shape' => $this->hasLegacyCodeShape($product, $variant),
            'manual_evidence_absent' => ! $this->hasContraryEvidence($variant),
        ];

        if (! in_array(false, $signals, true)) {
            return [
                'class' => self::PROBABLE_SYNTHETIC,
                'reasons' => array_keys($signals),
                'evidence' => $signals,
            ];
        }

        return [
            'class' => self::NOT_SYNTHETIC,
            'reasons' => collect($signals)->filter(fn (bool $matched) => ! $matched)->keys()->values()->all(),
            'evidence' => $signals,
        ];
    }

    private function isDefaultColor(ProductVariant $variant): bool
    {
        $colors = ['مشکی', 'سفید'];
        $variety = $this->normalize((string) $variant->variety_name);
        $name = $this->normalize((string) $variant->variant_name);

        return collect($colors)->contains(function (string $color) use ($variety, $name): bool {
            $color = $this->normalize($color);

            return $variety === $color || $name === $color || str_ends_with($name, ' '.$color);
        });
    }

    private function hasLegacyCodeShape(Product $product, ProductVariant $variant): bool
    {
        $varietyCode = (string) $variant->variety_code;
        if (! preg_match('/^00(?:0[1-9]|[1-9][0-9])$/', $varietyCode)) {
            return false;
        }

        return (string) $variant->variant_code
            === (string) $product->code.'000'.substr($varietyCode, -2);
    }

    private function hasContraryEvidence(ProductVariant $variant): bool
    {
        return ActivityLog::query()
            // The global observer emits a generic `created` row for automatic
            // variants too, so it is provenance-neutral rather than proof of
            // manual creation.
            ->whereNotIn('action', ['electric_default_color_created', 'created'])
            ->where(function ($query) use ($variant): void {
                $query->where(function ($subject) use ($variant): void {
                    $subject->where('subject_type', ProductVariant::class)
                        ->where('subject_id', $variant->id);
                })->orWhereJsonContains('properties->variant_id', (int) $variant->id);
            })
            ->exists();
    }

    private function normalize(string $value): string
    {
        $value = str_replace(['ي', 'ى', 'ك', "\u{200C}"], ['ی', 'ی', 'ک', ' '], $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
