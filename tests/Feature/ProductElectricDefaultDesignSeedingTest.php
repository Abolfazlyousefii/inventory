<?php

use App\Http\Controllers\ProductController;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\WarehouseStock;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function seedingElectricalCategory(): Category
{
    $root = Category::query()->firstOrCreate(['name' => 'برقیجات'], ['code' => '61']);

    return Category::query()->create([
        'name' => 'Seeding electrical child '.uniqid(),
        'code' => (string) (62 + Category::query()->count()),
        'parent_id' => $root->id,
    ]);
}

function seedingStoreProduct(Category $category, string $name, array $overrides = []): Product
{
    $request = Request::create(route('products.store'), 'POST', array_merge([
        'category_id' => $category->id,
        'name' => $name,
        'use_models' => false,
        'use_designs' => false,
        'sell_price' => 125,
        'buy_price' => 75,
        'is_sellable' => true,
    ], $overrides));

    app(ProductController::class)->store($request);

    return Product::query()->where('name', $name)->firstOrFail();
}

function seedingUpdateProduct(Product $product, array $overrides = []): void
{
    $request = Request::create(route('products.update', $product), 'PUT', array_merge([
        'category_id' => $product->category_id,
        'name' => $product->name,
        'use_models' => false,
        'use_designs' => false,
        'sell_price' => 125,
        'buy_price' => 75,
    ], $overrides));

    app(ProductController::class)->update($request, $product);
}

