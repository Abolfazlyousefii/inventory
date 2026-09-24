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

    public function __construct(
        private readonly FreshVariantActivityEvidenceService $freshActivity,
    ) {}

    /**
     * @return array{class:string,reasons:array<int,string>,evidence:array<string,mixed>}
     */
    public function classify(ProductVariant $variant): array
    {
        $variant->loadMissing('product.category.parent');

        $creationLogId = $this->creationLogId($variant);
        if ($creationLogId !== null) {
            return $this->proven($creationLogId);
        }

        return $this->classifySignals($variant, ! $this->hasContraryEvidence($variant));
    }

    /**
     * Cheap necessary-condition pre-check for hot paths such as purchasing.
     *
     * false guarantees classify() would return NOT_SYNTHETIC, so callers can
     * skip classify() and its bounded-but-full ActivityLog scan. true only
     * means classify() must be consulted for the exact answer.
     */
    public function mayBeSynthetic(ProductVariant $variant): bool
    {
        $variant->loadMissing('product');

        if ($variant->model_list_id === null
            && $this->isDefaultColor($variant)
            && $this->hasLegacyCodeShape($variant->product, $variant)) {
            return true;
        }

        return $this->creationLogId($variant) !== null;
    }

    private function creationLogId(ProductVariant $variant): ?int
    {
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

        return $creationLog ? (int) $creationLog->id : null;
    }

    /**
     * Audit-only classification from a complete evidence snapshot.
     *
     * @return array{class:string,reasons:array<int,string>,evidence:array<string,mixed>}
     */
    public function classifyWithEvidence(
        ProductVariant $variant,
        SyntheticDefaultVariantEvidenceSnapshot $evidence,
    ): array {
        $variant->loadMissing('product.category.parent');
        $creationLogId = $evidence->creationLogId((int) $variant->product_id, (int) $variant->id);
        if ($creationLogId !== null) {
            return $this->proven($creationLogId);
        }

        return $this->classifySignals($variant, ! $evidence->hasContraryEvidence((int) $variant->id));
    }

    /** @return array{class:string,reasons:array<int,string>,evidence:array<string,mixed>} */
    private function proven(int $activityLogId): array
    {
        return [
            'class' => self::PROVEN_SYNTHETIC,
            'reasons' => ['exact_electric_default_color_created_activity'],
            'evidence' => ['activity_log_id' => $activityLogId],
        ];
    }

    /** @return array{class:string,reasons:array<int,string>,evidence:array<string,mixed>} */
    private function classifySignals(ProductVariant $variant, bool $manualEvidenceAbsent): array
    {
        $product = $variant->product;

        $signals = [
            'electrical_category' => app(DefaultProductDesignService::class)->isElectricCategory($product->category),
            'null_model' => $variant->model_list_id === null,
            'default_color_name' => $this->isDefaultColor($variant),
            'legacy_code_shape' => $this->hasLegacyCodeShape($product, $variant),
            'manual_evidence_absent' => $manualEvidenceAbsent,
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
        // Bounded, chunked scan over provenance-neutral-excluded activity rows;
        // a single JSON_CONTAINS scan cannot survive a production ActivityLog.
        return $this->freshActivity->exists((int) $variant->id);
    }

    private function normalize(string $value): string
    {
        $value = str_replace(['ي', 'ى', 'ك', "\u{200C}"], ['ی', 'ی', 'ک', ' '], $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
