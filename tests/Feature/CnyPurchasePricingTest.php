<?php

use App\Models\{Category,CnyExchangeRate,Product,ProductCnyPricing,ProductVariant,Purchase,Supplier,User};
use App\Services\{CnyPricingService,WarehouseStockService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);
beforeEach(function(){
    config(['cache.default'=>'array','cny_pricing.enabled'=>true,'cny_pricing.allow_sample'=>true,'cny_pricing.production_mode'=>false, 'navasan.enabled'=>false, 'nobitex.enabled'=>false]);
    $this->withoutVite();$this->actor=User::factory()->create();$this->actor->assignRole(Role::findOrCreate('Owner','web'));$this->actingAs($this->actor);
    $this->supplier=Supplier::create(['name'=>'تأمین‌کننده نمونه']);
    $category=Category::create(['name'=>'چین']);
    $this->product=Product::withoutEvents(fn()=>Product::create(['name'=>'کالای یوانی','sku'=>'CNY-1','code'=>'CNY-1','category_id'=>$category->id,'price'=>1000000,'uniform_sale_price'=>1000000,'is_sellable'=>true]));
    $this->variants=collect();foreach(['کروم','سفید'] as $i=>$name)$this->variants->push(ProductVariant::create(['product_id'=>$this->product->id,'variant_name'=>$name,'variant_code'=>'CNY-'.$i,'variety_code'=>'0000','sell_price'=>1000000,'buy_price'=>800000,'is_active'=>true]));
    $this->rate=CnyExchangeRate::create(['provider'=>'sample','rate_rial'=>400000,'quoted_at'=>now()]);
    $this->payload=['submission_token'=>(string)Str::uuid(),'supplier_id'=>(string)$this->supplier->id,'purchase_origin'=>'china','cny_rate_id'=>$this->rate->id,
        'sale_price_change_confirmed'=>1,'sale_price_change_reason'=>'تأیید مبنای یوانی و قیمت‌گذاری خودکار',
        'items'=>[['product_id'=>$this->product->id,'variant_id'=>$this->variants[0]->id,'quantity'=>2,'buy_cny'=>'۵','buy_price'=>1,'sell_price'=>1,'expected_current_sell_price'=>1000000]]];
    $this->payload['items'][0]['product_price_version']=app(\App\Services\UniformPurchasePriceService::class)->version($this->product);
});

it('uses server prices and stores fixed purchase amounts while enabling uniform yuan pricing',function(){
    $this->payload['uniform_product_prices']=1;
    $version=app(\App\Services\UniformPurchasePriceService::class)->version($this->product);
    foreach($this->payload['items'] as &$row)$row['product_price_version']=$version;unset($row);
    $this->post(route('purchases.store'),$this->payload)->assertSessionHasNoErrors()->assertRedirect(route('purchases.index'));
    $purchase=Purchase::latest('id')->first();$item=$purchase->items()->sole();
    expect($purchase->purchase_origin)->toBe('china')->and((int)$purchase->cny_rate_id)->toBe($this->rate->id)
        ->and((int)$purchase->total_amount)->toBe(4000000)->and((int)$item->buy_price)->toBe(2000000)
        ->and((int)$item->sell_price)->toBe(2920000)->and((int)$item->buy_cny_units)->toBe(50000)
        ->and($this->product->fresh()->uniform_buy_price)->toBe(2000000)
        ->and($this->product->fresh()->uniform_sale_price)->toBe(2920000)
        ->and($this->product->variants()->pluck('sell_price')->unique()->all())->toBe([2920000])
        ->and(ProductCnyPricing::where('product_id',$this->product->id)->sole()->enabled)->toBeTrue()
        ->and(WarehouseStockService::available(WarehouseStockService::centralWarehouseId(),$this->product->id,$this->variants[0]->id))->toBe(2);
    $this->get(route('purchases.show',$purchase))->assertOk()->assertSee('خرید از چین')->assertSee('5.0000');
    $this->get(route('purchases.edit',$purchase))->assertOk()->assertSee('purchaseCnyEnabled')->assertSee('5.0000');
});

