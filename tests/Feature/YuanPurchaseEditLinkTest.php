<?php

use App\Models\{Category,CnyExchangeRate,Product,ProductCnyPricing,Purchase,PurchaseItem,Supplier,User};
use App\Services\CnyPriceCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('links a yuan product to its newest yuan purchase, not a newer domestic purchase', function () {
    config([
        'cache.default' => 'array', 'cny_pricing.enabled' => true,
        'cny_pricing.allow_sample' => true, 'cny_pricing.production_mode' => false,
        'navasan.enabled' => false, 'nobitex.enabled' => false,
    ]);
    $this->withoutVite();
    $actor = User::factory()->create(['is_active' => true]);
    $actor->assignRole(Role::findOrCreate('Owner', 'web'));
    $this->actingAs($actor);

    $category = Category::create(['name' => 'آزمایش یوان']);
    $product = Product::withoutEvents(fn () => Product::create([
        'name' => 'کالای یوانی', 'sku' => 'YUAN-EDIT-1', 'code' => 'YUAN-EDIT-1',
        'category_id' => $category->id, 'price' => 2920000, 'is_sellable' => true,
    ]));
    $rate = CnyExchangeRate::create(['provider' => 'sample', 'rate_rial' => 400000, 'quoted_at' => now()]);
    ProductCnyPricing::create([
        'product_id' => $product->id, 'buy_cny_units' => 50000,
        'enabled' => true, 'applied_rate_id' => $rate->id,
        'approved_by' => $actor->id, 'policy' => CnyPriceCalculator::POLICY,
    ]);
    $supplier = Supplier::create(['name' => 'فروشنده نمونه']);
    $addPurchase = function (string $origin, int $daysAgo) use ($supplier, $actor, $product): Purchase {
        $purchase = Purchase::create([
            'supplier_id' => $supplier->id, 'user_id' => $actor->id,
            'purchase_origin' => $origin, 'purchased_at' => now()->subDays($daysAgo),
            'total_amount' => 2000000,
        ]);
        PurchaseItem::create([
            'purchase_id' => $purchase->id, 'product_id' => $product->id,
            'product_name' => $product->name, 'product_code' => $product->code,
            'quantity' => 1, 'buy_price' => 2000000, 'sell_price' => 2920000,
            'line_total' => 2000000, 'buy_cny_units' => $origin === 'china' ? 50000 : null,
        ]);
        return $purchase;
    };

    $addPurchase('china', 12);
    $expected = $addPurchase('china', 6);
    $addPurchase('domestic', 1);

    $response = $this->getJson(route('products.yuan.data'));
    $response->assertOk();
    expect($response->json('rows.0.purchase_edit_url'))
        ->toBe(route('purchases.edit', ['purchase' => $expected->id, 'product_id' => $product->id]));
});
