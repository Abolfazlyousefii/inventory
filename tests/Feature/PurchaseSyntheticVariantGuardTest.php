<?php

use App\Http\Controllers\PurchaseController;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseSyntheticVariantGuard;
use App\Services\PurchaseVariantResolver;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    $this->actingAs(User::factory()->create());
    WarehouseStockService::centralWarehouseId();
    $this->supplier = Supplier::query()->create(['name' => 'Guard supplier']);
});

function guardCategory(bool $electric): Category
{
    if (! $electric) {
        return Category::query()->create(['name' => 'Guard ordinary '.uniqid(), 'code' => (string) (20 + Category::query()->count())]);
    }

    $root = Category::query()->firstOrCreate(['name' => 'برقیجات'], ['code' => '19']);

    return Category::query()->create([
        'name' => 'Guard electrical child '.uniqid(),
        'code' => (string) (20 + Category::query()->count()),
        'parent_id' => $root->id,
    ]);
}

/** @return array<int,array{0:string,1:string}> */
function guardBlackWhite(): array
{
    return [['0001', 'مشکی'], ['0002', 'سفید']];
}

/**
 * A product whose `use_designs` metadata is stuck on true, so the synthetic
 * black/white rows are structurally valid while the Base is not.
 *
 * @param  array<int,array{0:string,1:string}>  $designs  [variety_code, name]
 */
function guardProduct(Category $category, string $code, bool $withBase, array $designs, bool $provenBlack = true): array
{
    $product = Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Guard product '.$code,
        'sku' => uniqid('GUARD-'),
        'code' => $code,
        'short_barcode' => substr($code, -4),
        'stock' => 0,
        'reserved' => 0,
        'price' => 500,
        'is_sellable' => true,
        'models' => [
            'use_models' => false,
            'model_list_ids' => [],
            'use_designs' => true,
            'design_count' => count($designs),
            'design_notes' => array_column($designs, 1),
        ],
    ]);

    $base = $withBase ? guardBase($product) : null;

    $variants = [];
    foreach ($designs as [$varietyCode, $name]) {
        $variants[$name] = ProductVariant::query()->create([
            'product_id' => $product->id,
            'variant_name' => $product->name.' '.$name,
            'variety_name' => $name,
            'variety_code' => $varietyCode,
            'variant_code' => $code.'000'.substr($varietyCode, -2),
            'sell_price' => 0,
            'stock' => 0,
            'reserved' => 0,
            'is_active' => true,
            'sales_enabled' => true,
        ]);
    }

    if ($provenBlack && isset($variants['مشکی'])) {
        ActivityLog::query()->create([
            'action' => 'electric_default_color_created',
            'subject_type' => Product::class,
            'subject_id' => $product->id,
            'description' => 'legacy proof',
            'properties' => ['product_id' => (int) $product->id, 'variant_id' => (int) $variants['مشکی']->id],
            'occurred_at' => now(),
        ]);
    }

    return ['product' => $product, 'base' => $base, 'variants' => $variants];
}

function guardBase(Product $product): ProductVariant
{
    return ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => $product->name,
        'variety_name' => '—',
        'variety_code' => '0000',
        'variant_code' => $product->code.'00000',
        'sell_price' => 500,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ]);
}

function guardStore(Product $product, ProductVariant|int|null $variant, int $quantity = 3): void
{
    $request = Request::create('/purchases', 'POST', [
        'submission_token' => (string) Str::uuid(),
        'supplier_id' => (string) test()->supplier->id,
        'items' => [[
            'product_id' => $product->id,
            'variant_id' => $variant instanceof ProductVariant ? $variant->id : $variant,
            'quantity' => $quantity,
            'buy_price' => 1000,
            'sell_price' => 1500,
        ]],
    ]);

    app(PurchaseController::class)->store($request);
}

/** @return array<string,int> */
function guardWriteCounts(Product $product): array
{
    return [
        'purchases' => Purchase::query()->count(),
        'purchase_items' => PurchaseItem::query()->where('product_id', $product->id)->count(),
        'stock_movements' => DB::table('stock_movements')->where('product_id', $product->id)->count(),
        'warehouse_stocks' => DB::table('warehouse_stocks')->where('product_id', $product->id)->count(),
        'warehouse_quantity' => (int) DB::table('warehouse_stocks')->where('product_id', $product->id)->sum('quantity'),
    ];
}

