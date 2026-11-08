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
                $columns = array_unique(array_merge($model->getFillable(), ['created_at', 'updated_at']));
                if ($model instanceof StockCountDocument) {
                    $columns = array_diff($columns, ['superseded_by_id']);
                }
                foreach ($columns as $column) {
                    if ($column !== 'id') {
                        $table->text($column)->nullable();
                    }
                }
                if ($model instanceof StockCountDocument) {
                    $table->unsignedBigInteger('superseded_by_id')->nullable();
                }
            });
        }
        // The stock-count service must read the real bundle and Site-hold
        // queries; empty fixture tables are preferable to mocking away safety.
        Schema::create('product_bundle_components', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('bundle_variant_id');
            $table->unsignedBigInteger('component_variant_id'); $table->unsignedInteger('quantity');
        });
        Schema::create('bundle_component_allocations', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('bundle_variant_id');
            $table->unsignedBigInteger('component_variant_id'); $table->unsignedInteger('remaining');
        });
        Schema::create('invoices', function (Blueprint $table) {
            $table->id(); $table->string('status')->nullable(); $table->timestamp('shipped_at')->nullable();
        });
        Schema::create('site_stock_holds', function (Blueprint $table) {
            $table->id(); $table->string('status'); $table->unsignedBigInteger('invoice_id')->nullable();
        });
        Schema::create('site_stock_hold_lines', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('hold_id');
            $table->unsignedBigInteger('variant_id'); $table->unsignedInteger('quantity');
        });
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

    private function makeAllocatedBundle(int $remaining): void
    {
        DB::table('products')->insert(['id'=>2,'name'=>'Bundle','is_bundle'=>1,'bundle_stock_mode'=>'automatic']);
        DB::table('product_variants')->insert(['id'=>2,'product_id'=>2,'variant_name'=>'Bundle SKU']);
        DB::table('product_bundle_components')->insert([
            'bundle_variant_id'=>2, 'component_variant_id'=>1, 'quantity'=>2,
        ]);
        DB::table('bundle_component_allocations')->insert([
            'bundle_variant_id'=>2, 'component_variant_id'=>1, 'remaining'=>$remaining,
        ]);
        DB::table('warehouse_stocks')->where('product_variant_id',1)->update(['quantity'=>8]);
        DB::table('product_variants')->where('id',1)->update(['stock'=>8]);
        DB::table('products')->where('id',1)->update(['stock'=>8]);
    }

    public function test_saving_a_count_with_explicit_zero_and_positive_numbers_only_creates_a_draft(): void
    {
        // This is the same payload the inline category counter sends for one
        // product: zero is an explicit count, never an omitted quantity.
        DB::table('product_variants')->insert([
            'id' => 2, 'product_id' => 1, 'variant_name' => 'White',
            'stock' => 5, 'reserved' => 0,
        ]);
        DB::table('warehouse_stocks')->insert([
            'id' => 2, 'warehouse_id' => 1, 'product_id' => 1,
            'product_variant_id' => 2, 'quantity' => 5,
        ]);

        $document = app(StockCountDocumentService::class)->createProductDraft([
            'product_id' => 1,
            'document_date' => '2026-11-05',
            'actual_quantities' => [1 => 0, 2 => 7],
        ], 1);

        $this->assertSame('draft', $document->fresh()->status);
        $this->assertSame(2, $document->items()->count());
        $this->assertSame(0, (int) $document->items()->where('product_variant_id', 1)->value('actual_quantity'));
        $this->assertSame(7, (int) $document->items()->where('product_variant_id', 2)->value('actual_quantity'));
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(10, (int) DB::table('warehouse_stocks')->where('product_variant_id', 1)->value('quantity'));
        $this->assertSame(5, (int) DB::table('warehouse_stocks')->where('product_variant_id', 2)->value('quantity'));
        $this->assertSame(10, (int) DB::table('product_variants')->where('id', 1)->value('stock'));
        $this->assertSame(5, (int) DB::table('product_variants')->where('id', 2)->value('stock'));
    }

    public function test_bundle_component_is_not_recreated_as_free_stock_by_a_physical_count(): void
    {
        $this->makeAllocatedBundle(2);
        $draft=$this->draft(10);
        $item=$draft->items()->first();
        $this->assertSame(2,(int)$item->bundle_free_at_start);
        $this->assertSame(10,(int)$item->expected_physical_at_start);
        app(StockCountDocumentService::class)->finalize($draft,1,true);
        $this->unchangedStock(8);
        $this->assertSame('finalized',$draft->fresh()->status);
        $this->assertSame(0,StockMovement::count());
    }

    public function test_bundle_allocation_changed_after_draft_blocks_finalization(): void
    {
        $this->makeAllocatedBundle(2);
        $draft=$this->draft(10);
        DB::table('bundle_component_allocations')->update(['remaining'=>1]);
        try { app(StockCountDocumentService::class)->finalize($draft,1,true); $this->fail('Stale bundle share was accepted'); }
        catch (StockCountFinalizeValidationException $e) {
            $this->assertContains('bundle_allocation_changed', array_column($e->conflicts(), 'reason_code'));
        }
        $this->unchangedStock(8);
        $this->assertSame('draft',$draft->fresh()->status);
        $this->assertSame(0,StockMovement::count());
    }

    public function test_site_held_units_are_counted_as_physical_but_never_as_free_stock(): void
    {
        DB::table('warehouse_stocks')->where('product_variant_id',1)->update(['quantity'=>8]);
        DB::table('product_variants')->where('id',1)->update(['stock'=>8]);
        DB::table('products')->where('id',1)->update(['stock'=>8]);
        DB::table('site_stock_holds')->insert(['id'=>1,'status'=>'held']);
        DB::table('site_stock_hold_lines')->insert(['hold_id'=>1,'variant_id'=>1,'quantity'=>2]);
        $draft=$this->draft(10);
        $this->assertSame(2,(int)$draft->items()->first()->site_held_at_start);
        app(StockCountDocumentService::class)->finalize($draft,1,true);
        $this->unchangedStock(8);
        $this->assertSame(0,StockMovement::count());
    }


    public function test_superseded_draft_cannot_be_finalized_or_change_stock(): void
    {
        $draft = $this->draft();
        $draft->forceFill(['superseded_by_id' => 209])->save();

        try {
            app(StockCountDocumentService::class)->finalize($draft, 1, true);
            $this->fail('Superseded child draft was finalized again');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame(209, (int) $draft->fresh()->superseded_by_id);
        $this->unchangedStock(10);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, StockCountDocumentHistory::where('action_type', 'finalized')->count());
    }

    public function test_superseded_draft_cannot_be_updated(): void
    {
        $draft = $this->draft();
        $draft->forceFill(['superseded_by_id' => 209])->save();

        try {
            app(StockCountDocumentService::class)->updateProductDraft($draft, [
                'document_date' => '2026-10-19',
                'actual_quantities' => [1 => 99],
            ], 1);
            $this->fail('Superseded child draft was updated');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame(8, $draft->fresh()->items()->first()->actual_quantity);
        $this->unchangedStock(10);
        $this->assertSame(0, StockCountDocumentHistory::where('action_type', 'updated')->count());
    }

    public function test_superseded_draft_cannot_be_cancelled(): void
    {
        $draft = $this->draft();
        $draft->forceFill(['superseded_by_id' => 209])->save();

        try {
            app(StockCountDocumentService::class)->cancel($draft, 1, 'old document');
            $this->fail('Superseded child draft was cancelled');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame('draft', $draft->fresh()->status);
        $this->unchangedStock(10);
        $this->assertSame(0, StockCountDocumentHistory::where('action_type', 'cancelled')->count());
    }

    public function test_stale_review_token_rejects_edits_without_mutating_counts(): void
    {
        $draft = $this->draft();
        $service = app(StockCountDocumentService::class);
        $staleToken = $service->reviewToken($draft);
        $draft->update(['description' => 'another page updated description']);

        try {
            $service->updateProductDraft($draft, [
                'document_date' => '2026-10-19',
                'actual_quantities' => [1 => 99],
                'expected_review_token' => $staleToken,
            ], 1);
            $this->fail('Stale review token was accepted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame(8, $draft->fresh()->items()->first()->actual_quantity);
        $this->unchangedStock(10);
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