/** A product carrying pre-existing historical black/white variants with business history. */
function seedingHistoricalElectricProduct(): array
{
    $category = seedingElectricalCategory();
    $product = Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Historical electrical product',
        'sku' => uniqid('HIST-'),
        'code' => '640001',
        'short_barcode' => '0001',
        'stock' => 0,
        'reserved' => 0,
        'price' => 125,
        'is_sellable' => true,
        'models' => [],
    ]);
    $base = ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => $product->name,
        'variety_name' => '—',
        'variety_code' => '0000',
        'variant_code' => '64000100000',
        'sell_price' => 125,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ]);
    $black = ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => $product->name.' مشکی',
        'variety_name' => 'مشکی',
        'variety_code' => '0001',
        'variant_code' => '64000100001',
        'sell_price' => 130,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ]);
    $white = ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => $product->name.' سفید',
        'variety_name' => 'سفید',
        'variety_code' => '0002',
        'variant_code' => '64000100002',
        'sell_price' => 140,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ]);
    $inactive = ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => $product->name.' مشکی قدیمی',
        'variety_name' => 'مشکی قدیمی',
        'variety_code' => '0003',
        'variant_code' => '64000100003',
        'sell_price' => 100,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => false,
        'sales_enabled' => false,
    ]);
    $mapped = ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => $product->name.' سفید سایت',
        'variety_name' => 'سفید سایت',
        'variety_code' => '0004',
        'variant_code' => '64000100004',
        'sell_price' => 150,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
        'variety_id' => 7788,
    ]);

    // C) warehouse stock on the historical black variant
    WarehouseStock::query()->create([
        'warehouse_id' => WarehouseStockService::centralWarehouseId(),
        'product_id' => $product->id,
        'product_variant_id' => $black->id,
        'quantity' => 9,
    ]);

    // A) invoice history on the black variant
    $invoiceId = DB::table('invoices')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'customer_name' => 'Seeding customer',
        'subtotal' => 130,
        'total' => 130,
        'status' => 'processing',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('invoice_items')->insert([
        'invoice_id' => $invoiceId,
        'product_id' => $product->id,
        'variant_id' => $black->id,
        'quantity' => 1,
        'price' => 130,
        'line_total' => 130,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // B) purchase history on the white variant
    $supplier = Supplier::query()->create(['name' => 'Seeding supplier']);
    $purchase = Purchase::query()->create([
        'supplier_id' => $supplier->id,
        'total_amount' => 140,
        'purchased_at' => now(),
    ]);
    DB::table('purchase_items')->insert([
        'purchase_id' => $purchase->id,
        'product_id' => $product->id,
        'product_variant_id' => $white->id,
        'product_name' => $product->name,
        'product_code' => $product->code,
        'quantity' => 1,
        'buy_price' => 140,
        'sell_price' => 140,
        'line_total' => 140,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return compact('product', 'base', 'black', 'white', 'inactive', 'mapped', 'invoiceId', 'purchase');
}

/** @return array<int,array<string,mixed>> */
function seedingVariantFingerprint(Product $product): array
{
    return ProductVariant::query()
        ->where('product_id', $product->id)
        ->orderBy('id')
        ->get(['id', 'variant_code', 'variety_name', 'variety_id', 'is_active', 'sales_enabled', 'sell_price'])
        ->map(fn (ProductVariant $variant): array => $variant->only([
            'id', 'variant_code', 'variety_name', 'variety_id', 'is_active', 'sales_enabled', 'sell_price',
        ]))
        ->all();
}

it('has no automatic electric design seeding in the product create and edit views', function (string $view): void {
    $source = file_get_contents(resource_path('views/products/'.$view.'.blade.php'));

    expect($source)->not->toContain('ensureDefaultElectricDesignNotes')
        ->and($source)->not->toContain('isElectricCategorySelected')
        ->and($source)->not->toContain("var required = ['مشکی', 'سفید']")
        // The category-change handler and initial setup must not seed designs.
        ->and($source)->not->toContain('useDesignsEl.checked = true');
})->with(['create', 'edit']);

it('creates an electrical product without injecting black or white variants', function (): void {
    $product = seedingStoreProduct(seedingElectricalCategory(), 'Plain electrical product');

    expect($product->variants()->count())->toBe(1)
        ->and($product->variants()->whereIn('variety_name', ['مشکی', 'سفید'])->count())->toBe(0)
        ->and(ActivityLog::query()->where('action', 'electric_default_color_created')->count())->toBe(0);
});

it('creates only the arbitrary colors the user explicitly entered', function (): void {
    $product = seedingStoreProduct(seedingElectricalCategory(), 'Manual color electrical product', [
        'use_designs' => true,
        'design_count' => 2,
        'design_notes' => ['قرمز', 'آبی'],
    ]);

    expect($product->variants()->pluck('variety_name')->sort()->values()->all())
        ->toBe(['آبی', 'قرمز'])
        ->and($product->variants()->whereIn('variety_name', ['مشکی', 'سفید'])->count())->toBe(0);
});

it('allows black and white when the user explicitly chose them', function (): void {
    $product = seedingStoreProduct(seedingElectricalCategory(), 'Manual black white product', [
        'use_designs' => true,
        'design_count' => 2,
        'design_notes' => ['مشکی', 'سفید'],
    ]);

    expect($product->variants()->pluck('variety_name')->sort()->values()->all())
        ->toBe(['سفید', 'مشکی'])
        // Manual choice is legitimate and must not be logged as automatic provenance.
        ->and(ActivityLog::query()->where('action', 'electric_default_color_created')->count())->toBe(0);
});

it('does not mutate variant structure when the category changes in either direction', function (): void {
    $ordinary = Category::query()->create(['name' => 'Ordinary seeding', 'code' => '71']);
    $electrical = seedingElectricalCategory();
    $product = seedingStoreProduct($ordinary, 'Category switching product');
    $before = seedingVariantFingerprint($product);

    seedingUpdateProduct($product->fresh(), ['category_id' => $electrical->id]);
    $afterElectric = seedingVariantFingerprint($product->fresh());

    seedingUpdateProduct($product->fresh(), ['category_id' => $ordinary->id]);
    $afterOrdinary = seedingVariantFingerprint($product->fresh());

    expect($afterElectric)->toBe($before)
        ->and($afterOrdinary)->toBe($before)
        ->and(ActivityLog::query()->where('action', 'electric_default_color_created')->count())->toBe(0);
});

it('preserves historical black and white variants with business history across an ordinary edit', function (): void {
    ['product' => $product, 'black' => $black, 'white' => $white, 'inactive' => $inactive, 'mapped' => $mapped]
        = seedingHistoricalElectricProduct();
    $before = seedingVariantFingerprint($product);
    $stockBefore = (int) DB::table('warehouse_stocks')->where('product_id', $product->id)->sum('quantity');

    seedingUpdateProduct($product->fresh(), ['sell_price' => 126]);
    seedingUpdateProduct($product->fresh());

    $freshBlack = $black->fresh();
    $freshWhite = $white->fresh();

    expect(seedingVariantFingerprint($product->fresh()))->toBe($before)
        // A) invoice reference intact
        ->and(DB::table('invoice_items')->where('variant_id', $black->id)->count())->toBe(1)
        // B) purchase reference intact
        ->and(DB::table('purchase_items')->where('product_variant_id', $white->id)->count())->toBe(1)
        // C) warehouse quantities intact
        ->and((int) DB::table('warehouse_stocks')->where('product_variant_id', $black->id)->sum('quantity'))->toBe(9)
        ->and((int) DB::table('warehouse_stocks')->where('product_id', $product->id)->sum('quantity'))->toBe($stockBefore)
        // D) inactive historical variant stays inactive
        ->and((bool) $inactive->fresh()->is_active)->toBeFalse()
        ->and((bool) $inactive->fresh()->sales_enabled)->toBeFalse()
        // E) site mapping intact
        ->and((int) $mapped->fresh()->variety_id)->toBe(7788)
        // identities and names unchanged
        ->and($freshBlack->variety_name)->toBe('مشکی')
        ->and($freshBlack->variant_code)->toBe('64000100001')
        ->and($freshWhite->variety_name)->toBe('سفید')
        ->and($freshWhite->variant_code)->toBe('64000100002')
        ->and((bool) $product->fresh()->is_sellable)->toBeTrue();
});

it('does not re-add a historical black or white variant that was previously removed', function (): void {
    ['product' => $product, 'black' => $black] = seedingHistoricalElectricProduct();
    DB::table('invoice_items')->where('variant_id', $black->id)->delete();
    DB::table('warehouse_stocks')->where('product_variant_id', $black->id)->delete();
    ProductVariant::query()->whereKey($black->id)->delete();

    seedingUpdateProduct($product->fresh());

    expect(ProductVariant::query()->whereKey($black->id)->exists())->toBeFalse()
        ->and($product->fresh()->variants()->where('variety_code', '0001')->count())->toBe(0)
        ->and(ActivityLog::query()->where('action', 'electric_default_color_created')->count())->toBe(0);
});
