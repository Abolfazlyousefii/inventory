<?php
namespace Tests\Feature;
use App\Services\PreinvoiceReservationReportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema,Route};
use Tests\TestCase;
class PreinvoiceReservationReportTest extends TestCase
{
 protected function setUp(): void
 {
  parent::setUp();
  Schema::create('users',fn(Blueprint $t)=>[$t->id(),$t->string('name'),$t->boolean('is_active')->default(true),$t->boolean('can_access_erp')->default(true)]);
  Schema::create('categories',fn(Blueprint $t)=>[$t->id(),$t->string('name'),$t->integer('parent_id')->nullable()]);
  Schema::create('products',fn(Blueprint $t)=>[$t->id(),$t->string('name'),$t->integer('category_id')]);
  Schema::create('product_variants',fn(Blueprint $t)=>[$t->id(),$t->integer('product_id'),$t->string('variant_name'),$t->string('variant_code')]);
  Schema::create('preinvoice_orders',function(Blueprint $t){$t->id();$t->string('uuid');$t->string('customer_name');$t->string('customer_mobile');$t->string('status');$t->integer('seller_id')->nullable();$t->integer('created_by');$t->timestamp('stock_frozen_until')->nullable();});
  Schema::create('preinvoice_draft_reservations',function(Blueprint $t){$t->id();foreach(['product_id','variant_id','preinvoice_order_id','quantity']as$c)$t->integer($c)->nullable();$t->string('reservation_scope')->default('official');foreach(['converted_at','released_at','expires_at']as$c)$t->timestamp($c)->nullable();$t->string('release_reason')->nullable();$t->timestamps();});
  Schema::create('invoices',fn(Blueprint $t)=>[$t->id(),$t->integer('preinvoice_order_id')]);
  DB::table('users')->insert([['id'=>1,'name'=>'Seller'],['id'=>2,'name'=>'Creator']]);
  DB::table('categories')->insert([['id'=>1,'name'=>'Parent','parent_id'=>null],['id'=>2,'name'=>'Child','parent_id'=>1],['id'=>3,'name'=>'Other','parent_id'=>null]]);
  DB::table('products')->insert([['id'=>1,'name'=>'Cable','category_id'=>2],['id'=>2,'name'=>'Case','category_id'=>3]]);
  DB::table('product_variants')->insert([['id'=>1,'product_id'=>1,'variant_name'=>'Black','variant_code'=>'X'],['id'=>2,'product_id'=>2,'variant_name'=>'White','variant_code'=>'Y']]);
  foreach(range(1,8)as$id)DB::table('preinvoice_orders')->insert(['id'=>$id,'uuid'=>'P'.$id,'customer_name'=>'Customer','customer_mobile'=>'0912','status'=>'pending_finance','seller_id'=>$id===1?1:null,'created_by'=>2,'stock_frozen_until'=>'2020-01-01']);
 }
 private function reservation(array $overrides=[]): void
 {
  DB::table('preinvoice_draft_reservations')->insert(array_merge(['product_id'=>1,'variant_id'=>1,'preinvoice_order_id'=>1,'quantity'=>3,'created_at'=>'2020-01-01'],$overrides));
 }
 public function test_only_open_official_preinvoice_reservations_are_visible_without_changing_stock(): void
 {
  $this->reservation();$this->reservation(['quantity'=>4]);
  $this->reservation(['preinvoice_order_id'=>2,'reservation_scope'=>'temporary_online']);
  $this->reservation(['preinvoice_order_id'=>3,'released_at'=>'2020-01-02']);
  $this->reservation(['preinvoice_order_id'=>4,'converted_at'=>'2020-01-02']);
  $this->reservation(['preinvoice_order_id'=>5,'release_reason'=>'consumed']);
  $this->reservation(['preinvoice_order_id'=>6]);DB::table('invoices')->insert(['preinvoice_order_id'=>6]);
  $this->reservation(['preinvoice_order_id'=>7]);DB::table('preinvoice_orders')->where('id',7)->update(['status'=>'draft']);
  $this->reservation(['quantity'=>0,'preinvoice_order_id'=>8]);
  DB::enableQueryLog();$report=app(PreinvoiceReservationReportService::class);$rows=$report->rows()->get();
  $this->assertCount(1,$rows);$this->assertSame(7,(int)$rows[0]->quantity);$this->assertSame(1,(int)$rows[0]->seller_id);
  $this->assertSame('2020-01-01',$rows[0]->stock_frozen_until);
  $this->assertEmpty(array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])));
 }
 public function test_filters_follow_seller_ownership_and_category_descendants(): void
 {
  $this->reservation();$this->reservation(['preinvoice_order_id'=>2,'product_id'=>2,'variant_id'=>2]);
  $report=app(PreinvoiceReservationReportService::class);
  $this->assertCount(1,$report->rows(['seller_id'=>1,'category_id'=>1])->get());
  $this->assertCount(0,$report->rows(['seller_id'=>2,'category_id'=>1])->get());
  $this->assertSame(2,(int)$report->rows(['q'=>'Case'])->first()->seller_id);
  $this->assertCount(2,$report->sellers());
  $summary=$report->summary($report->rows());$this->assertSame(2,(int)$summary->orders_count);$this->assertSame(6,(int)$summary->quantity);
 }
 public function test_seller_options_include_accounts_without_reservations(): void
 {
  DB::table('users')->insert([['id'=>3,'name'=>'No reservations','is_active'=>true,'can_access_erp'=>true],['id'=>4,'name'=>'Inactive','is_active'=>false,'can_access_erp'=>true],['id'=>5,'name'=>'No ERP','is_active'=>true,'can_access_erp'=>false]]);
  $report=app(PreinvoiceReservationReportService::class);
  $this->assertEqualsCanonicalizing([1,2,3],$report->sellers()->pluck('seller_id')->all());
  $this->assertCount(0,$report->rows()->get());
 }
 public function test_report_page_has_no_manual_stock_release_routes(): void
 {
  foreach(['warehouse-reservations.release','warehouse-reservations.bulk-release','warehouse-reservations.bulk-legacy-cleanup']as$name)$this->assertFalse(Route::has($name));
  $this->assertSame(\App\Http\Controllers\PreinvoiceReservationReportController::class.'@index',Route::getRoutes()->getByName('warehouse-reservations.index')->getActionName());
 }
}
