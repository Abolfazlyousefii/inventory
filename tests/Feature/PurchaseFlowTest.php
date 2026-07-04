<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\WarehouseStock;
use App\Models\Supplier;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_create_and_update_store_purchase_note(): void
    {
        foreach (PermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission['key'], 'web')->update([
                'name' => $permission['name'],
                'group' => $permission['group'],
            ]);
        }

        $role = Role::findOrCreate('purchase-manager', 'web');
        $role->givePermissionTo(Permission::whereIn('key', ['stock_in.create', 'stock_in.edit'])->get());

        $user = User::factory()->create();
        $user->assignRole($role);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'لوازم جانبی',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $supplier = Supplier::create(['name' => 'تامین‌کننده تست']);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'کالای تست',
            'sku' => 'SKU-TEST-1',
            'code' => 'P-TEST-1',
            'price' => 100000,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'variant_name' => 'تنوع تست',
            'variant_code' => 'V-TEST-1',
            'sell_price' => 150000,
            'buy_price' => 100000,
            'stock' => 0,
        ]);

        $note = 'توضیحات خرید باید ذخیره شود';

        $response = $this->actingAs($user)->post(route('purchases.store'), [
            'supplier_id' => $supplier->id,
            'note' => $note,
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 2,
                    'buy_price' => 100000,
                    'sell_price' => 150000,
                ],
            ],
        ]);

        $response->assertRedirect(route('purchases.index'));
        $purchase = Purchase::first();
        $this->assertSame($note, $purchase?->note);

        $updatedNote = 'توضیحات ویرایش‌شده خرید باید در لیست نمایش داده شود';

        $updateResponse = $this->actingAs($user)->put(route('purchases.update', $purchase), [
            'supplier_id' => $supplier->id,
            'note' => '',
            'description' => $updatedNote,
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 2,
                    'buy_price' => 100000,
                    'sell_price' => 150000,
                ],
            ],
        ]);

        $updateResponse->assertRedirect(route('purchases.index'));
        $this->assertSame($updatedNote, $purchase->refresh()->note);
    }

    public function test_purchase_allows_partial_variant_rows_and_persian_formatted_prices(): void
    {
        [$user, $supplier, $product, $variants] = $this->purchaseFixture(20);

        $response = $this->actingAs($user)->post(route('purchases.store'), [
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $product->id, 'variant_id' => $variants[0]->id, 'quantity' => '۲', 'buy_price' => '۱۲۰,۰۰۰', 'sell_price' => '150,000'],
                ['product_id' => $product->id, 'variant_id' => $variants[1]->id, 'quantity' => '', 'buy_price' => '', 'sell_price' => ''],
                ['product_id' => $product->id, 'variant_id' => $variants[2]->id, 'quantity' => 3, 'buy_price' => '130000', 'sell_price' => '۱۶۰۰۰۰'],
                ['product_id' => $product->id, 'variant_id' => $variants[3]->id, 'quantity' => 1, 'buy_price' => '۱۴۰,۰۰۰', 'sell_price' => '170,000'],
            ],
        ]);

        $response->assertRedirect(route('purchases.index'));
        $purchase = Purchase::query()->firstOrFail();

        $this->assertSame(3, $purchase->items()->count());
        $this->assertDatabaseHas('purchase_items', ['purchase_id' => $purchase->id, 'product_variant_id' => $variants[0]->id, 'quantity' => 2, 'buy_price' => 120000, 'sell_price' => 150000]);
        $this->assertDatabaseHas('purchase_items', ['purchase_id' => $purchase->id, 'product_variant_id' => $variants[2]->id, 'quantity' => 3, 'buy_price' => 130000, 'sell_price' => 160000]);
        $this->assertDatabaseHas('purchase_items', ['purchase_id' => $purchase->id, 'product_variant_id' => $variants[3]->id, 'quantity' => 1, 'buy_price' => 140000, 'sell_price' => 170000]);
        $this->assertDatabaseMissing('purchase_items', ['purchase_id' => $purchase->id, 'product_variant_id' => $variants[1]->id]);

        $this->assertSame(2, (int) $variants[0]->refresh()->stock);
        $this->assertSame(0, (int) $variants[1]->refresh()->stock);
        $this->assertSame(170000, (int) $variants[3]->refresh()->sell_price);
        $this->assertSame(100004, (int) $variants[4]->refresh()->sell_price);
        $this->assertSame(2, (int) WarehouseStock::query()->where('product_variant_id', $variants[0]->id)->whereNotNull('product_variant_id')->value('quantity'));
    }

    public function test_purchase_accepts_sale_price_alias_for_selected_variant_row(): void
    {
        [$user, $supplier, $product, $variants] = $this->purchaseFixture(3);

        $response = $this->actingAs($user)->post(route('purchases.store'), [
            'supplier_id' => $supplier->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'variant_id' => $variants[0]->id,
                    'quantity' => 1,
                    'buy_price' => '۱۲۰٬۰۰۰',
                    'sale_price' => '۱۲۰,۰۰۰',
                ],
                ['product_id' => $product->id, 'variant_id' => $variants[1]->id, 'quantity' => '', 'buy_price' => '', 'sale_price' => ''],
                ['product_id' => $product->id, 'variant_id' => $variants[2]->id, 'quantity' => '', 'buy_price' => '', 'sell_price' => ''],
            ],
        ]);

        $response->assertRedirect(route('purchases.index'));
        $purchase = Purchase::query()->firstOrFail();

        $this->assertSame(1, $purchase->items()->count());
        $this->assertDatabaseHas('purchase_items', [
            'purchase_id' => $purchase->id,
            'product_variant_id' => $variants[0]->id,
            'buy_price' => 120000,
            'sell_price' => 120000,
        ]);
    }

    public function test_purchase_accepts_dot_thousand_separator_for_sell_price(): void
    {
        [$user, $supplier, $product, $variants] = $this->purchaseFixture(1);

        $response = $this->actingAs($user)->post(route('purchases.store'), [
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $product->id, 'variant_id' => $variants[0]->id, 'quantity' => 1, 'buy_price' => '100.000', 'sell_price' => '120.000'],
            ],
        ]);

        $response->assertRedirect(route('purchases.index'));
        $this->assertDatabaseHas('purchase_items', ['product_variant_id' => $variants[0]->id, 'buy_price' => 100000, 'sell_price' => 120000]);
    }

    public function test_purchase_reports_missing_price_only_for_selected_variant_row(): void
    {
        [$user, $supplier, $product, $variants] = $this->purchaseFixture(2);

        $response = $this->actingAs($user)->from(route('purchases.create'))->post(route('purchases.store'), [
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $product->id, 'variant_id' => $variants[0]->id, 'quantity' => 1, 'buy_price' => '', 'sell_price' => '150000', 'variant_name' => 'مشکی / مدل A'],
                ['product_id' => $product->id, 'variant_id' => $variants[1]->id, 'quantity' => '', 'buy_price' => '', 'sell_price' => ''],
            ],
        ]);

        $response->assertRedirect(route('purchases.create'));
        $response->assertSessionHasErrors(['items.0.buy_price']);
        $this->assertStringContainsString('مشکی / مدل A', session('errors')->first('items.0.buy_price'));
        $this->assertStringNotContainsString('همه تنوع‌ها', session('errors')->first('items.0.buy_price'));
    }

    public function test_purchase_update_can_add_new_variants_without_requiring_all_variants(): void
    {
        [$user, $supplier, $product, $variants] = $this->purchaseFixture(5);

        $this->actingAs($user)->post(route('purchases.store'), [
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $product->id, 'variant_id' => $variants[0]->id, 'quantity' => 2, 'buy_price' => 100000, 'sell_price' => 150000],
            ],
        ])->assertRedirect(route('purchases.index'));

        $purchase = Purchase::query()->with('items')->firstOrFail();
        $existingItem = $purchase->items->first();

        $this->actingAs($user)->put(route('purchases.update', $purchase), [
            'supplier_id' => $supplier->id,
            'items' => [
                ['id' => $existingItem->id, 'product_id' => $product->id, 'variant_id' => $variants[0]->id, 'quantity' => 2, 'buy_price' => 100000, 'sell_price' => 150000],
                ['product_id' => $product->id, 'variant_id' => $variants[1]->id, 'quantity' => 4, 'buy_price' => 110000, 'sale_price' => '۱۶۰,۰۰۰'],
                ['product_id' => $product->id, 'variant_id' => $variants[2]->id, 'quantity' => '', 'buy_price' => '', 'sell_price' => ''],
            ],
        ])->assertRedirect(route('purchases.index'));

        $this->assertSame(2, $purchase->refresh()->items()->count());
        $this->assertDatabaseHas('purchase_items', ['purchase_id' => $purchase->id, 'product_variant_id' => $variants[1]->id, 'quantity' => 4]);
        $this->assertDatabaseMissing('purchase_items', ['purchase_id' => $purchase->id, 'product_variant_id' => $variants[2]->id]);
        $this->assertSame(2, (int) $variants[0]->refresh()->stock);
        $this->assertSame(4, (int) $variants[1]->refresh()->stock);
        $this->assertSame(0, (int) $variants[2]->refresh()->stock);
    }

    public function test_purchase_update_can_delete_zero_quantity_existing_item_without_prices(): void
    {
        [$user, $supplier, $product, $variants] = $this->purchaseFixture(1);

        $this->actingAs($user)->post(route('purchases.store'), [
            'supplier_id' => $supplier->id,
            'items' => [
                ['product_id' => $product->id, 'variant_id' => $variants[0]->id, 'quantity' => 2, 'buy_price' => 100000, 'sell_price' => 150000],
            ],
        ])->assertRedirect(route('purchases.index'));

        $purchase = Purchase::query()->with('items')->firstOrFail();
        $existingItem = $purchase->items->first();

        $this->actingAs($user)->put(route('purchases.update', $purchase), [
            'supplier_id' => $supplier->id,
            'items' => [
                ['id' => $existingItem->id, 'product_id' => $product->id, 'variant_id' => $variants[0]->id, 'quantity' => 0, 'buy_price' => '', 'sell_price' => ''],
            ],
        ])->assertRedirect(route('purchases.index'));

        $this->assertSame(0, $purchase->refresh()->items()->count());
        $this->assertSame(0, (int) $variants[0]->refresh()->stock);
    }

    public function test_purchase_from_customer_is_added_to_customer_account_statement(): void
    {
        [$user, $_supplier, $product, $variants] = $this->purchaseFixture(1);

        $customer = Customer::create([
            'first_name' => 'علی',
            'last_name' => 'حسابی',
            'mobile' => '09123456789',
        ]);

        $this->actingAs($user)->post(route('purchases.store'), [
            'supplier_id' => 'customer:' . $customer->id,
            'items' => [
                ['product_id' => $product->id, 'variant_id' => $variants[0]->id, 'quantity' => 2, 'buy_price' => 100000, 'sell_price' => 150000],
            ],
        ])->assertRedirect(route('purchases.index'));

        $purchase = Purchase::query()->firstOrFail();

        $this->assertDatabaseHas('customer_ledgers', [
            'customer_id' => $customer->id,
            'type' => 'credit',
            'amount' => 200000,
            'reference_type' => Purchase::class,
            'reference_id' => $purchase->id,
        ]);
    }

    private function purchaseFixture(int $variantCount): array
    {
        foreach (PermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission['key'], 'web')->update([
                'name' => $permission['name'],
                'group' => $permission['group'],
            ]);
        }

        $role = Role::findOrCreate('purchase-manager', 'web');
        $role->givePermissionTo(Permission::whereIn('key', ['stock_in.create', 'stock_in.edit'])->get());

        $user = User::factory()->create();
        $user->assignRole($role);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'لوازم جانبی',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $supplier = Supplier::create(['name' => 'تامین‌کننده تست']);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'گارد سیلیکون سامسونگ',
            'sku' => 'SKU-PARTIAL-1',
            'code' => 'P-PARTIAL-1',
            'price' => 100000,
        ]);

        $variants = collect(range(1, $variantCount))->map(fn (int $number) => ProductVariant::create([
            'product_id' => $product->id,
            'variant_name' => 'تنوع ' . $number,
            'variant_code' => 'V-PARTIAL-' . $number,
            'sell_price' => 100000 + $number,
            'buy_price' => 90000 + $number,
            'stock' => 0,
        ]));

        return [$user, $supplier, $product, $variants];
    }

}
