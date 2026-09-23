<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\SyntheticDefaultVariantAuditService;
use App\Services\SyntheticDefaultVariantClassifier;
use App\Services\SyntheticDefaultVariantEvidenceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function phaseFiveAuditCandidate(string $color = 'مشکی', string $suffix = '01'): ProductVariant
{
    $root = Category::query()->firstOrCreate(['name' => 'برقیجات'], ['code' => '81']);
    $category = Category::query()->create(['name' => 'Audit child '.uniqid(), 'code' => uniqid('A'), 'parent_id' => $root->id]);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Audit product',
        'sku' => uniqid('AUDIT-'),
        'code' => '81'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
        'stock' => 0,
        'reserved' => 0,
        'price' => 100,
        'is_sellable' => true,
    ]);

    return ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => $product->name.' '.$color,
        'variety_name' => $color,
        'variety_code' => '00'.$suffix,
        'variant_code' => $product->code.'000'.$suffix,
        'sell_price' => 0,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ]);
}

function phaseFiveAuditProof(ProductVariant $variant): void
{
    ActivityLog::query()->create([
        'action' => 'electric_default_color_created',
        'subject_type' => Product::class,
        'subject_id' => $variant->product_id,
        'description' => 'exact legacy proof',
        'properties' => ['product_id' => (int) $variant->product_id, 'variant_id' => (int) $variant->id],
        'occurred_at' => now(),
    ]);
}

it('reports proven probable safe and protected synthetic candidates with required columns', function (): void {
    $safe = phaseFiveAuditCandidate();
    phaseFiveAuditProof($safe);
    $probable = phaseFiveAuditCandidate('سفید', '02');
    $protected = phaseFiveAuditCandidate();
    phaseFiveAuditProof($protected);
    $protected->forceFill(['stock' => 4])->save();

    $rows = app(SyntheticDefaultVariantAuditService::class)->rows();
    $summary = app(SyntheticDefaultVariantAuditService::class)->summary($rows);
    expect($summary)->toMatchArray([
        'proven' => 2,
        'probable' => 1,
        'safe' => 2,
        'stock_protected' => 1,
    ]);

    $this->artisan('inventory:audit-synthetic-default-variants')
        ->expectsOutputToContain('product_id')
        ->expectsOutputToContain(SyntheticDefaultVariantClassifier::PROVEN_SYNTHETIC)
        ->expectsOutputToContain(SyntheticDefaultVariantClassifier::PROBABLE_SYNTHETIC)
        ->expectsOutputToContain('proven=2')
        ->expectsOutputToContain('probable=1')
        ->expectsOutputToContain('safe=2')
        ->expectsOutputToContain('stock_protected=1')
        ->expectsOutputToContain('Read-only audit complete; no data was changed.')
        ->assertSuccessful();
});

it('performs no writes while auditing', function (): void {
    $variant = phaseFiveAuditCandidate();
    ActivityLog::query()->where('subject_type', Product::class)->count();
    phaseFiveAuditProof($variant);
    $before = [
        'products' => DB::table('products')->orderBy('id')->get()->toJson(),
        'variants' => DB::table('product_variants')->orderBy('id')->get()->toJson(),
        'activities' => DB::table('activity_logs')->orderBy('id')->get()->toJson(),
    ];
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->artisan('inventory:audit-synthetic-default-variants')->assertSuccessful();

    expect(collect($queries)->contains(fn (string $sql): bool => (bool) preg_match('/^\s*(insert|update|delete|replace|truncate|alter|drop|create|rename)\b/i', $sql)))->toBeFalse()
        ->and(DB::table('products')->orderBy('id')->get()->toJson())->toBe($before['products'])
        ->and(DB::table('product_variants')->orderBy('id')->get()->toJson())->toBe($before['variants'])
        ->and(DB::table('activity_logs')->orderBy('id')->get()->toJson())->toBe($before['activities']);
});

