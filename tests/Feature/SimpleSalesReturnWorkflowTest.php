<?php
use App\Models\{Category,Customer,Invoice,Product,ProductVariant,SalesReturnDocument,CustomerLedger,StockMovement,User,Warehouse,WarehouseInboundReceipt};
use App\Services\{SalesReturnService,WarehouseStockService,SalesReturnCorrectionService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
uses(RefreshDatabase::class);
beforeEach(function(){
 $this->user=User::factory()->create(); $this->actingAs($this->user);
 $this->customer=Customer::create(['name'=>'Return QA','mobile'=>'09121112233']);
 $c=Category::withoutEvents(fn()=>Category::create(['name'=>'Returns QA']));
 $this->product=Product::withoutEvents(fn()=>Product::create(['name'=>'Return product','category_id'=>$c->id,'sku'=>'R-QA','price'=>9999,'is_sellable'=>true]));
 $this->variant=ProductVariant::withoutEvents(fn()=>ProductVariant::create(['product_id'=>$this->product->id,'variant_name'=>'QA','variant_code'=>'R-QA','sell_price'=>9999,'is_active'=>true,'sales_enabled'=>true,'stock'=>0]));
 $this->central=WarehouseStockService::centralWarehouseId();
 $this->returns=Warehouse::firstOrCreate(['type'=>'return','name'=>'انبار مرجوعی'],['is_active'=>true]);
 WarehouseStockService::set($this->central,$this->product->id,$this->variant->id,7);
 $this->invoice=Invoice::create(['uuid'=>'QA-'.Str::uuid(),'customer_id'=>$this->customer->id,'status'=>Invoice::STATUS_SHIPPED,'subtotal'=>3000,'total'=>2400,'discount_amount'=>600,'invoice_discount_amount'=>300,'discount_allocation_mode'=>'product_lines']);
 $this->line=$this->invoice->items()->create(['product_id'=>$this->product->id,'variant_id'=>$this->variant->id,'quantity'=>3,'price'=>1000,'line_discount_amount'=>300]);
 $this->data=['submission_token'=>(string)Str::uuid(),'source_type'=>'internal_invoice','customer_id'=>$this->customer->id,'invoice_id'=>$this->invoice->id,'return_reason'=>'warranty','commission_effect_type'=>'commercial','return_flow'=>'financial_only','default_destination_warehouse_id'=>$this->returns->id,'items'=>[['item_source'=>'invoice_item','invoice_item_id'=>$this->line->id,'return_quantity'=>2,'destination_warehouse_id'=>$this->returns->id]]];
 $this->service=app(SalesReturnService::class);
});
it('keeps drafts inert and finalizes only once into returns with the final invoice net price',function(){
 $doc=$this->service->createSimpleDraft($this->data,$this->user->id);
 expect($doc->return_flow)->toBe('return_only')->and($doc->commission_effect_type)->toBeNull()->and($doc->total_refund_amount)->toBe(1600);
 expect(CustomerLedger::count())->toBe(0)->and(WarehouseStockService::available($this->returns->id,$this->product->id,$this->variant->id))->toBe(0);
 for($n=0;$n<2;$n++)$this->service->apply($doc,$this->user->id);
 expect($doc->fresh()->status)->toBe('applied')->and(CustomerLedger::where('reference_id',$doc->id)->sum('amount'))->toBe(1600)
 ->and(CustomerLedger::count())->toBe(1)->and(StockMovement::where('reference_type',App\Models\SalesReturnDocumentItem::class)->count())->toBe(1)
 ->and(WarehouseStockService::available($this->returns->id,$this->product->id,$this->variant->id))->toBe(2)
 ->and(WarehouseStockService::available($this->central,$this->product->id,$this->variant->id))->toBe(7)->and(WarehouseInboundReceipt::count())->toBe(0);
 expect(app(App\Services\SalesReturnQueryService::class)->appliedHealthCheck($doc->fresh('items'))['ok'])->toBeTrue();
 $retry=$this->service->createSimpleDraft($this->data,$this->user->id);expect($retry->id)->toBe($doc->id);
 expect(app(App\Services\Commissions\CommissionReconciliationService::class)->reconcileReturn($doc,$this->user->id))->toBe(0);
});
it('rechecks remaining quantities under the invoice lock before finalizing a second draft',function(){
 $a=$this->service->createSimpleDraft($this->data,$this->user->id);
 $this->data['submission_token']=(string)Str::uuid();$b=$this->service->createSimpleDraft($this->data,$this->user->id);
 $this->service->apply($a,$this->user->id);
 expect(fn()=>$this->service->apply($b,$this->user->id))->toThrow(ValidationException::class);
 expect($b->fresh()->status)->toBe('draft')->and(CustomerLedger::count())->toBe(1)->and(WarehouseStockService::available($this->returns->id,$this->product->id,$this->variant->id))->toBe(2);
});
it('rolls back stock and credit when finalization fails after entering stock',function(){
 $doc=$this->service->createSimpleDraft($this->data,$this->user->id);
 // A foreign-key failure on the financial actor must roll back the earlier stock write.
 expect(fn()=>$this->service->apply($doc,999999))->toThrow(\Illuminate\Database\QueryException::class);
 expect($doc->fresh()->status)->toBe('draft')->and(CustomerLedger::count())->toBe(0)
 ->and(WarehouseStockService::available($this->returns->id,$this->product->id,$this->variant->id))->toBe(0);
});
it('voids via an auditable reversal once and never changes the original invoice',function(){
 $doc=$this->service->apply($this->service->createSimpleDraft($this->data,$this->user->id),$this->user->id);
 $before=$this->invoice->fresh()->getAttributes(); $version=App\Support\InboundReviewVersion::token($doc);
 for($n=0;$n<2;$n++)app(SalesReturnCorrectionService::class)->voidDirect($doc,$this->user->id,'ثبت اشتباه آزمایشی',$version);
 expect($doc->fresh()->status)->toBe('cancelled')->and(CustomerLedger::where('type','debit')->sum('amount'))->toBe(1600)
 ->and(WarehouseStockService::available($this->returns->id,$this->product->id,$this->variant->id))->toBe(0)->and($this->invoice->fresh()->getAttributes())->toBe($before);
});
it('normalizes forged destination and commission values on both HTTP store routes',function(){
 $this->withoutMiddleware();$this->withoutVite();
 foreach(['vouchers.return-from-sale.store','sales-returns.store'] as $route){
  $this->data['submission_token']=(string)Str::uuid();
  $this->postJson(route($route),array_merge($this->data,['action'=>'draft']))->assertSessionHasNoErrors()->assertStatus($route === 'sales-returns.store' ? 302 : 200);
 }
 expect(SalesReturnDocument::count())->toBe(2);
 foreach(SalesReturnDocument::with('items')->get() as $doc)expect($doc->return_flow)->toBe('return_only')->and($doc->items->first()->destination_warehouse_id)->toBe($this->returns->id)->and($doc->commission_effect_type)->toBeNull();
});

it('renders a minimal new form while preserving historical edit controls', function () {
 $this->withoutMiddleware(); $this->withoutVite();
 view()->share('errors', new \Illuminate\Support\ViewErrorBag);
 $response=$this->get(route('vouchers.return-from-sale.create'));
 $response->assertOk()->assertSee('ثبت نهایی برگشت')
  ->assertDontSee('اثر پورسانت')->assertDontSee('نوع عملیات برگشت')->assertDontSee('توضیحات سند')->assertDontSee('مقصد پیش‌فرض سند');
});

it('keeps existing catalog prices independent from the external customer refund',function(){
 $this->data['source_type']='sazeh_hesab';unset($this->data['invoice_id']);
 $this->data['external_invoice_number']='OLD-123';$this->data['external_invoice_date']='2026-10-01';
 $this->data['items']=[['item_source'=>'existing_product','product_id'=>$this->product->id,'product_variant_id'=>$this->variant->id,'return_quantity'=>2,'refund_unit_price'=>700,'sell_price'=>1500]];
 $doc=$this->service->apply($this->service->createSimpleDraft($this->data,$this->user->id),$this->user->id);
 expect($doc->total_refund_amount)->toBe(1400)->and($this->variant->fresh()->sell_price)->toBe(9999)
  ->and($this->product->fresh()->price)->toBe(9999)->and($doc->commission_effect_type)->toBeNull();
});

it('rejects oversized or duplicated source invoice rows rather than silently truncating them',function(){
 $this->data['items'][0]['return_quantity']=4;
 expect(fn()=>$this->service->createSimpleDraft($this->data,$this->user->id))->toThrow(ValidationException::class);
 expect(SalesReturnDocument::count())->toBe(0);
 $this->data['items'][0]['return_quantity']=1;$this->data['items'][]=$this->data['items'][0];
 expect(fn()=>$this->service->createSimpleDraft($this->data,$this->user->id))->toThrow(ValidationException::class);
});
it('creates inline products only at finalization and places their stock only in returns',function(){
 $category=Category::withoutEvents(fn()=>Category::create(['name'=>'Inline QA','code'=>'98']));
 $this->data['source_type']='sazeh_hesab';unset($this->data['invoice_id']);
 $this->data['external_invoice_number']='OLD-NEW';$this->data['external_invoice_date']='2026-10-01';
 $this->data['items']=[['item_source'=>'new_product','return_quantity'=>2,'refund_unit_price'=>700,'new_product_payload'=>['product_name'=>'Inline return product','variant_name'=>'Inline QA','category_id'=>$category->id,'purchase_price'=>500,'sell_price'=>900]]];
 $doc=$this->service->createSimpleDraft($this->data,$this->user->id);
 expect($doc->items->first()->product_id)->toBeNull()->and(Product::count())->toBe(1);
 $doc=$this->service->apply($doc,$this->user->id);$item=$doc->items->first();
 expect(Product::count())->toBe(2)->and($item->created_variant_id)->not->toBeNull()
  ->and(WarehouseStockService::available($this->returns->id,$item->product_id,$item->product_variant_id))->toBe(2)
  ->and(WarehouseStockService::available($this->central,$item->product_id,$item->product_variant_id))->toBe(0);
});

it('rejects finalization if the source invoice status changed after drafting',function(){
 $doc=$this->service->createSimpleDraft($this->data,$this->user->id);
 $this->invoice->update(['status'=>Invoice::STATUS_PENDING_FINANCE_REAPPROVAL]);
 expect(fn()=>$this->service->apply($doc,$this->user->id))->toThrow(ValidationException::class);
 expect($doc->fresh()->status)->toBe('draft')->and(CustomerLedger::count())->toBe(0);
});


function controlledReturnFixture($test) {
 $data=$test->data;
 $data['items'][0]['return_quantity']=2;
 $data['items'][0]['destination_warehouse_id']=$test->central;
 $data['items'][]=array_replace($data['items'][0],['return_quantity'=>1,'destination_warehouse_id'=>$test->returns->id]);
 return $data;
}
function receiveControlledReturn($test, $doc, $quantity=2) {
 $receipt=WarehouseInboundReceipt::where('source_id',$doc->id)->firstOrFail()->load('items.suggestedWarehouse');
 $rows=$receipt->reviewItems()->map(fn($item)=>['id'=>$item->id,'accepted_quantity'=>$quantity,'received_warehouse_id'=>$item->suggested_warehouse_id])->all();
 return app(App\Services\WarehouseInboundService::class)->receive($receipt,$rows,$test->user->id,'بررسی دریافت آزمایشی');
}
it('holds mixed stock and credit until central approval then posts each destination exactly once',function(){
 $doc=$this->service->apply($this->service->createSimpleDraft(controlledReturnFixture($this),$this->user->id),$this->user->id);
 expect($doc->return_flow)->toBe('controlled_return')->and($doc->status)->toBe('pending_warehouse')->and(CustomerLedger::count())->toBe(0);
 $receipt=WarehouseInboundReceipt::first()->load('items.suggestedWarehouse');
 expect($receipt->items)->toHaveCount(2)->and($receipt->reviewItems())->toHaveCount(1)
 ->and(WarehouseStockService::available($this->returns->id,$this->product->id,$this->variant->id))->toBe(0);
 receiveControlledReturn($this,$doc);
 expect($doc->fresh()->status)->toBe('applied')->and(CustomerLedger::count())->toBe(1)->and(CustomerLedger::sum('amount'))->toBe(2400)
 ->and(WarehouseStockService::available($this->central,$this->product->id,$this->variant->id))->toBe(9)
 ->and(WarehouseStockService::available($this->returns->id,$this->product->id,$this->variant->id))->toBe(1);
 expect(fn()=>receiveControlledReturn($this,$doc))->toThrow(ValidationException::class);
 expect(CustomerLedger::count())->toBe(1)->and(app(App\Services\SalesReturnQueryService::class)->appliedHealthCheck($doc->fresh('items'))['ok'])->toBeTrue();
});
it('reduces only the partially received central row and refunds the exact accepted net amount',function(){
 $doc=$this->service->apply($this->service->createSimpleDraft(controlledReturnFixture($this),$this->user->id),$this->user->id);
 receiveControlledReturn($this,$doc,1);
 expect($doc->fresh()->total_quantity)->toBe(2)->and(CustomerLedger::sum('amount'))->toBe(1600)
 ->and(WarehouseStockService::available($this->central,$this->product->id,$this->variant->id))->toBe(8)
 ->and(WarehouseStockService::available($this->returns->id,$this->product->id,$this->variant->id))->toBe(1);
});
it('cancels the whole mixed document when central receipt is fully rejected',function(){
 $doc=$this->service->apply($this->service->createSimpleDraft(controlledReturnFixture($this),$this->user->id),$this->user->id);
 receiveControlledReturn($this,$doc,0);
 expect($doc->fresh()->status)->toBe('cancelled')->and(CustomerLedger::count())->toBe(0)
 ->and(WarehouseStockService::available($this->central,$this->product->id,$this->variant->id))->toBe(7)
 ->and(WarehouseStockService::available($this->returns->id,$this->product->id,$this->variant->id))->toBe(0);
});
it('rejects combined over-return quantities and damaged central items',function(){
 $data=controlledReturnFixture($this);$data['items'][1]['return_quantity']=2;
 expect(fn()=>$this->service->createSimpleDraft($data,$this->user->id))->toThrow(ValidationException::class);
 $data=controlledReturnFixture($this);$data['items'][0]['item_condition']='damaged';
 expect(fn()=>$this->service->createSimpleDraft($data,$this->user->id))->toThrow(ValidationException::class);
 expect(SalesReturnDocument::count())->toBe(0);
});
it('can disable future central returns without stranding an already queued receipt',function(){
 $data=controlledReturnFixture($this);
 $doc=$this->service->apply($this->service->createSimpleDraft($data,$this->user->id),$this->user->id);
 config(['sales_returns.allow_central_destination'=>false]);
 $data['submission_token']=(string)Str::uuid();
 expect(fn()=>$this->service->createSimpleDraft($data,$this->user->id))->toThrow(ValidationException::class);
 receiveControlledReturn($this,$doc);
 expect($doc->fresh()->status)->toBe('applied')->and(CustomerLedger::count())->toBe(1);
});
it('rejects over-receipt and client changes to held returns without any side effects',function(){
 $doc=$this->service->apply($this->service->createSimpleDraft(controlledReturnFixture($this),$this->user->id),$this->user->id);
 expect(fn()=>receiveControlledReturn($this,$doc,3))->toThrow(ValidationException::class);
 $receipt=WarehouseInboundReceipt::first()->load('items.suggestedWarehouse');
 $rows=$receipt->items->map(fn($item)=>['id'=>$item->id,'accepted_quantity'=>$item->expected_quantity,'received_warehouse_id'=>$item->suggested_warehouse_id])->all();
 expect(fn()=>app(App\Services\WarehouseInboundService::class)->receive($receipt,$rows,$this->user->id))->toThrow(ValidationException::class);
 expect(CustomerLedger::count())->toBe(0)->and($doc->fresh()->status)->toBe('pending_warehouse');
});

it('preserves rounding cents across destination partitions and keeps healthy returns healthy',function(){
 $this->invoice->update(['invoice_discount_amount'=>301,'discount_amount'=>601,'total'=>2399]);
 $data=controlledReturnFixture($this);foreach($data['items'] as &$row)$row['item_condition']='healthy';unset($row);
 $doc=$this->service->apply($this->service->createSimpleDraft($data,$this->user->id),$this->user->id);
 expect($doc->total_refund_amount)->toBe(2399);
 receiveControlledReturn($this,$doc);
 expect(CustomerLedger::sum('amount'))->toBe(2399)->and($doc->fresh('items')->items->pluck('item_condition')->unique()->all())->toBe(['healthy']);
});
it('rolls back mixed receipt stock credit and status together on financial failure',function(){
 $doc=$this->service->apply($this->service->createSimpleDraft(controlledReturnFixture($this),$this->user->id),$this->user->id);
 $receipt=WarehouseInboundReceipt::first()->load('items.suggestedWarehouse');
 $rows=$receipt->reviewItems()->map(fn($item)=>['id'=>$item->id,'accepted_quantity'=>2,'received_warehouse_id'=>$item->suggested_warehouse_id])->all();
 expect(fn()=>app(App\Services\WarehouseInboundService::class)->receive($receipt,$rows,999999))->toThrow(Illuminate\Database\QueryException::class);
 expect($doc->fresh()->status)->toBe('pending_warehouse')->and($receipt->fresh()->status)->toBe('pending')->and(CustomerLedger::count())->toBe(0)
 ->and(WarehouseStockService::available($this->central,$this->product->id,$this->variant->id))->toBe(7)
 ->and(WarehouseStockService::available($this->returns->id,$this->product->id,$this->variant->id))->toBe(0);
});

it('renders both per-row destinations on a controlled draft',function(){
 $doc=$this->service->createSimpleDraft(controlledReturnFixture($this),$this->user->id);
 $this->withoutMiddleware();$this->withoutVite();view()->share('errors',new \Illuminate\Support\ViewErrorBag);
 $html=app(App\Http\Controllers\VoucherSalesReturnController::class)->edit($doc)->render();
 expect($html)->toContain('تفکیک مقصد')->toContain('central');
 if (getenv('RETURN_FORM_PREVIEW')) file_put_contents(base_path('tmp/simple-return-form.html'),$html);
});
