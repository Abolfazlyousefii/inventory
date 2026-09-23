<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use App\Services\ProductVariantStructureService;
use App\Services\SyntheticDefaultVariantClassifier;
use App\Services\SyntheticDefaultVariantCleanupService;
use App\Services\VariantUsageAuditService;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function phaseFiveCleanupCommandFixture(bool $proven): ProductVariant
{
    $root = Category::query()->firstOrCreate(['name' => 'برقیجات'], ['code' => uniqid('CC')]);
    $productCode = '90'.str_pad((string) (Product::query()->count() + 1), 4, '0', STR_PAD_LEFT);
    $product = Product::query()->create([
        'category_id' => $root->id,
        'name' => 'Cleanup command product',
        'sku' => uniqid('CLEAN-CMD-'),
        'code' => $productCode,
        'stock' => 0,
        'reserved' => 0,
        'price' => 100,
        'is_sellable' => true,
        'models' => ['use_models' => false, 'model_list_ids' => [], 'use_designs' => false, 'design_count' => 0],
    ]);
    ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => 'Base',
        'variety_name' => '—',
        'variety_code' => '0000',
        'variant_code' => $productCode.'00000',
        'sell_price' => 100,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ]);
    $synthetic = ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => $product->name.' مشکی',
        'variety_name' => 'مشکی',
        'variety_code' => '0001',
        'variant_code' => $productCode.'00001',
        'sell_price' => 0,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ]);
    WarehouseStock::query()->create([
        'warehouse_id' => WarehouseStockService::centralWarehouseId(),
        'product_id' => $product->id,
        'product_variant_id' => $synthetic->id,
        'quantity' => 0,
    ]);
    if ($proven) {
        ActivityLog::query()->create([
            'action' => 'electric_default_color_created',
            'subject_type' => Product::class,
            'subject_id' => $product->id,
            'description' => 'proof',
            'properties' => ['product_id' => (int) $product->id, 'variant_id' => (int) $synthetic->id],
            'occurred_at' => now(),
        ]);
    }

    return $synthetic;
}

/**
 * Fails the test if the dry-run path falls back to the per-candidate fresh
 * assessment, or if --apply stops revalidating freshly under its lock.
 */
function phaseFiveBindCleanupGuard(bool $forbidFresh): void
{
    app()->bind(SyntheticDefaultVariantCleanupService::class, fn ($app) => new class($app->make(SyntheticDefaultVariantClassifier::class), $app->make(VariantUsageAuditService::class), $app->make(ProductVariantStructureService::class), $forbidFresh) extends SyntheticDefaultVariantCleanupService
    {
        public function __construct(
            SyntheticDefaultVariantClassifier $classifier,
            VariantUsageAuditService $usage,
            ProductVariantStructureService $summaries,
            private readonly bool $forbidFresh,
        ) {
            parent::__construct($classifier, $usage, $summaries);
        }

        public function assess(int $variantId, bool $explicit): array
        {
            if ($this->forbidFresh) {
                throw new RuntimeException('Dry-run must not assess candidates one by one.');
            }

            return parent::assess($variantId, $explicit);
        }

        public function assessAuditRow(int $variantId, ?array $row, bool $explicit): array
        {
            if (! $this->forbidFresh) {
                throw new RuntimeException('Apply must revalidate freshly, not from audit rows.');
            }

            return parent::assessAuditRow($variantId, $row, $explicit);
        }
    });
}

it('requires exactly one selector and both apply confirmation flags', function (): void {
    $this->artisan('inventory:cleanup-synthetic-default-variants')->assertFailed();
    $this->artisan('inventory:cleanup-synthetic-default-variants --ids=1 --all-safe')->assertFailed();
    $this->artisan('inventory:cleanup-synthetic-default-variants --ids=1 --apply')->assertFailed();
    $this->artisan('inventory:cleanup-synthetic-default-variants --ids=1 --confirm')->assertFailed();
});

it('is dry run by default and all-safe excludes probable candidates', function (): void {
    $proven = phaseFiveCleanupCommandFixture(true);
    $probable = phaseFiveCleanupCommandFixture(false);

    $this->artisan('inventory:cleanup-synthetic-default-variants --all-safe')
        ->expectsOutputToContain((string) $proven->id)
        ->expectsOutputToContain('ELIGIBLE')
        ->assertSuccessful();

    expect(ProductVariant::query()->whereKey($proven->id)->exists())->toBeTrue()
        ->and(ProductVariant::query()->whereKey($probable->id)->exists())->toBeTrue();
});

it('allows an explicitly reviewed probable id but still revalidates safety', function (): void {
    $probable = phaseFiveCleanupCommandFixture(false);

    $this->artisan('inventory:cleanup-synthetic-default-variants --ids='.$probable->id.' --apply --confirm')
        ->expectsOutputToContain('DELETED')
        ->assertSuccessful();

    expect(ProductVariant::query()->whereKey($probable->id)->exists())->toBeFalse();
});

