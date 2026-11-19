<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\BundleComponentAllocationService;
use App\Services\ProductBundleService;
use App\Services\WarehouseStockService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class BundleReleasedComponentStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (!Schema::hasColumn('products', 'is_bundle')) {
            Schema::table('products', fn (Blueprint $t) => $t->boolean('is_bundle')->default(false));
        }
        if (!Schema::hasColumn('products', 'bundle_stock_mode')) {
            Schema::table('products', fn (Blueprint $t) => $t->string('bundle_stock_mode')->default('physical'));
        }
        if (!Schema::hasTable('product_bundle_components')) {
            Schema::create('product_bundle_components', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('bundle_variant_id');
                $t->unsignedBigInteger('component_variant_id');
                $t->unsignedInteger('quantity');
                $t->string('discount_type')->default('percent');
                $t->unsignedBigInteger('discount_value')->default(0);
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('bundle_component_allocations')) {
            Schema::create('bundle_component_allocations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('bundle_variant_id');
                $t->unsignedBigInteger('component_variant_id');
                $t->unsignedInteger('remaining');
                $t->timestamps();
            });
        }
        config(['database.connections.site' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::purge('site');
        Schema::connection('site')->create('products', function (Blueprint $t) {
            $t->id(); $t->boolean('published')->default(false);
        });
        Schema::connection('site')->create('order_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('price_id');
        });
    }

    private function createBundle(): array
    {
        $category = Category::withoutEvents(fn () => Category::create(['name' => 'Allocation release test']));
        $make = function (string $name, bool $bundle = false) use ($category) {
            $product = Product::withoutEvents(fn () => Product::create([
                'name' => $name, 'sku' => 'TEST-'.Str::random(10),
                'category_id' => $category->id, 'is_sellable' => true,
                'is_bundle' => $bundle, 'bundle_stock_mode' => $bundle ? 'automatic' : 'physical',
                'price' => 1000,
            ]));
            $variant = ProductVariant::withoutEvents(fn () => ProductVariant::create([
                'product_id' => $product->id, 'variant_name' => $name,
                'variant_code' => 'TEST-'.Str::random(10), 'sell_price' => 1000,
                'is_active' => true, 'sales_enabled' => true,
            ]));
            return $variant;
        };
        $a = $make('Component A');
        $b = $make('Component B');
        $bundle = $make('Automatic bundle', true);
        $central = WarehouseStockService::centralWarehouseId();
        WarehouseStockService::set($central, (int) $a->product_id, (int) $a->id, 100);
        WarehouseStockService::set($central, (int) $b->product_id, (int) $b->id, 100);
        app(ProductBundleService::class)->defineRecipe((int) $bundle->id, [
            ['variant_id' => $a->id, 'quantity' => 10],
            ['variant_id' => $b->id, 'quantity' => 5],
        ]);
        app(BundleComponentAllocationService::class)->setAllocations((int) $bundle->id, [
            (int) $a->id => 50, (int) $b->id => 25,
        ]);
        return [$bundle, $a, $b, $central];
    }

    public function test_reducing_units_per_bundle_returns_freed_stock_only_once(): void
    {
        [$bundle, $a, $b, $central] = $this->createBundle();
        $service = app(ProductBundleService::class);
        $recipe = [
            ['variant_id' => $a->id, 'quantity' => 8],
            ['variant_id' => $b->id, 'quantity' => 5],
        ];
        $service->defineRecipe((int) $bundle->id, $recipe);
        $this->assertSame(60, WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id));
        $this->assertSame(75, WarehouseStockService::available($central, (int) $b->product_id, (int) $b->id));
        $this->assertDatabaseHas('bundle_component_allocations', [
            'bundle_variant_id' => $bundle->id, 'component_variant_id' => $a->id, 'remaining' => 40,
        ]);
        $service->defineRecipe((int) $bundle->id, $recipe);
        $this->assertSame(60, WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id));
    }

    public function test_release_command_previews_then_returns_only_explicit_excess(): void
    {
        [$bundle, $a, $b, $central] = $this->createBundle();
        $this->artisan('bundle:release-unused', [
            'bundle_variant_id' => $bundle->id, '--keep' => 2,
        ])->assertExitCode(0);
        $this->assertSame(50, WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id));
        $this->assertDatabaseHas('bundle_component_allocations', [
            'bundle_variant_id' => $bundle->id, 'component_variant_id' => $a->id, 'remaining' => 50,
        ]);
        $this->artisan('bundle:release-unused', [
            'bundle_variant_id' => $bundle->id, '--keep' => 2, '--apply' => true,
        ])->assertExitCode(0);
        $this->assertSame(80, WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id));
        $this->assertSame(90, WarehouseStockService::available($central, (int) $b->product_id, (int) $b->id));
        $this->assertDatabaseHas('bundle_component_allocations', [
            'bundle_variant_id' => $bundle->id, 'component_variant_id' => $a->id, 'remaining' => 20,
        ]);
        $this->assertDatabaseHas('bundle_component_allocations', [
            'bundle_variant_id' => $bundle->id, 'component_variant_id' => $b->id, 'remaining' => 10,
        ]);
        $this->artisan('bundle:release-unused', [
            'bundle_variant_id' => $bundle->id, '--keep' => 2, '--apply' => true,
        ])->assertExitCode(0);
        $this->assertSame(80, WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id));
    }
}
