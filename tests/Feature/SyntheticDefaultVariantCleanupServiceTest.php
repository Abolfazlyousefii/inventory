<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\SyntheticDefaultVariantCleanupService;
use App\Services\WarehouseStockService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function phaseFiveCleanupFixture(bool $proven = true): array
{
    $root = Category::query()->firstOrCreate(['name' => 'برقیجات'], ['code' => uniqid('C')]);
    $productCode = '89'.str_pad((string) (Product::query()->count() + 1), 4, '0', STR_PAD_LEFT);
    $product = Product::query()->create([
        'category_id' => $root->id,
        'name' => 'Cleanup product',
        'sku' => uniqid('CLEAN-'),
        'code' => $productCode,
        'stock' => 0,
        'reserved' => 0,
        'price' => 100,
        'is_sellable' => true,
        'models' => ['use_models' => false, 'model_list_ids' => [], 'use_designs' => false, 'design_count' => 0],
    ]);
    $base = ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => 'Cleanup base',
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
        'variant_name' => 'Cleanup product مشکی',
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

    return compact('product', 'base', 'synthetic');
}

function phaseFiveInsertMinimalVariantReference(string $table, string $column, ProductVariant $variant): array
{
    DB::statement('PRAGMA defer_foreign_keys = ON');
    $values = [];

    foreach (DB::select('PRAGMA table_info("'.str_replace('"', '""', $table).'")') as $definition) {
        $name = (string) $definition->name;
        if ((int) $definition->pk === 1 || ((int) $definition->notnull === 0 && $name !== $column)) {
            continue;
        }
        if ($definition->dflt_value !== null && $name !== $column) {
            continue;
        }

        $values[$name] = match (true) {
            $name === $column => (int) $variant->id,
            $name === 'product_id' => (int) $variant->product_id,
            $name === 'deactivation_type' => 'variant',
            $name === 'created_at', $name === 'updated_at', str_ends_with($name, '_at'), str_ends_with($name, '_date') => now()->toDateTimeString(),
            str_ends_with($name, '_id') => 1,
            str_contains(strtoupper((string) $definition->type), 'INT') => 1,
            str_contains(strtoupper((string) $definition->type), 'REAL'), str_contains(strtoupper((string) $definition->type), 'DECIMAL') => 1,
            default => 'test',
        };
    }

    $values[$column] = (int) $variant->id;
    DB::table($table)->insert($values);

    return $values;
}

it('deletes only a locked revalidated proven safe variant and its zero warehouse row', function (): void {
    ['product' => $product, 'base' => $base, 'synthetic' => $synthetic] = phaseFiveCleanupFixture();
    $before = [
        'warehouse_total' => (int) WarehouseStock::query()->sum('quantity'),
        'movements' => DB::table('stock_movements')->count(),
        'activities' => DB::table('activity_logs')->orderBy('id')->get()->toJson(),
        'base' => $base->fresh()->getRawOriginal(),
        'is_sellable' => $product->is_sellable,
    ];

    $result = app(SyntheticDefaultVariantCleanupService::class)->cleanup($synthetic->id, false);

    expect($result['status'])->toBe('DELETED')
        ->and(ProductVariant::query()->whereKey($synthetic->id)->exists())->toBeFalse()
        ->and(WarehouseStock::query()->where('product_variant_id', $synthetic->id)->exists())->toBeFalse()
        ->and((int) WarehouseStock::query()->sum('quantity'))->toBe($before['warehouse_total'])
        ->and(DB::table('stock_movements')->count())->toBe($before['movements'])
        ->and(DB::table('activity_logs')->orderBy('id')->get()->toJson())->toBe($before['activities'])
        ->and($base->fresh()->getRawOriginal())->toBe($before['base'])
        ->and($product->fresh()->price)->toBe(100)
        ->and($product->fresh()->is_sellable)->toBe($before['is_sellable']);
});

it('requires explicit review for probable variants', function (): void {
    ['synthetic' => $synthetic] = phaseFiveCleanupFixture(false);
    $service = app(SyntheticDefaultVariantCleanupService::class);

    expect($service->cleanup($synthetic->id, false)['status'])->toBe('SKIPPED')
        ->and(ProductVariant::query()->whereKey($synthetic->id)->exists())->toBeTrue()
        ->and($service->cleanup($synthetic->id, true)['status'])->toBe('DELETED');
});