it('compares each rate with the last applied rate and never rewrites purchase or supplier history',function(){
    $this->post(route('purchases.store'),$this->payload)->assertSessionHasNoErrors();
    $purchase=Purchase::latest('id')->first();$snapshot=DB::table('purchase_items')->where('purchase_id',$purchase->id)->first();
    $ledgers=DB::table('supplier_ledgers')->get()->toJson();
    CnyExchangeRate::create(['provider'=>'sample','rate_rial'=>409000,'quoted_at'=>now()]);
    expect(app(CnyPricingService::class)->reprice())->toBe(0)->and($this->product->fresh()->price)->toBe(2920000);
    $rate=CnyExchangeRate::create(['provider'=>'sample','rate_rial'=>410000,'quoted_at'=>now()]);
    expect(app(CnyPricingService::class)->reprice())->toBe(1)->and($this->product->fresh()->price)->toBe(2990000)
        ->and($this->product->fresh()->uniform_buy_price)->toBe(2000000)
        ->and(app(CnyPricingService::class)->reprice())->toBe(0)
        ->and(ProductCnyPricing::where('product_id',$this->product->id)->value('applied_rate_id'))->toBe($rate->id)
        ->and(DB::table('purchase_items')->where('purchase_id',$purchase->id)->first())->toEqual($snapshot)
        ->and(DB::table('supplier_ledgers')->get()->toJson())->toBe($ledgers);
    CnyExchangeRate::create(['provider'=>'sample','rate_rial'=>390000,'quoted_at'=>now()]);
    expect(app(CnyPricingService::class)->reprice())->toBe(1)->and($this->product->fresh()->price)->toBe(2850000);
});

it('rejects stale rates, conflicting product bases and missing approval without any purchase or stock write',function($case){
    if($case==='stale')$this->rate->update(['quoted_at'=>now()->subMinutes((int) config('cny_pricing.max_rate_age_minutes') + 2)]);
    if($case==='approval')$this->payload['sale_price_change_confirmed']=0;
    if($case==='conflict'){$row=$this->payload['items'][0];$row['variant_id']=$this->variants[1]->id;$row['buy_cny']='6';$this->payload['items'][]=$row;}
    if($case==='catalog')$this->payload['items'][0]['expected_current_sell_price']=123;
    $this->post(route('purchases.store'),$this->payload)->assertSessionHasErrors();
    expect(Purchase::count())->toBe(0)->and(ProductCnyPricing::count())->toBe(0)->and($this->product->fresh()->price)->toBe(1000000);
})->with(['stale','approval','conflict','catalog']);

it('does not enable sample rates outside the explicitly allowed sample mode',function(){
    config(['cny_pricing.allow_sample'=>false]);
    $this->post(route('purchases.store'),$this->payload)->assertSessionHasErrors();
    expect(Purchase::count())->toBe(0);
});

it('does not allow sample purchase pricing when live Navasan fetching is enabled', function () {
    config(['navasan.enabled' => true]);
    $this->post(route('purchases.store'), $this->payload)->assertSessionHasErrors();
    expect(Purchase::count())->toBe(0)->and(ProductCnyPricing::count())->toBe(0);
});

it('does not allow sample purchase pricing when the live Nobitex source is enabled', function () {
    config(['nobitex.enabled' => true]);
    $this->post(route('purchases.store'), $this->payload)->assertSessionHasErrors();
    expect(Purchase::count())->toBe(0)->and(ProductCnyPricing::count())->toBe(0);
});

it('requires approval to switch an active yuan product back to fixed prices even when the selling price is unchanged',function(){
    $this->post(route('purchases.store'),$this->payload)->assertSessionHasNoErrors();
    $fixed=$this->payload;$fixed['purchase_origin']='domestic';$fixed['submission_token']=(string)Str::uuid();$fixed['sale_price_change_confirmed']=0;
    $fixed['items'][0]['buy_price']=2000000;$fixed['items'][0]['sell_price']=2920000;$fixed['items'][0]['expected_current_sell_price']=2920000;
    $this->post(route('purchases.store'),$fixed)->assertSessionHasErrors('sale_price_change_confirmed');
    expect(ProductCnyPricing::where('product_id',$this->product->id)->sole()->enabled)->toBeTrue();
    $fixed['sale_price_change_confirmed']=1;$fixed['submission_token']=(string)Str::uuid();
    $this->post(route('purchases.store'),$fixed)->assertSessionHasNoErrors();
    CnyExchangeRate::create(['provider'=>'sample','rate_rial'=>410000,'quoted_at'=>now()]);
    expect(app(CnyPricingService::class)->reprice())->toBe(0)->and($this->product->fresh()->price)->toBe(2920000);
});

it('requires the existing price-change permission even when purchase creation is allowed',function(){
    $user=User::factory()->create();
    $user->permissions()->attach(DB::table('permissions')->where('key','page.warehouse.purchases')->value('id'));
    $this->actingAs($user)->get(route('purchases.create'))->assertOk();
    $this->post(route('purchases.store'),$this->payload)->assertForbidden();
    expect(Purchase::count())->toBe(0)->and(ProductCnyPricing::count())->toBe(0);
});

