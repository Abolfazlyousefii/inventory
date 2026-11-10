<?php

use App\Models\{Category,Product,ProductVariant,Purchase,Supplier,User};
use App\Services\{UniformPurchasePriceService,WarehouseStockService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);
beforeEach(function(){
    config(['cache.default'=>'array']);$this->withoutVite();
    $this->actor=User::factory()->create();$this->actor->assignRole(Role::findOrCreate('Owner','web'));$this->actingAs($this->actor);
    $this->supplier=Supplier::create(['name'=>'تأمین‌کننده خرید یکسان']);$category=Category::create(['name'=>'قیمت کالا']);
    $this->product=Product::withoutEvents(fn()=>Product::create(['name'=>'کالای چندرنگ','sku'=>'UNIFORM-PUR','code'=>'UNIFORM-PUR','category_id'=>$category->id,'price'=>1000000,'uniform_sale_price'=>1000000,'is_sellable'=>true]));
    $this->variants=collect();foreach(['کروم','سفید','مشکی'] as $i=>$name)$this->variants->push(ProductVariant::create(['product_id'=>$this->product->id,'variant_name'=>$name,'variant_code'=>'UP-'.$i,'variety_code'=>'0000','sell_price'=>1000000,'buy_price'=>700000,'is_active'=>true]));
    $this->payload=['submission_token'=>(string)Str::uuid(),'supplier_id'=>(string)$this->supplier->id,
        'sale_price_change_confirmed'=>1,'sale_price_change_reason'=>'قیمت جدید برای کل کالا',
        'items'=>$this->variants->take(2)->map(fn($v,$i)=>['product_id'=>$this->product->id,'variant_id'=>$v->id,'quantity'=>$i+2,'buy_price'=>800000,'sell_price'=>1200000,'expected_current_sell_price'=>1000000])->all()];
});

it('stores a single product buying and selling price while moving only each selected variant quantity',function(){
    $this->post(route('purchases.store'),$this->payload)->assertSessionHasNoErrors()->assertRedirect(route('purchases.index'));
    $product=$this->product->fresh();$purchase=Purchase::latest('id')->first();
    expect((int)$product->uniform_buy_price)->toBe(800000)->and((int)$product->uniform_sale_price)->toBe(1200000)
        ->and($product->variants()->pluck('buy_price')->unique()->all())->toBe([800000])
        ->and($product->variants()->pluck('sell_price')->unique()->all())->toBe([1200000])
        ->and((int)$purchase->total_amount)->toBe(4000000)->and($purchase->items()->count())->toBe(2);
    foreach([2,3,0] as $i=>$qty)expect(WarehouseStockService::available(WarehouseStockService::centralWarehouseId(),$product->id,$this->variants[$i]->id))->toBe($qty);
    $report=app(\App\Services\Inventory\InventoryReconciliationService::class)->rows($product->id)->firstWhere('variant_id',$this->variants[2]->id);
    expect($report['expected_buy_price'])->toBe(800000)->and($report['expected_sell_price'])->toBe(1200000);
});

it('rejects contradictory prices for one product before writing a purchase',function($field){
    $this->payload['items'][1][$field]++;
    $this->post(route('purchases.store'),$this->payload)->assertSessionHasErrors('items');
    expect(Purchase::count())->toBe(0)->and($this->product->fresh()->price)->toBe(1000000);
})->with(['buy_price','sell_price']);

it('does not replace the submitted product price with the old uniform selling price',function(){
    $this->payload['sale_price_change_confirmed']=0;
    $this->post(route('purchases.store'),$this->payload)->assertSessionHasErrors('sale_price_change_confirmed');
    expect(Purchase::count())->toBe(0)->and($this->product->fresh()->price)->toBe(1000000);
});

it('returns canonical product prices and rejects a changed unselected sibling before any write',function(){
    $response=$this->getJson(route('purchases.products.variants',$this->product))->assertOk()->assertJsonPath('product_prices.buy_price',700000)->assertJsonPath('product_prices.sell_price',1000000);
    $this->payload['uniform_product_prices']=1;
    foreach($this->payload['items'] as &$row)$row['product_price_version']=$response->json('product_prices.version');unset($row);
    $this->variants[2]->update(['buy_price'=>600000]);
    $this->post(route('purchases.store'),$this->payload)->assertSessionHasErrors('items');
    expect(Purchase::count())->toBe(0)->and($this->product->fresh()->price)->toBe(1000000);
});