it('loads exact chunk evidence and deduplicates activity rows across reference routes', function (): void {
    $variant = phaseFiveAuditCandidate();
    $exact = ActivityLog::query()->create([
        'action' => 'electric_default_color_created',
        'subject_type' => Product::class,
        'subject_id' => $variant->product_id,
        'description' => 'exact proof',
        'properties' => ['product_id' => (int) $variant->product_id, 'variant_id' => (int) $variant->id],
        'occurred_at' => now(),
    ]);
    ActivityLog::query()->create([
        'action' => 'electric_default_color_created',
        'subject_type' => Product::class,
        'subject_id' => $variant->product_id,
        'description' => 'wrong product property',
        'properties' => ['product_id' => 999999, 'variant_id' => (int) $variant->id],
        'occurred_at' => now(),
    ]);
    ActivityLog::query()->create([
        'action' => 'electric_default_color_created',
        'subject_type' => Product::class,
        'subject_id' => $variant->product_id,
        'description' => 'wrong variant property',
        'properties' => ['product_id' => (int) $variant->product_id, 'variant_id' => 999999],
        'occurred_at' => now(),
    ]);
    ActivityLog::query()->create([
        'action' => 'electric_default_color_created',
        'subject_type' => Product::class,
        'subject_id' => $variant->product_id,
        'description' => 'string identifiers',
        'properties' => ['product_id' => (string) $variant->product_id, 'variant_id' => (string) $variant->id],
        'occurred_at' => now(),
    ]);
    ActivityLog::query()->create([
        'action' => 'updated',
        'subject_type' => ProductVariant::class,
        'subject_id' => $variant->id,
        'description' => 'direct only',
        'properties' => [],
        'occurred_at' => now(),
    ]);
    ActivityLog::query()->create([
        'action' => 'updated',
        'subject_type' => Product::class,
        'subject_id' => $variant->product_id,
        'description' => 'property only',
        'properties' => ['variant_id' => (int) $variant->id],
        'occurred_at' => now(),
    ]);
    ActivityLog::query()->create([
        'action' => 'updated',
        'subject_type' => ProductVariant::class,
        'subject_id' => $variant->id,
        'description' => 'both routes',
        'properties' => ['variant_id' => (int) $variant->id],
        'occurred_at' => now(),
    ]);
    ActivityLog::query()->create([
        'action' => 'created',
        'subject_type' => ProductVariant::class,
        'subject_id' => $variant->id,
        'description' => 'neutral observer row',
        'properties' => ['variant_id' => (int) $variant->id],
        'occurred_at' => now(),
    ]);
    DB::table('activity_logs')->insert([
        'action' => 'electric_default_color_created',
        'subject_type' => Product::class,
        'subject_id' => $variant->product_id,
        'description' => 'malformed properties',
        'properties' => '{broken-json',
        'occurred_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $snapshot = app(SyntheticDefaultVariantEvidenceService::class)->load(collect([$variant]));

    expect($snapshot->creationLogId((int) $variant->product_id, (int) $variant->id))->toBe((int) $exact->id)
        ->and($snapshot->hasContraryEvidence((int) $variant->id))->toBeTrue()
        ->and($snapshot->activityReferenceCount((int) $variant->id))->toBe(3);
});

it('rejects each invalid proof independently in a complete snapshot', function (array|string $properties): void {
    $variant = phaseFiveAuditCandidate();
    if (is_string($properties)) {
        DB::table('activity_logs')->insert([
            'action' => 'electric_default_color_created',
            'subject_type' => Product::class,
            'subject_id' => $variant->product_id,
            'description' => 'invalid proof',
            'properties' => $properties,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } else {
        ActivityLog::query()->create([
            'action' => 'electric_default_color_created',
            'subject_type' => Product::class,
            'subject_id' => $variant->product_id,
            'description' => 'invalid proof',
            'properties' => array_merge([
                'product_id' => (int) $variant->product_id,
                'variant_id' => (int) $variant->id,
            ], $properties),
            'occurred_at' => now(),
        ]);
    }

    $snapshot = app(SyntheticDefaultVariantEvidenceService::class)->load(collect([$variant]));

    expect($snapshot->creationLogId((int) $variant->product_id, (int) $variant->id))->toBeNull();
})->with([
    'wrong product' => [['product_id' => 999999]],
    'wrong variant' => [['variant_id' => 999999]],
    'string identifiers' => [['product_id' => '1', 'variant_id' => '1']],
    'malformed json' => ['{broken-json'],
]);

it('rejects snapshot lookups outside the loaded variant scope', function (): void {
    $loaded = phaseFiveAuditCandidate();
    $outside = phaseFiveAuditCandidate();
    $snapshot = app(SyntheticDefaultVariantEvidenceService::class)->load(collect([$loaded]));

    expect(fn () => $snapshot->activityReferenceCount((int) $outside->id))
        ->toThrow(LogicException::class, 'outside the complete evidence snapshot scope')
        ->and(fn () => app(SyntheticDefaultVariantClassifier::class)->classifyWithEvidence($outside, $snapshot))
        ->toThrow(LogicException::class, 'outside the complete evidence snapshot scope');
});

it('fails closed when an activity chunk scan reports incomplete', function (): void {
    $variant = phaseFiveAuditCandidate();
    $loader = new class extends SyntheticDefaultVariantEvidenceService
    {
        protected function scanQuery(Builder $query, Closure $consume): bool
        {
            return false;
        }
    };

    expect(fn () => $loader->load(collect([$variant])))
        ->toThrow(RuntimeException::class, 'ActivityLog evidence scan did not complete');
});

it('loads every activity reference across an activity chunk boundary', function (): void {
    $variant = phaseFiveAuditCandidate();
    foreach (range(1, 501) as $index) {
        ActivityLog::query()->create([
            'action' => 'updated',
            'subject_type' => Product::class,
            'subject_id' => $variant->product_id,
            'description' => 'chunk boundary '.$index,
            'properties' => ['variant_id' => (int) $variant->id],
            'occurred_at' => now(),
        ]);
    }

    $snapshot = app(SyntheticDefaultVariantEvidenceService::class)->load(collect([$variant]));

    expect($snapshot->activityReferenceCount((int) $variant->id))->toBe(501);
});

it('keeps activity log query growth bounded as the variant chunk grows', function (): void {
    $first = phaseFiveAuditCandidate();
    $variants = collect([$first]);
    foreach (range(2, 100) as $index) {
        $suffix = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
        $variants->push(ProductVariant::query()->create([
            'product_id' => $first->product_id,
            'variant_name' => 'Bulk '.$index,
            'variety_name' => 'Bulk',
            'variety_code' => str_pad((string) $index, 4, '0', STR_PAD_LEFT),
            'variant_code' => $first->product->code.'9'.$suffix,
            'sell_price' => 0,
            'stock' => 0,
            'reserved' => 0,
            'is_active' => true,
            'sales_enabled' => true,
        ]));
    }
    ActivityLog::query()->create([
        'action' => 'updated',
        'subject_type' => Product::class,
        'subject_id' => $first->product_id,
        'description' => 'bounded scan fixture',
        'properties' => ['variant_id' => (int) $first->id],
        'occurred_at' => now(),
    ]);

    $activityQueries = [];
    DB::listen(function ($query) use (&$activityQueries): void {
        if (str_contains(strtolower($query->sql), 'activity_logs')) {
            $activityQueries[] = $query->sql;
        }
    });
    app(SyntheticDefaultVariantEvidenceService::class)->load($variants->take(10));
    $tenCount = count($activityQueries);
    $activityQueries = [];
    app(SyntheticDefaultVariantEvidenceService::class)->load($variants);
    $hundredCount = count($activityQueries);

    expect($tenCount)->toBe(2)
        ->and($hundredCount)->toBe(2);
});

it('fails explicitly when an activity evidence query aborts', function (): void {
    $variant = phaseFiveAuditCandidate();
    phaseFiveAuditProof($variant);
    $activityQueries = 0;
    DB::listen(function ($query) use (&$activityQueries): void {
        if (str_contains(strtolower($query->sql), 'activity_logs') && ++$activityQueries === 2) {
            throw new RuntimeException('simulated activity evidence scan failure');
        }
    });

    expect(fn () => app(SyntheticDefaultVariantAuditService::class)->rows(null, (int) $variant->id))
        ->toThrow(RuntimeException::class, 'simulated activity evidence scan failure');
});

it('summarizes missing bases with bounded product and variant lookups', function (): void {
    foreach (range(1, 12) as $index) {
        $variant = phaseFiveAuditCandidate();
        $variant->product->forceFill(['models' => [
            'use_models' => false,
            'model_list_ids' => [],
            'use_designs' => false,
            'design_count' => 0,
        ]])->save();
        phaseFiveAuditProof($variant);
    }
    $audit = app(SyntheticDefaultVariantAuditService::class);
    $rows = $audit->rows();
    $summaryQueries = [];
    DB::listen(function ($query) use (&$summaryQueries): void {
        $sql = strtolower($query->sql);
        if (str_starts_with(ltrim($sql), 'select')
            && (str_contains($sql, 'products') || str_contains($sql, 'product_variants'))) {
            $summaryQueries[] = $query->sql;
        }
    });

    $summary = $audit->summary($rows);

    expect($summary['missing_base'])->toBe(12)
        ->and(count($summaryQueries))->toBeLessThanOrEqual(5);
});

it('keeps full-audit activity queries bounded from ten to one hundred variants', function (): void {
    $makeProductChunk = function (int $count): Product {
        $first = phaseFiveAuditCandidate();
        phaseFiveAuditProof($first);
        foreach (range(2, $count) as $index) {
            $code = str_pad((string) $index, 4, '0', STR_PAD_LEFT);
            $variant = ProductVariant::query()->create([
                'product_id' => $first->product_id,
                'variant_name' => 'Proven bulk '.$index,
                'variety_name' => 'Bulk',
                'variety_code' => $code,
                'variant_code' => $first->product->code.$code.'0',
                'sell_price' => 0,
                'stock' => 0,
                'reserved' => 0,
                'is_active' => true,
                'sales_enabled' => true,
            ]);
            phaseFiveAuditProof($variant);
        }

        return $first->product;
    };
    $tenProduct = $makeProductChunk(10);
    $hundredProduct = $makeProductChunk(100);
    $activityQueries = [];
    DB::listen(function ($query) use (&$activityQueries): void {
        if (str_contains(strtolower($query->sql), 'activity_logs')) {
            $activityQueries[] = $query->sql;
        }
    });
    $audit = app(SyntheticDefaultVariantAuditService::class);

    expect($audit->rows((int) $tenProduct->id))->toHaveCount(10);
    $tenCount = count($activityQueries);
    $activityQueries = [];
    expect($audit->rows((int) $hundredProduct->id))->toHaveCount(100);
    $hundredCount = count($activityQueries);

    expect($tenCount)->toBeGreaterThan(0)
        ->and($hundredCount)->toBeLessThanOrEqual($tenCount + 2);
});

it('does not restore per-variant activity queries for non-proven classification', function (): void {
    $first = phaseFiveAuditCandidate();
    foreach (range(2, 100) as $index) {
        $code = $index <= 99 ? str_pad((string) $index, 4, '0', STR_PAD_LEFT) : '0100';
        ProductVariant::query()->create([
            'product_id' => $first->product_id,
            'variant_name' => $first->product->name.' مشکی',
            'variety_name' => 'مشکی',
            'variety_code' => $code,
            'variant_code' => $first->product->code.'000'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'sell_price' => 0,
            'stock' => 0,
            'reserved' => 0,
            'is_active' => true,
            'sales_enabled' => true,
        ]);
    }
    $activityQueries = [];
    DB::listen(function ($query) use (&$activityQueries): void {
        if (str_contains(strtolower($query->sql), 'activity_logs')) {
            $activityQueries[] = $query->sql;
        }
    });

    app(SyntheticDefaultVariantAuditService::class)->rows((int) $first->product_id);

    expect(count($activityQueries))->toBeLessThanOrEqual(4);
});
