<?php

namespace Tests\Feature;

use App\Models\{Category, Product, ProductVariant, User};
use App\Services\PreinvoiceProductFinderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class PreinvoiceCountFreezeSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        foreach (['is_bundle', 'bundle_stock_mode'] as $column) {
            if (! Schema::hasColumn('products', $column)) Schema::table('products', function (Blueprint $table) use ($column) {
                $column === 'is_bundle' ? $table->boolean($column)->default(false) : $table->string($column)->default('physical');
            });
        }
        if (! Schema::hasTable('product_bundle_components')) Schema::create('product_bundle_components', function (Blueprint $table) {
            $table->id(); $table->integer('bundle_variant_id'); $table->integer('component_variant_id'); $table->integer('quantity'); $table->timestamps();
        });
        if (! Schema::hasTable('bundle_component_allocations')) Schema::create('bundle_component_allocations', function (Blueprint $table) {
            $table->id(); $table->integer('bundle_variant_id'); $table->integer('component_variant_id'); $table->integer('remaining'); $table->timestamps();
        });
        $root = Category::create(['name' => 'Handsfree']);
        $child = Category::create(['name' => 'Wireless', 'parent_id' => $root->id]);
        $other = Category::create(['name' => 'Cables']);
        $make = function ($category, $name) {
            $product = Product::withoutEvents(fn () => Product::create(['name' => $name, 'category_id' => $category->id, 'sku' => (string) Str::uuid(), 'stock' => 5, 'price' => 1000, 'is_sellable' => true]));
            $variant = ProductVariant::withoutEvents(fn () => ProductVariant::create(['product_id' => $product->id, 'variant_name' => 'Black', 'variant_code' => (string) Str::uuid(), 'stock' => 5, 'sell_price' => 1000, 'is_active' => true, 'sales_enabled' => true]));
            \App\Models\WarehouseStock::withoutEvents(fn () => \App\Models\WarehouseStock::create(['warehouse_id' => \App\Services\WarehouseStockService::centralWarehouseId(), 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 5]));
            return $product;
        };
        $blocked = $make($child, 'Phone accessory blocked');
        $allowed = $make($other, 'Phone accessory allowed');
        DB::table('inventory_count_freezes')->updateOrInsert(['id' => 1], ['enabled' => true, 'version' => 1, 'all_products' => false, 'category_ids' => json_encode([$root->id]), 'product_ids' => json_encode([$blocked->id]), 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create(['is_active' => true, 'can_access_erp' => true]);
        $user->assignRole(Role::findOrCreate('Owner', 'web'));
        $this->actingAs($user);
        return [$blocked, $allowed];
    }

    public function test_sales_finder_excludes_frozen_descendants_but_keeps_other_categories(): void
    {
        [$blocked, $allowed] = $this->fixture();
        $results = app(PreinvoiceProductFinderService::class)->search(['q' => 'Phone', 'in_stock_only' => false]);
        $this->assertEquals([$allowed->id], array_column($results['data'], 'id'));
    }

    public function test_legacy_sales_lookup_excludes_frozen_products_and_direct_selection_is_rejected(): void
    {
        [$blocked, $allowed] = $this->fixture();
        $response = $this->getJson(route('preinvoice.api.products', ['q' => 'Phone']));
        $response->assertOk();
        $this->assertNotContains($blocked->id, array_column($response->json('data.products.data'), 'id'));
        $this->assertContains($allowed->id, array_column($response->json('data.products.data'), 'id'));
        $this->getJson(route('preinvoice.api.product', $blocked))->assertUnprocessable()->assertJsonPath('errors.stocktake.0', app(\App\Services\InventoryCountFreezeService::class)->productMessage($blocked->id));
    }

    public function test_existing_draft_can_read_frozen_product_but_no_parent_or_variant_quantity_is_selectable(): void
    {
        [$blocked] = $this->fixture();
        $order = \App\Models\PreinvoiceOrder::create(['uuid' => 'CF-'.Str::random(8), 'created_by' => auth()->id(), 'customer_name' => 'Test customer', 'customer_mobile' => '09120000000', 'status' => 'draft']);
        $order->items()->create(['product_id' => $blocked->id, 'variant_id' => $blocked->variants()->value('id'), 'quantity' => 2, 'price' => 1000, 'line_total' => 2000]);
        $response = $this->getJson(route('preinvoice.api.product', ['product' => $blocked->id, 'preinvoice_uuid' => $order->uuid, 'include_unavailable' => 1]));
        $response->assertOk()->assertJsonPath('data.product.stocktake_blocked', true)
            ->assertJsonPath('data.product.quantity', 0)
            ->assertJsonPath('data.product.varieties.0.max_selectable_for_current_form', 0)
            ->assertJsonPath('data.product.varieties.0.sales_enabled', false);
        $this->assertSame(2, (int) $order->items()->value('quantity'));
    }
}
