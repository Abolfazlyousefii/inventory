<?php
namespace Tests\Feature;
use App\Models\Product;
use App\Http\Controllers\ProductStockLedgerController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Http\Request;
use Tests\TestCase;

class ProductStockLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('products',fn(Blueprint $t)=>[$t->id(),$t->string('name')]);
        Schema::create('product_variants',fn(Blueprint $t)=>[$t->id(),$t->integer('product_id'),$t->string('variant_name'),$t->string('variant_code')->nullable()]);
        Schema::create('warehouses',fn(Blueprint $t)=>[$t->id(),$t->string('name'),$t->string('type')->nullable()]);
        Schema::create('users',fn(Blueprint $t)=>[$t->id(),$t->string('name')]);
        Schema::create('purchases',fn(Blueprint $t)=>[$t->id(),$t->integer('user_id')->nullable(),$t->timestamps()]);
        Schema::create('invoices',function(Blueprint $t){$t->id();$t->string('uuid');$t->string('status')->default('shipped');$t->integer('preinvoice_order_id')->nullable();$t->integer('seller_id')->nullable();$t->timestamp('cancelled_at')->nullable();$t->timestamps();});
        Schema::create('invoice_items',fn(Blueprint $t)=>[$t->id(),$t->integer('invoice_id'),$t->integer('product_id'),$t->integer('variant_id')->nullable(),$t->integer('quantity')]);
        Schema::create('purchase_items',fn(Blueprint $t)=>[$t->id(),$t->integer('purchase_id'),$t->integer('product_id'),$t->integer('product_variant_id')->nullable(),$t->integer('quantity')]);
        Schema::create('preinvoice_orders',fn(Blueprint $t)=>[$t->id(),$t->string('uuid')]);
        Schema::create('preinvoice_draft_reservations',function(Blueprint $t){$t->id();$t->integer('product_id');$t->integer('variant_id');$t->integer('preinvoice_order_id')->nullable();$t->integer('quantity');$t->integer('user_id')->nullable();$t->integer('released_by')->nullable();$t->string('release_reason')->nullable();$t->string('release_note')->nullable();$t->timestamp('released_at')->nullable();$t->timestamps();});
        Schema::create('warehouse_stocks',fn(Blueprint $t)=>[$t->id(),$t->integer('warehouse_id'),$t->integer('product_id'),$t->integer('product_variant_id')->nullable(),$t->integer('quantity')]);
        Schema::create('warehouse_transfers',function(Blueprint $t){$t->id();$t->string('reference')->nullable();$t->string('voucher_type');$t->integer('from_warehouse_id')->nullable();$t->integer('to_warehouse_id')->nullable();$t->integer('user_id')->nullable();$t->timestamp('transferred_at')->nullable();$t->timestamps();});
        Schema::create('warehouse_transfer_items',function(Blueprint $t){$t->id();$t->integer('warehouse_transfer_id');$t->integer('product_id');$t->integer('product_variant_id')->nullable();$t->integer('destination_warehouse_id')->nullable();$t->integer('quantity');});
        Schema::create('stock_count_documents',fn(Blueprint $t)=>[$t->id(),$t->string('document_number'),$t->string('status'),$t->timestamp('finalized_at')->nullable(),$t->integer('finalized_by')->nullable()]);
        Schema::create('stock_count_document_items',function(Blueprint $t){$t->id();foreach(['document_id','product_id','product_variant_id','warehouse_id'] as $c)$t->integer($c);$t->integer('system_available_at_start')->nullable();$t->integer('new_available')->nullable();});
        Schema::create('stock_movements',function(Blueprint $t){$t->id();$t->integer('product_id');foreach(['product_variant_id','warehouse_id','user_id','stock_before','stock_after','reference_id']as$c)$t->integer($c)->nullable();foreach(['type','reason','reference','reference_type','note','transaction_type']as$c)$t->string($c)->nullable();$t->integer('quantity');$t->timestamps();});
        DB::table('products')->insert([['id'=>1,'name'=>'نمونه'],['id'=>2,'name'=>'دیگر']]);DB::table('product_variants')->insert([['id'=>1,'product_id'=>1,'variant_name'=>'مدل۱'],['id'=>2,'product_id'=>2,'variant_name'=>'مدل۲']]);DB::table('warehouses')->insert([['id'=>1,'name'=>'مرکزی'],['id'=>2,'name'=>'مرجوعی']]);DB::table('purchases')->insert(['id'=>4]);
        foreach([[1,1,'in',200,'purchase','PUR-4','2026-10-01 23:59:59'],[1,1,'out',100,'sale',null,'2026-10-02 00:00:00'],[1,2,'in',10,'return',null,'2026-10-03 00:00:00'],[2,1,'in',999,'purchase',null,'2026-10-01 00:00:00']] as [$product,$warehouse,$type,$qty,$reason,$ref,$date]) DB::table('stock_movements')->insert(['product_id'=>$product,'product_variant_id'=>$product,'warehouse_id'=>$warehouse,'type'=>$type,'reason'=>$reason,'quantity'=>$qty,'reference'=>$ref,'created_at'=>$date]);
        DB::table('warehouses')->where('id',1)->update(['type'=>'central']);
    }
    private function data(array $filters=[]): array
    {
        $request=Request::create('/products/1/stock-ledger','GET',$filters);app()->instance('request',$request);
        return app(ProductStockLedgerController::class)->index($request,Product::findOrFail(1))->getData();
    }
    public function test_only_product_movements_are_counted_and_purchase_sources_are_resolved(): void
    {
        DB::enableQueryLog();$data=$this->data();$this->assertSame(210,(int)$data['summary']->incoming);$this->assertSame(100,(int)$data['summary']->outgoing);$this->assertSame(3,$data['movements']->total());$this->assertSame(route('purchases.show',4),$data['sources'][1]['url']);
        $this->assertEmpty(array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])));
    }
    public function test_old_filters_do_not_hide_any_history(): void
    {
        $data=$this->data(['date_from'=>'1405/07/09','date_to'=>'1405/07/10','warehouse_id'=>1,'type'=>'in']);$this->assertSame(3,$data['movements']->total());$this->assertSame(210,(int)$data['summary']->incoming);$this->assertSame(100,(int)$data['summary']->outgoing);
        $this->assertStringNotContainsString('date_from',$data['movements']->url(2));
    }
    public function test_foreign_variant_is_rejected(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);$this->data(['variant_id'=>2]);
    }
    public function test_all_history_of_selected_variant_is_available(): void
    {
        $data=$this->data(['variant_id'=>1]);$this->assertSame(3,$data['movements']->total());$this->assertStringContainsString('variant_id=1',$data['movements']->url(2));
    }
    public function test_sales_without_stock_logs_are_visible_without_inventing_stock_deltas(): void
    {
        DB::table('invoices')->insert(['id'=>9,'uuid'=>'01737','status'=>'shipped','created_at'=>'2026-10-03 12:01:00']);
        DB::table('invoice_items')->insert(['invoice_id'=>9,'product_id'=>1,'variant_id'=>1,'quantity'=>10]);
        DB::enableQueryLog();$data=$this->data();
        $sale=$data['movements']->first(fn($row)=>$row->history_kind==='سند فروش');
        $this->assertSame(10,$data['summary']->sold);$this->assertSame(4,$data['movements']->total());
        $this->assertSame('document_out',$sale->type);$this->assertNull($sale->stock_before);
        $this->assertSame(100,$data['summary']->outgoing);
        $this->assertSame(route('invoices.show','01737'),$data['sources'][$sale->id]['url']);
        $this->assertEmpty(array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])));
    }
    public function test_consumed_and_archived_reservations_do_not_create_false_stock_returns(): void
    {
        foreach(['consumed','converted_invoice_cleanup','legacy_cleanup','historical_reconciliation_stock_neutral','manual_release','unknown'] as $reason)
            DB::table('preinvoice_draft_reservations')->insert(['product_id'=>1,'variant_id'=>1,'quantity'=>10,'release_reason'=>$reason,'created_at'=>'2026-09-01','released_at'=>'2026-09-02']);
        $data=$this->data();$releases=$data['movements']->filter(fn($r)=>str_starts_with((string)$r->id,'release-'));
        $this->assertCount(6,$releases);$this->assertCount(1,$releases->where('type','document_in'));
        $this->assertCount(5,$releases->where('type','info'));
        $this->assertSame(210,$data['summary']->incoming);$this->assertSame(100,$data['summary']->outgoing);
    }
    public function test_purchase_and_transfer_documents_are_not_duplicated_when_stock_events_exist(): void
    {
        DB::table('purchase_items')->insert(['purchase_id'=>4,'product_id'=>1,'product_variant_id'=>1,'quantity'=>200]);
        DB::table('warehouse_transfers')->insert(['id'=>8,'voucher_type'=>'between_warehouses','from_warehouse_id'=>1,'to_warehouse_id'=>2,'created_at'=>'2026-09-01']);
        DB::table('warehouse_transfer_items')->insert(['warehouse_transfer_id'=>8,'product_id'=>1,'product_variant_id'=>1,'quantity'=>3]);
        $data=$this->data();$this->assertSame(5,$data['movements']->total());
        $this->assertCount(0,$data['movements']->filter(fn($r)=>str_starts_with((string)$r->id,'purchase-item-')));
        DB::table('stock_movements')->where('id',3)->update(['reference_type'=>\App\Models\WarehouseTransfer::class,'reference_id'=>8]);
        $this->assertSame(4,$this->data()['movements']->total());
    }
    public function test_current_stock_excludes_aggregate_duplicates_and_cancelled_sales(): void
    {
        DB::table('warehouse_stocks')->insert([['warehouse_id'=>1,'product_id'=>1,'product_variant_id'=>null,'quantity'=>73],['warehouse_id'=>1,'product_id'=>1,'product_variant_id'=>1,'quantity'=>73],['warehouse_id'=>2,'product_id'=>1,'product_variant_id'=>1,'quantity'=>9]]);
        DB::table('invoices')->insert(['id'=>9,'uuid'=>'X','status'=>'shipped','cancelled_at'=>'2026-10-01']);
        DB::table('invoice_items')->insert(['invoice_id'=>9,'product_id'=>1,'variant_id'=>1,'quantity'=>10]);
        $data=$this->data();$this->assertSame(73,$data['summary']->current);$this->assertSame(0,$data['summary']->sold);
    }
    public function test_finalized_stocktake_is_visible_once_and_drafts_have_no_stock_effect(): void
    {
        DB::table('stock_count_documents')->insert([['id'=>1,'document_number'=>'SC-1','status'=>'finalized','finalized_at'=>'2026-09-01'],['id'=>2,'document_number'=>'SC-2','status'=>'draft','finalized_at'=>null]]);
        foreach([1,2] as $id) DB::table('stock_count_document_items')->insert(['document_id'=>$id,'product_id'=>1,'product_variant_id'=>1,'warehouse_id'=>1,'system_available_at_start'=>20,'new_available'=>25]);
        $data=$this->data();$this->assertSame(4,$data['movements']->total());$this->assertSame(215,$data['summary']->incoming);
        DB::table('stock_movements')->where('id',1)->update(['reference_type'=>'stock_count_document','reference_id'=>1]);
        $this->assertSame(3,$this->data()['movements']->total());
    }
    public function test_document_history_is_paginated_and_variant_filter_is_preserved(): void
    {
        for($i=1;$i<=35;$i++) DB::table('preinvoice_draft_reservations')->insert(['product_id'=>1,'variant_id'=>1,'quantity'=>1,'created_at'=>'2026-09-01']);
        $data=$this->data(['variant_id'=>1,'page'=>2]);$this->assertSame(38,$data['movements']->total());$this->assertCount(8,$data['movements']);
        $this->assertStringContainsString('variant_id=1',$data['movements']->url(1));
    }
}