it('accepts the exact product-priced form payload and makes new variants inherit both prices',function(){
    $this->payload['uniform_product_prices']=1;$version=app(UniformPurchasePriceService::class)->version($this->product);
    foreach($this->payload['items'] as &$row)$row['product_price_version']=$version;unset($row);
    $this->post(route('purchases.store'),$this->payload)->assertSessionHasNoErrors();
    $variant=ProductVariant::create(['product_id'=>$this->product->id,'variant_name'=>'طلایی','variant_code'=>'UP-GOLD','variety_code'=>'0000','buy_price'=>1,'sell_price'=>1,'is_active'=>true]);
    expect((int)$variant->buy_price)->toBe(800000)->and((int)$variant->sell_price)->toBe(1200000);
});

it('preserves quantities-only edits and never rewrites current prices from an older purchase',function(){
    $this->post(route('purchases.store'),$this->payload)->assertSessionHasNoErrors();$first=Purchase::latest('id')->first();
    $oldItems=$first->items()->get();
    $new=$this->payload;$new['submission_token']=(string)Str::uuid();
    foreach($new['items'] as &$row){$row['buy_price']=900000;$row['sell_price']=1300000;$row['expected_current_sell_price']=1200000;}unset($row);
    $this->post(route('purchases.store'),$new)->assertSessionHasNoErrors();
    $edit=$this->payload;$edit['uniform_product_prices']=1;$edit['submission_token']=(string)Str::uuid();
    foreach($edit['items'] as $i=>&$row){$row['id']=$oldItems[$i]->id;$row['quantity']++;$row['product_price_version']='old version does not matter for quantity-only editing';}unset($row);
    // Version is syntactically valid but deliberately stale; no price mutation should use it.
    foreach($edit['items'] as &$row)$row['product_price_version']=str_repeat('0',64);unset($row);
    $this->put(route('purchases.update',$first),$edit)->assertSessionHasNoErrors();
    expect($this->product->fresh()->uniform_buy_price)->toBe(900000)->and($this->product->fresh()->price)->toBe(1300000)
        ->and((int)$first->items()->first()->buy_price)->toBe(800000);
    $edit['submission_token']=(string)Str::uuid();$version=app(UniformPurchasePriceService::class)->version($this->product->fresh());
    foreach($edit['items'] as &$row){$row['buy_price']=1000000;$row['expected_current_sell_price']=1300000;$row['product_price_version']=$version;}unset($row);
    $this->put(route('purchases.update',$first),$edit)->assertSessionHasNoErrors();
    expect($this->product->fresh()->uniform_buy_price)->toBe(900000)
        ->and((int)$first->items()->first()->buy_price)->toBe(1000000);
    foreach($edit['items'] as &$row)$row['sell_price']=1400000;unset($row);
    $edit['submission_token']=(string)Str::uuid();
    $this->put(route('purchases.update',$first),$edit)->assertSessionHasErrors('items');
    expect($this->product->fresh()->price)->toBe(1300000);
});