it('re-audits while locked and skips a candidate that becomes referenced', function (): void {
    ['synthetic' => $synthetic] = phaseFiveCleanupFixture();
    Schema::create('phase_five_cleanup_race_refs', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('product_variant_id');
    });

    $result = app(SyntheticDefaultVariantCleanupService::class)->cleanup(
        $synthetic->id,
        false,
        fn () => DB::table('phase_five_cleanup_race_refs')->insert(['product_variant_id' => $synthetic->id]),
    );

    expect($result['status'])->toBe('SKIPPED')
        ->and($result['blocking_reasons'])->toContain('unknown_reference:phase_five_cleanup_race_refs.product_variant_id')
        ->and(ProductVariant::query()->whereKey($synthetic->id)->exists())->toBeTrue();
});

it('is idempotent after deletion', function (): void {
    ['synthetic' => $synthetic] = phaseFiveCleanupFixture();
    $service = app(SyntheticDefaultVariantCleanupService::class);
    expect($service->cleanup($synthetic->id, false)['status'])->toBe('DELETED')
        ->and($service->cleanup($synthetic->id, false)['status'])->toBe('SKIPPED');
});

it('preserves every registered business-history category', function (string $table, string $column): void {
    ['synthetic' => $synthetic] = phaseFiveCleanupFixture();
    $reference = phaseFiveInsertMinimalVariantReference($table, $column, $synthetic);
    $stocksBefore = DB::table('warehouse_stocks')->orderBy('id')->get()->toJson();
    $movementsBefore = DB::table('stock_movements')->count();

    $result = app(SyntheticDefaultVariantCleanupService::class)->cleanup($synthetic->id, false);

    expect($result['status'])->toBe('SKIPPED')
        ->and(ProductVariant::query()->whereKey($synthetic->id)->exists())->toBeTrue()
        ->and(DB::table($table)->where($column, $synthetic->id)->exists())->toBeTrue()
        ->and(DB::table('warehouse_stocks')->orderBy('id')->get()->toJson())->toBe($stocksBefore)
        ->and(DB::table('stock_movements')->count())->toBe($movementsBefore);
})->with([
    'purchase' => ['purchase_items', 'product_variant_id'],
    'invoice' => ['invoice_items', 'variant_id'],
    'preinvoice' => ['preinvoice_order_items', 'variant_id'],
    'reservation' => ['preinvoice_draft_reservations', 'variant_id'],
    'stock movement' => ['stock_movements', 'product_variant_id'],
    'warehouse transfer' => ['warehouse_transfer_items', 'product_variant_id'],
    'stock count' => ['stock_count_document_items', 'product_variant_id'],
    'warehouse location stock' => ['warehouse_location_stocks', 'product_variant_id'],
    'warehouse location movement' => ['warehouse_location_movements', 'product_variant_id'],
    'warehouse review history' => ['warehouse_review_item_logs', 'product_variant_id'],
    'inbound receipt' => ['warehouse_inbound_receipt_items', 'product_variant_id'],
    'sales return source' => ['sales_return_document_items', 'product_variant_id'],
    'sales return created variant' => ['sales_return_document_items', 'created_variant_id'],
    'price change' => ['price_change_document_items', 'product_variant_id'],
    'product deactivation document' => ['product_deactivation_documents', 'variant_id'],
    'product deactivation item' => ['product_deactivation_document_items', 'variant_id'],
    'invoice collection revision' => ['invoice_collection_revision_items', 'product_variant_id'],
    'commission campaign' => ['commission_campaign_targets', 'product_variant_id'],
    'commission revision' => ['commission_rate_revisions', 'product_variant_id'],
    'commission ledger' => ['commission_ledger_entries', 'product_variant_id'],
    'seller sales history' => ['seller_sales_document_items', 'product_variant_id'],
]);

