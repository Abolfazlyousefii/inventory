<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\SyntheticDefaultVariantClassifier;
use App\Services\SyntheticDefaultVariantAuditService;
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