it('rejects a purchase on a synthetic electrical variant when a Base exists and writes nothing', function (string $color): void {
    ['product' => $product, 'base' => $base, 'variants' => $variants] = guardProduct(guardCategory(true), '910001', true, guardBlackWhite());
    $before = guardWriteCounts($product);

    try {
        guardStore($product, $variants[$color]);
        $this->fail('The synthetic variant purchase was accepted.');
    } catch (ValidationException $exception) {
        $message = $exception->errors()['items.0.variant_id'][0];
        expect($message)->toContain('این تنوع از تنوع‌های خودکار قدیمی است')
            ->and($message)->toContain($base->variant_name)
            ->and($message)->toContain($base->variant_code);
    }

    expect(guardWriteCounts($product))->toBe($before);
})->with([
    'proven black' => 'مشکی',
    'probable white' => 'سفید',
]);

it('accepts the same synthetic variant when no Base exists and logs a warning', function (): void {
    ['product' => $product, 'variants' => $variants] = guardProduct(guardCategory(true), '910002', false, guardBlackWhite());
    Log::spy();

    guardStore($product, $variants['مشکی']);

    expect(PurchaseItem::query()->where('product_variant_id', $variants['مشکی']->id)->count())->toBe(1);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'synthetic electrical variant')
            && ($context['variant_id'] ?? null) === (int) $variants['مشکی']->id
            && ($context['product_id'] ?? null) === (int) $product->id
            && ($context['synthetic_class'] ?? null) === 'proven_synthetic')
        ->once();
});

it('purchases on the Base Variant normally, even while use_designs excludes it structurally', function (): void {
    ['product' => $product, 'base' => $base] = guardProduct(guardCategory(true), '910003', true, guardBlackWhite());

    guardStore($product, $base, 4);

    expect(PurchaseItem::query()->where('product_variant_id', $base->id)->value('quantity'))->toBe(4)
        ->and((int) DB::table('warehouse_stocks')->where('product_variant_id', $base->id)->sum('quantity'))->toBe(4);
});

it('does not block real variants of a multi-variant electrical product', function (): void {
    ['product' => $product, 'variants' => $variants] = guardProduct(
        guardCategory(true), '910004', true, [['0001', 'قرمز'], ['0002', 'آبی'], ['0003', 'مشکی']], provenBlack: false,
    );
    // A manually managed black variant: business evidence makes it NOT synthetic.
    ActivityLog::query()->create([
        'action' => 'updated',
        'subject_type' => ProductVariant::class,
        'subject_id' => $variants['مشکی']->id,
        'description' => 'manual edit',
        'properties' => [],
        'occurred_at' => now(),
    ]);

    guardStore($product, $variants['قرمز']);
    guardStore($product, $variants['مشکی']);

    expect(PurchaseItem::query()->where('product_variant_id', $variants['قرمز']->id)->count())->toBe(1)
        ->and(PurchaseItem::query()->where('product_variant_id', $variants['مشکی']->id)->count())->toBe(1);
});

it('does not block a black variant of a non-electrical product', function (): void {
    ['product' => $product, 'variants' => $variants] = guardProduct(guardCategory(false), '910005', true, guardBlackWhite(), provenBlack: false);

    guardStore($product, $variants['مشکی']);

    expect(PurchaseItem::query()->where('product_variant_id', $variants['مشکی']->id)->count())->toBe(1);
});

it('routes a purchase without variant_id on a simple product to the Base as before', function (): void {
    $product = Product::query()->create([
        'category_id' => guardCategory(true)->id,
        'name' => 'Simple guard product',
        'sku' => uniqid('SIMPLE-'),
        'code' => '910006',
        'short_barcode' => '0006',
        'stock' => 0,
        'reserved' => 0,
        'price' => 500,
        'is_sellable' => true,
        'models' => ['use_models' => false, 'model_list_ids' => [], 'use_designs' => false, 'design_count' => 0],
    ]);
    $base = guardBase($product);

    guardStore($product, null);

    expect(PurchaseItem::query()->where('product_id', $product->id)->value('product_variant_id'))->toBe($base->id);
});

