<?php

namespace Tests\Feature;

use App\Exceptions\StockCountFinalizeValidationException;
use App\Models\{Product, ProductVariant, StockCountDocument, StockCountDocumentHistory, StockCountDocumentItem, StockMovement, Warehouse, WarehouseStock};
use App\Services\{ReservationQueryService, StockCountDocumentService, WarehouseStockService};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Event, Http, Log, Schema};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StockCountLifecycleSafetyTest extends TestCase
{
    private int $reserved=0;

    protected function setUp(): void
    {
        parent::setUp(); Event::fake(); Http::fake();
        Schema::create('users',function(Blueprint $table){$table->id();$table->string('name')->nullable();});
        foreach ([Product::class,ProductVariant::class,Warehouse::class,WarehouseStock::class,StockCountDocument::class,StockCountDocumentItem::class,StockCountDocumentHistory::class,StockMovement::class] as $class) {
            $model=new $class;
            Schema::create($model->getTable(),function(Blueprint $table)use($model){
                $table->id();
                foreach(array_unique(array_merge($model->getFillable(),['created_at','updated_at'])) as $column) if($column!=='id')$table->text($column)->nullable();
            });
        }
        DB::table('products')->insert(['id'=>1,'name'=>'Safety product','stock'=>10,'reserved'=>0]);
        DB::table('product_variants')->insert(['id'=>1,'product_id'=>1,'variant_name'=>'Black','stock'=>10,'reserved'=>0]);
        DB::table('warehouses')->insert(['id'=>1,'name'=>'انبار مرکزی','type'=>'central','is_active'=>1]);
        DB::table('warehouse_stocks')->insert(['id'=>1,'warehouse_id'=>1,'product_id'=>1,'product_variant_id'=>1,'quantity'=>10]);
        $this->mock(ReservationQueryService::class)->shouldReceive('quantitiesByVariant')->andReturnUsing(fn()=>collect([1=>$this->reserved]));
    }

    private function draft(int $actual=8): StockCountDocument
    {
        return app(StockCountDocumentService::class)->createProductDraft(['product_id'=>1,'document_date'=>'2026-10-18','actual_quantities'=>[1=>$actual]],1);
    }

    private function unchangedStock(int $expected): void
    {
        $this->assertSame($expected,(int)DB::table('warehouse_stocks')->value('quantity'));
        $this->assertSame($expected,(int)DB::table('product_variants')->value('stock'));
        $this->assertSame($expected,(int)DB::table('products')->value('stock'));
    }

    public function test_partial_draft_stays_editable_but_blank_variant_cannot_be_finalized_as_zero(): void
    {
        DB::table('product_variants')->insert([
            'id'=>2,'product_id'=>1,'variant_name'=>'Blue',
            'variant_code'=>'BLUE-2','stock'=>4,'reserved'=>0,
        ]);
        DB::table('warehouse_stocks')->insert([
            'warehouse_id'=>1,'product_id'=>1,'product_variant_id'=>2,'quantity'=>4,
        ]);
        $draft=app(StockCountDocumentService::class)->createProductDraft([
            'product_id'=>1,'document_date'=>'2026-10-29',
            'actual_quantities'=>[1=>8],
        ],1);
        $this->assertSame('draft',$draft->status);
        $this->assertNull($draft->items()->where('product_variant_id',2)->value('actual_quantity'));
        $this->assertSame(4, (int)DB::table('warehouse_stocks')->where('product_variant_id',2)->value('quantity'));
        try {
            // Even the old "confirm empty as zero" flag cannot bypass the gate.
            app(StockCountDocumentService::class)->finalize($draft,1,true);
            $this->fail('Incomplete count must never finalize');
        } catch (StockCountFinalizeValidationException $e) {
            $this->assertContains('uncounted_variant',array_column($e->conflicts(),'reason_code'));
        }
        $this->assertSame('draft',$draft->fresh()->status);
        $this->assertSame(0,StockMovement::count());
        $this->unchangedStock(10);
        $this->assertSame(4,(int)DB::table('warehouse_stocks')->where('product_variant_id',2)->value('quantity'));

        $edited=app(StockCountDocumentService::class)->updateProductDraft($draft,[
            'document_date'=>'2026-10-29','actual_quantities'=>[1=>8,2=>0],
        ],1);
        $this->assertSame(0, $edited->items()->where('product_variant_id',2)->value('actual_quantity'));
        $final=app(StockCountDocumentService::class)->finalize($edited,1,false);
        $this->assertSame('finalized',$final->status);
        $this->assertSame(0,(int)DB::table('warehouse_stocks')->where('product_variant_id',2)->value('quantity'));
    }

    public function test_stale_cancel_cannot_relabel_an_applied_stocktake(): void
    {
        $stale=$this->draft();$service=app(StockCountDocumentService::class);
        $service->finalize($stale->fresh(),1,true);
        try{$service->cancel($stale,2,'stale browser');$this->fail('Applied stocktake was cancelled using a stale draft');}
        catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        $this->assertSame('finalized',$stale->fresh()->status);$this->unchangedStock(8);
        $this->assertSame(0,StockCountDocumentHistory::where('action_type','cancelled')->count());
    }

    public function test_stale_update_cannot_edit_applied_count_details(): void
    {
        $stale=$this->draft();$service=app(StockCountDocumentService::class);
        $service->finalize($stale->fresh(),1,true);
        try{$service->updateProductDraft($stale,['document_date'=>'2026-10-19','actual_quantities'=>[1=>99]],2);$this->fail('Applied count was edited using a stale draft');}
        catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        $this->assertSame(8,$stale->fresh()->items()->first()->actual_quantity);$this->unchangedStock(8);
    }

    public function test_repeated_stale_cancel_does_not_duplicate_history(): void
    {
        $stale=$this->draft();$service=app(StockCountDocumentService::class);
        $service->cancel($stale->fresh(),1);$service->cancel($stale,2);
        $this->assertSame(1,StockCountDocumentHistory::where('action_type','cancelled')->count());$this->unchangedStock(10);
    }

    public function test_changed_free_stock_blocks_the_entire_stocktake(): void
    {
        $draft=$this->draft();DB::table('warehouse_stocks')->update(['quantity'=>7]);
        try{app(StockCountDocumentService::class)->finalize($draft,1,true);$this->fail('Changed stock must block finalization');}
        catch(StockCountFinalizeValidationException $e){$this->assertSame('draft',$draft->fresh()->status);}
        $this->assertSame(7,(int)DB::table('warehouse_stocks')->value('quantity'));$this->assertSame(0,StockMovement::count());
    }

    public function test_changed_reservation_blocks_the_entire_stocktake(): void
    {
        $draft=$this->draft();$this->reserved=2;
        try{app(StockCountDocumentService::class)->finalize($draft,1,true);$this->fail('Changed reservation must block finalization');}
        catch(StockCountFinalizeValidationException $e){$this->assertSame('draft',$draft->fresh()->status);}
        $this->unchangedStock(10);$this->assertSame(0,StockMovement::count());
    }

    public function test_failed_movement_insert_rolls_back_stock_and_document(): void
    {
        $draft=$this->draft();Schema::drop('stock_movements');
        try{app(StockCountDocumentService::class)->finalize($draft,1,true);$this->fail('Expected movement failure');}
        catch(\Illuminate\Database\QueryException $e){$this->assertSame('draft',$draft->fresh()->status);}
        $this->unchangedStock(10);$this->assertNull($draft->items()->first()->new_available);
    }

    public function test_repeated_finalize_cannot_apply_the_stock_delta_twice(): void
    {
        $draft=$this->draft();$service=app(StockCountDocumentService::class);$service->finalize($draft,1,true);
        try{$service->finalize($draft,1,true);$this->fail('Expected repeated finalization rejection');}
        catch(HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        $this->unchangedStock(8);$this->assertSame(1,StockMovement::count());
    }

    public function test_return_warehouse_change_cannot_replace_central_display_for_a_product_without_variants(): void
    {
        DB::table('products')->insert(['id'=>2,'name'=>'Legacy single product','stock'=>7]);
        DB::table('warehouses')->insert(['id'=>2,'name'=>'Returns','type'=>'return','is_active'=>1]);
        DB::table('warehouse_stocks')->insert(['warehouse_id'=>1,'product_id'=>2,'product_variant_id'=>null,'quantity'=>7]);
        WarehouseStockService::change(2,2,2);
        $this->assertSame(7,(int)DB::table('products')->where('id',2)->value('stock'));
        $this->assertSame(7,WarehouseStockService::available(1,2));
        $this->assertSame(2,WarehouseStockService::available(2,2));
    }

    public function test_failed_cancel_history_rolls_back_document_status(): void
    {
        $draft=$this->draft();Schema::drop('stock_count_document_history');
        try{app(StockCountDocumentService::class)->cancel($draft,1);$this->fail('Expected history failure');}
        catch(\Illuminate\Database\QueryException $e){$this->assertSame('draft',$draft->fresh()->status);}
        $this->unchangedStock(10);
    }

    public function test_projection_refresh_failure_does_not_fail_a_committed_stock_change(): void
    {
        Log::spy();Event::fakeExcept([\Illuminate\Database\Events\QueryExecuted::class]);$updates=0;
        DB::listen(function($event)use(&$updates){
            if(str_starts_with($event->sql,'update "products"') && ++$updates===2)throw new \RuntimeException('Projection connection failed');
        });
        WarehouseStockService::change(1,1,1,1);
        $this->unchangedStock(11);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_outer_rollback_discards_stock_and_projection_callbacks(): void
    {
        try{DB::transaction(function(){WarehouseStockService::change(1,1,2,1);throw new \RuntimeException('Later document step failed');});}
        catch(\RuntimeException $e){$this->assertSame('Later document step failed',$e->getMessage());}
        $this->unchangedStock(10);
        WarehouseStockService::change(1,1,1,1);$this->unchangedStock(11);
    }
}
