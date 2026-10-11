<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\{PreinvoiceDraftReservation as Reservation,PreinvoiceOrder,Product};
use App\Services\{PreinvoiceDraftReservationService,ReservationClassificationService};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
class TemporaryReservationExpiryTest extends TestCase
{
 protected function setUp(): void
 {
  parent::setUp();
  Schema::create('users',fn(Blueprint $t)=>[$t->id(),$t->string('name')]);
  Schema::create('warehouses',fn(Blueprint $t)=>[$t->id(),$t->string('name'),$t->string('type'),$t->boolean('is_active'),$t->timestamps()]);
  Schema::create('products',fn(Blueprint $t)=>[$t->id(),$t->string('name'),$t->integer('stock'),$t->integer('reserved'),$t->timestamps()]);
  Schema::create('product_variants',fn(Blueprint $t)=>[$t->id(),$t->integer('product_id'),$t->string('variant_name'),$t->string('variant_code'),$t->string('variety_name')->nullable(),$t->integer('stock'),$t->integer('reserved'),$t->boolean('is_active')->default(true),$t->boolean('sales_enabled')->default(true),$t->timestamps()]);
  Schema::create('warehouse_stocks',fn(Blueprint $t)=>[$t->id(),$t->integer('warehouse_id'),$t->integer('product_id'),$t->integer('product_variant_id')->nullable(),$t->integer('quantity'),$t->timestamps()]);
  Schema::create('preinvoice_orders',fn(Blueprint $t)=>[$t->id(),$t->string('uuid'),$t->integer('created_by')->default(1),$t->string('draft_token')->nullable(),$t->string('status'),$t->timestamp('stock_released_at')->nullable(),$t->timestamps()]);
  Schema::create('invoices',fn(Blueprint $t)=>[$t->id(),$t->integer('preinvoice_order_id')]);
  Schema::create('preinvoice_draft_reservations',function(Blueprint $t){$t->id();$t->string('token');foreach(['user_id','product_id','variant_id','quantity']as$c)$t->integer($c);$t->integer('preinvoice_order_id')->nullable();$t->string('reservation_scope');$t->string('reservation_tier')->nullable();foreach(['expires_at','last_seen_at','converted_at','released_at']as$c)$t->timestamp($c)->nullable();$t->integer('released_by')->nullable();$t->string('release_reason')->nullable();$t->text('release_note')->nullable();$t->timestamps();});
  Schema::create('activity_logs',function(Blueprint $t){$t->id();$t->integer('user_id')->nullable();foreach(['action','subject_type','description']as$c)$t->string($c);$t->integer('subject_id');$t->text('properties');$t->timestamp('occurred_at');$t->timestamps();});
  DB::table('users')->insert(['id'=>1,'name'=>'Seller']);
  DB::table('warehouses')->insert(['id'=>1,'name'=>'انبار مرکزی','type'=>'central','is_active'=>true]);
  DB::table('products')->insert(['id'=>1,'name'=>'Cable','stock'=>20,'reserved'=>5]);
  DB::table('product_variants')->insert(['id'=>1,'product_id'=>1,'variant_name'=>'Black','variant_code'=>'X','stock'=>20,'reserved'=>5]);
  DB::table('warehouse_stocks')->insert(['warehouse_id'=>1,'product_id'=>1,'product_variant_id'=>1,'quantity'=>20]);
  DB::table('preinvoice_orders')->insert(['id'=>1,'uuid'=>'D1','draft_token'=>'token','status'=>'draft']);
  DB::table('preinvoice_draft_reservations')->insert(['id'=>1,'token'=>'token','user_id'=>1,'product_id'=>1,'variant_id'=>1,'quantity'=>5,'reservation_scope'=>'temporary_online','expires_at'=>now()->subHour(),'last_seen_at'=>now()->subHour(),'created_at'=>now()->subHours(2),'updated_at'=>now()->subHour()]);
 }
 private function cleanup(): array {return Model::withoutEvents(fn()=>app(PreinvoiceDraftReservationService::class)->cleanupStaleTemporaryReservations());}
 public function test_saved_draft_does_not_hold_stale_temporary_stock_and_release_is_idempotent(): void
 {
  $first=$this->cleanup();$this->assertSame(5,$first['released_quantity']);
  $this->assertSame(25,(int)DB::table('warehouse_stocks')->value('quantity'));
  $this->assertSame(0,(int)DB::table('product_variants')->value('reserved'));
  $this->assertSame('draft',PreinvoiceOrder::find(1)->status);
  $this->assertSame(0,$this->cleanup()['released_quantity']);
  $this->assertSame(25,(int)DB::table('warehouse_stocks')->value('quantity'));
  $this->assertSame(1,DB::table('activity_logs')->where('action','reservation_auto_release')->count());
 }
 public function test_fresh_editor_heartbeat_keeps_stock_even_if_saved_draft_exists(): void
 {
  DB::table('preinvoice_draft_reservations')->update(['last_seen_at'=>now()]);
  $this->assertSame(0,$this->cleanup()['released_quantity']);$this->assertSame(20,(int)DB::table('warehouse_stocks')->value('quantity'));
 }
 public function test_historical_rows_remain_protected_for_review(): void
 {
  DB::table('preinvoice_draft_reservations')->update(['last_seen_at'=>now()->subDays(5)]);
  $this->assertSame(0,$this->cleanup()['released_quantity']);$this->assertNull(Reservation::find(1)->released_at);
 }
 public function test_official_or_consumed_or_released_rows_never_reenter_cleanup(): void
 {
  foreach([['preinvoice_order_id'=>1,'reservation_scope'=>'official'],['converted_at'=>now()],['released_at'=>now()]]as$change){
   DB::table('preinvoice_draft_reservations')->update(array_merge(['preinvoice_order_id'=>null,'reservation_scope'=>'temporary_online','converted_at'=>null,'released_at'=>null],$change));
   $this->assertSame(0,$this->cleanup()['released_quantity']);$this->assertSame(20,(int)DB::table('warehouse_stocks')->value('quantity'));
  }
 }
 public function test_dry_run_preserves_every_table(): void
 {
  $before=DB::table('preinvoice_draft_reservations')->first();
  $result=app(PreinvoiceDraftReservationService::class)->cleanupStaleTemporaryReservations(5,15,true);
  $this->assertSame(5,$result['released_quantity']);$this->assertEquals($before,DB::table('preinvoice_draft_reservations')->first());$this->assertSame(20,(int)DB::table('warehouse_stocks')->value('quantity'));
 }
 public function test_seller_can_reserve_saved_lines_again_after_expiry_without_double_deduction(): void
 {
  $this->cleanup();$service=app(PreinvoiceDraftReservationService::class);
  $items=[['product_id'=>1,'variant_id'=>1,'quantity'=>5]];
  Model::withoutEvents(fn()=>$service->syncReservationRows('token',1,$items));
  $this->assertSame(20,(int)DB::table('warehouse_stocks')->value('quantity'));
  Model::withoutEvents(fn()=>$service->syncReservationRows('token',1,$items));
  $this->assertSame(20,(int)DB::table('warehouse_stocks')->value('quantity'));
  $this->assertNull(Reservation::find(1)->released_at);
  $this->assertSame(5,(int)DB::table('product_variants')->value('reserved'));
 }
 public function test_submitting_an_expired_saved_draft_rechecks_and_holds_stock_once(): void
 {
  $this->cleanup();$this->actingAs(\App\Models\User::find(1));
  DB::table('preinvoice_orders')->where('id',1)->update(['status'=>'pending_finance']);
  $order=PreinvoiceOrder::find(1);$order->setRelation('items',collect([(object)['product_id'=>1,'variant_id'=>1,'quantity'=>5]]));
  $controller=app(\App\Http\Controllers\PreinvoiceController::class);
  $meta=['expires_at'=>now()->addHours(24),'tier'=>'normal'];
  Model::withoutEvents(fn()=>\App\Support\ReservationSideEffects::transaction(function()use($controller,$order,$meta){
   $method=new \ReflectionMethod($controller,'finalizeDraftReservations');
   $method->invoke($controller,$order,'token',[['id'=>1,'variety_id'=>1,'quantity'=>5]],$meta);
   $controller->syncPreinvoiceReservations($order,true,$meta);
  }));
  $this->assertSame(20,(int)DB::table('warehouse_stocks')->value('quantity'));
  $this->assertSame(5,(int)Reservation::where('reservation_scope','official')->whereNull('released_at')->sum('quantity'));
  $this->assertSame(0,$this->cleanup()['released_quantity']);
  $this->assertSame(20,(int)DB::table('warehouse_stocks')->value('quantity'));
 }

}
