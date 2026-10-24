<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\WarehouseTransfer;
use App\Services\WarehouseStockService;
use App\Support\JalaliDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PersonnelVoucherManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_personnel_voucher_store_uses_default_warehouses_jalali_date_and_moves_stock(): void
    {
        [$admin, $receiver, $central, $personnel, $category, $product, $variant] = $this->fixture();

        $this->actingAs($admin)
            ->post(route('vouchers.section.store', 'personnel'), [
                'receiver_user_id' => $receiver->id,
                'reference' => 'PER-100',
                'transferred_at_jalali' => '1405/06/04',
                'note' => 'تحویل تستی',
                'items' => [[
                    'category_id' => $category->id,
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 2,
                    'personnel_asset_code' => '2039',
                ]],
            ])
            ->assertRedirect(route('vouchers.section.index', 'personnel'));

        $transfer = WarehouseTransfer::query()->where('voucher_type', WarehouseTransfer::TYPE_PERSONNEL_ASSET)->firstOrFail();

        $this->assertSame($central->id, (int) $transfer->from_warehouse_id);
        $this->assertSame($personnel->id, (int) $transfer->to_warehouse_id);
        $this->assertSame($receiver->id, (int) $transfer->receiver_user_id);
        $this->assertSame('1405/06/04', JalaliDate::date($transfer->transferred_at));
        $this->assertSame('2039', $transfer->items()->first()->personnel_asset_code);
        $this->assertSame(8, $this->stock($central, $product, $variant));
        $this->assertSame(2, $this->stock($personnel, $product, $variant));
    }

    public function test_personnel_voucher_requires_four_digit_asset_code(): void
    {
        [$admin, $receiver,,, $category, $product, $variant] = $this->fixture();

        $this->actingAs($admin)
            ->post(route('vouchers.section.store', 'personnel'), [
                'receiver_user_id' => $receiver->id,
                'transferred_at_jalali' => '1405/06/04',
                'items' => [[
                    'category_id' => $category->id,
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 1,
                    'personnel_asset_code' => '39',
                ]],
            ])
            ->assertSessionHasErrors('items.0.personnel_asset_code');
    }

    public function test_personnel_voucher_update_keeps_id_and_recalculates_stock(): void
    {
        [$admin, $receiver, $central, $personnel, $category, $product, $variant] = $this->fixture();

        $this->actingAs($admin)->post(route('vouchers.section.store', 'personnel'), [
            'receiver_user_id' => $receiver->id,
            'reference' => 'PER-101',
            'transferred_at_jalali' => '1405/06/04',
            'items' => [[
                'category_id' => $category->id,
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'quantity' => 2,
                'personnel_asset_code' => '2039',
            ]],
        ])->assertRedirect();

        $transfer = WarehouseTransfer::query()->where('reference', 'PER-101')->firstOrFail();
        $originalId = $transfer->id;
        $editHtml = $this->get(route('vouchers.edit', $transfer))->assertOk()->getContent();
        preg_match('/const rootCategories = (.*);/', $editHtml, $matches);
        $roots = array_column(json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR), 'id');
        $this->assertContains($category->parent_id, $roots);
        $this->assertNotContains($category->id, $roots);


        $this->actingAs($admin)
            ->put(route('vouchers.update', $transfer), [
                'receiver_user_id' => $receiver->id,
                'reference' => 'PER-101-EDIT',
                'transferred_at_jalali' => '1405/06/05',
                'items' => [[
                    'category_id' => $category->id,
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 4,
                    'personnel_asset_code' => '2040',
                ]],
            ])
            ->assertRedirect(route('vouchers.section.index', 'personnel'));

        $fresh = $transfer->fresh('items');

        $this->assertSame($originalId, $fresh->id);
        $this->assertSame('PER-101-EDIT', $fresh->reference);
        $this->assertSame('1405/06/05', JalaliDate::date($fresh->transferred_at));
        $this->assertSame(4, (int) $fresh->items->first()->quantity);
        $this->assertSame('2040', $fresh->items->first()->personnel_asset_code);
        $this->assertSame(6, $this->stock($central, $product, $variant));
        $this->assertSame(4, $this->stock($personnel, $product, $variant));
    }

    public function test_personnel_voucher_delete_rolls_back_stock(): void
    {
        [$admin, $receiver, $central, $personnel, $category, $product, $variant] = $this->fixture();

        $this->actingAs($admin)->post(route('vouchers.section.store', 'personnel'), [
            'receiver_user_id' => $receiver->id,
            'reference' => 'PER-102',
            'transferred_at_jalali' => '1405/06/04',
            'items' => [[
                'category_id' => $category->id,
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'quantity' => 3,
                'personnel_asset_code' => '2041',
            ]],
        ])->assertRedirect();

        $transfer = WarehouseTransfer::query()->where('reference', 'PER-102')->firstOrFail();

        $this->actingAs($admin)->delete(route('vouchers.destroy', $transfer))->assertRedirect();

        $this->assertDatabaseMissing('warehouse_transfers', ['id' => $transfer->id]);
        $this->assertSame(10, $this->stock($central, $product, $variant));
        $this->assertSame(0, $this->stock($personnel, $product, $variant));
    }

    public function test_personnel_index_filters_by_jalali_date_and_reference(): void
    {
        [$admin, $receiver,,, $category, $product, $variant] = $this->fixture();

        $this->actingAs($admin)->post(route('vouchers.section.store', 'personnel'), [
            'receiver_user_id' => $receiver->id,
            'reference' => 'PER-FILTER',
            'transferred_at_jalali' => '1405/06/04',
            'items' => [[
                'category_id' => $category->id,
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'quantity' => 1,
                'personnel_asset_code' => '2042',
            ]],
        ])->assertRedirect();

        $this->actingAs($admin)
            ->get(route('vouchers.section.index', 'personnel') . '?reference=PER-FILTER&date_from=1405/06/04&date_to=1405/06/04')
            ->assertOk()
            ->assertSee('PER-FILTER')
            ->assertSee('1405/06/04');
    }



    public function test_personnel_product_variants_endpoint_returns_central_stock_and_editing_available_quantity(): void
    {
        [$admin, $receiver, $central,, $category, $product, $variant] = $this->fixture();

        $this->actingAs($admin)
            ->post(route('vouchers.section.store', 'personnel'), [
                'receiver_user_id' => $receiver->id,
                'reference' => 'PER-STOCK-ENDPOINT',
                'transferred_at_jalali' => '1405/06/04',
                'items' => [[
                    'category_id' => $category->id,
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 2,
                    'personnel_asset_code' => '2043',
                ]],
            ])
            ->assertRedirect();

        $transfer = WarehouseTransfer::query()->where('reference', 'PER-STOCK-ENDPOINT')->firstOrFail();

        $this->assertSame(8, $this->stock($central, $product, $variant));

        $this->actingAs($admin)
            ->getJson(route('vouchers.personnel.products.variants', $product) . '?editing_voucher_id=' . $transfer->id)
            ->assertOk()
            ->assertJsonPath('results.0.id', $variant->id)
            ->assertJsonPath('results.0.central_stock', 8)
            ->assertJsonPath('results.0.previous_quantity', 2)
            ->assertJsonPath('results.0.available_for_edit', 10);
    }

    public function test_personnel_voucher_rejects_quantity_over_central_stock_as_form_error(): void
    {
        [$admin, $receiver, $central,, $category, $product, $variant] = $this->fixture();

        WarehouseStock::query()
            ->where('warehouse_id', $central->id)
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variant->id)
            ->update(['quantity' => 1]);

        $this->actingAs($admin)
            ->from(route('vouchers.section.create', 'personnel'))
            ->post(route('vouchers.section.store', 'personnel'), [
                'receiver_user_id' => $receiver->id,
                'reference' => 'PER-OVER-STOCK',
                'transferred_at_jalali' => '1405/06/04',
                'items' => [[
                    'category_id' => $category->id,
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'quantity' => 2,
                    'personnel_asset_code' => '2044',
                ]],
            ])
            ->assertRedirect(route('vouchers.section.create', 'personnel'))
            ->assertSessionHasErrors('items.0.quantity');

        $this->assertDatabaseMissing('warehouse_transfers', ['reference' => 'PER-OVER-STOCK']);
    }

    public function test_personnel_create_page_does_not_embed_all_products_and_variants(): void
    {
        [$admin] = $this->fixture();

        $this->actingAs($admin)
            ->get(route('vouchers.section.create', 'personnel'))
            ->assertOk()
            ->assertDontSee('const products =', false)
            ->assertDontSee('const variants =', false);

        $html = $this->get(route('vouchers.section.create', 'personnel'))->getContent();

        // Optional, database-isolated fixtures for tests/browser/personnel-voucher.cjs.
        if ($directory = getenv('VOUCHER_BROWSER_FIXTURES')) {
            if (! is_dir($directory)) mkdir($directory, 0777, true);
            file_put_contents($directory.'/voucher-candidate-create.html', $html);
            file_put_contents($directory.'/voucher-candidate-hub.html', $this->get(route('vouchers.index'))->assertOk()->getContent());
        }


        $this->assertTrue(
            str_contains($html, route('vouchers.personnel.products.search'))
                || str_contains($html, str_replace('/', '\/', route('vouchers.personnel.products.search'))),
            'Personnel products search endpoint was not rendered in the create page.'
        );
    }

    public function test_variant_lookup_reads_all_central_quantities_in_one_query_without_changing_stock(): void
    {
        [$admin,, $central, $personnel,, $product, $first] = $this->fixture();
        for ($n = 1; $n <= 35; $n++) {
            $variant = ProductVariant::withoutEvents(fn () => ProductVariant::create([
                'product_id' => $product->id, 'variant_name' => 'Lookup '.$n,
                'variant_code' => 'LOOKUP-'.$n, 'variety_code' => str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                'sell_price' => 1000, 'stock' => 999, 'is_active' => true,
            ]));
            WarehouseStock::withoutEvents(fn () => WarehouseStock::create([
                'warehouse_id' => $central->id, 'product_id' => $product->id,
                'product_variant_id' => $variant->id, 'quantity' => $n,
            ]));
            WarehouseStock::withoutEvents(fn () => WarehouseStock::create([
                'warehouse_id' => $personnel->id, 'product_id' => $product->id,
                'product_variant_id' => $variant->id, 'quantity' => 200,
            ]));
        }
        $before = \Illuminate\Support\Facades\DB::table('warehouse_stocks')->orderBy('id')->get()->toJson();
        $stockQueries = 0;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$stockQueries) {
            if (str_contains(strtolower($query->sql), 'select') && str_contains($query->sql, 'warehouse_stocks')) $stockQueries++;
        });
        $response = $this->actingAs($admin)->getJson(route('vouchers.personnel.products.variants', $product))->assertOk();
        $this->assertCount(36, $response->json('results'));
        $this->assertSame(1, $stockQueries, 'Variant lookup must use one batched warehouse query, not one per variant.');
        $rows = collect($response->json('results'))->keyBy('id');
        $this->assertSame(10, $rows[$first->id]['central_stock']);
        $this->assertSame(35, $rows[$variant->id]['central_stock']);
        $this->assertSame($before, \Illuminate\Support\Facades\DB::table('warehouse_stocks')->orderBy('id')->get()->toJson());
    }

    public function test_personnel_category_picker_only_contains_roots_despite_global_category_composer(): void
    {
        [$admin,,,, $child, $product] = $this->fixture();
        $root = $child->parent;
        $otherRoot = Category::create(['name' => 'Other root', 'code' => '91']);
        $otherChild = Category::create(['name' => 'Other child', 'code' => '92', 'parent_id' => $otherRoot->id]);

        $html = $this->actingAs($admin)->get(route('vouchers.section.create', 'personnel'))->assertOk()->getContent();
        preg_match('/const rootCategories = (.*);/', $html, $matches);
        $this->assertNotEmpty($matches, 'Category picker configuration was not rendered.');
        $ids = array_column(json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR), 'id');
        $this->assertContains($root->id, $ids);
        $this->assertContains($otherRoot->id, $ids);
        $this->assertNotContains($child->id, $ids);
        $this->assertNotContains($otherChild->id, $ids);
        $this->getJson(route('vouchers.personnel.categories.children', $root))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $child->id);
        $this->getJson(route('vouchers.personnel.products.search', ['category_id' => $child->id]))
            ->assertOk()->assertJsonPath('results.0.id', $product->id);
    }

    private function fixture(): array
    {
        $role = Role::findOrCreate('Owner', 'web');
        $admin = User::factory()->create(['is_active' => true, 'can_access_erp' => true]);
        $admin->assignRole($role);

        $receiver = User::factory()->create([
            'name' => 'مجتبی عرب احمدی',
            'phone' => '09357927222',
            'personnel_code' => 'P-111',
            'is_active' => true,
            'can_access_erp' => true,
        ]);

        $central = Warehouse::query()->findOrFail(WarehouseStockService::centralWarehouseId());
        $central->forceFill([
            'name' => 'انبار مرکزی',
            'type' => 'central',
            'is_active' => true,
        ])->save();

        $personnelRoot = Warehouse::query()->firstOrCreate(
            [
                'type' => 'personnel',
                'parent_id' => null,
            ],
            [
                'name' => 'انبار پرسنل',
                'is_active' => true,
            ]
        );

        $personnel = Warehouse::query()
            ->where('type', 'personnel')
            ->whereNotNull('parent_id')
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (! $personnel) {
            $personnel = Warehouse::query()->create([
                'name' => 'انبار پرسنل',
                'type' => 'personnel',
                'parent_id' => $personnelRoot->id,
                'is_active' => true,
            ]);
        }

        $root = Category::query()->create(['name' => 'برقیجات']);
        $category = Category::query()->create(['name' => 'آداپتور', 'parent_id' => $root->id]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'کیبورد HKCW130',
            'sku' => 'HKCW130',
            'code' => '180496',
            'price' => 1000,
            'is_sellable' => true,
            'stock' => 10,
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'variant_name' => 'کیبورد HKCW130 طرح 0000',
            'variant_code' => '1804960000',
            'variety_code' => '0000',
            'sell_price' => 1000,
            'stock' => 10,
            'is_active' => true,
        ]);

        WarehouseStock::query()->updateOrCreate(
            [
                'warehouse_id' => $central->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
            ],
            ['quantity' => 10]
        );

        WarehouseStock::query()->updateOrCreate(
            [
                'warehouse_id' => $personnel->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
            ],
            ['quantity' => 0]
        );

        return [$admin, $receiver, $central, $personnel, $category, $product, $variant];
    }

    private function stock(Warehouse $warehouse, Product $product, ProductVariant $variant): int
    {
        return (int) WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variant->id)
            ->value('quantity');
    }
}
