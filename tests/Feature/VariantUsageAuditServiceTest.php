<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\VariantUsageAuditService;
use App\Services\WarehouseStockService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function phaseFiveUnusedVariant(array $attributes = []): ProductVariant
{
    $category = Category::query()->create(['name' => 'Usage '.uniqid(), 'code' => uniqid('U')]);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Usage product',
        'sku' => uniqid('USAGE-'),
        'code' => '820001',
        'stock' => 0,
        'reserved' => 0,
        'price' => 100,
        'is_sellable' => true,
    ]);

    return ProductVariant::query()->create(array_merge([
        'product_id' => $product->id,
        'variant_name' => 'Unused',
        'variety_name' => '—',
        'variety_code' => '0000',
        'variant_code' => '82000100000',
        'sell_price' => 0,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ], $attributes));
}

it('marks a zero-authority unreferenced variant safe while allowing zero warehouse rows', function (): void {
    $variant = phaseFiveUnusedVariant();
    WarehouseStock::query()->create([
        'warehouse_id' => WarehouseStockService::centralWarehouseId(),
        'product_id' => $variant->product_id,
        'product_variant_id' => $variant->id,
        'quantity' => 0,
    ]);

    $audit = app(VariantUsageAuditService::class)->audit($variant);

    expect($audit['safe_to_remove'])->toBeTrue()
        ->and($audit['warehouse_stock'])->toBe(0)
        ->and($audit['reserved'])->toBe(0)
        ->and($audit['blocking_reasons'])->toBe([]);
});

it('blocks each direct physical cache mapping and business-evidence class', function (string $case): void {
    $variant = phaseFiveUnusedVariant();

    match ($case) {
        'physical stock' => WarehouseStock::query()->create([
            'warehouse_id' => WarehouseStockService::centralWarehouseId(),
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ]),
        'cached stock' => $variant->forceFill(['stock' => 2])->save(),
        'cached reserved' => $variant->forceFill(['reserved' => 2])->save(),
        'external mapping' => $variant->forceFill(['variety_id' => 987])->save(),
        'audit evidence' => ActivityLog::query()->create([
            'action' => 'updated',
            'subject_type' => ProductVariant::class,
            'subject_id' => $variant->id,
            'description' => 'business-significant update',
            'properties' => [],
            'occurred_at' => now(),
        ]),
    };

    $audit = app(VariantUsageAuditService::class)->audit($variant->fresh());

    expect($audit['safe_to_remove'])->toBeFalse()
        ->and($audit['blocking_reasons'])->not->toBeEmpty();
})->with(['physical stock', 'cached stock', 'cached reserved', 'external mapping', 'audit evidence']);

it('blocks known purchase references and unknown schema-discovered references', function (): void {
    $variant = phaseFiveUnusedVariant();
    $supplier = Supplier::query()->create(['name' => 'Phase 5 supplier']);
    $purchase = Purchase::query()->create([
        'supplier_id' => $supplier->id,
        'total_amount' => 1,
        'purchased_at' => now(),
    ]);
    DB::table('purchase_items')->insert([
        'purchase_id' => $purchase->id,
        'product_id' => $variant->product_id,
        'product_variant_id' => $variant->id,
        'product_name' => 'Usage product',
        'product_code' => '820001',
        'quantity' => 1,
        'buy_price' => 1,
        'sell_price' => 1,
        'line_total' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Schema::create('phase_five_unknown_refs', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('variant_id');
    });
    DB::table('phase_five_unknown_refs')->insert(['variant_id' => $variant->id]);

    $audit = app(VariantUsageAuditService::class)->audit($variant);

    expect($audit['safe_to_remove'])->toBeFalse()
        ->and($audit['purchase_refs'])->toBe(1)
        ->and($audit['other_reference_tables'])->toContain('phase_five_unknown_refs.variant_id')
        ->and($audit['blocking_reasons'])->toContain('purchase_references')
        ->and($audit['blocking_reasons'])->toContain('unknown_reference:phase_five_unknown_refs.variant_id');
});
