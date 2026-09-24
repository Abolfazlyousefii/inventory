<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\ModelList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\SyntheticDefaultVariantClassifier;
use App\Services\SyntheticDefaultVariantEvidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function phaseFiveClassifierVariant(array $variantAttributes = [], bool $electrical = true): ProductVariant
{
    if (($variantAttributes['model_list_id'] ?? null) === -1) {
        $variantAttributes['model_list_id'] = ModelList::query()->create([
            'brand' => 'Phase 5',
            'model_name' => 'Explicit',
            'code' => uniqid('M'),
        ])->id;
    }
    $parent = $electrical
        ? Category::query()->firstOrCreate(['name' => 'برقیجات'], ['code' => uniqid('C')])
        : Category::query()->create(['name' => 'Unrelated root '.uniqid(), 'code' => uniqid('C')]);
    $category = Category::query()->create([
        'name' => 'Classifier child '.uniqid(),
        'code' => uniqid('D'),
        'parent_id' => $parent->id,
    ]);
    $productCode = '81'.str_pad((string) (Product::query()->count() + 1), 4, '0', STR_PAD_LEFT);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Classifier product',
        'sku' => uniqid('CLASS-'),
        'code' => $productCode,
        'stock' => 0,
        'reserved' => 0,
        'price' => 100,
        'is_sellable' => true,
    ]);

    return ProductVariant::query()->create(array_merge([
        'product_id' => $product->id,
        'model_list_id' => null,
        'variant_name' => 'Classifier product مشکی',
        'variety_name' => 'مشکی',
        'variety_code' => '0001',
        'variant_code' => $productCode.'00001',
        'sell_price' => 0,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ], $variantAttributes));
}

function phaseFiveDefaultCreationLog(ProductVariant $variant, array $properties = []): ActivityLog
{
    return ActivityLog::query()->create([
        'action' => 'electric_default_color_created',
        'subject_type' => Product::class,
        'subject_id' => $variant->product_id,
        'description' => 'legacy automatic default color',
        'properties' => array_merge([
            'product_id' => (int) $variant->product_id,
            'variant_id' => (int) $variant->id,
        ], $properties),
        'occurred_at' => now(),
    ]);
}

it('requires an exact product and variant match for proven synthetic evidence', function (): void {
    $variant = phaseFiveClassifierVariant([], false);
    phaseFiveDefaultCreationLog($variant);

    $result = app(SyntheticDefaultVariantClassifier::class)->classify($variant);

    expect($result['class'])->toBe(SyntheticDefaultVariantClassifier::PROVEN_SYNTHETIC)
        ->and($result['evidence']['activity_log_id'])->toBeInt();
});

it('does not accept wrong or malformed activity properties as proof', function (array $properties): void {
    $variant = phaseFiveClassifierVariant([], false);
    phaseFiveDefaultCreationLog($variant, $properties);

    expect(app(SyntheticDefaultVariantClassifier::class)->classify($variant)['class'])
        ->toBe(SyntheticDefaultVariantClassifier::NOT_SYNTHETIC);
})->with([
    'wrong product' => [['product_id' => 999999]],
    'wrong variant' => [['variant_id' => 999999]],
    'string identifiers' => [['product_id' => '1', 'variant_id' => '1']],
]);

it('classifies only the complete legacy electrical black-white shape as probable', function (): void {
    $variant = phaseFiveClassifierVariant();
    $result = app(SyntheticDefaultVariantClassifier::class)->classify($variant);

    expect($result['evidence'])->toBe([
        'electrical_category' => true,
        'null_model' => true,
        'default_color_name' => true,
        'legacy_code_shape' => true,
        'manual_evidence_absent' => true,
    ])->and($result['class'])->toBe(SyntheticDefaultVariantClassifier::PROBABLE_SYNTHETIC);
});

it('fails closed when probable evidence is incomplete or contrary', function (array $attributes, bool $electrical, bool $manualLog): void {
    $variant = phaseFiveClassifierVariant($attributes, $electrical);
    if ($manualLog) {
        ActivityLog::query()->create([
            'action' => 'product_variant_created',
            'subject_type' => ProductVariant::class,
            'subject_id' => $variant->id,
            'description' => 'manual creation evidence',
            'properties' => ['variant_id' => (int) $variant->id],
            'occurred_at' => now(),
        ]);
    }

    expect(app(SyntheticDefaultVariantClassifier::class)->classify($variant)['class'])
        ->toBe(SyntheticDefaultVariantClassifier::NOT_SYNTHETIC);
})->with([
    'non electrical' => [[], false, false],
    'explicit model' => [['model_list_id' => -1], true, false],
    'arbitrary color' => [['variant_name' => 'Classifier blue', 'variety_name' => 'آبی'], true, false],
    'wrong legacy code' => [['variant_code' => '81000199901'], true, false],
    'manual evidence' => [[], true, true],
]);

it('does not mutate any classified record', function (): void {
    $variant = phaseFiveClassifierVariant();
    $before = [
        'product' => $variant->product->fresh()->getRawOriginal(),
        'variant' => $variant->fresh()->getRawOriginal(),
        'activities' => ActivityLog::query()->orderBy('id')->get()->toArray(),
    ];

    app(SyntheticDefaultVariantClassifier::class)->classify($variant);

    expect($variant->product->fresh()->getRawOriginal())->toBe($before['product'])
        ->and($variant->fresh()->getRawOriginal())->toBe($before['variant'])
        ->and(ActivityLog::query()->orderBy('id')->get()->toArray())->toBe($before['activities']);
});

it('matches fresh classification when complete chunk evidence is supplied explicitly', function (): void {
    $proven = phaseFiveClassifierVariant([], false);
    phaseFiveDefaultCreationLog($proven);
    $probable = phaseFiveClassifierVariant();
    ActivityLog::query()->create([
        'action' => 'created',
        'subject_type' => ProductVariant::class,
        'subject_id' => $probable->id,
        'description' => 'neutral observer row',
        'properties' => ['variant_id' => (int) $probable->id],
        'occurred_at' => now(),
    ]);
    $directContrary = phaseFiveClassifierVariant();
    ActivityLog::query()->create([
        'action' => 'updated',
        'subject_type' => ProductVariant::class,
        'subject_id' => $directContrary->id,
        'description' => 'direct contrary evidence',
        'properties' => [],
        'occurred_at' => now(),
    ]);
    $propertyContrary = phaseFiveClassifierVariant();
    ActivityLog::query()->create([
        'action' => 'updated',
        'subject_type' => Product::class,
        'subject_id' => $propertyContrary->product_id,
        'description' => 'property contrary evidence',
        'properties' => ['variant_id' => (int) $propertyContrary->id],
        'occurred_at' => now(),
    ]);
    $variants = collect([$proven, $probable, $directContrary, $propertyContrary]);
    $snapshot = app(SyntheticDefaultVariantEvidenceService::class)->load($variants);
    $classifier = app(SyntheticDefaultVariantClassifier::class);

    foreach ($variants as $variant) {
        expect($classifier->classifyWithEvidence($variant, $snapshot))
            ->toBe($classifier->classify($variant));
    }
});
