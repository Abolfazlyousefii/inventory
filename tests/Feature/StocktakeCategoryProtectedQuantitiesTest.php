<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Services\ReservationQueryService;
use App\Services\StocktakeCategoryWorkspaceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Uses only temporary SQLite :memory: fixtures; never touches production stock.
 */
final class StocktakeCategoryProtectedQuantitiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('categories', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('parent_id')->nullable(); $t->string('name');
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('category_id'); $t->string('name');
            $t->string('sku')->nullable(); $t->boolean('is_bundle')->default(false);
            $t->string('bundle_stock_mode')->nullable();
        });
        Schema::create('product_variants', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('product_id'); $t->string('variant_name')->nullable();
            $t->string('variety_name')->nullable(); $t->string('variant_code')->nullable();
            $t->string('variety_code')->nullable(); $t->boolean('is_active')->default(true);
            $t->boolean('sales_enabled')->default(true);
        });
        Schema::create('warehouses', function (Blueprint $t) {
            $t->id(); $t->string('type'); $t->string('name');
        });
        Schema::create('warehouse_stocks', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('warehouse_id'); $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('product_variant_id'); $t->integer('quantity');
        });
        Schema::create('product_bundle_components', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('bundle_variant_id');
            $t->unsignedBigInteger('component_variant_id'); $t->integer('quantity');
        });
        Schema::create('bundle_component_allocations', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('bundle_variant_id');
            $t->unsignedBigInteger('component_variant_id'); $t->integer('remaining');
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id(); $t->string('status')->nullable(); $t->timestamp('shipped_at')->nullable();
        });
        Schema::create('invoice_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('invoice_id');
            $t->unsignedBigInteger('variant_id'); $t->integer('quantity');
        });
        Schema::create('site_stock_holds', function (Blueprint $t) {
            $t->id(); $t->string('status'); $t->unsignedBigInteger('invoice_id')->nullable();
        });
        Schema::create('site_stock_hold_lines', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('hold_id');
            $t->unsignedBigInteger('variant_id'); $t->integer('quantity');
        });

        DB::table('categories')->insert([
            ['id'=>10,'parent_id'=>null,'name'=>'Parent'],
            ['id'=>11,'parent_id'=>10,'name'=>'Child'],
            ['id'=>12,'parent_id'=>null,'name'=>'Outside'],
        ]);
        DB::table('products')->insert([
            ['id'=>1,'category_id'=>11,'name'=>'Countable component','is_bundle'=>0],
            ['id'=>2,'category_id'=>11,'name'=>'Automatic bundle','is_bundle'=>1,'bundle_stock_mode'=>'automatic'],
            ['id'=>3,'category_id'=>12,'name'=>'Outside product','is_bundle'=>0],
            ['id'=>4,'category_id'=>11,'name'=>'Second countable','is_bundle'=>0],
        ]);
        DB::table('product_variants')->insert([
            ['id'=>1,'product_id'=>1,'variant_name'=>'Component','variant_code'=>'TEST-C'],
            ['id'=>2,'product_id'=>2,'variant_name'=>'Automatic bundle','variant_code'=>'TEST-B'],
            ['id'=>3,'product_id'=>3,'variant_name'=>'Outside','variant_code'=>'OUTSIDE'],
            ['id'=>4,'product_id'=>4,'variant_name'=>'Second','variant_code'=>'SECOND'],
        ]);
        DB::table('warehouses')->insert(['id'=>1,'type'=>'central','name'=>'انبار مرکزی']);
        DB::table('warehouse_stocks')->insert([
            ['warehouse_id'=>1,'product_id'=>1,'product_variant_id'=>1,'quantity'=>10],
            ['warehouse_id'=>1,'product_id'=>4,'product_variant_id'=>4,'quantity'=>7],
        ]);
        DB::table('product_bundle_components')->insert([
            'bundle_variant_id'=>2, 'component_variant_id'=>1, 'quantity'=>1,
        ]);
        DB::table('bundle_component_allocations')->insert([
            'bundle_variant_id'=>2, 'component_variant_id'=>1, 'remaining'=>1,
        ]);
        DB::table('site_stock_holds')->insert(['id'=>1,'status'=>'held']);
        DB::table('site_stock_hold_lines')->insert([
            'hold_id'=>1, 'variant_id'=>1, 'quantity'=>3,
        ]);
        DB::table('invoices')->insert(['id'=>1,'status'=>Invoice::STATUS_PENDING_COLLECTION]);
        DB::table('invoice_items')->insert([
            'invoice_id'=>1,'variant_id'=>1,'quantity'=>5,
        ]);

        $this->mock(ReservationQueryService::class)
            ->shouldReceive('quantitiesByVariant')
            ->andReturnUsing(static function (
                ?int $productId = null,
                array $variantIds = [],
                ?bool $official = null,
                $at = null
            ) {
                return in_array(2, $variantIds, true)
                    ? collect([2=>2]) // Two reserved automatic bundles
                    : collect([1=>2,4=>0]); // Two ordinary component reservations
            });
    }

    public function test_preview_uses_the_same_five_bucket_physical_formula_as_finalization(): void
    {
        $before = DB::table('warehouse_stocks')->orderBy('product_variant_id')->pluck('quantity', 'product_variant_id')->all();
        $result = app(StocktakeCategoryWorkspaceService::class)->rows(10, 0, 60);
        $this->assertSame([1,4], array_column($result['rows'], 'variant_id'));

        $row = $result['rows'][0];
        $this->assertSame(10, $row['system_available']);
        $this->assertSame(2, $row['active_reserved']);
        $this->assertSame(1, $row['bundle_free']);
        $this->assertSame(2, $row['bundle_reserved']);
        $this->assertSame(3, $row['site_held']);
        $this->assertSame(18, $row['expected_physical']);
        $this->assertSame(8, $row['protected_total']);
        $this->assertSame(5, $row['queue_total']); // Report only; not another physical bucket.
        $this->assertSame($before, DB::table('warehouse_stocks')->orderBy('product_variant_id')->pluck('quantity', 'product_variant_id')->all());
        $this->assertSame(1, DB::table('warehouses')->count());
    }

    public function test_automatic_bundle_is_excluded_before_cursor_pagination(): void
    {
        $page1 = app(StocktakeCategoryWorkspaceService::class)->rows(10, 0, 1);
        $this->assertSame([1], array_column($page1['rows'], 'variant_id'));
        $this->assertTrue($page1['has_more']);
        $this->assertSame(1, $page1['next_cursor']);

        $page2 = app(StocktakeCategoryWorkspaceService::class)->rows(10, $page1['next_cursor'], 1);
        $this->assertSame([4], array_column($page2['rows'], 'variant_id'));
        $this->assertFalse($page2['has_more']);
        $this->assertSame(4, $page2['next_cursor']);
        $this->assertSame(7, $page2['rows'][0]['expected_physical']);
    }

    public function test_search_remains_within_selected_category_and_descendants(): void
    {
        $found = app(StocktakeCategoryWorkspaceService::class)->rows(10, 0, 60, 'SECOND');
        $this->assertSame([4], array_column($found['rows'], 'variant_id'));
        $outside = app(StocktakeCategoryWorkspaceService::class)->rows(10, 0, 60, 'OUTSIDE');
        $this->assertSame([], $outside['rows']);
    }

    public function test_missing_central_warehouse_fails_without_creating_one(): void
    {
        DB::table('warehouses')->delete();
        try {
            app(StocktakeCategoryWorkspaceService::class)->rows(10, 0, 60);
            $this->fail('Preview should not create a central warehouse');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertSame(0, DB::table('warehouses')->count());
    }
}
