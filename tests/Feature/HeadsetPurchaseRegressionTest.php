<?php

/*
 * End-to-end regression for the real "headset" bug.
 *
 * Damaged electrical products kept `use_designs = true`, so structure
 * validation treated the synthetic black `0001` as the only valid variant and
 * the priced Base `0000` as invalid. Purchases therefore put stock on a
 * zero-priced black row while the Base/product kept the price with no stock.
 *
 * Every purchase here goes through the real HTTP routes, exactly like the form.
 */

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\ModelList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ProductVariantStructureService;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('cache.default', 'array');

    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($user);

    $this->centralWarehouseId = WarehouseStockService::centralWarehouseId();
    $this->supplier = Supplier::query()->create(['name' => 'Headset supplier']);
});

function headsetElectricalCategory(): Category
{
    $root = Category::query()->firstOrCreate(['name' => 'برقیجات'], ['code' => '17']);

    return Category::query()->create([
        'name' => 'هدست '.uniqid(),
        'code' => (string) (30 + Category::query()->count()),
        'parent_id' => $root->id,
    ]);
}

/** The damaged headset: use_designs stuck on true, one synthetic black design. */
function headsetDamagedProduct(string $code = '930001'): array
{
    $product = Product::query()->create([
        'category_id' => headsetElectricalCategory()->id,
        'name' => 'هدست '.$code,
        'sku' => 'HEADSET-'.$code,
        'code' => $code,
        'short_barcode' => substr($code, -4),
        'stock' => 0,
        'reserved' => 0,
        'price' => 2_500_000,
        'is_sellable' => true,
        'models' => ['use_models' => false, 'model_list_ids' => [], 'use_designs' => true, 'design_count' => 1, 'design_notes' => ['مشکی']],
    ]);
    $base = ProductVariant::query()->create([
        'product_id' => $product->id,
        'model_list_id' => null,
        'variant_name' => $product->name,
        'variety_name' => '—',
        'variety_code' => '0000',
        'variant_code' => $code.'00000',
        'sell_price' => 2_500_000,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ]);
    $black = ProductVariant::query()->create([
        'product_id' => $product->id,
        'model_list_id' => null,
        'variant_name' => $product->name.' مشکی',
        'variety_name' => 'مشکی',
        'variety_code' => '0001',
        'variant_code' => $code.'00001',
        'sell_price' => 0,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ]);
    ActivityLog::query()->create([
        'action' => 'electric_default_color_created',
        'subject_type' => Product::class,
        'subject_id' => $product->id,
        'description' => 'legacy automatic black',
        'properties' => ['product_id' => (int) $product->id, 'variant_id' => (int) $black->id],
        'occurred_at' => now(),
    ]);

    return [$product, $base, $black];
}

/** Submit the purchase form the way the browser does: rows packed in items_json. */
function headsetPostPurchase(array $rows)
{
    return test()->from(route('purchases.create'))->post(route('purchases.store'), [
        'submission_token' => (string) Str::uuid(),
        'supplier_id' => (string) test()->supplier->id,
        'items_json' => json_encode($rows, JSON_THROW_ON_ERROR),
    ]);
}

function headsetRow(Product $product, ProductVariant $variant, int $quantity = 5, array $extra = []): array
{
    return array_merge([
        'client_key' => "product-{$product->id}-variant-{$variant->id}",
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => $quantity,
        'buy_price' => 1_800_000,
        'sell_price' => 2_500_000,
        'product_buy_price' => 1_800_000,
        'product_sell_price' => 2_500_000,
    ], $extra);
}

function headsetCentralQuantity(ProductVariant $variant): int
{
    return (int) DB::table('warehouse_stocks')
        ->where('warehouse_id', test()->centralWarehouseId)
        ->where('product_variant_id', $variant->id)
        ->sum('quantity');
}

it('1 — rejects the purchase on the synthetic black and writes nothing', function (): void {
    [, $base, $black] = headsetDamagedProduct();
    [$product] = [Product::query()->findOrFail($black->product_id)];

    headsetPostPurchase([headsetRow($product, $black)])
        ->assertRedirect(route('purchases.create'))
        ->assertSessionHasErrors('items.0.variant_id');

    $message = session('errors')->first('items.0.variant_id');
    expect($message)->toContain('تنوع‌های خودکار قدیمی')
        ->and($message)->toContain($base->variant_code)
        ->and(PurchaseItem::query()->count())->toBe(0)
        ->and(DB::table('stock_movements')->count())->toBe(0)
        ->and(DB::table('warehouse_stocks')->where('product_variant_id', $black->id)->count())->toBe(0)
        ->and((int) $black->fresh()->stock)->toBe(0);
});

it('2 — records the purchase on the Base with no black stock', function (): void {
    [$product, $base, $black] = headsetDamagedProduct();

    headsetPostPurchase([headsetRow($product, $base, 5)])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('purchases.index'));

    expect(PurchaseItem::query()->count())->toBe(1)
        ->and(PurchaseItem::query()->value('product_variant_id'))->toBe($base->id)
        ->and(headsetCentralQuantity($base))->toBe(5)
        ->and(DB::table('warehouse_stocks')->where('product_variant_id', $black->id)->count())->toBe(0);
});

it('2b — keeps stock and price together on the Base in the product summary', function (): void {
    [$product, $base] = headsetDamagedProduct();

    headsetPostPurchase([headsetRow($product, $base, 5)])->assertSessionHasNoErrors();
    app(ProductVariantStructureService::class)->recalculateProductSummary($product->fresh());

    $product->refresh();
    $base->refresh();

    // The headset symptom was stock and price living on two different rows.
    expect((int) $base->stock)->toBe(5)
        ->and((int) $product->stock)->toBe((int) $base->stock)
        ->and((int) $base->sell_price)->toBeGreaterThan(0)
        ->and((int) $product->price)->toBe((int) $base->sell_price);
});

