<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\InventoryService;
use App\Services\WarehouseStockService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::fake();
    $this->actingAs(User::factory()->create());
});

function inventoryAtomicityCatalog(int $stock = 10, int $otherVariantStock = 0): array
{
    static $sequence = 0;
    $sequence++;

    $category = Category::query()->create([
        'name' => 'تست اتمیک موجودی '.$sequence,
    ]);

    $product = Product::query()->create([
        'category_id' => $category->id,
        'name' => 'کالای تست اتمیک '.$sequence,
        'sku' => 'ATOMIC-'.$sequence,
        'price' => 100_000,
        'stock' => $stock + $otherVariantStock,
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => 'تنوع اصلی',
        'sell_price' => 100_000,
        'stock' => $stock,
        'is_active' => true,
        'sales_enabled' => true,
    ]);

    $warehouseId = WarehouseStockService::centralWarehouseId();

    WarehouseStock::query()->create([
        'warehouse_id' => $warehouseId,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'quantity' => $stock,
    ]);

    $otherVariant = null;
    if ($otherVariantStock > 0) {
        $otherVariant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'variant_name' => 'تنوع دوم',
            'sell_price' => 120_000,
            'stock' => $otherVariantStock,
            'is_active' => true,
            'sales_enabled' => true,
        ]);

        WarehouseStock::query()->create([
            'warehouse_id' => $warehouseId,
            'product_id' => $product->id,
            'product_variant_id' => $otherVariant->id,
            'quantity' => $otherVariantStock,
        ]);
    }

    return [$product, $variant, $warehouseId, $otherVariant];
}

function centralVariantQuantity(int $warehouseId, int $variantId): int
{
    return (int) WarehouseStock::query()
        ->where('warehouse_id', $warehouseId)
        ->where('product_variant_id', $variantId)
        ->value('quantity');
}

it('atomically applies an inbound central stock adjustment and creates one movement', function (): void {
    [$product, $variant, $warehouseId] = inventoryAtomicityCatalog(10);

    app(InventoryService::class)->adjustCentralStock(
        $product->id,
        $variant->id,
        5,
        'TEST-IN-001',
        'افزایش تستی موجودی'
    );

    expect(centralVariantQuantity($warehouseId, $variant->id))->toBe(15)
        ->and($variant->fresh()->stock)->toBe(15)
        ->and($product->fresh()->stock)->toBe(15)
        ->and(StockMovement::query()->count())->toBe(1);

    $movement = StockMovement::query()->sole();

    expect($movement->product_id)->toBe($product->id)
        ->and($movement->product_variant_id)->toBe($variant->id)
        ->and($movement->warehouse_id)->toBe($warehouseId)
        ->and($movement->user_id)->toBe(auth()->id())
        ->and($movement->type)->toBe(StockMovement::TYPE_IN)
        ->and($movement->reason)->toBe(StockMovement::REASON_ADJUSTMENT)
        ->and($movement->quantity)->toBe(5)
        ->and($movement->stock_before)->toBe(10)
        ->and($movement->stock_after)->toBe(15)
        ->and($movement->reference)->toBe('TEST-IN-001')
        ->and($movement->note)->toBe('افزایش تستی موجودی');
});

it('uses the affected central variant stock for outbound movement before and after values', function (): void {
    [$product, $variant, $warehouseId] = inventoryAtomicityCatalog(10, 7);

    app(InventoryService::class)->adjustCentralStock(
        $product->id,
        $variant->id,
        -4,
        'TEST-OUT-001',
        'کسر تستی موجودی'
    );

    expect(centralVariantQuantity($warehouseId, $variant->id))->toBe(6)
        ->and($variant->fresh()->stock)->toBe(6)
        ->and($product->fresh()->stock)->toBe(13)
        ->and(StockMovement::query()->count())->toBe(1);

    $movement = StockMovement::query()->sole();

    expect($movement->type)->toBe(StockMovement::TYPE_OUT)
        ->and($movement->reason)->toBe(StockMovement::REASON_SALE)
        ->and($movement->quantity)->toBe(4)
        ->and($movement->stock_before)->toBe(10)
        ->and($movement->stock_after)->toBe(6);
});

