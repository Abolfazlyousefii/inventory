<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\WarehouseInboundReceiptItem;
use App\Services\AutomaticBundleAvailabilityService;
use App\Services\ReturnedAutomaticBundleRecoveryService;
use App\Services\WarehouseStockService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReturnedAutomaticBundleRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('products', 'is_bundle')) {
            Schema::table('products', fn (Blueprint $t) => $t->boolean('is_bundle')->default(false));
        }
        if (! Schema::hasColumn('products', 'bundle_stock_mode')) {
            Schema::table('products', fn (Blueprint $t) => $t->string('bundle_stock_mode')->default('physical'));
        }
        if (! Schema::hasTable('product_bundle_components')) {
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
        if (! Schema::hasTable('bundle_component_allocations')) {
            Schema::create('bundle_component_allocations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('bundle_variant_id');
                $t->unsignedBigInteger('component_variant_id');
                $t->unsignedInteger('remaining');
                $t->timestamps();
            });
        }
        config(['database.connections.site' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('site');
        Schema::connection('site')->create('products', function (Blueprint $t) {
            $t->id(); $t->boolean('published')->default(false);
        });
    }

    private function returnedPack(): array
    {
        $category = Category::withoutEvents(fn () => Category::create(['name' => 'Returned-pack recovery']));
        $make = function (string $name, bool $bundle = false) use ($category): ProductVariant {
            $product = Product::withoutEvents(fn () => Product::create([
                'name' => $name, 'sku' => 'TEST-'.Str::random(8),
                'category_id' => $category->id, 'is_sellable' => true,
                'is_bundle' => $bundle, 'bundle_stock_mode' => $bundle ? 'automatic' : 'physical',
                'price' => 1000,
            ]));
            return ProductVariant::withoutEvents(fn () => ProductVariant::create([
                'product_id' => $product->id, 'variant_name' => $name,
                'variant_code' => 'TEST-'.Str::random(8),
                'sell_price' => 1000, 'is_active' => true, 'sales_enabled' => true,
            ]));
        };

        $a = $make('Component A');
        $b = $make('Component B');
        $bundle = $make('Automatic bundle', true);
        $warehouse = WarehouseStockService::centralWarehouseId();
        WarehouseStockService::set($warehouse, $a->product_id, $a->id, 1);
        WarehouseStockService::set($warehouse, $b->product_id, $b->id, 30);
        WarehouseStockService::set($warehouse, $bundle->product_id, $bundle->id, 1);
        foreach ([$a->id => 10, $b->id => 10] as $id => $qty) {
            DB::table('product_bundle_components')->insert([
                'bundle_variant_id' => $bundle->id, 'component_variant_id' => $id,
                'quantity' => $qty, 'discount_type' => 'percent', 'discount_value' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $source = StockMovement::create([
            'product_id' => $bundle->product_id,
            'product_variant_id' => $bundle->id,
            'warehouse_id' => $warehouse,
            'type' => 'in', 'reason' => 'invoice_cancel_return',
            'quantity' => 1, 'stock_before' => 0, 'stock_after' => 1,
            'reference' => 'WI-000682',
            'reference_type' => WarehouseInboundReceiptItem::class,
            'reference_id' => 5845,
        ]);

        return [$bundle, $a, $b, $warehouse, $source];
    }

    public function test_previews_one_returned_pack_without_touching_any_stock(): void
    {
        [$bundle, $a, $b, $warehouse, $source] = $this->returnedPack();
        $plan = app(ReturnedAutomaticBundleRecoveryService::class)->preview($bundle->id, $source->id);

        $this->assertSame(1, $plan['finished_stock']);
        $this->assertSame([$a->id => 10, $b->id => 10], $plan['recipe']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $plan['recipe_hash']);
        $this->assertSame(1, WarehouseStockService::available($warehouse, $bundle->product_id, $bundle->id));
        $this->assertSame(1, WarehouseStockService::available($warehouse, $a->product_id, $a->id));
        $this->assertSame(30, WarehouseStockService::available($warehouse, $b->product_id, $b->id));
        $this->assertSame(0, DB::table('bundle_component_allocations')->count());
    }

    public function test_reclassifies_one_physically_verified_return_without_deducting_ordinary_components_twice(): void
    {
        [$bundle, $a, $b, $warehouse, $source] = $this->returnedPack();
        $service = app(ReturnedAutomaticBundleRecoveryService::class);
        $hash = $service->preview($bundle->id, $source->id)['recipe_hash'];

        $service->apply($bundle->id, $source->id, $hash, true);

        $this->assertSame(0, WarehouseStockService::available($warehouse, $bundle->product_id, $bundle->id));
        $this->assertSame(1, WarehouseStockService::available($warehouse, $a->product_id, $a->id));
        $this->assertSame(30, WarehouseStockService::available($warehouse, $b->product_id, $b->id));
        $this->assertDatabaseHas('bundle_component_allocations', [
            'bundle_variant_id' => $bundle->id, 'component_variant_id' => $a->id, 'remaining' => 10,
        ]);
        $this->assertDatabaseHas('bundle_component_allocations', [
            'bundle_variant_id' => $bundle->id, 'component_variant_id' => $b->id, 'remaining' => 10,
        ]);
        $this->assertSame(1, app(AutomaticBundleAvailabilityService::class)->available($bundle->id));
        $this->assertSame(1, StockMovement::where('reason', 'bundle_return_reclassified')->count());

        try {
            $service->apply($bundle->id, $source->id, $hash, true);
            $this->fail('Repeated conversion must be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(1, StockMovement::where('reason', 'bundle_return_reclassified')->count());
            $this->assertSame(1, app(AutomaticBundleAvailabilityService::class)->available($bundle->id));
        }
    }

    public function test_never_converts_without_matching_recipe_hash_and_physical_confirmation(): void
    {
        [$bundle, $a, $b, $warehouse, $source] = $this->returnedPack();
        $service = app(ReturnedAutomaticBundleRecoveryService::class);
        $hash = $service->preview($bundle->id, $source->id)['recipe_hash'];

        foreach ([[$hash, false], [str_repeat('0', 64), true]] as [$providedHash, $checked]) {
            try {
                $service->apply($bundle->id, $source->id, $providedHash, $checked);
                $this->fail('Unsafe conversion must not be applied.');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame(1, WarehouseStockService::available($warehouse, $bundle->product_id, $bundle->id));
                $this->assertSame(0, DB::table('bundle_component_allocations')->count());
            }
        }
    }
}