it('keeps editing an existing purchase recorded on a synthetic variant, blocking only new placements', function (): void {
    // Recorded historically while no Base existed (the guard allows that path).
    ['product' => $product, 'variants' => $variants] = guardProduct(guardCategory(true), '910007', false, guardBlackWhite());
    guardStore($product, $variants['مشکی'], 2);
    $purchase = Purchase::query()->latest('id')->firstOrFail();
    $item = PurchaseItem::query()->where('purchase_id', $purchase->id)->firstOrFail();

    // The Base appears later; from now on new placements must be blocked.
    guardBase($product);

    $update = fn (array $items) => app(PurchaseController::class)->update(Request::create('/purchases/'.$purchase->id, 'PUT', [
        'submission_token' => (string) Str::uuid(),
        'supplier_id' => (string) $this->supplier->id,
        'items' => $items,
    ]), $purchase->fresh());
    $existingRow = [
        'id' => $item->id,
        'product_id' => $product->id,
        'variant_id' => $variants['مشکی']->id,
        'quantity' => 5,
        'buy_price' => 1000,
        'sell_price' => 1500,
    ];

    $update([$existingRow]);

    expect(PurchaseItem::query()->where('purchase_id', $purchase->id)->where('product_variant_id', $variants['مشکی']->id)->value('quantity'))->toBe(5);

    $before = guardWriteCounts($product);
    expect(fn () => $update([
        $existingRow,
        ['product_id' => $product->id, 'variant_id' => $variants['سفید']->id, 'quantity' => 1, 'buy_price' => 1000, 'sell_price' => 1500],
    ]))->toThrow(ValidationException::class);
    expect(guardWriteCounts($product))->toBe($before);
});

it('marks blocked variants in the purchase form and offers the Base instead', function (): void {
    ['product' => $product, 'base' => $base, 'variants' => $variants] = guardProduct(guardCategory(true), '910008', true, guardBlackWhite());

    $payload = collect(app(PurchaseController::class)->productVariants($product)->getData(true)['variants'])->keyBy('id');

    expect($payload[$variants['مشکی']->id]['purchase_blocked'])->toBeTrue()
        ->and($payload[$variants['مشکی']->id]['purchase_blocked_label'])->toBe('تنوع قدیمی — غیرقابل خرید')
        ->and($payload[$variants['سفید']->id]['purchase_blocked'])->toBeTrue()
        ->and($payload[$base->id]['is_base_redirect'])->toBeTrue()
        ->and($payload[$base->id])->not->toHaveKey('purchase_blocked');
});

it('leaves the purchase form untouched for non-electrical products', function (): void {
    ['product' => $product] = guardProduct(guardCategory(false), '910009', true, guardBlackWhite(), provenBlack: false);

    $payload = app(PurchaseController::class)->productVariants($product)->getData(true)['variants'];

    expect(collect($payload)->filter(fn (array $row): bool => isset($row['purchase_blocked']) || isset($row['is_base_redirect'])))->toBeEmpty();
});

it('adds no activity queries to the non-electrical purchase path', function (): void {
    ['product' => $product, 'variants' => $variants] = guardProduct(guardCategory(false), '910010', true, guardBlackWhite(), provenBlack: false);
    $resolver = app(PurchaseVariantResolver::class);
    $resolver->resolve($product, $variants['مشکی']->id);
    $activityQueries = [];
    DB::listen(function ($query) use (&$activityQueries): void {
        if (str_contains(strtolower($query->sql), 'activity_logs')) {
            $activityQueries[] = $query->sql;
        }
    });

    $resolver->resolve($product, $variants['مشکی']->id);
    $resolver->resolve($product, $variants['سفید']->id);

    expect($activityQueries)->toBeEmpty();
});

it('skips the full activity scan for electrical variants that cannot be synthetic', function (): void {
    ['product' => $product, 'variants' => $variants] = guardProduct(
        guardCategory(true), '910011', true, [['0001', 'قرمز']], provenBlack: false,
    );
    $chunkScans = [];
    DB::listen(function ($query) use (&$chunkScans): void {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'activity_logs') && str_contains($sql, 'limit')) {
            $chunkScans[] = $query->sql;
        }
    });

    $verdict = app(PurchaseSyntheticVariantGuard::class)->evaluate($product, $variants['قرمز']);

    expect($verdict['blocked'])->toBeFalse()
        ->and($verdict['synthetic_class'])->toBeNull()
        ->and($chunkScans)->toBeEmpty();
});
