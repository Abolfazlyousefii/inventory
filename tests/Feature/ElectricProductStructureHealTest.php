<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\ModelList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ElectricProductStructureHealService;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function healCategory(bool $electric = true): Category
{
    if (! $electric) {
        return Category::query()->create(['name' => 'Heal ordinary '.uniqid(), 'code' => (string) (40 + Category::query()->count())]);
    }

    $root = Category::query()->firstOrCreate(['name' => 'برقیجات'], ['code' => '39']);

    return Category::query()->create([
        'name' => 'Heal electrical '.uniqid(),
        'code' => (string) (40 + Category::query()->count()),
        'parent_id' => $root->id,
    ]);
}

/**
 * The production damage: Base inactive yet holding all stock, price and
 * history; automatic black/white shells active and completely empty; the
 * product summary showing no stock and no price.
 */
function healDamagedProduct(string $code = '950001', bool $electric = true, bool $withBase = true): array
{
    $product = Product::query()->create([
        'category_id' => healCategory($electric)->id,
        'name' => 'Heal product '.$code,
        'sku' => 'HEAL-'.$code,
        'code' => $code,
        'short_barcode' => substr($code, -4),
        'stock' => 0,
        'reserved' => 0,
        'price' => 0,
        'is_sellable' => true,
        'models' => ['use_models' => false, 'model_list_ids' => [], 'use_designs' => true, 'design_count' => 2, 'design_notes' => ['مشکی', 'سفید']],
    ]);
    $base = $withBase ? ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => $product->name,
        'variety_name' => '—',
        'variety_code' => '0000',
        'variant_code' => $code.'00000',
        'sell_price' => 2_500_000,
        'buy_price' => 1_800_000,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => false,
        'sales_enabled' => true,
    ]) : null;
    $shells = collect([['0001', 'مشکی'], ['0002', 'سفید']])->mapWithKeys(fn (array $design) => [$design[1] => ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => $product->name.' '.$design[1],
        'variety_name' => $design[1],
        'variety_code' => $design[0],
        'variant_code' => $code.'000'.substr($design[0], -2),
        'sell_price' => 0,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ])]);

    if ($base) {
        WarehouseStockService::set(WarehouseStockService::centralWarehouseId(), $product->id, $base->id, 7);
        healHistoryOn($product, $base);
    }

    // Reproduce the damaged summary (unsellable, unpriced) without observers.
    DB::table('products')->where('id', $product->id)->update(['stock' => 0, 'price' => 0]);

    return [$product->fresh(), $base?->fresh(), $shells->map->fresh()];
}