it('corrects a zero historical purchase cost without touching newer catalog prices or stock',function(){
    $first=Purchase::create(['supplier_id'=>$this->supplier->id,'user_id'=>$this->actor->id,'purchased_at'=>now()->subMonth(),'subtotal_amount'=>0,'total_amount'=>0]);
    $items=collect();foreach($this->variants->take(2) as $variant){
        $items->push($first->items()->create(['product_id'=>$this->product->id,'product_variant_id'=>$variant->id,'product_name'=>$this->product->name,'product_code'=>$this->product->code,'variant_name'=>$variant->variant_name,'quantity'=>1,'buy_price'=>0,'sell_price'=>1000000,'line_subtotal'=>0,'line_total'=>0]));
        WarehouseStockService::set(WarehouseStockService::centralWarehouseId(),$this->product->id,$variant->id,1);
    }
    $new=$this->payload;
    foreach($new['items'] as &$row){$row['buy_price']=900000;$row['sell_price']=1300000;}unset($row);
    $this->post(route('purchases.store'),$new)->assertSessionHasNoErrors();
    $currentPrices=$this->product->variants()->orderBy('id')->get(['id','buy_price','sell_price'])->toArray();
    $movementCount=\App\Models\StockMovement::count();$registered=$first->purchased_at->toDateTimeString();
    $edit=$this->payload;$edit['submission_token']=(string)Str::uuid();$edit['uniform_product_prices']=1;$edit['sale_price_change_confirmed']=0;unset($edit['sale_price_change_reason']);
    foreach($edit['items'] as $i=>&$row){$row['id']=$items[$i]->id;$row['quantity']=1;$row['buy_price']=1070000;$row['sell_price']=1000000;$row['product_price_version']=str_repeat('0',64);}unset($row);
    $this->put(route('purchases.update',$first),$edit)->assertSessionHasNoErrors();
    expect((int)$first->fresh()->total_amount)->toBe(2140000)
        ->and($first->fresh()->purchased_at->toDateTimeString())->toBe($registered)
        ->and($first->items()->pluck('buy_price')->map(fn($v)=>(int)$v)->all())->toBe([1070000,1070000])
        ->and($this->product->variants()->orderBy('id')->get(['id','buy_price','sell_price'])->toArray())->toBe($currentPrices)
        ->and((int)$this->product->fresh()->uniform_buy_price)->toBe(900000)
        ->and((int)$this->product->fresh()->uniform_sale_price)->toBe(1300000)
        ->and(\App\Models\StockMovement::count())->toBe($movementCount);
    $ledger=\App\Models\SupplierLedger::where('reference_type',Purchase::class)->where('reference_id',$first->id)->get();
    expect($ledger)->toHaveCount(1)->and((int)$ledger->first()->amount)->toBe(2140000);
    expect(\App\Models\ActivityLog::where('action','purchase_historical_cost_corrected')->where('subject_id',$first->id)->count())->toBe(1);
    $edit['submission_token']=(string)Str::uuid();
    $this->put(route('purchases.update',$first),$edit)->assertSessionHasNoErrors();
    $ledger=\App\Models\SupplierLedger::where('reference_type',Purchase::class)->where('reference_id',$first->id)->get();
    expect($ledger)->toHaveCount(1)->and((int)$ledger->first()->amount)->toBe(2140000)
        ->and(\App\Models\StockMovement::count())->toBe($movementCount)
        ->and($this->product->variants()->orderBy('id')->get(['id','buy_price','sell_price'])->toArray())->toBe($currentPrices);
});

it('preserves mixed historical line prices when only quantities are edited in the product-priced form',function(){
    $purchase=Purchase::create(['supplier_id'=>$this->supplier->id,'user_id'=>$this->actor->id,'purchased_at'=>now()->subMonth(),'subtotal_amount'=>1300000,'total_amount'=>1300000]);
    $edit=$this->payload;$edit['uniform_product_prices']=1;$edit['items']=[];
    foreach($this->variants->take(2) as $i=>$variant){
        $buy=600000+$i*100000;$sell=900000+$i*100000;
        $item=$purchase->items()->create(['product_id'=>$this->product->id,'product_variant_id'=>$variant->id,'product_name'=>$this->product->name,'product_code'=>$this->product->code,'variant_name'=>$variant->variant_name,'quantity'=>1,'buy_price'=>$buy,'sell_price'=>$sell,'line_subtotal'=>$buy,'line_total'=>$buy]);
        WarehouseStockService::set(WarehouseStockService::centralWarehouseId(),$this->product->id,$variant->id,1);
        $edit['items'][]=['id'=>$item->id,'product_id'=>$this->product->id,'variant_id'=>$variant->id,'quantity'=>$i+2,'buy_price'=>$buy,'sell_price'=>$sell,'product_price_version'=>str_repeat('0',64),'expected_current_sell_price'=>1000000];
    }
    $this->put(route('purchases.update',$purchase),$edit)->assertSessionHasNoErrors();
    expect($purchase->items()->orderBy('id')->pluck('buy_price')->map(fn($v)=>(int)$v)->all())->toBe([600000,700000])
        ->and($purchase->items()->orderBy('id')->pluck('sell_price')->map(fn($v)=>(int)$v)->all())->toBe([900000,1000000])
        ->and((int)$purchase->fresh()->total_amount)->toBe(3300000)->and($this->product->fresh()->price)->toBe(1000000)
        ->and($this->product->fresh()->uniform_buy_price)->toBeNull();
    $this->get(route('purchases.edit',$purchase))->assertOk()->assertSee('original_buy_price')->assertSee('data-legacy-price-note',false);
});

