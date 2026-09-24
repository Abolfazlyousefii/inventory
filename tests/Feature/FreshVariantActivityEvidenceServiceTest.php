<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\FreshVariantActivityEvidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function phaseFiveFreshEvidenceVariant(): ProductVariant
{
    $category = Category::query()->create(['name' => 'Fresh '.uniqid(), 'code' => uniqid('F')]);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Fresh evidence product',
        'sku' => uniqid('FRESH-'),
        'code' => '830001',
        'stock' => 0,
        'reserved' => 0,
        'price' => 100,
        'is_sellable' => true,
    ]);

    return ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => 'Fresh',
        'variety_name' => '—',
        'variety_code' => '0000',
        'variant_code' => uniqid('FRESH-VAR-'),
        'sell_price' => 0,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ]);
}

function phaseFiveActivityRow(array $attributes): ActivityLog
{
    return ActivityLog::query()->create(array_merge([
        'action' => 'updated',
        'subject_type' => ProductVariant::class,
        'subject_id' => null,
        'description' => 'fresh evidence',
        'properties' => [],
        'occurred_at' => now(),
    ], $attributes));
}

it('detects direct ProductVariant subject activity evidence', function (): void {
    $variant = phaseFiveFreshEvidenceVariant();
    phaseFiveActivityRow(['subject_id' => $variant->id]);

    $service = app(FreshVariantActivityEvidenceService::class);

    expect($service->exists((int) $variant->id))->toBeTrue()
        ->and($service->count((int) $variant->id))->toBe(1);
});

it('detects properties variant_id activity evidence without a direct subject', function (): void {
    $variant = phaseFiveFreshEvidenceVariant();
    phaseFiveActivityRow([
        'subject_type' => Product::class,
        'subject_id' => $variant->product_id,
        'properties' => ['variant_id' => (int) $variant->id],
    ]);

    $service = app(FreshVariantActivityEvidenceService::class);

    expect($service->exists((int) $variant->id))->toBeTrue()
        ->and($service->count((int) $variant->id))->toBe(1);
});

it('ignores provenance-neutral actions and other variants', function (): void {
    $variant = phaseFiveFreshEvidenceVariant();
    $other = phaseFiveFreshEvidenceVariant();
    phaseFiveActivityRow(['action' => 'created', 'subject_id' => $variant->id]);
    phaseFiveActivityRow([
        'action' => 'electric_default_color_created',
        'subject_type' => Product::class,
        'subject_id' => $variant->product_id,
        'properties' => ['product_id' => (int) $variant->product_id, 'variant_id' => (int) $variant->id],
    ]);
    phaseFiveActivityRow(['subject_id' => $other->id]);

    $service = app(FreshVariantActivityEvidenceService::class);

    expect($service->exists((int) $variant->id))->toBeFalse()
        ->and($service->count((int) $variant->id))->toBe(0);
});

it('counts one row once when both evidence routes reference the variant', function (): void {
    $variant = phaseFiveFreshEvidenceVariant();
    phaseFiveActivityRow([
        'subject_id' => $variant->id,
        'properties' => ['variant_id' => (int) $variant->id],
    ]);

    expect(app(FreshVariantActivityEvidenceService::class)->count((int) $variant->id))->toBe(1);
});

it('never issues a JSON_CONTAINS query for fresh activity evidence', function (): void {
    $variant = phaseFiveFreshEvidenceVariant();
    phaseFiveActivityRow([
        'subject_type' => Product::class,
        'subject_id' => $variant->product_id,
        'properties' => ['variant_id' => (int) $variant->id],
    ]);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $service = app(FreshVariantActivityEvidenceService::class);
    $service->count((int) $variant->id);
    $service->exists((int) $variant->id);

    expect($queries)->not->toBeEmpty()
        ->and(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'json')))->toBeEmpty()
        ->and(file_get_contents(app_path('Services/FreshVariantActivityEvidenceService.php')))
        ->not->toContain('orWhereJsonContains');
});

it('scans activity rows in bounded chunks and stops early on the first match', function (): void {
    $variant = phaseFiveFreshEvidenceVariant();
    $rows = FreshVariantActivityEvidenceService::CHUNK_SIZE * 2 + 10;
    phaseFiveActivityRow([
        'subject_type' => Product::class,
        'subject_id' => $variant->product_id,
        'properties' => ['variant_id' => (int) $variant->id],
    ]);
    for ($index = 0; $index < $rows; $index++) {
        phaseFiveActivityRow(['subject_id' => $variant->id + 100000 + $index]);
    }
    $scanQueries = [];
    DB::listen(function ($query) use (&$scanQueries): void {
        if (str_contains(strtolower($query->sql), 'activity_logs')
            && str_contains(strtolower($query->sql), 'limit')) {
            $scanQueries[] = $query->sql;
        }
    });

    $service = app(FreshVariantActivityEvidenceService::class);
    expect($service->exists((int) $variant->id))->toBeTrue();
    $earlyStopChunks = count($scanQueries);
    $service->count((int) $variant->id);
    $fullScanChunks = count($scanQueries) - $earlyStopChunks;

    // The match sits in the first chunk: exists() stops there, count() must
    // still walk every bounded chunk to the end.
    expect($earlyStopChunks)->toBe(1)
        ->and($fullScanChunks)->toBeGreaterThanOrEqual((int) ceil($rows / FreshVariantActivityEvidenceService::CHUNK_SIZE));
});
