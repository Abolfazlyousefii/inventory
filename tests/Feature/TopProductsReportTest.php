<?php
namespace Tests\Feature;

use App\Services\TopProductsReportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;
use App\Models\User;
use Mockery;

class TopProductsReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('categories', function (Blueprint $t) { $t->id(); $t->string('name'); $t->integer('parent_id')->nullable(); });
        Schema::create('products', function (Blueprint $t) { $t->id(); $t->string('name'); $t->integer('category_id'); $t->string('image_path')->nullable(); });
        Schema::create('product_variants', function (Blueprint $t) { $t->id(); $t->integer('product_id'); $t->string('variant_name'); });
        Schema::create('preinvoice_orders', function (Blueprint $t) { $t->id(); $t->integer('seller_id')->nullable(); $t->integer('created_by')->nullable(); });
        Schema::create('invoices', function (Blueprint $t) { $t->id(); $t->string('uuid'); $t->string('status'); $t->integer('seller_id')->nullable(); $t->integer('preinvoice_order_id')->nullable(); $t->timestamp('cancelled_at')->nullable(); $t->timestamp('shipped_at')->nullable(); $t->timestamp('created_at'); $t->integer('discount_amount')->default(0); $t->integer('invoice_discount_amount')->nullable(); $t->string('discount_allocation_mode')->nullable(); });
        Schema::create('invoice_items', function (Blueprint $t) { $t->id(); $t->integer('invoice_id'); $t->integer('product_id'); $t->integer('variant_id')->nullable(); $t->integer('quantity'); $t->integer('price'); $t->integer('line_discount_amount')->default(0); });
        Schema::create('warehouses', function (Blueprint $t) { $t->id(); $t->string('type'); });
        Schema::create('warehouse_stocks', function (Blueprint $t) { $t->id(); $t->integer('warehouse_id'); $t->integer('product_id'); $t->integer('product_variant_id')->nullable(); $t->integer('quantity'); });
        DB::table('categories')->insert([['id'=>1,'name'=>'گارد','parent_id'=>null],['id'=>2,'name'=>'مات','parent_id'=>1],['id'=>3,'name'=>'شارژر','parent_id'=>null]]);
        DB::table('products')->insert([['id'=>1,'name'=>'گارد','category_id'=>2],['id'=>2,'name'=>'شارژر','category_id'=>3]]);
        DB::table('product_variants')->insert([['id'=>1,'product_id'=>1,'variant_name'=>'مشکی'],['id'=>2,'product_id'=>1,'variant_name'=>'سفید']]);
        DB::table('preinvoice_orders')->insert(['id'=>1,'seller_id'=>7,'created_by'=>8]);
        foreach ([1=>'2026-10-03 00:00:00',2=>'2026-10-03 23:59:59',3=>'2026-10-04 00:00:00',4=>null,5=>'2026-10-03 12:00:00'] as $id=>$date) {
            DB::table('invoices')->insert(['id'=>$id,'uuid'=>(string)$id,'status'=>$id===4?'ready_to_ship':'shipped','shipped_at'=>$date,'created_at'=>'2026-09-01 00:00:00','seller_id'=>$id===1?null:9,'preinvoice_order_id'=>1,'cancelled_at'=>$id===5?'2026-10-04':null,'discount_amount'=>$id===1?13:0]);
        }
        DB::table('invoice_items')->insert([['id'=>1,'invoice_id'=>1,'product_id'=>1,'variant_id'=>1,'quantity'=>2,'price'=>100,'line_discount_amount'=>20],['id'=>2,'invoice_id'=>1,'product_id'=>2,'variant_id'=>null,'quantity'=>1,'price'=>50,'line_discount_amount'=>0],['id'=>3,'invoice_id'=>2,'product_id'=>1,'variant_id'=>2,'quantity'=>3,'price'=>100,'line_discount_amount'=>0]]);
        foreach ([3,4,5] as $id) DB::table('invoice_items')->insert(['invoice_id'=>$id,'product_id'=>1,'variant_id'=>1,'quantity'=>100,'price'=>100]);
        DB::table('warehouses')->insert([['id'=>1,'type'=>'central'],['id'=>2,'type'=>'return']]);
        DB::table('warehouse_stocks')->insert([['warehouse_id'=>1,'product_id'=>1,'product_variant_id'=>1,'quantity'=>6],['warehouse_id'=>1,'product_id'=>1,'product_variant_id'=>2,'quantity'=>4],['warehouse_id'=>1,'product_id'=>1,'product_variant_id'=>null,'quantity'=>10],['warehouse_id'=>2,'product_id'=>1,'product_variant_id'=>1,'quantity'=>80]]);
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); });
        DB::table('users')->insert(['id'=>7,'name'=>'فروشنده']);
        Schema::create('permissions', function (Blueprint $t) { $t->id(); foreach(['key','name','group','guard_name'] as $c)$t->string($c)->nullable(); $t->timestamps(); });
    }

    private function filters(array $extra=[]): array { return $extra+['from'=>'2026-10-03','to'=>'2026-10-03','group'=>'product','metric'=>'quantity','category_id'=>null,'seller_id'=>null,'product_id'=>null]; }

    public function test_shipping_date_boundaries_and_exact_discounts_without_inventory_writes(): void
    {
        DB::enableQueryLog();
        $r=app(TopProductsReportService::class)->report($this->filters());
        $this->assertSame(6,$r['summary']['quantity']);
        // Old discount_amount contains both the 20 line discount and document discount.
        $this->assertSame(530,$r['summary']['revenue']);
        $this->assertSame(2,$r['summary']['invoices']);
        $this->assertSame(5,$r['rows'][0]['quantity']);
        $this->assertSame(10,$r['rows'][0]['stock']); // No legacy product summary double-counting.
        foreach(DB::getQueryLog() as $q) $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|alter)/i',$q['query']);
    }

    public function test_product_and_variant_filters_use_canonical_seller_and_descendant_category(): void
    {
        $s=app(TopProductsReportService::class);
        $r=$s->report($this->filters(['category_id'=>1,'seller_id'=>7,'group'=>'variant']));
        $this->assertCount(1,$r['rows']); $this->assertSame(2,$r['summary']['quantity']); $this->assertSame(180,$r['summary']['revenue']);
        $r=$s->report($this->filters(['product_id'=>1,'group'=>'variant','metric'=>'revenue']));
        $this->assertCount(2,$r['rows']); $this->assertSame(2,$r['rows'][0]['variant_id']);
        $this->assertSame(2,$r['summary']['invoices']); $this->assertCount(2,$r['invoices']);
        $this->assertSame(5,$r['trend'][0]['quantity']);
    }

    public function test_document_discount_allocation_reconciles_and_is_not_redistributed_after_filtering(): void
    {
        DB::table('invoices')->where('id',1)->update(['discount_amount'=>33,'invoice_discount_amount'=>13]);
        $s=app(TopProductsReportService::class);
        $all=$s->report($this->filters(['seller_id'=>7]));
        $this->assertSame(217,$all['summary']['revenue']);
        $one=$s->report($this->filters(['seller_id'=>7,'product_id'=>1]));
        $two=$s->report($this->filters(['seller_id'=>7,'product_id'=>2]));
        $this->assertSame(217,$one['summary']['revenue']+$two['summary']['revenue']);
    }

    public function test_undated_shipped_documents_are_warned_about_without_using_creation_date(): void
    {
        DB::table('invoices')->where('id',4)->update(['status'=>'shipped']);
        $r=app(TopProductsReportService::class)->report($this->filters());
        $this->assertSame(1,$r['undated_invoices']);$this->assertSame(6,$r['summary']['quantity']);
        $empty=app(TopProductsReportService::class)->report($this->filters(['from'=>'2026-12-01','to'=>'2026-12-02']));
        $this->assertSame(0,$empty['summary']['invoices']);$this->assertSame([],$empty['rows']);
    }

    public function test_already_allocated_discounts_are_not_deducted_twice(): void
    {
        DB::table('invoices')->where('id',1)->update(['discount_amount'=>20,'discount_allocation_mode'=>'allocated_lines']);
        $r=app(TopProductsReportService::class)->report($this->filters(['seller_id'=>7]));
        $this->assertSame(230,$r['summary']['revenue']);
        $this->assertEquals($r['summary']['revenue'],array_sum(array_column($r['rows'],'revenue')));
    }

    private function actor(bool $allowed): User
    {
        $u=Mockery::mock(User::class)->makePartial(); $u->forceFill(['id'=>7,'name'=>'فروشنده','is_active'=>true,'can_access_erp'=>true]);
        $u->shouldReceive('isSuperAdmin')->andReturn(false);$u->shouldReceive('hasAnyRole')->andReturn(false);$u->shouldReceive('hasPermission')->andReturn(false);
        $u->shouldReceive('getRoleNames')->andReturn(collect());$u->shouldReceive('getPermissionsViaRoles')->andReturn(collect($allowed?[(object)['key'=>'page.finance.accounts']]:[]));return $u;
    }

    public function test_all_endpoints_require_financial_access_and_dates_are_validated(): void
    {
        $this->actingAs($this->actor(false));
        foreach(['finance.reports.top-products','finance.reports.top-products.detail','finance.reports.top-products.export'] as $route)$this->getJson(route($route))->assertForbidden();
        $this->actingAs($this->actor(true));
        $this->getJson(route('finance.reports.top-products.detail',['product_id'=>1,'date_from'=>'1405/12/31']))->assertUnprocessable();
        $this->getJson(route('finance.reports.top-products.detail',['product_id'=>1,'date_from'=>'1405/07/12','date_to'=>'1405/07/11']))->assertUnprocessable();
        $this->getJson(route('finance.reports.top-products.detail',['product_id'=>1,'date_from'=>'1405/07/11','date_to'=>'1405/07/11']))->assertOk()->assertJsonPath('summary.quantity',5);
        $response=$this->get(route('finance.reports.top-products',['date_from'=>'1405/07/11','date_to'=>'1405/07/11']));
        $response->assertOk()->assertSee('tp-chart-data')->assertSee('کالاهای پرفروش')
            ->assertSee('id="tp-filter-dialog"',false)->assertSee('id="tp-filter-open"',false)->assertDontSee('class="tp-table"',false);
        file_put_contents(storage_path('app/top-products-20261010/rendered.html'),$response->getContent());
    }

    public function test_default_month_is_jalali_and_exports_preserve_filters_and_formula_safety(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-10 12:00:00'));
        $f=app(\App\Http\Controllers\TopProductsReportController::class)->filters(\Illuminate\Http\Request::create('/'));
        $this->assertSame('1405/07/01',$f['date_from']);$this->assertSame('1405/07/18',$f['date_to']);
        $this->actingAs($this->actor(true));
        $p=['date_from'=>'1405/07/11','date_to'=>'1405/07/11','seller_id'=>7,'format'=>'print'];
        $this->get(route('finance.reports.top-products.export',$p))->assertOk()->assertSee('گارد')->assertSee('شارژر')->assertSee('180');
        $p['format']='xlsx'; $response=$this->get(route('finance.reports.top-products.export',$p));$response->assertOk();
        $cell=(new \PhpOffice\PhpSpreadsheet\Spreadsheet())->getActiveSheet()->getCell('A1');
        $export=new \App\Exports\TopProductsExport([],$f);$export->bindValue($cell,'=1+1');$this->assertSame('s',$cell->getDataType());
    }
}