it('preserves external mappings and unknown discovered history', function (string $case): void {
    ['synthetic' => $synthetic] = phaseFiveCleanupFixture();
    if ($case === 'external mapping') {
        $synthetic->forceFill(['variety_id' => 991])->save();
    } else {
        Schema::create('phase_five_unknown_cleanup_refs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_variant_id');
        });
        DB::table('phase_five_unknown_cleanup_refs')->insert(['product_variant_id' => $synthetic->id]);
    }
    $stocksBefore = DB::table('warehouse_stocks')->orderBy('id')->get()->toJson();
    $movementsBefore = DB::table('stock_movements')->count();

    app(SyntheticDefaultVariantCleanupService::class)->cleanup($synthetic->id, false);

    expect(ProductVariant::query()->whereKey($synthetic->id)->exists())->toBeTrue()
        ->and(DB::table('warehouse_stocks')->orderBy('id')->get()->toJson())->toBe($stocksBefore)
        ->and(DB::table('stock_movements')->count())->toBe($movementsBefore);
    if ($case === 'unknown reference') {
        expect(DB::table('phase_five_unknown_cleanup_refs')->where('product_variant_id', $synthetic->id)->exists())->toBeTrue();
    }
})->with(['external mapping', 'unknown reference']);

it('preserves every nondeleted warehouse row and never nets nonzero rows to safety', function (): void {
    ['product' => $product, 'synthetic' => $synthetic] = phaseFiveCleanupFixture();
    $other = ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => 'Other stock owner',
        'variety_name' => '—',
        'variety_code' => '0099',
        'variant_code' => $product->code.'00099',
        'sell_price' => 100,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ]);
    $secondWarehouse = Warehouse::query()->create(['name' => 'Phase 5 secondary', 'type' => 'branch', 'is_active' => true]);
    WarehouseStock::query()->create(['warehouse_id' => WarehouseStockService::centralWarehouseId(), 'product_id' => $product->id, 'product_variant_id' => $other->id, 'quantity' => 5]);
    WarehouseStock::query()->create(['warehouse_id' => $secondWarehouse->id, 'product_id' => $product->id, 'product_variant_id' => $other->id, 'quantity' => -5]);
    $preservedBefore = DB::table('warehouse_stocks')->where('product_variant_id', '<>', $synthetic->id)->orderBy('id')->get()->toJson();
    $totalBefore = (int) DB::table('warehouse_stocks')->sum('quantity');
    $movementsBefore = DB::table('stock_movements')->count();

    expect(app(SyntheticDefaultVariantCleanupService::class)->cleanup($synthetic->id, false)['status'])->toBe('DELETED')
        ->and(DB::table('warehouse_stocks')->where('product_variant_id', '<>', $synthetic->id)->orderBy('id')->get()->toJson())->toBe($preservedBefore)
        ->and((int) DB::table('warehouse_stocks')->sum('quantity'))->toBe($totalBefore)
        ->and(DB::table('stock_movements')->count())->toBe($movementsBefore);

    ['synthetic' => $netZeroCandidate] = phaseFiveCleanupFixture();
    WarehouseStock::query()->where('product_variant_id', $netZeroCandidate->id)->delete();
    WarehouseStock::query()->create(['warehouse_id' => WarehouseStockService::centralWarehouseId(), 'product_id' => $netZeroCandidate->product_id, 'product_variant_id' => $netZeroCandidate->id, 'quantity' => 5]);
    WarehouseStock::query()->create(['warehouse_id' => $secondWarehouse->id, 'product_id' => $netZeroCandidate->product_id, 'product_variant_id' => $netZeroCandidate->id, 'quantity' => -5]);
    $candidateRowsBefore = DB::table('warehouse_stocks')->where('product_variant_id', $netZeroCandidate->id)->orderBy('id')->get()->toJson();

    expect(app(SyntheticDefaultVariantCleanupService::class)->cleanup($netZeroCandidate->id, false)['status'])->toBe('SKIPPED')
        ->and($netZeroCandidate->fresh())->not->toBeNull()
        ->and(DB::table('warehouse_stocks')->where('product_variant_id', $netZeroCandidate->id)->orderBy('id')->get()->toJson())->toBe($candidateRowsBefore);
});

it('uses fresh activity queries for locked cleanup revalidation', function (): void {
    ['synthetic' => $synthetic] = phaseFiveCleanupFixture();
    $activityQueries = [];
    DB::listen(function ($query) use (&$activityQueries): void {
        if (str_contains(strtolower($query->sql), 'activity_logs')) {
            $activityQueries[] = $query->sql;
        }
    });

    app(SyntheticDefaultVariantCleanupService::class)->cleanup($synthetic->id, true);

    expect($activityQueries)->not->toBeEmpty()
        ->and(collect($activityQueries)->contains(fn (string $sql): bool => str_contains(strtolower($sql), 'json')))->toBeTrue();
});