/** Purchase, sale and stock-movement history recorded on the Base. */
function healHistoryOn(Product $product, ProductVariant $variant): void
{
    $supplier = Supplier::query()->create(['name' => 'Heal supplier '.uniqid()]);
    $purchase = Purchase::query()->create(['supplier_id' => $supplier->id, 'total_amount' => 12_600_000, 'purchased_at' => now()->subMonths(2)]);
    DB::table('purchase_items')->insert([
        'purchase_id' => $purchase->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id,
        'product_name' => $product->name, 'product_code' => $product->code, 'quantity' => 7,
        'buy_price' => 1_800_000, 'sell_price' => 2_500_000, 'line_total' => 12_600_000,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $invoiceId = DB::table('invoices')->insertGetId([
        'uuid' => (string) Str::uuid(), 'customer_name' => 'Heal customer', 'subtotal' => 2_500_000,
        'total' => 2_500_000, 'status' => 'delivered', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('invoice_items')->insert([
        'invoice_id' => $invoiceId, 'product_id' => $product->id, 'variant_id' => $variant->id,
        'quantity' => 1, 'price' => 2_500_000, 'line_total' => 2_500_000, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('stock_movements')->insert([
        'user_id' => User::factory()->create()->id,
        'product_id' => $product->id, 'product_variant_id' => $variant->id,
        'warehouse_id' => WarehouseStockService::centralWarehouseId(), 'type' => 'in', 'reason' => 'purchase',
        'quantity' => 7, 'stock_before' => 0, 'stock_after' => 7, 'reference' => 'PUR-'.$purchase->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Full row-level snapshot of every table the repair could touch. */
function healSnapshot(): array
{
    $dump = fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

    return [
        'products' => $dump('products'),
        'product_variants' => $dump('product_variants'),
        'warehouse_stocks' => $dump('warehouse_stocks'),
        'activity_logs' => $dump('activity_logs'),
    ];
}

/** History identity: every row keeps its product and variant. */
function healHistory(): array
{
    return [
        'purchase_items' => DB::table('purchase_items')->orderBy('id')->get(['id', 'product_id', 'product_variant_id', 'quantity'])->map(fn ($row) => (array) $row)->all(),
        'invoice_items' => DB::table('invoice_items')->orderBy('id')->get(['id', 'product_id', 'variant_id', 'quantity'])->map(fn ($row) => (array) $row)->all(),
        'stock_movements' => DB::table('stock_movements')->orderBy('id')->get(['id', 'product_id', 'product_variant_id', 'quantity'])->map(fn ($row) => (array) $row)->all(),
    ];
}

function healRun(Product $product, bool $apply = false)
{
    return test()->artisan('inventory:heal-electric-product-structure --ids='.$product->id.($apply ? ' --apply --confirm' : ''));
}

it('heals the damaged product: Base on, shells off, designs off, summary on the Base', function (): void {
    [$product, $base, $shells] = healDamagedProduct();
    $variantObserverRows = fn (): int => ActivityLog::query()->where('subject_type', ProductVariant::class)->count();
    $variantObserverRowsBefore = $variantObserverRows();

    healRun($product, apply: true)
        ->expectsOutputToContain('status=HEALED')
        ->expectsOutputToContain('stock: 0 → 7')
        ->expectsOutputToContain('price: 0 → 2500000')
        ->expectsOutputToContain('healed=1')
        ->expectsOutputToContain('total_stock_restored=7')
        ->assertSuccessful();

    $product->refresh();
    $base->refresh();

    expect((bool) $base->is_active)->toBeTrue()
        ->and($shells->every(fn (ProductVariant $shell): bool => ! (bool) $shell->fresh()->is_active))->toBeTrue()
        ->and(ProductVariant::query()->where('product_id', $product->id)->count())->toBe(3)
        ->and($product->models['use_designs'])->toBeFalse()
        ->and($product->models['design_count'])->toBe(0)
        ->and($product->models['design_notes'])->toBe([])
        ->and($product->models['use_models'])->toBeFalse()
        ->and($product->models['model_list_ids'])->toBe([])
        ->and((int) $product->stock)->toBe((int) $base->stock)
        ->and((int) $product->stock)->toBe(7)
        ->and((int) $product->price)->toBe((int) $base->sell_price);

    $log = ActivityLog::query()->where('action', 'electric_product_structure_healed')->sole();
    expect($log->subject_id)->toBe($product->id)
        ->and($log->properties['base_variant_id'])->toBe($base->id)
        ->and($log->properties['deactivated_variant_ids'])->toBe($shells->pluck('id')->sort()->values()->all())
        ->and($log->properties['stock_before'])->toBe(0)
        ->and($log->properties['stock_after'])->toBe(7)
        ->and($log->properties['price_before'])->toBe(0)
        ->and($log->properties['price_after'])->toBe(2_500_000)
        ->and($log->properties['models_before']['use_designs'])->toBeTrue()
        ->and($log->properties['models_after']['use_designs'])->toBeFalse()
        // The heal manufactures no observer rows on the variants, which the
        // synthetic classifier would read as business evidence.
        ->and($variantObserverRows())->toBe($variantObserverRowsBefore);
});

it('dry run reports the plan and writes nothing', function (): void {
    [$product, $base, $shells] = healDamagedProduct();
    $before = healSnapshot();

    healRun($product)
        ->expectsOutputToContain('status=ELIGIBLE')
        ->expectsOutputToContain('base_variant_id='.$base->id.' (will be activated)')
        ->expectsOutputToContain('deactivating=['.$shells->pluck('id')->sort()->implode(',').']')
        ->expectsOutputToContain('stock: 0 → 7')
        ->expectsOutputToContain('eligible=1')
        ->expectsOutputToContain('healed=0')
        ->expectsOutputToContain('Dry-run only; no data was changed.')
        ->assertSuccessful();

    expect(healSnapshot())->toBe($before);
});

it('blocks and changes nothing when a shell is not empty', function (string $case): void {
    [$product, , $shells] = healDamagedProduct();
    $black = $shells['مشکی'];
    match ($case) {
        'stock' => WarehouseStockService::set(WarehouseStockService::centralWarehouseId(), $product->id, $black->id, 2),
        'purchase reference' => DB::table('purchase_items')->insert([
            'purchase_id' => Purchase::query()->value('id'), 'product_id' => $product->id, 'product_variant_id' => $black->id,
            'product_name' => $product->name, 'product_code' => $product->code, 'quantity' => 1,
            'buy_price' => 1, 'sell_price' => 1, 'line_total' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]),
        // Exactly product 1121: a shell that carries a price.
        'positive price' => DB::table('product_variants')->where('id', $black->id)->update(['sell_price' => 900_000]),
        'reserved' => DB::table('product_variants')->where('id', $black->id)->update(['reserved' => 1]),
        'invoice reference' => DB::table('invoice_items')->insert([
            'invoice_id' => DB::table('invoices')->value('id'), 'product_id' => $product->id, 'variant_id' => $black->id,
            'quantity' => 1, 'price' => 1, 'line_total' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]),
        'stock movement reference' => DB::table('stock_movements')->insert([
            'user_id' => DB::table('stock_movements')->value('user_id'), 'product_id' => $product->id,
            'product_variant_id' => $black->id, 'warehouse_id' => WarehouseStockService::centralWarehouseId(),
            'type' => 'in', 'reason' => 'purchase', 'quantity' => 1, 'stock_before' => 0, 'stock_after' => 1,
            'reference' => 'LEGACY', 'created_at' => now(), 'updated_at' => now(),
        ]),
    };
    $before = healSnapshot();
    $history = healHistory();

    healRun($product, apply: true)
        ->expectsOutputToContain('status=BLOCKED')
        ->expectsOutputToContain('variant_'.$black->id.':')
        ->expectsOutputToContain('healed=0')
        ->assertSuccessful();

    expect(healSnapshot())->toBe($before)
        ->and(healHistory())->toBe($history);
})->with(['stock', 'purchase reference', 'positive price', 'reserved', 'invoice reference', 'stock movement reference']);

it('blocks a product whose Base carries no price', function (): void {
    [$product, $base] = healDamagedProduct();
    DB::table('product_variants')->where('id', $base->id)->update(['sell_price' => 0]);
    $before = healSnapshot();

    healRun($product, apply: true)
        ->expectsOutputToContain('blockers=base_variant_without_price')
        ->assertSuccessful();

    expect(healSnapshot())->toBe($before);
});

it('blocks a product that uses a model list', function (): void {
    [$product, , $shells] = healDamagedProduct();
    $model = ModelList::query()->create(['brand' => 'Heal', 'model_name' => 'M1', 'code' => '301']);
    DB::table('product_variants')->where('id', $shells['مشکی']->id)->update(['model_list_id' => $model->id]);
    $before = healSnapshot();

    healRun($product, apply: true)
        ->expectsOutputToContain('blockers=product_uses_model_list')
        ->assertSuccessful();

    expect(healSnapshot())->toBe($before);
});

it('blocks a non-electrical product', function (): void {
    [$product] = healDamagedProduct('950002', electric: false);
    $before = healSnapshot();

    healRun($product, apply: true)
        ->expectsOutputToContain('blockers=not_electrical_category')
        ->assertSuccessful();

    expect(healSnapshot())->toBe($before);
});

it('blocks a product without a Base Variant', function (): void {
    [$product] = healDamagedProduct('950003', withBase: false);
    $before = healSnapshot();

    healRun($product, apply: true)
        ->expectsOutputToContain('blockers=base_variant_missing')
        ->assertSuccessful();

    expect(healSnapshot())->toBe($before);
});

it('is idempotent: a second run reports nothing to do and changes nothing', function (): void {
    [$product] = healDamagedProduct();
    healRun($product, apply: true)->assertSuccessful();
    $afterFirst = healSnapshot();

    healRun($product, apply: true)
        ->expectsOutputToContain('status=NOTHING_TO_DO')
        ->expectsOutputToContain('healed=0')
        ->assertSuccessful();

    expect(healSnapshot())->toBe($afterFirst)
        ->and(ActivityLog::query()->where('action', 'electric_product_structure_healed')->count())->toBe(1);
});

it('never deletes or moves purchase, invoice or stock-movement history', function (): void {
    [$product] = healDamagedProduct();
    $history = healHistory();

    expect($history['purchase_items'])->toHaveCount(1)
        ->and($history['invoice_items'])->toHaveCount(1)
        ->and($history['stock_movements'])->toHaveCount(1);

    healRun($product, apply: true)->expectsOutputToContain('status=HEALED')->assertSuccessful();

    expect(healHistory())->toBe($history);
});

it('revalidates at apply time instead of trusting an earlier dry run', function (): void {
    [$product, , $shells] = healDamagedProduct();
    healRun($product)->expectsOutputToContain('status=ELIGIBLE')->assertSuccessful();

    // Data changes between the dry run and the apply.
    WarehouseStockService::set(WarehouseStockService::centralWarehouseId(), $product->id, $shells['سفید']->id, 3);
    $before = healSnapshot();

    healRun($product, apply: true)->expectsOutputToContain('status=BLOCKED')->assertSuccessful();

    expect(healSnapshot())->toBe($before);
});

it('requires --ids and paired --apply --confirm flags', function (): void {
    $this->artisan('inventory:heal-electric-product-structure')->assertFailed();
    $this->artisan('inventory:heal-electric-product-structure --ids=1 --apply')->assertFailed();
    $this->artisan('inventory:heal-electric-product-structure --ids=1 --confirm')->assertFailed();
    $this->artisan('inventory:heal-electric-product-structure --ids=abc')->assertFailed();
});

it('assess() never writes, even for an eligible product', function (): void {
    [$product] = healDamagedProduct();
    $before = healSnapshot();
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $result = app(ElectricProductStructureHealService::class)->assess($product->id);

    expect($result['status'])->toBe(ElectricProductStructureHealService::ELIGIBLE)
        ->and($writes)->toBe([])
        ->and(healSnapshot())->toBe($before);
});