it('3 — the purchase form offers the Base and disables the synthetic black', function (): void {
    [$product, $base, $black] = headsetDamagedProduct();

    // For a new purchase the page does not embed variant rows: it fetches them
    // from the variants endpoint when the user adds the product, and renders
    // the badges below from that payload.
    $page = $this->get(route('purchases.create'))->assertOk()->getContent();
    expect($page)->toContain('__PRODUCT__')
        ->and($page)->toContain('purchase_blocked')
        ->and($page)->toContain('is_base_redirect');

    $variants = collect($this->getJson(route('purchases.products.variants', $product))
        ->assertOk()
        ->json('variants'))->keyBy('id');

    expect($variants->has($base->id))->toBeTrue()
        ->and($variants[$base->id]['is_base_redirect'] ?? false)->toBeTrue()
        ->and($variants[$base->id])->not->toHaveKey('purchase_blocked')
        ->and($variants->has($black->id))->toBeTrue()
        ->and($variants[$black->id]['purchase_blocked'] ?? false)->toBeTrue()
        ->and($variants[$black->id]['purchase_blocked_label'])->toBe('تنوع قدیمی — غیرقابل خرید');
});

it('4 — a real multi-variant electrical product keeps purchasing normally', function (): void {
    $model = ModelList::query()->create(['brand' => 'Headset brand', 'model_name' => 'H-200', 'code' => '201']);
    $category = headsetElectricalCategory();

    // Defined by the user through the real product form.
    $this->post(route('products.store'), [
        'category_id' => $category->id,
        'name' => 'هدست چندتنوعی',
        'use_models' => true,
        'model_brand_group' => $model->brand,
        'model_list_ids' => [$model->id],
        'use_designs' => true,
        'design_count' => 2,
        'design_notes' => ['قرمز', 'آبی'],
        'sell_price' => 3_000_000,
        'buy_price' => 2_000_000,
        'is_sellable' => true,
    ])->assertSessionHasNoErrors();
    $product = Product::query()->where('name', 'هدست چندتنوعی')->firstOrFail();
    $variants = $product->variants()->orderBy('variety_code')->get();

    expect($variants)->toHaveCount(2)
        ->and($variants->pluck('model_list_id')->unique()->values()->all())->toBe([$model->id]);

    headsetPostPurchase($variants->map(fn (ProductVariant $variant) => headsetRow($product, $variant, 3))->all())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('purchases.index'));

    foreach ($variants as $variant) {
        expect(PurchaseItem::query()->where('product_variant_id', $variant->id)->value('quantity'))->toBe(3)
            ->and(headsetCentralQuantity($variant))->toBe(3);
    }
});

it('5 — editing a legacy purchase already on the synthetic black keeps working', function (): void {
    [$product, , $black] = headsetDamagedProduct();

    // Built directly: the HTTP path can no longer create this broken state.
    $purchase = Purchase::query()->create([
        'supplier_id' => $this->supplier->id,
        'user_id' => auth()->id(),
        'purchased_at' => now()->subMonth(),
        'subtotal_amount' => 3_600_000,
        'total_discount' => 0,
        'total_amount' => 3_600_000,
    ]);
    $item = PurchaseItem::query()->create([
        'purchase_id' => $purchase->id,
        'product_id' => $product->id,
        'product_variant_id' => $black->id,
        'product_name' => $product->name,
        'product_code' => $product->code,
        'quantity' => 2,
        'buy_price' => 1_800_000,
        'sell_price' => 2_500_000,
        'line_subtotal' => 3_600_000,
        'line_total' => 3_600_000,
    ]);
    WarehouseStockService::set($this->centralWarehouseId, $product->id, $black->id, 2);

    $this->from(route('purchases.edit', $purchase))->put(route('purchases.update', $purchase), [
        'submission_token' => (string) Str::uuid(),
        'supplier_id' => (string) $this->supplier->id,
        'items_json' => json_encode([headsetRow($product, $black, 6, ['id' => $item->id])], JSON_THROW_ON_ERROR),
    ])->assertSessionHasNoErrors()->assertRedirect(route('purchases.index'));

    $item->refresh();
    expect($item->product_variant_id)->toBe($black->id)
        ->and($item->quantity)->toBe(6)
        ->and(headsetCentralQuantity($black))->toBe(6)
        ->and((int) $black->fresh()->stock)->toBe(6);
});

it('6 — a black variant of a non-electrical product is not blocked', function (): void {
    $category = Category::query()->create(['name' => 'لوازم جانبی '.uniqid(), 'code' => '16']);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'name' => 'قاب گوشی',
        'sku' => 'CASE-940001',
        'code' => '940001',
        'short_barcode' => '0001',
        'stock' => 0,
        'reserved' => 0,
        'price' => 400_000,
        'is_sellable' => true,
        'models' => ['use_models' => false, 'model_list_ids' => [], 'use_designs' => true, 'design_count' => 1, 'design_notes' => ['مشکی']],
    ]);
    ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => $product->name,
        'variety_name' => '—',
        'variety_code' => '0000',
        'variant_code' => '94000100000',
        'sell_price' => 400_000,
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
        'variant_code' => '94000100001',
        'sell_price' => 0,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ]);

    headsetPostPurchase([headsetRow($product, $black, 4)])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('purchases.index'));

    expect(PurchaseItem::query()->where('product_variant_id', $black->id)->value('quantity'))->toBe(4)
        ->and(headsetCentralQuantity($black))->toBe(4);
});