it('edits historical quantities using the stored yuan rate and keeps automatic pricing unchanged',function(){
    $this->post(route('purchases.store'),$this->payload)->assertSessionHasNoErrors();
    $purchase=Purchase::latest('id')->first();$item=$purchase->items()->sole();
    CnyExchangeRate::create(['provider'=>'sample','rate_rial'=>410000,'quoted_at'=>now()]);app(CnyPricingService::class)->reprice();
    $data=$this->payload;$data['submission_token']=(string)Str::uuid();$data['items'][0]['id']=$item->id;$data['items'][0]['quantity']=3;
    $this->put(route('purchases.update',$purchase),$data)->assertSessionHasNoErrors();
    expect($this->product->fresh()->price)->toBe(2990000)->and((int)$item->fresh()->buy_price)->toBe(2000000)
        ->and((int)$item->fresh()->sell_price)->toBe(2920000)->and((int)$purchase->fresh()->total_amount)->toBe(6000000)
        ->and(ProductCnyPricing::where('product_id',$this->product->id)->sole()->enabled)->toBeTrue();
});

it('pauses automatic pricing before a manual price document can be overwritten',function(){
    $this->post(route('purchases.store'),$this->payload)->assertSessionHasNoErrors();
    $service=app(\App\Services\PriceChangeService::class);
    $payload=['category_id'=>$this->product->category_id,'uniform_pricing'=>true,'product_id'=>$this->product->id,'change_type'=>\App\Models\PriceChangeDocument::CHANGE_SET_FIXED_PRICE,'change_value'=>3100000,'rounding_mode'=>'none'];
    $document=$service->storeDraft($payload,$service->buildPreview($payload));
    $service->applyDocument($document,$this->actor);
    CnyExchangeRate::create(['provider'=>'sample','rate_rial'=>410000,'quoted_at'=>now()]);
    expect(app(CnyPricingService::class)->reprice())->toBe(0)->and($this->product->fresh()->price)->toBe(3100000)
        ->and(ProductCnyPricing::where('product_id',$this->product->id)->sole()->enabled)->toBeFalse();
});

it('preserves the last valid price when the latest quote is unsafe',function($kind){
    $this->post(route('purchases.store'),$this->payload)->assertSessionHasNoErrors();
    if($kind==='stale')$this->rate->update(['quoted_at'=>now()->subMinutes((int) config('cny_pricing.max_rate_age_minutes') + 2)]);
    else CnyExchangeRate::create(['provider'=>'sample','rate_rial'=>$kind==='zero'?0:410000,'quoted_at'=>$kind==='future'?now()->addDay():now()]);
    expect(fn()=>app(CnyPricingService::class)->reprice())->toThrow(\Illuminate\Validation\ValidationException::class);
    expect($this->product->fresh()->price)->toBe(2920000)->and(DB::table('cny_price_revisions')->count())->toBe(1);
})->with(['stale','zero','future']);

it('refuses stale catalog versions even when uniform pricing was disabled by a tampered request',function(){
    $this->payload['uniform_product_prices']=0;
    $this->payload['items'][0]['product_price_version']=str_repeat('0',64);
    $this->post(route('purchases.store'),$this->payload)->assertSessionHasErrors();
    expect(Purchase::count())->toBe(0)->and(ProductCnyPricing::count())->toBe(0)
        ->and($this->product->fresh()->price)->toBe(1000000);
});

it('never accepts sample rates in explicit production mode even if APP_ENV is local',function(){
    config(['cny_pricing.production_mode'=>true]);
    $this->post(route('purchases.store'),$this->payload)->assertSessionHasErrors();
    expect(Purchase::count())->toBe(0);
});

it('upgrades legacy v1 automatic pricing policy only when a new price revision is applied', function () {
    $this->post(route('purchases.store'), $this->payload)->assertSessionHasNoErrors();
    $policy=ProductCnyPricing::where('product_id', $this->product->id)->sole();
    $policy->update(['policy' => 'margin30-buffer2-round1000-v1']);
    $purchase=Purchase::latest('id')->first();
    $historicalSale=$purchase->items()->sole()->sell_price;
    CnyExchangeRate::create(['provider' => 'sample', 'rate_rial' => 410000, 'quoted_at' => now()]);
    expect(app(CnyPricingService::class)->reprice())->toBe(1)
        ->and($policy->fresh()->policy)->toBe(\App\Services\CnyPriceCalculator::POLICY)
        ->and($purchase->items()->sole()->sell_price)->toBe($historicalSale);
});