it('reports all-safe dry-run candidates from audit rows without per-candidate fresh assessment', function (): void {
    $proven = phaseFiveCleanupCommandFixture(true);
    phaseFiveCleanupCommandFixture(false);
    phaseFiveBindCleanupGuard(forbidFresh: true);
    $activityQueries = [];
    DB::listen(function ($query) use (&$activityQueries): void {
        if (str_contains(strtolower($query->sql), 'activity_logs')) {
            $activityQueries[] = strtolower($query->sql);
        }
    });

    $this->artisan('inventory:cleanup-synthetic-default-variants --all-safe')
        ->expectsOutputToContain('id='.$proven->id)
        ->expectsOutputToContain('class='.SyntheticDefaultVariantClassifier::PROVEN_SYNTHETIC)
        ->expectsOutputToContain('selected=1')
        ->expectsOutputToContain('eligible=1')
        ->expectsOutputToContain('Dry-run only; no data was changed.')
        ->assertSuccessful();

    expect(collect($activityQueries)->filter(fn (string $sql): bool => str_contains($sql, 'json')))->toBeEmpty();
});

it('excludes proven candidates that are no longer safe from an all-safe dry run', function (): void {
    $blocked = phaseFiveCleanupCommandFixture(true);
    $blocked->forceFill(['stock' => 3])->save();
    phaseFiveBindCleanupGuard(forbidFresh: true);

    $this->artisan('inventory:cleanup-synthetic-default-variants --all-safe')
        ->expectsOutputToContain('selected=0')
        ->expectsOutputToContain('eligible=0')
        ->assertSuccessful();

    expect(ProductVariant::query()->whereKey($blocked->id)->exists())->toBeTrue();
});

it('excludes a safe probable candidate from an all-safe dry run', function (): void {
    $probable = phaseFiveCleanupCommandFixture(false);
    phaseFiveBindCleanupGuard(forbidFresh: true);

    $this->artisan('inventory:cleanup-synthetic-default-variants --all-safe')
        ->doesntExpectOutputToContain('id='.$probable->id)
        ->expectsOutputToContain('selected=0')
        ->assertSuccessful();

    expect(ProductVariant::query()->whereKey($probable->id)->exists())->toBeTrue();
});

it('dry-run assesses an explicit probable id through the scoped bulk audit', function (): void {
    $probable = phaseFiveCleanupCommandFixture(false);
    phaseFiveBindCleanupGuard(forbidFresh: true);
    $movementsBefore = DB::table('stock_movements')->count();
    $stockBefore = (int) DB::table('warehouse_stocks')->sum('quantity');

    $this->artisan('inventory:cleanup-synthetic-default-variants --ids='.$probable->id)
        ->expectsOutputToContain('id='.$probable->id)
        ->expectsOutputToContain('status=ELIGIBLE')
        ->expectsOutputToContain('class='.SyntheticDefaultVariantClassifier::PROBABLE_SYNTHETIC)
        ->expectsOutputToContain('eligible=1')
        ->assertSuccessful();

    expect(ProductVariant::query()->whereKey($probable->id)->exists())->toBeTrue()
        ->and(DB::table('stock_movements')->count())->toBe($movementsBefore)
        ->and((int) DB::table('warehouse_stocks')->sum('quantity'))->toBe($stockBefore);
});

it('blocks a non-synthetic explicit id in a dry run', function (): void {
    $synthetic = phaseFiveCleanupCommandFixture(false);
    $base = ProductVariant::query()
        ->where('product_id', $synthetic->product_id)
        ->whereKeyNot($synthetic->id)
        ->firstOrFail();
    phaseFiveBindCleanupGuard(forbidFresh: true);

    $this->artisan('inventory:cleanup-synthetic-default-variants --ids='.$base->id)
        ->expectsOutputToContain('status=SKIPPED')
        ->expectsOutputToContain('blockers=not_synthetic')
        ->expectsOutputToContain('eligible=0')
        ->assertSuccessful();
});

it('applies through fresh locked revalidation and never migrates stock history', function (): void {
    $proven = phaseFiveCleanupCommandFixture(true);
    phaseFiveBindCleanupGuard(forbidFresh: false);
    $movementsBefore = DB::table('stock_movements')->count();
    $stockBefore = (int) DB::table('warehouse_stocks')->sum('quantity');

    $this->artisan('inventory:cleanup-synthetic-default-variants --ids='.$proven->id.' --apply --confirm')
        ->expectsOutputToContain('status=DELETED')
        ->expectsOutputToContain('Apply complete.')
        ->assertSuccessful();

    expect(ProductVariant::query()->whereKey($proven->id)->exists())->toBeFalse()
        ->and(DB::table('warehouse_stocks')->where('product_variant_id', $proven->id)->count())->toBe(0)
        ->and(DB::table('stock_movements')->count())->toBe($movementsBefore)
        ->and((int) DB::table('warehouse_stocks')->sum('quantity'))->toBe($stockBefore);
});
