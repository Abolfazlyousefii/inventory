<?php
namespace Tests\Feature;
use App\Models\{Product,ProductVariant,StockCountDocument,StockCountDocumentHistory,StockCountDocumentItem,StockMovement,Warehouse,WarehouseStock};
use App\Services\{ReservationQueryService,StockCountDocumentService};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Event,Http,Schema};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class StockCountSiteHoldSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); Event::fake(); Http::fake();
        Schema::create('users',fn(Blueprint $t)=>[$t->id(),$t->string('name')->nullable()]);
        foreach([Product::class,ProductVariant::class,Warehouse::class,WarehouseStock::class,StockCountDocument::class,StockCountDocumentItem::class,StockCountDocumentHistory::class,StockMovement::class]as$class){$m=new$class;Schema::create($m->getTable(),function(Blueprint$t)use($m){$t->id();foreach(array_unique(array_merge($m->getFillable(),['created_at','updated_at']))as$c)if($c!=='id')$t->text($c)->nullable();});}
        Schema::create('site_stock_holds',function(Blueprint$t){$t->id();$t->integer('site_order_id');$t->string('status');});
        Schema::create('site_stock_hold_lines',function(Blueprint$t){$t->id();$t->integer('hold_id');$t->integer('product_id');$t->integer('variant_id');$t->integer('quantity');});
        DB::table('products')->insert(['id'=>1,'name'=>'Count me','stock'=>10,'reserved'=>0]);
        DB::table('product_variants')->insert(['id'=>1,'product_id'=>1,'variant_name'=>'Black','stock'=>10,'reserved'=>0]);
        DB::table('warehouses')->insert(['id'=>1,'name'=>'انبار مرکزی','type'=>'central','is_active'=>1]);
        DB::table('warehouse_stocks')->insert(['id'=>1,'warehouse_id'=>1,'product_id'=>1,'product_variant_id'=>1,'quantity'=>10]);
        $this->mock(ReservationQueryService::class)->shouldReceive('quantitiesByVariant')->andReturn(collect());
    }
    private function hold(string$status): void
    {
        DB::table('site_stock_holds')->insert(['id'=>1,'site_order_id'=>92,'status'=>$status]);
        DB::table('site_stock_hold_lines')->insert(['hold_id'=>1,'product_id'=>1,'variant_id'=>1,'quantity'=>2]);
    }
    private function draft(): StockCountDocument
    {return app(StockCountDocumentService::class)->createProductDraft(['product_id'=>1,'document_date'=>'2026-10-30','actual_quantities'=>[1=>10]],1);}
    public function test_unpaid_site_hold_blocks_snapshot_without_writing_count_or_stock(): void
    {
        $this->hold('held');try{$this->draft();$this->fail('Site held units ignored');}catch(ValidationException$e){$this->assertStringContainsString('92',$e->getMessage());}
        $this->assertSame(0,StockCountDocument::count());$this->assertSame(10,(int)DB::table('warehouse_stocks')->value('quantity'));
    }
    public function test_paid_site_hold_blocks_unfrozen_snapshot(): void
    {
        $this->hold('payment_confirmed');$this->expectException(ValidationException::class);$this->draft();
    }
    public function test_new_site_hold_blocks_finalization_even_when_available_quantity_still_matches_snapshot(): void
    {
        $draft=$this->draft();$this->hold('payment_confirmed');try{app(StockCountDocumentService::class)->finalize($draft,1);$this->fail('New Site hold ignored');}catch(ValidationException$e){$this->assertStringContainsString('92',$e->getMessage());}
        $this->assertSame('draft',$draft->fresh()->status);$this->assertSame(10,(int)DB::table('warehouse_stocks')->value('quantity'));$this->assertSame(0,StockMovement::count());
    }
    public function test_released_site_hold_does_not_prevent_ordinary_product_count(): void
    {
        $this->hold('released');$draft=$this->draft();$this->assertSame('finalized',app(StockCountDocumentService::class)->finalize($draft,1)->status);
    }
    public function test_automatic_bundle_cannot_be_counted_as_physical_stock(): void
    {
        DB::table('products')->where('id',1)->update(['is_bundle'=>1,'bundle_stock_mode'=>'automatic']);
        try{$this->draft();$this->fail('Virtual bundle counted as physical');}catch(ValidationException$e){$this->assertStringContainsString('قطعات',$e->getMessage());}$this->assertSame(0,StockCountDocument::count());
    }
    public function test_physical_bundle_remains_countable(): void
    {
        DB::table('products')->where('id',1)->update(['is_bundle'=>1,'bundle_stock_mode'=>'physical']);$this->assertSame('draft',$this->draft()->status);
    }
}
