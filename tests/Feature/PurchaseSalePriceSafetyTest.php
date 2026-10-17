<?php

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    $this->operator = User::factory()->create(['is_active' => true]);
    $this->operator->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($this->operator);
    $this->supplier = Supplier::query()->create(['name' => 'Regression supplier']);
    $this->warehouseId = WarehouseStockService::centralWarehouseId();
});

/** Mirrors the 578 purchase: the historical price is 5m, today's price is 4.8m. */
function purchasePriceFixture(): array
{
    $product = Product::query()->create([
        'name' => 'لنز سری ۱۸ آزمایشی', 'sku' => 'PRICE-SAFETY-18',
        'price' => 4_800_000, 'stock' => 5, 'is_sellable' => true,
    ]);
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id, 'variant_name' => '18PM',
        'variant_code' => 'SAFETY-18PM', 'sell_price' => 4_800_000,
        'buy_price' => 2_100_000, 'stock' => 5, 'reserved' => 0,
        'is_active' => true, 'sales_enabled' => true,
    ]);
    $purchase = Purchase::query()->create([
        'supplier_id' => test()->supplier->id, 'warehouse_id' => test()->warehouseId,
        'user_id' => test()->operator->id, 'purchased_at' => now()->subMonth(),
        'subtotal_amount' => 6_000_000, 'total_amount' => 6_000_000,
    ]);
    $item = PurchaseItem::query()->create([
        'purchase_id' => $purchase->id, 'product_id' => $product->id,
        'product_variant_id' => $variant->id, 'product_name' => $product->name,
        'variant_name' => '18PM', 'quantity' => 3, 'buy_price' => 2_000_000,
        'sell_price' => 5_000_000, 'line_subtotal' => 6_000_000,
        'line_total' => 6_000_000,
    ]);
    WarehouseStockService::set(test()->warehouseId, $product->id, $variant->id, 5);

    return compact('product', 'variant', 'purchase', 'item');
}

function purchasePricePayload(array $fixture, array $overrides = [], array $fields = []): array
{
    $row = array_merge([
        'id' => $fixture['item']->id,
        'product_id' => $fixture['product']->id,
        'variant_id' => $fixture['variant']->id,
        'quantity' => 4,
        'buy_price' => 2_000_000,
        'sell_price' => 5_000_000,
        'expected_current_sell_price' => 4_800_000,
    ], $overrides);

    return array_merge([
        'submission_token' => (string) Str::uuid(),
        'supplier_id' => (string) test()->supplier->id,
        'items_json' => json_encode([$row], JSON_THROW_ON_ERROR),
    ], $fields);
}

it('keeps the current selling and buying prices when a historical purchase is edited without price changes', function (): void {
    $f = purchasePriceFixture();

    $this->put(route('purchases.update', $f['purchase']), purchasePricePayload($f))
        ->assertRedirect(route('purchases.index'))->assertSessionHasNoErrors();

    expect((int) $f['variant']->fresh()->sell_price)->toBe(4_800_000)
        ->and((int) $f['variant']->fresh()->buy_price)->toBe(2_100_000)
        ->and((int) $f['item']->fresh()->sell_price)->toBe(5_000_000)
        ->and((int) $f['item']->fresh()->quantity)->toBe(4)
        ->and(ActivityLog::query()->where('action', 'purchase_sale_price_explicitly_changed')->count())->toBe(0);
});

it('rejects an intentional change without explicit confirmation and rolls back the document', function (): void {
    $f = purchasePriceFixture();
    $data = purchasePricePayload($f, ['sell_price' => 5_300_000]);

    $this->from(route('purchases.edit', $f['purchase']))
        ->put(route('purchases.update', $f['purchase']), $data)
        ->assertSessionHasErrors('sale_price_change_confirmed');

    expect((int) $f['variant']->fresh()->sell_price)->toBe(4_800_000)
        ->and((int) $f['item']->fresh()->quantity)->toBe(3)
        ->and((int) $f['item']->fresh()->sell_price)->toBe(5_000_000);
});

it('applies an approved price change and logs the old and new prices and actor', function (): void {
    $f = purchasePriceFixture();
    $data = purchasePricePayload($f, ['sell_price' => 5_300_000], [
        'sale_price_change_confirmed' => '1',
        'sale_price_change_reason' => 'مدیر تغییر قیمت فروش را تأیید کرد.',
    ]);

    $this->put(route('purchases.update', $f['purchase']), $data)
        ->assertRedirect(route('purchases.index'))->assertSessionHasNoErrors();

    expect((int) $f['variant']->fresh()->sell_price)->toBe(5_300_000);
    $audit = ActivityLog::query()->where('action', 'purchase_sale_price_explicitly_changed')->firstOrFail();
    expect((int) $audit->user_id)->toBe((int) $this->operator->id)
        ->and((int) $audit->properties['purchase_id'])->toBe((int) $f['purchase']->id)
        ->and((int) $audit->properties['variant_id'])->toBe((int) $f['variant']->id)
        ->and((int) $audit->properties['old_price'])->toBe(4_800_000)
        ->and((int) $audit->properties['new_price'])->toBe(5_300_000);
});

it('rejects stale-price confirmations before changing the purchase or inventory', function (): void {
    $f = purchasePriceFixture();
    $data = purchasePricePayload($f, [
        'sell_price' => 5_300_000,
        'expected_current_sell_price' => 5_000_000,
    ], [
        'sale_price_change_confirmed' => '1',
        'sale_price_change_reason' => 'درخواست قدیمی',
    ]);

    $this->put(route('purchases.update', $f['purchase']), $data)
        ->assertSessionHasErrors('items');

    expect((int) $f['variant']->fresh()->sell_price)->toBe(4_800_000)
        ->and((int) $f['item']->fresh()->quantity)->toBe(3)
        ->and(ActivityLog::query()->where('action', 'purchase_sale_price_explicitly_changed')->count())->toBe(0);
});

it('requires sale price permission in addition to permission to edit purchases', function (): void {
    $f = purchasePriceFixture();
    $user = User::factory()->create(['is_active' => true]);
    $user->givePermissionTo(Permission::findOrCreate('stock_in.edit', 'web'));
    $this->actingAs($user);
    $data = purchasePricePayload($f, ['sell_price' => 5_300_000], [
        'sale_price_change_confirmed' => '1',
        'sale_price_change_reason' => 'attempt without permissions',
    ]);

    $this->put(route('purchases.update', $f['purchase']), $data)->assertForbidden();
    expect((int) $f['variant']->fresh()->sell_price)->toBe(4_800_000)
        ->and((int) $f['item']->fresh()->quantity)->toBe(3);
});
