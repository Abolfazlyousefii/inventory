<?php

use App\Models\{Category, Product, ProductVariant, Purchase, PurchaseItem, Supplier, SupplierLedger, User};
use App\Services\{SupplierLedgerService, WarehouseStockService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    $this->operator = User::factory()->create(['is_active' => true]);
    $this->operator->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($this->operator);
    $this->supplier = Supplier::create(['name' => 'Deletion safety supplier']);
    $this->warehouseId = WarehouseStockService::centralWarehouseId();
});

function purchaseDeletionFixture(int $stock, int $amount = 3000): array
{
    $product = Product::create([
        'category_id' => Category::firstOrCreate(['name' => 'Deletion safety'])->id,
        'name' => 'کالای آزمایشی حذف', 'sku' => 'DELETE-'.Product::count(),
        'code' => (string) (940001 + Product::count()), 'price' => 1500, 'stock' => 0, 'is_sellable' => true,
    ]);
    $variant = ProductVariant::create([
        'product_id' => $product->id, 'variant_name' => 'مدل آزمایشی',
        'variety_code' => '0000', 'variant_code' => 'DELETE-'.$product->id,
        'sell_price' => 1500, 'buy_price' => 1000, 'stock' => 0, 'reserved' => 0,
        'is_active' => true, 'sales_enabled' => true,
    ]);
    $purchase = Purchase::create([
        'supplier_id' => test()->supplier->id, 'user_id' => test()->operator->id,
        'purchased_at' => now(), 'subtotal_amount' => $amount, 'total_amount' => $amount,
    ]);
    $item = PurchaseItem::create([
        'purchase_id' => $purchase->id, 'product_id' => $product->id,
        'product_variant_id' => $variant->id, 'product_name' => $product->name,
        'product_code' => $product->sku, 'variant_name' => $variant->variant_name,
        'quantity' => 3, 'buy_price' => intdiv($amount, 3), 'sell_price' => 1500,
        'line_subtotal' => $amount, 'line_total' => $amount,
    ]);
    WarehouseStockService::set(test()->warehouseId, $product->id, $variant->id, $stock);
    app(SupplierLedgerService::class)->syncPurchaseCredit($purchase);

    return compact('purchase', 'product', 'variant', 'item');
}

it('redirects insufficient stock deletion back with a visible actionable error and preserves the ledger', function (int $stock, int $amount): void {
    $f = purchaseDeletionFixture($stock, $amount);
    $ledgerBefore = SupplierLedger::query()->get()->toArray();

    $response = $this->from(route('purchases.index'))->delete(route('purchases.destroy', $f['purchase']));
    $response->assertRedirect(route('purchases.index'))->assertSessionHasErrors('items');
    $message = session('errors')->first('items');
    expect($message)->toContain($f['product']->name, (string) $stock, '3', 'موجودی', 'حذف');
    $this->assertDatabaseHas('purchases', ['id' => $f['purchase']->id]);
    $this->assertDatabaseHas('purchase_items', ['id' => $f['item']->id, 'quantity' => 3]);
    expect(WarehouseStockService::available($this->warehouseId, $f['product']->id, $f['variant']->id))->toBe($stock)
        ->and(SupplierLedger::query()->get()->toArray())->toBe($ledgerBefore);
    $this->get(route('purchases.index'))->assertOk()->assertSeeText($message);
})->with(['consumed zero-cost purchase' => [0, 0], 'partly consumed purchase' => [2, 3000]]);

it('deletes a purchase with sufficient stock and removes only its supplier credit', function (): void {
    $f = purchaseDeletionFixture(5);
    $this->delete(route('purchases.destroy', $f['purchase']))
        ->assertRedirect(route('purchases.index'))->assertSessionHasNoErrors()->assertSessionHas('success');

    $this->assertDatabaseMissing('purchases', ['id' => $f['purchase']->id]);
    $this->assertDatabaseMissing('purchase_items', ['id' => $f['item']->id]);
    $this->assertDatabaseMissing('supplier_ledgers', ['reference_type' => Purchase::class, 'reference_id' => $f['purchase']->id]);
    expect(WarehouseStockService::available($this->warehouseId, $f['product']->id, $f['variant']->id))->toBe(2)
        ->and((int) $f['variant']->fresh()->sell_price)->toBe(1500);
});

it('rolls back earlier rows and supplier credit when a later row cannot be removed', function (): void {
    $first = purchaseDeletionFixture(5);
    $second = purchaseDeletionFixture(0);
    $second['item']->update(['purchase_id' => $first['purchase']->id]);
    $ledgerBefore = SupplierLedger::query()->get()->toArray();

    $this->from(route('purchases.index'))->delete(route('purchases.destroy', $first['purchase']))
        ->assertRedirect(route('purchases.index'))->assertSessionHasErrors('items');

    expect(WarehouseStockService::available($this->warehouseId, $first['product']->id, $first['variant']->id))->toBe(5)
        ->and($first['purchase']->fresh()->items()->count())->toBe(2)
        ->and(SupplierLedger::query()->get()->toArray())->toBe($ledgerBefore);
});
