<?php

namespace Tests\Feature;

use App\Models\BundleAssembly;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use App\Services\ProductBundleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductBundleRecipeEditingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['is_bundle', 'bundle_stock_mode'] as $column) {
            if (!Schema::hasColumn('products', $column)) {
                Schema::table('products', function (Blueprint $table) use ($column): void {
                    if ($column === 'is_bundle') $table->boolean($column)->default(false);
                    else $table->string($column)->default('physical');
                });
            }
        }
        if (!Schema::hasTable('product_bundle_components')) {
            Schema::create('product_bundle_components', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('bundle_variant_id');
                $table->unsignedBigInteger('component_variant_id');
                $table->unsignedInteger('quantity');
                $table->string('discount_type')->default('percent');
                $table->unsignedBigInteger('discount_value')->default(0);
                $table->timestamps();
                $table->unique(['bundle_variant_id', 'component_variant_id']);
            });
        }
        if (!Schema::hasTable('bundle_component_allocations')) {
            Schema::create('bundle_component_allocations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('bundle_variant_id');
                $table->unsignedBigInteger('component_variant_id');
                $table->unsignedInteger('remaining');
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('bundle_assemblies')) {
            Schema::create('bundle_assemblies', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('bundle_variant_id');
                $table->unsignedBigInteger('warehouse_id');
                $table->unsignedBigInteger('performed_by')->nullable();
                $table->string('operation');
                $table->unsignedInteger('quantity');
                $table->json('components_snapshot');
                $table->timestamps();
            });
        }
        config(['database.connections.site' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::purge('site');
        Schema::connection('site')->create('products', function (Blueprint $table): void {
            $table->id();
            $table->boolean('published')->default(false);
        });
        Schema::connection('site')->create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('price_id');
        });
    }

    /** @return array{ProductVariant, ProductVariant} */
    private function catalog(string $mode = 'physical'): array
    {
        $category = Category::create(['name' => 'Recipe editing test', 'code' => '78']);
        $component = Product::withoutEvents(fn () => Product::create([
            'name' => 'Component', 'category_id' => $category->id,
            'code' => '780001', 'sku' => 'TEST-COMPONENT', 'is_sellable' => true,
            'is_bundle' => false, 'price' => 1000,
        ]));
        $componentVariant = ProductVariant::withoutEvents(fn () => ProductVariant::create([
            'product_id' => $component->id, 'variant_name' => 'Component variant',
            'variant_code' => 'TEST-C1', 'variety_code' => '0000',
            'is_active' => true, 'sales_enabled' => true, 'sell_price' => 1000,
        ]));
        $bundle = Product::withoutEvents(fn () => Product::create([
            'name' => 'Editable bundle', 'category_id' => $category->id,
            'code' => '780002', 'sku' => 'TEST-BUNDLE', 'is_sellable' => true,
            'is_bundle' => true, 'bundle_stock_mode' => $mode, 'price' => 2000,
        ]));
        $bundleVariant = ProductVariant::withoutEvents(fn () => ProductVariant::create([
            'product_id' => $bundle->id, 'variant_name' => 'Bundle variant',
            'variant_code' => 'TEST-B1', 'variety_code' => '0000',
            'is_active' => true, 'sales_enabled' => true, 'sell_price' => 2000,
        ]));
        app(ProductBundleService::class)->defineRecipe((int) $bundleVariant->id, [
            ['variant_id' => $componentVariant->id, 'quantity' => 2],
        ]);
        return [$bundleVariant, $componentVariant];
    }

    public function test_past_assembly_without_remaining_stock_does_not_permanently_lock_recipe(): void
    {
        [$bundleVariant, $componentVariant] = $this->catalog();
        BundleAssembly::create([
            'bundle_variant_id' => $bundleVariant->id, 'warehouse_id' => 1,
            'operation' => 'assemble', 'quantity' => 1,
            'components_snapshot' => [$componentVariant->id => 2],
        ]);

        app(ProductBundleService::class)->defineRecipe((int) $bundleVariant->id, [
            ['variant_id' => $componentVariant->id, 'quantity' => 3],
        ]);

        $this->assertDatabaseHas('product_bundle_components', [
            'bundle_variant_id' => $bundleVariant->id,
            'component_variant_id' => $componentVariant->id, 'quantity' => 3,
        ]);
        $this->assertDatabaseCount('bundle_assemblies', 1);
    }

    public function test_finished_stock_allows_idempotent_saves_discount_edits_and_composition_changes(): void
    {
        [$bundleVariant, $componentVariant] = $this->catalog();
        WarehouseStock::withoutEvents(fn () => WarehouseStock::create([
            'warehouse_id' => 1, 'product_id' => $bundleVariant->product_id,
            'product_variant_id' => $bundleVariant->id, 'quantity' => 1,
        ]));

        $service = app(ProductBundleService::class);
        $service->defineRecipe((int) $bundleVariant->id, [
            ['variant_id' => $componentVariant->id, 'quantity' => 2],
        ]);
        $service->defineRecipe((int) $bundleVariant->id, [
            ['variant_id' => $componentVariant->id, 'quantity' => 2,
                'discount_type' => 'fixed', 'discount_value' => 100],
        ]);
        $this->assertDatabaseHas('product_bundle_components', [
            'bundle_variant_id' => $bundleVariant->id,
            'component_variant_id' => $componentVariant->id,
            'quantity' => 2, 'discount_type' => 'fixed', 'discount_value' => 100,
        ]);

        // Editing the recipe must work even while finished bundles are in stock.
        $service->defineRecipe((int) $bundleVariant->id, [
            ['variant_id' => $componentVariant->id, 'quantity' => 3],
        ]);
        $this->assertDatabaseHas('product_bundle_components', [
            'bundle_variant_id' => $bundleVariant->id,
            'component_variant_id' => $componentVariant->id, 'quantity' => 3,
        ]);
        // Saving a new recipe must not silently deduct or erase finished stock.
        $this->assertDatabaseHas('warehouse_stocks', [
            'product_variant_id' => $bundleVariant->id, 'quantity' => 1,
        ]);
    }

    public function test_live_publication_blocks_automatic_composition_but_existing_allocations_remain_editable(): void
    {
        [$bundleVariant, $componentVariant] = $this->catalog('automatic');
        $service = app(ProductBundleService::class);
        $newRecipe = [['variant_id' => $componentVariant->id, 'quantity' => 3]];
        DB::connection('site')->table('products')->insert([
            'id' => $bundleVariant->product_id, 'published' => true,
        ]);
        try {
            $service->defineRecipe((int) $bundleVariant->id, $newRecipe);
            $this->fail('Published bundle composition must remain protected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('components', $e->errors());
        }

        DB::connection('site')->table('products')
            ->where('id', $bundleVariant->product_id)->update(['published' => false]);
        DB::table('bundle_component_allocations')->insert([
            'bundle_variant_id' => $bundleVariant->id,
            'component_variant_id' => $componentVariant->id,
            'remaining' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Existing allocated units for a retained component should no longer
        // block recipe edits, and the allocation must not be reduced or deleted.
        $service->defineRecipe((int) $bundleVariant->id, $newRecipe);
        $this->assertDatabaseHas('product_bundle_components', [
            'bundle_variant_id' => $bundleVariant->id,
            'component_variant_id' => $componentVariant->id, 'quantity' => 3,
        ]);
        $this->assertDatabaseHas('bundle_component_allocations', [
            'bundle_variant_id' => $bundleVariant->id,
            'component_variant_id' => $componentVariant->id,
            'remaining' => 2,
        ]);
    }

    public function test_historical_site_order_does_not_block_unpublished_unallocated_recipe(): void
    {
        [$bundleVariant, $componentVariant] = $this->catalog('automatic');
        DB::connection('site')->table('order_items')->insert(['price_id' => $bundleVariant->id]);
        app(ProductBundleService::class)->defineRecipe((int) $bundleVariant->id, [
            ['variant_id' => $componentVariant->id, 'quantity' => 4],
        ]);
        $this->assertDatabaseHas('product_bundle_components', [
            'bundle_variant_id' => $bundleVariant->id,
            'component_variant_id' => $componentVariant->id, 'quantity' => 4,
        ]);
    }
}