it('updates only the historical invoice discount and its supplier credit without repricing goods',function($discountType,$discountValue,$expectedTotal){
    $this->post(route('purchases.store'),$this->payload)->assertSessionHasNoErrors();
    $purchase=Purchase::latest('id')->first();
    $registeredAt=$purchase->purchased_at->toDateTimeString();
    $items=$purchase->items()->orderBy('id')->get();
    $snapshots=$items->map(fn($item)=>$item->only(['buy_price','sell_price','quantity','line_total']))->all();
    $new=$this->payload;$new['submission_token']=(string)Str::uuid();
    foreach($new['items'] as &$row){$row['buy_price']=900000;$row['sell_price']=1300000;$row['expected_current_sell_price']=1200000;}unset($row);
    $this->post(route('purchases.store'),$new)->assertSessionHasNoErrors();
    $edit=$this->payload;$edit['uniform_product_prices']=1;$edit['submission_token']=(string)Str::uuid();
    $edit['invoice_discount_type']=$discountType;$edit['invoice_discount_value']=$discountValue;
    foreach($edit['items'] as $i=>&$row){$row['id']=$items[$i]->id;$row['product_price_version']=str_repeat('0',64);}unset($row);
    $this->put(route('purchases.update',$purchase),$edit)->assertSessionHasNoErrors();
    expect($purchase->items()->orderBy('id')->get()->map(fn($item)=>$item->only(['buy_price','sell_price','quantity','line_total']))->all())->toBe($snapshots)
        ->and((int)$purchase->fresh()->total_amount)->toBe($expectedTotal)
        ->and($purchase->fresh()->purchased_at->toDateTimeString())->toBe($registeredAt)
        ->and((int)$this->product->fresh()->uniform_buy_price)->toBe(900000)
        ->and((int)$this->product->fresh()->uniform_sale_price)->toBe(1300000);
    $ledger=\App\Models\SupplierLedger::where('reference_type',Purchase::class)->where('reference_id',$purchase->id)->where('type','credit')->get();
    expect($ledger)->toHaveCount(1)->and((int)$ledger->first()->amount)->toBe($expectedTotal);
    // Saving the same edit again must update the same credit, never duplicate it.
    $edit['submission_token']=(string)Str::uuid();
    $this->put(route('purchases.update',$purchase),$edit)->assertSessionHasNoErrors();
    expect(\App\Models\SupplierLedger::where('reference_type',Purchase::class)->where('reference_id',$purchase->id)->count())->toBe(1);
})->with([['amount',100000,3900000],['percent',10,3600000]]);

it('retains historical line discounts when the compact edit form omits them',function(){
    $this->payload['items'][0]['discount_type']='percent';$this->payload['items'][0]['discount_value']=10;
    $this->post(route('purchases.store'),$this->payload)->assertSessionHasNoErrors();
    $purchase=Purchase::latest('id')->first();$items=$purchase->items()->orderBy('id')->get();
    $edit=$this->payload;$edit['uniform_product_prices']=1;$edit['submission_token']=(string)Str::uuid();
    foreach($edit['items'] as $i=>&$row){$row['id']=$items[$i]->id;unset($row['discount_type'],$row['discount_value']);}unset($row);
    $this->put(route('purchases.update',$purchase),$edit)->assertSessionHasNoErrors();
    expect((int)$purchase->fresh()->total_amount)->toBe(3840000)
        ->and($items[0]->fresh()->discount_type)->toBe('percent')->and((int)$items[0]->fresh()->discount_value)->toBe(10);
});
