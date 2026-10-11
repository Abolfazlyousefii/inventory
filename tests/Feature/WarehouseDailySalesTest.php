<?php

namespace Tests\Feature;

use App\Models\{Invoice, User};
use App\Services\WarehouseDailySalesService;
use App\Support\PageAccessCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Http, Schema};
use Mockery;
use Tests\TestCase;

class WarehouseDailySalesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // A minimal isolated SQLite schema: never migrate or write the working database.
        Http::fake();
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); });
        Schema::create('categories', function (Blueprint $t) { $t->id(); $t->string('name'); });
        Schema::create('permissions', function (Blueprint $t) { $t->id(); foreach (['key','name','group','guard_name'] as $c) $t->string($c)->nullable(); $t->timestamps(); });
        Schema::create('warehouses', function (Blueprint $t) { $t->id(); $t->string('type'); });
        Schema::create('products', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('code')->nullable(); $t->string('sku')->nullable(); $t->integer('stock')->default(0); $t->integer('reserved')->default(0); $t->timestamps(); });
        Schema::create('product_variants', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('product_id'); $t->string('variant_name')->nullable(); $t->string('variant_code')->nullable(); $t->integer('stock')->default(0); $t->integer('reserved')->default(0); $t->timestamps(); });
        Schema::create('warehouse_stocks', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('warehouse_id'); $t->unsignedBigInteger('product_id'); $t->unsignedBigInteger('product_variant_id')->nullable(); $t->integer('quantity'); });
        Schema::create('invoices', function (Blueprint $t) { $t->id(); $t->string('uuid'); $t->string('status'); $t->timestamp('cancelled_at')->nullable(); $t->timestamps(); });
        Schema::create('invoice_items', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('invoice_id'); $t->unsignedBigInteger('product_id'); $t->unsignedBigInteger('variant_id')->nullable(); $t->integer('quantity'); });
        Schema::create('stock_movements', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('product_id'); $t->unsignedBigInteger('product_variant_id')->nullable(); $t->unsignedBigInteger('warehouse_id')->nullable(); $t->unsignedBigInteger('user_id')->nullable(); foreach (['type','reason','reference','reference_type'] as $c) $t->string($c)->nullable(); $t->unsignedBigInteger('reference_id')->nullable(); $t->text('note')->nullable(); $t->integer('quantity'); $t->timestamps(); });
        (require database_path('migrations/2026_10_07_150000_create_warehouse_daily_counts.php'))->up();
        DB::table('users')->insert(['id'=>1,'name'=>'مدیر انبار']);
        DB::table('warehouses')->insert([['id'=>1,'type'=>'central'],['id'=>2,'type'=>'return']]);
        DB::table('products')->insert(['id'=>1,'name'=>'گارد','code'=>'001','stock'=>999,'reserved'=>90]);
        DB::table('product_variants')->insert(['id'=>1,'product_id'=>1,'variant_name'=>'مشکی','variant_code'=>'BLACK','stock'=>999,'reserved'=>90]);
        DB::table('warehouse_stocks')->insert([['warehouse_id'=>1,'product_id'=>1,'product_variant_id'=>1,'quantity'=>36],['warehouse_id'=>2,'product_id'=>1,'product_variant_id'=>1,'quantity'=>100],['warehouse_id'=>1,'product_id'=>1,'product_variant_id'=>null,'quantity'=>36]]);
        DB::table('invoices')->insert([['id'=>1,'uuid'=>'01756','status'=>Invoice::STATUS_PENDING_COLLECTION,'created_at'=>'2026-10-03 15:00:00'],['id'=>2,'uuid'=>'01757','status'=>Invoice::STATUS_NOT_SHIPPED,'created_at'=>'2026-10-03 16:00:00'],['id'=>3,'uuid'=>'01758','status'=>Invoice::STATUS_SHIPPED,'created_at'=>'2026-10-04 00:00:00']]);
        DB::table('invoice_items')->insert([['invoice_id'=>1,'product_id'=>1,'variant_id'=>1,'quantity'=>24],['invoice_id'=>2,'product_id'=>1,'variant_id'=>1,'quantity'=>100],['invoice_id'=>3,'product_id'=>1,'variant_id'=>1,'quantity'=>200]]);
        DB::table('stock_movements')->insert([['product_id'=>1,'product_variant_id'=>1,'warehouse_id'=>1,'type'=>'in','reason'=>'purchase','quantity'=>20,'created_at'=>'2026-10-03 10:00:00'],['product_id'=>1,'product_variant_id'=>1,'warehouse_id'=>1,'type'=>'out','reason'=>'sale','quantity'=>32,'created_at'=>'2026-10-03 11:00:00'],['product_id'=>1,'product_variant_id'=>1,'warehouse_id'=>2,'type'=>'in','reason'=>'return','quantity'=>100,'created_at'=>'2026-10-03 10:00:00'],['product_id'=>1,'product_variant_id'=>1,'warehouse_id'=>null,'type'=>'in','reason'=>'purchase','quantity'=>1000,'created_at'=>'2026-10-03 10:00:00']]);
    }

    private function actor(bool $allowed = true): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id'=>1,'name'=>'مدیر انبار','is_active'=>true,'can_access_erp'=>true]);
        $user->shouldReceive('isSuperAdmin')->andReturn(false);
        $user->shouldReceive('hasAnyRole')->andReturn(false);
        $user->shouldReceive('hasPermission')->andReturn(false);
        $user->shouldReceive('getRoleNames')->andReturn(collect(['Warehouse']));
        $user->shouldReceive('getPermissionsViaRoles')->andReturn(collect($allowed ? [(object)['key'=>'page.warehouse.daily_sales']] : []));
        return $user;
    }

    private function inventorySnapshot(): array
    {
        return collect(['products','product_variants','warehouse_stocks','stock_movements','invoices','invoice_items'])->mapWithKeys(fn ($t)=>[$t=>DB::table($t)->orderBy('id')->get()->toArray()])->all();
    }

    private function payload(array $changes = []): array
    {
        return $changes + ['day'=>'1405/07/11','product_id'=>1,'variant_id'=>1,'physical_quantity'=>38,'expected_stock'=>36,'version'=>0,'note'=>'دو عدد اضافه'];
    }

    public function test_day_sales_and_movement_totals_are_independent_and_central_stock_is_not_subtracted_twice(): void
    {
        $before = $this->inventorySnapshot();
        $this->actingAs($this->actor())->getJson(route('warehouse.daily-sales.data', ['day'=>'1405/07/11']))
            ->assertOk()->assertJsonPath('rows.0.sales',24)->assertJsonPath('rows.0.incoming',20)->assertJsonPath('rows.0.outgoing',32)->assertJsonPath('rows.0.current_stock',36)->assertJsonPath('summary.total',1);
        $this->assertEquals($before, $this->inventorySnapshot());
        Http::assertNothingSent();
    }

    public function test_save_is_stock_neutral_and_creates_an_audit_history(): void
    {
        $before = $this->inventorySnapshot();
        $this->actingAs($this->actor())->postJson(route('warehouse.daily-sales.store'),$this->payload())->assertOk();
        $this->assertDatabaseHas('warehouse_daily_counts',['physical_quantity'=>38,'stock_snapshot'=>36,'updated_by'=>1,'version'=>1]);
        $this->assertDatabaseCount('warehouse_daily_count_history',1);
        $this->assertEquals($before,$this->inventorySnapshot());
        Http::assertNothingSent();
        $this->getJson(route('warehouse.daily-sales.data',['day'=>'1405/07/11','filter'=>'issues']))->assertJsonPath('total',1);
        $this->getJson(route('warehouse.daily-sales.data',['day'=>'1405/07/11','filter'=>'pending']))->assertJsonPath('total',0);
    }

    public function test_zero_is_valid_and_negative_or_invalid_dates_are_rejected(): void
    {
        $this->actingAs($this->actor());
        $this->postJson(route('warehouse.daily-sales.store'),$this->payload(['physical_quantity'=>-1]))->assertUnprocessable();
        $this->getJson(route('warehouse.daily-sales.data',['day'=>'1405/12/31']))->assertUnprocessable();
        $this->getJson(route('warehouse.daily-sales.data'))->assertUnprocessable();
        $this->postJson(route('warehouse.daily-sales.store'),$this->payload(['physical_quantity'=>0]))->assertOk();
        $this->assertDatabaseHas('warehouse_daily_counts',['physical_quantity'=>0]);
    }

    public function test_stock_changes_require_recounting_and_do_not_save(): void
    {
        DB::table('warehouse_stocks')->where('product_variant_id',1)->where('warehouse_id',1)->update(['quantity'=>35]);
        $this->actingAs($this->actor())->postJson(route('warehouse.daily-sales.store'),$this->payload())->assertConflict();
        $this->assertDatabaseCount('warehouse_daily_counts',0);
        $this->assertDatabaseCount('warehouse_daily_count_history',0);
    }

    public function test_concurrent_edit_is_rejected_and_revisions_are_preserved(): void
    {
        $this->actingAs($this->actor());
        $this->postJson(route('warehouse.daily-sales.store'),$this->payload())->assertOk();
        $this->postJson(route('warehouse.daily-sales.store'),$this->payload(['physical_quantity'=>40]))->assertConflict();
        $this->postJson(route('warehouse.daily-sales.store'),$this->payload(['physical_quantity'=>40,'version'=>1]))->assertOk();
        $this->assertDatabaseCount('warehouse_daily_counts',1);
        $this->assertDatabaseCount('warehouse_daily_count_history',2);
        $this->assertDatabaseHas('warehouse_daily_counts',['physical_quantity'=>40,'version'=>2]);
    }

    public function test_permission_applies_to_every_endpoint(): void
    {
        $this->actingAs($this->actor(false));
        foreach (['index','data','detail','export'] as $route) $this->getJson(route('warehouse.daily-sales.'.$route,['day'=>'1405/07/11']))->assertForbidden();
        $this->postJson(route('warehouse.daily-sales.store'),$this->payload())->assertForbidden();
        $this->assertDatabaseCount('warehouse_daily_counts',0);
        foreach (['index','data','detail','store','export'] as $name) $this->assertSame(['page.warehouse.daily_sales'],PageAccessCatalog::permissionsForRoute('warehouse.daily-sales.'.$name));
    }

    public function test_notes_are_separated_by_day_and_missing_central_warehouse_is_not_created(): void
    {
        $this->actingAs($this->actor());
        $this->postJson(route('warehouse.daily-sales.store'),$this->payload())->assertOk();
        $this->getJson(route('warehouse.daily-sales.data',['day'=>'1405/07/12']))->assertJsonPath('summary.reviewed',0);
        DB::table('warehouses')->where('type','central')->delete();
        $this->getJson(route('warehouse.daily-sales.data',['day'=>'1405/07/11']))->assertUnprocessable();
        $this->assertDatabaseCount('warehouses',1);
    }

    public function test_legacy_product_level_rows_cannot_be_counted_as_variant_stock(): void
    {
        DB::table('invoice_items')->insert(['invoice_id'=>1,'product_id'=>1,'variant_id'=>null,'quantity'=>7]);
        $this->actingAs($this->actor());
        $row = app(WarehouseDailySalesService::class)->rows('2026-10-03',1)->where('k.variant_id',0)->first();
        $this->assertNull($row->current_stock);
        $this->postJson(route('warehouse.daily-sales.store'),$this->payload(['variant_id'=>0]))->assertUnprocessable();
    }

    public function test_detail_pagination_and_export_use_real_records_and_escape_notes(): void
    {
        $this->actingAs($this->actor());
        $this->getJson(route('warehouse.daily-sales.detail',['day'=>'1405/07/11','product_id'=>1,'variant_id'=>1,'type'=>'incoming']))
            ->assertOk()->assertJsonPath('rows.0.quantity',20)->assertJsonPath('total',1)->assertJsonPath('rows.0.url',null);
        $this->postJson(route('warehouse.daily-sales.store'),$this->payload(['note'=>'=1+1']))->assertOk();
        $response = $this->get(route('warehouse.daily-sales.export',['day'=>'1405/07/11']));
        $response->assertOk(); $this->assertStringContainsString("'=1+1",$response->streamedContent());
        $this->get(route('warehouse.daily-sales.export',['day'=>'1405/07/11','format'=>'print']))->assertOk()->assertSee('گزارش فروش روز');
    }

    public function test_large_day_is_paginated_and_search_does_not_load_all_rows(): void
    {
        for ($id = 2; $id <= 85; $id++) {
            DB::table('product_variants')->insert(['id'=>$id,'product_id'=>1,'variant_name'=>'مدل '.$id,'variant_code'=>'CODE-'.$id]);
            DB::table('invoice_items')->insert(['invoice_id'=>1,'product_id'=>1,'variant_id'=>$id,'quantity'=>1]);
        }
        $this->actingAs($this->actor());
        $this->getJson(route('warehouse.daily-sales.data',['day'=>'1405/07/11','page'=>1]))->assertOk()->assertJsonCount(40,'rows')->assertJsonPath('total',85)->assertJsonPath('last_page',3);
        $this->getJson(route('warehouse.daily-sales.data',['day'=>'1405/07/11','page'=>3]))->assertOk()->assertJsonCount(5,'rows');
        $this->getJson(route('warehouse.daily-sales.data',['day'=>'1405/07/11','q'=>'CODE-85']))->assertOk()->assertJsonCount(1,'rows')->assertJsonPath('rows.0.variant_id',85);
    }

    public function test_stale_counts_remain_based_on_the_original_snapshot(): void
    {
        $this->actingAs($this->actor());
        $this->postJson(route('warehouse.daily-sales.store'),$this->payload())->assertOk();
        DB::table('warehouse_stocks')->where('warehouse_id',1)->where('product_variant_id',1)->update(['quantity'=>30]);
        $this->getJson(route('warehouse.daily-sales.data',['day'=>'1405/07/11','filter'=>'issues']))->assertOk()->assertJsonPath('rows.0.stock_snapshot',36)->assertJsonPath('rows.0.current_stock',30)->assertJsonPath('rows.0.physical_quantity',38);
    }

    public function test_page_renders_in_the_actual_application_layout_with_the_warehouse_menu(): void
    {
        $this->withoutVite();
        $response = $this->actingAs($this->actor())->get(route('warehouse.daily-sales.index'));
        $response->assertOk()->assertSee('گزارش فروش روز')->assertSee('data-daily-sales',false)->assertSee('warehouse/daily-sales');
        $directory = storage_path('app/daily-warehouse-implementation');
        if (! is_dir($directory)) mkdir($directory,0775,true);
        file_put_contents($directory.'/rendered-index.html',$response->getContent());
    }
}