it('rejects an adjustment that would make central variant stock negative without mutating inventory', function (): void {
    [$product, $variant, $warehouseId] = inventoryAtomicityCatalog(3);

    $caught = null;

    try {
        app(InventoryService::class)->adjustCentralStock(
            $product->id,
            $variant->id,
            -5,
            'TEST-NEGATIVE-001'
        );
    } catch (HttpException $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull()
        ->and($caught?->getStatusCode())->toBe(422)
        ->and(centralVariantQuantity($warehouseId, $variant->id))->toBe(3)
        ->and($variant->fresh()->stock)->toBe(3)
        ->and($product->fresh()->stock)->toBe(3)
        ->and(StockMovement::query()->count())->toBe(0);
});

it('rolls back warehouse variant and product stock when stock movement persistence fails', function (): void {
    [$product, $variant, $warehouseId] = inventoryAtomicityCatalog(10);

    // stock_movements.user_id is NOT NULL. Overriding it with null forces the
    // database insert to fail only after WarehouseStockService has attempted
    // the stock mutation, which exercises the real atomicity boundary.
    expect(fn () => app(InventoryService::class)->adjustCentralStock(
        $product->id,
        $variant->id,
        -2,
        'TEST-ROLLBACK-001',
        'این حرکت باید fail شود',
        ['user_id' => null]
    ))->toThrow(QueryException::class);

    expect(centralVariantQuantity($warehouseId, $variant->id))->toBe(10)
        ->and($variant->fresh()->stock)->toBe(10)
        ->and($product->fresh()->stock)->toBe(10)
        ->and(StockMovement::query()->count())->toBe(0);

    Http::assertNothingSent();
});

it('treats a zero delta as a no-op', function (): void {
    [$product, $variant, $warehouseId] = inventoryAtomicityCatalog(10);

    app(InventoryService::class)->adjustCentralStock(
        $product->id,
        $variant->id,
        0,
        'TEST-ZERO-001'
    );

    expect(centralVariantQuantity($warehouseId, $variant->id))->toBe(10)
        ->and($variant->fresh()->stock)->toBe(10)
        ->and($product->fresh()->stock)->toBe(10)
        ->and(StockMovement::query()->count())->toBe(0);
});

it('rejects a variant that does not belong to the supplied product without mutating inventory', function (): void {
    [$product, $variant, $warehouseId] = inventoryAtomicityCatalog(10);
    [$otherProduct, $otherVariant, $otherWarehouseId] = inventoryAtomicityCatalog(8);

    $caught = null;

    try {
        app(InventoryService::class)->adjustCentralStock(
            $product->id,
            $otherVariant->id,
            -1,
            'TEST-MISMATCH-001'
        );
    } catch (HttpException $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull()
        ->and($caught?->getStatusCode())->toBe(422)
        ->and(centralVariantQuantity($warehouseId, $variant->id))->toBe(10)
        ->and(centralVariantQuantity($otherWarehouseId, $otherVariant->id))->toBe(8)
        ->and($variant->fresh()->stock)->toBe(10)
        ->and($otherVariant->fresh()->stock)->toBe(8)
        ->and($product->fresh()->stock)->toBe(10)
        ->and($otherProduct->fresh()->stock)->toBe(8)
        ->and(StockMovement::query()->count())->toBe(0);
});

it('preserves explicit stock movement attributes supplied by callers', function (): void {
    [$product, $variant, $warehouseId] = inventoryAtomicityCatalog(10);

    app(InventoryService::class)->adjustCentralStock(
        $product->id,
        $variant->id,
        2,
        'TEST-ATTR-001',
        'حرکت با مشخصات سفارشی',
        [
            'reason' => StockMovement::REASON_RETURN,
            'transaction_type' => 'atomicity_test',
            'reference_type' => Product::class,
            'reference_id' => $product->id,
        ]
    );

    $movement = StockMovement::query()->sole();

    expect($movement->warehouse_id)->toBe($warehouseId)
        ->and($movement->reason)->toBe(StockMovement::REASON_RETURN)
        ->and($movement->transaction_type)->toBe('atomicity_test')
        ->and($movement->reference_type)->toBe(Product::class)
        ->and($movement->reference_id)->toBe($product->id)
        ->and($movement->stock_before)->toBe(10)
        ->and($movement->stock_after)->toBe(12);
});
