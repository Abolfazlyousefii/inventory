<?php

use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function variantRegressionProduct(string $sku, int $stock = 0): Product
{
    $category = Category::create(['name' => 'دسته ' . $sku]);

    return Product::create([
        'category_id' => $category->id,
        'name' => 'کالای ' . $sku,
        'sku' => $sku,
        'stock' => $stock,
        'price' => 1000,
        'is_sellable' => true,
    ]);
}

function variantRegressionVariant(Product $product, string $code, int $stock = 0): ProductVariant
{
    return ProductVariant::create([
        'product_id' => $product->id,
        'variant_name' => 'تنوع ' . $code,
        'variant_code' => $code,
        'sell_price' => 1000,
        'stock' => $stock,
        'is_active' => true,
    ]);
}

function variantRegressionInvoice(): Invoice
{
    return Invoice::create([
        'uuid' => (string) Str::uuid(),
        'status' => Invoice::STATUS_PENDING_COLLECTION,
        'subtotal' => 0,
        'total' => 0,
    ]);
}

/*
|--------------------------------------------------------------------------
| STEP 8 — InvoiceItem variant validation
|--------------------------------------------------------------------------
*/

it('saves an invoice item for a variant product when a valid variant is supplied', function (): void {
    $product = variantRegressionProduct('VAR-OK');
    $variant = variantRegressionVariant($product, 'V1');
    $invoice = variantRegressionInvoice();

    $item = InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => 2,
        'price' => 1000,
    ]);

    expect($item->exists)->toBeTrue()
        ->and((int) $item->variant_id)->toBe($variant->id)
        ->and((int) $item->line_total)->toBe(2000);
});

it('rejects an invoice item for a variant product when variant_id is null', function (): void {
    $product = variantRegressionProduct('VAR-MISSING');
    variantRegressionVariant($product, 'V1');
    $invoice = variantRegressionInvoice();

    expect(fn () => InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'variant_id' => null,
        'quantity' => 1,
        'price' => 1000,
    ]))->toThrow(ValidationException::class, 'برای کالای دارای تنوع، انتخاب تنوع الزامی است.');

    expect(InvoiceItem::query()->where('invoice_id', $invoice->id)->count())->toBe(0);
});

it('still saves an invoice item for a product that genuinely has no variants', function (): void {
    $product = variantRegressionProduct('NO-VAR');
    $invoice = variantRegressionInvoice();

    $item = InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'variant_id' => null,
        'quantity' => 3,
        'price' => 500,
    ]);

    expect($item->exists)->toBeTrue()
        ->and($item->variant_id)->toBeNull()
        ->and((int) $item->line_total)->toBe(1500);
});

it('does not invent a variant for a product that has none', function (): void {
    $product = variantRegressionProduct('NO-VAR-2');
    $invoice = variantRegressionInvoice();

    $item = InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 500,
    ]);

    expect($item->fresh()->variant_id)->toBeNull();
});

it('reads existing invoice items without triggering variant validation', function (): void {
    $product = variantRegressionProduct('LEGACY');
    $invoice = variantRegressionInvoice();

    $item = InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 500,
    ]);

    // The product gains variants only after the legacy line already exists.
    variantRegressionVariant($product, 'LATE');

    $reloaded = InvoiceItem::query()->findOrFail($item->id);

    expect($reloaded->id)->toBe($item->id)
        ->and($reloaded->variant_id)->toBeNull();
});

it('does not silently change variant_id when unrelated invoice item fields are updated', function (): void {
    $product = variantRegressionProduct('KEEP-VAR');
    $variant = variantRegressionVariant($product, 'V1');
    variantRegressionVariant($product, 'V2');
    $invoice = variantRegressionInvoice();

    $item = InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => 1,
        'price' => 1000,
    ]);

    $item->update(['quantity' => 4]);

    expect((int) $item->fresh()->variant_id)->toBe($variant->id)
        ->and((int) $item->fresh()->line_total)->toBe(4000);
});

/*
|--------------------------------------------------------------------------
| STEP 9 — WarehouseStockService::available()
|--------------------------------------------------------------------------
*/

function variantRegressionWarehouse(): Warehouse
{
    return Warehouse::create([
        'name' => 'انبار تست موجودی',
        'type' => 'central',
        'is_active' => true,
    ]);
}

it('returns the variant stock for a valid variant', function (): void {
    $warehouse = variantRegressionWarehouse();
    $product = variantRegressionProduct('AV-1');
    $variant = variantRegressionVariant($product, 'V1');

    WarehouseStock::create([
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'quantity' => 12,
    ]);

    expect(WarehouseStockService::available($warehouse->id, $product->id, $variant->id))->toBe(12);
});

it('treats variant id 0 and -1 exactly like null', function (): void {
    $warehouse = variantRegressionWarehouse();
    $product = variantRegressionProduct('AV-NULLABLE');

    WarehouseStock::create([
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'product_variant_id' => null,
        'quantity' => 7,
    ]);

    $withNull = WarehouseStockService::available($warehouse->id, $product->id, null);
    $withZero = WarehouseStockService::available($warehouse->id, $product->id, 0);
    $withNegative = WarehouseStockService::available($warehouse->id, $product->id, -1);

    expect($withZero)->toBe($withNull)
        ->and($withNegative)->toBe($withNull)
        ->and($withNull)->toBe(7);
});

it('treats variant id 0 and -1 like null for a variant product too, by rejecting both', function (): void {
    $warehouse = variantRegressionWarehouse();
    $product = variantRegressionProduct('AV-STRICT');
    variantRegressionVariant($product, 'V1');

    $nullFailed = false;
    $zeroFailed = false;
    $negativeFailed = false;

    try {
        WarehouseStockService::available($warehouse->id, $product->id, null);
    } catch (ValidationException) {
        $nullFailed = true;
    }

    try {
        WarehouseStockService::available($warehouse->id, $product->id, 0);
    } catch (ValidationException) {
        $zeroFailed = true;
    }

    try {
        WarehouseStockService::available($warehouse->id, $product->id, -1);
    } catch (ValidationException) {
        $negativeFailed = true;
    }

    expect($zeroFailed)->toBe($nullFailed)
        ->and($negativeFailed)->toBe($nullFailed)
        ->and($nullFailed)->toBeTrue();
});

it('rejects a variant that belongs to a different product', function (): void {
    $warehouse = variantRegressionWarehouse();
    $productA = variantRegressionProduct('AV-A');
    $productB = variantRegressionProduct('AV-B');
    variantRegressionVariant($productA, 'A1');
    $foreignVariant = variantRegressionVariant($productB, 'B1');

    expect(fn () => WarehouseStockService::available($warehouse->id, $productA->id, $foreignVariant->id))
        ->toThrow(ValidationException::class);
});

it('never reports negative available stock', function (): void {
    $warehouse = variantRegressionWarehouse();
    $product = variantRegressionProduct('AV-NEG');
    $variant = variantRegressionVariant($product, 'V1');

    WarehouseStock::create([
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'quantity' => -5,
    ]);

    expect(WarehouseStockService::available($warehouse->id, $product->id, $variant->id))->toBe(0);
});

it('refuses a change() that would drive stock negative', function (): void {
    $warehouse = variantRegressionWarehouse();
    $product = variantRegressionProduct('CH-NEG');
    $variant = variantRegressionVariant($product, 'V1');

    WarehouseStock::create([
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'quantity' => 2,
    ]);

    expect(fn () => WarehouseStockService::change($warehouse->id, $product->id, -5, $variant->id))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);

    expect(WarehouseStockService::available($warehouse->id, $product->id, $variant->id))->toBe(2);
});
