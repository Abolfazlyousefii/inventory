<?php
namespace Tests\Feature;

use App\Models\{Category, Product, ProductVariant, User, WarehouseStock};
use App\Services\{WarehouseStockService, AutomaticBundleAvailabilityService, BundleDiscountService};
use App\Services\Sync\SyncProductsToSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Database\Schema\Blueprint;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LargeProductBundleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Bundle tables are provisioned separately in production, outside the recovered baseline.
        Schema::table('products', function(Blueprint $t) {
            $t->boolean('is_bundle')->default(false); $t->string('bundle_stock_mode')->default('physical');
            $t->string('bundle_discount_type')->nullable(); $t->bigInteger('bundle_discount_value')->nullable();
        });
        Schema::create('product_bundle_components', function(Blueprint $t) {
            $t->id(); $t->integer('bundle_variant_id'); $t->integer('component_variant_id'); $t->integer('quantity');
            $t->string('discount_type')->default('percent'); $t->bigInteger('discount_value')->default(0); $t->timestamps();
            $t->unique(['bundle_variant_id','component_variant_id']);
        });
        Schema::create('bundle_component_allocations', function(Blueprint $t) {
            $t->id(); $t->integer('bundle_variant_id'); $t->integer('component_variant_id'); $t->integer('remaining'); $t->timestamps();
            $t->unique(['bundle_variant_id','component_variant_id']);
        });
        (require database_path('migrations/2026_10_24_210000_complete_legacy_bundle_settings.php'))->up();

    }

    private function catalog(): array
    {
        config(['database.connections.site' => ['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>false]]);
        DB::purge('site');
        Schema::connection('site')->create('products', function(Blueprint $t){$t->id();$t->boolean('published')->default(false);});
        Schema::connection('site')->create('order_items', function(Blueprint $t){$t->id();$t->integer('price_id');});
        DB::connection()->getPdo()->sqliteCreateFunction('JSON_CONTAINS_PATH', fn()=>0, -1);
        $user=User::factory()->create(['is_active'=>true,'can_access_erp'=>true]);
        $user->assignRole(Role::findOrCreate('Owner','web'));
        $this->actingAs($user);
        $category=Category::create(['name'=>'Bundle test','code'=>'77']);
        $product=Product::withoutEvents(fn()=>Product::create(['name'=>'Large component product','category_id'=>$category->id,'code'=>'770001','sku'=>'LARGE-TEST','is_sellable'=>true,'is_bundle'=>false,'price'=>1000]));
        $central=WarehouseStockService::centralWarehouseId();
        $rows=[];
        for($n=1;$n<=300;$n++){
            $variant=ProductVariant::withoutEvents(fn()=>ProductVariant::create(['product_id'=>$product->id,'variant_name'=>'Variant '.$n,'variant_code'=>'LARGE-'.$n,'variety_code'=>str_pad((string)$n,4,'0',STR_PAD_LEFT),'sell_price'=>1000,'is_active'=>true,'sales_enabled'=>true,'stock'=>10]));
            WarehouseStock::withoutEvents(fn()=>WarehouseStock::create(['warehouse_id'=>$central,'product_id'=>$product->id,'product_variant_id'=>$variant->id,'quantity'=>10]));
            $rows[]=['variant_id'=>$variant->id,'quantity'=>1,'discount_type'=>'percent','discount_value'=>0];
        }
        return [$category,$product,$central,$rows];
    }

    public function test_complete_product_search_returns_all_300_eligible_variants(): void
    {
        [, $product,, $rows]=$this->catalog();
        $response=$this->getJson(route('products.bundle.search',['q'=>$product->name,'complete_products'=>1]))->assertOk()->assertJsonCount(300,'results');
        $this->assertSame(array_column($rows,'variant_id'),array_column($response->json('results'),'id'));
        $this->getJson(route('products.bundle.search',['q'=>$product->name]))->assertOk()->assertJsonCount(100,'results');
        if ($file = getenv('BUNDLE_BROWSER_FIXTURE')) file_put_contents($file, $this->get(route('products.create'))->assertOk()->getContent());
    }

    public function test_300_component_bundle_creation_allocation_edit_and_site_snapshot_preserve_all_items(): void
    {
        [$category,$source,$central,$rows]=$this->catalog();
        $this->mock(SyncProductsToSite::class)->shouldReceive('syncProduct')->andReturn(true);
        $this->post(route('products.store'),[
            'product_type'=>'bundle','bundle_stock_mode'=>'automatic','bundle_quantity_mode'=>'shared',
            'bundle_discount_type'=>'percent','bundle_discount_value'=>0,'bundle_shared_quantity'=>2,
            'category_id'=>$category->id,'name'=>'Large 300 bundle','is_sellable'=>1,'sell_price'=>300000,
            'bundle_form_payload'=>json_encode(['components'=>$rows],JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $bundle=Product::where('name','Large 300 bundle')->firstOrFail();
        $variantId=(int)$bundle->variants()->value('id');
        $this->assertSame(300,DB::table('product_bundle_components')->where('bundle_variant_id',$variantId)->count());
        $this->assertSame(300,DB::table('bundle_component_allocations')->where('bundle_variant_id',$variantId)->where('remaining',2)->count());
        $this->assertSame(300,DB::table('warehouse_stocks')->where('product_id',$source->id)->where('warehouse_id',$central)->where('quantity',8)->count());
        $this->assertSame(2,app(AutomaticBundleAvailabilityService::class)->available($variantId));
        $this->assertSame(300000,app(BundleDiscountService::class)->quoteForBundle($variantId,'percent',0)['final']);
        $sync=new SyncProductsToSite;
        $snapshot=(new \ReflectionMethod($sync,'bundleContentsFor'))->invoke($sync,$bundle);
        $this->assertCount(300,$snapshot);
        $this->assertSame(300,array_sum(array_column($snapshot,'quantity')));
        $expected=array_fill_keys(array_column($rows,'variant_id'),2);
        $this->post(route('products.bundle.policy',$bundle),[
            'bundle_quantity_mode'=>'shared','bundle_shared_quantity'=>0,
            'bundle_form_payload'=>json_encode(['expected_allocations'=>$expected],JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(300,DB::table('warehouse_stocks')->where('product_id',$source->id)->where('warehouse_id',$central)->where('quantity',10)->count());
        $this->post(route('products.bundle.recipe',$bundle),[
            'bundle_variant_id'=>$variantId,'bundle_form_payload'=>json_encode(['components'=>$rows],JSON_THROW_ON_ERROR),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(300,DB::table('product_bundle_components')->where('bundle_variant_id',$variantId)->count());
    }


    public function test_bundle_details_load_on_demand_in_bounded_pages_without_polluting_product_list(): void
    {
        [$category, $source,, $rows] = $this->catalog();
        $bundle = Product::withoutEvents(fn () => Product::create([
            'name' => 'Bundle with 300 items', 'category_id' => $category->id,
            'sku' => 'BUNDLE-DRAWER', 'is_bundle' => true, 'bundle_stock_mode' => 'physical',
            'is_sellable' => true, 'price' => 300000,
        ]));
        $finished = ProductVariant::withoutEvents(fn () => ProductVariant::create([
            'product_id' => $bundle->id, 'variant_name' => 'Finished package',
            'variant_code' => 'FINISHED-BUNDLE', 'sell_price' => 300000,
            'stock' => 1, 'is_active' => true, 'sales_enabled' => true,
        ]));
        $records = [];
        foreach ($rows as $row) {
            $records[] = [
                'bundle_variant_id' => $finished->id,
                'component_variant_id' => $row['variant_id'],
                'quantity' => 2,
                'discount_type' => 'percent', 'discount_value' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('product_bundle_components')->insert($records);

        $sql = [];
        DB::listen(function ($query) use (&$sql) { $sql[] = $query->sql; });
        $catalog = $this->getJson(route('products.data', ['product_type' => 'bundle']))->assertOk();
        $product = collect($catalog->json('data'))->firstWhere('id', $bundle->id);
        $this->assertTrue($product['is_bundle']);
        $this->assertSame([], $product['bundle_contents'] ?? []);
        $this->assertSame(0, count(array_filter($sql, fn ($query) => str_contains($query, 'product_bundle_components'))));

        $all = [];
        $cursor = 0;
        for ($page = 0; $page < 4; $page++) {
            $response = $this->getJson(route('products.bundle-components', $bundle) . '?cursor=' . $cursor . '&limit=80')
                ->assertOk()->assertJsonPath('product.id', $bundle->id)
                ->assertJsonPath('product.stock_mode', 'physical');
            $all = [...$all, ...$response->json('data')];
            $cursor = $response->json('meta.next_cursor') ?? 0;
            if ($page < 3) $response->assertJsonPath('meta.has_more', true);
            else $response->assertJsonPath('meta.has_more', false);
        }
        $this->assertCount(300, $all);
        $this->assertCount(300, array_unique(array_column($all, 'variant_id')));
        $this->assertSame('Variant 1', $all[0]['variant_name']);
        $this->assertSame(2, $all[0]['quantity_per_bundle']);

        $this->getJson(route('products.bundle-components', $bundle) . '?q=Variant%20299')
            ->assertOk()->assertJsonPath('meta.total.items', 1)
            ->assertJsonPath('data.0.variant_name', 'Variant 299')
            ->assertJsonPath('data.0.quantity_per_bundle', 2)
            ->assertJsonPath('meta.has_more', false);
        $this->getJson(route('products.bundle-components', $bundle) . '?cursor=-1')
            ->assertUnprocessable();
        $this->getJson(route('products.bundle-components', $bundle) . '?limit=300')
            ->assertUnprocessable();

        $this->getJson(route('products.bundle-components', $source))->assertNotFound();
        $this->assertSame(300, DB::table('product_bundle_components')->where('bundle_variant_id', $finished->id)->count());
    }

    public function test_product_page_exposes_a_dedicated_accessible_bundle_drawer(): void
    {
        $this->catalog();
        $this->get(route('products.index'))->assertOk()
            ->assertSee('id="bundleComponentsDrawer"', false)
            ->assertSee('product-bundle-drawer.js', false)
            ->assertSee('مشاهده اقلام بسته', false);
    }

    public function test_product_list_renders_selectable_rows_and_bundle_drawer_without_external_asset_dependency(): void
    {
        $this->catalog();

        $html = $this->get(route('products.index'))->assertOk()->getContent();

        // Product rows are streamed into the table by the existing inline loader:
        // the checkbox markup must live in the row template itself, not depend
        // on a separately loaded JS file to inject another table cell.
        $this->assertStringContainsString('class="product-select"', $html);
        $this->assertStringContainsString('class="select-col"', $html);
        $this->assertStringContainsString('id="selectLoadedProducts"', $html);
        $this->assertStringContainsString('id="downloadSelectedProducts"', $html);

        // Default state must be hidden even with missing external CSS.
        $this->assertMatchesRegularExpression(
            '/<div[^>]*id="bundleComponentsDrawer"[^>]*hidden/',
            $html
        );
        $this->assertStringContainsString('.bundle-detail-drawer.is-open', $html);
        $this->assertStringContainsString('drawer.hidden = false', $html);
        $this->assertStringContainsString('drawer.hidden = true', $html);

        // Export handlers and CSV support must actually be present in the
        // rendered HTML, instead of relying on a public asset URL.
        $this->assertStringContainsString('ProductStockCsv', $html);
        $this->assertStringContainsString('function syncControls()', $html);
        $this->assertStringContainsString('bundle-detail-item', $html);
        $this->assertStringNotContainsString(
            '<script src="' . asset('js/product-selection-export.js'),
            $html
        );
    }

    public function test_truncated_payload_is_rejected_before_creating_any_product(): void
    {
        $user=User::factory()->create(['is_active'=>true,'can_access_erp'=>true]);
        $user->assignRole(Role::findOrCreate('Owner','web'));
        $before=Product::count();
        $this->actingAs($user)->postJson(route('products.store'),['product_type'=>'bundle','bundle_form_payload'=>'{"components":['])
            ->assertUnprocessable()->assertJsonValidationErrors('components');
        $this->assertSame($before,Product::count());
    }
}
