<?php
use App\Models\{Category,Product,ProductVariant,Purchase,PurchaseDraft,StockMovement,Supplier,SupplierLedger,User};
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
uses(RefreshDatabase::class);
beforeEach(function(){
    config(['cache.default'=>'array']);$this->withoutVite();
    $this->actor=User::factory()->create();$this->actor->assignRole(Role::findOrCreate('Owner','web'));$this->actingAs($this->actor);
    $this->token=(string)Str::uuid();$this->draft=['version'=>1,'fields'=>['note'=>'خرید ناتمام فارسی','supplier_id'=>'','invoice_discount_value'=>'۵٬۰۰۰'],'cards'=>[['product_id'=>12,'buy'=>'','sell'=>'','buy_cny'=>'۲٫۵','items'=>[['variant_id'=>14,'quantity'=>'۳']]]]];
    $this->save=function($revision=0,$token=null,$payload=null){return $this->postJson(route('purchases.drafts.save'),['token'=>$token??$this->token,'revision'=>$revision,'draft_json'=>json_encode($payload??$this->draft,JSON_UNESCAPED_UNICODE)]);};
});
it('saves and restores incomplete purchases without touching business data',function(){
    ($this->save)()->assertOk()->assertJsonPath('draft.revision',1);
    $this->getJson(route('purchases.drafts.latest'))->assertOk()->assertJsonPath('draft.payload.fields.note','خرید ناتمام فارسی')->assertJsonPath('draft.payload.cards.0.buy_cny','۲٫۵');
    expect(Purchase::count())->toBe(0)->and(StockMovement::count())->toBe(0)->and(SupplierLedger::count())->toBe(0)->and(Product::count())->toBe(0);
});
it('prevents stale tabs from overwriting or discarding newer work',function(){
    ($this->save)()->assertOk();$new=$this->draft;$new['fields']['note']='نسخه جدید';
    ($this->save)(1,null,$new)->assertOk()->assertJsonPath('draft.revision',2);
    ($this->save)(1)->assertStatus(409)->assertJsonPath('draft.payload.fields.note','نسخه جدید');
    $this->deleteJson(route('purchases.drafts.discard',$this->token),['revision'=>1])->assertStatus(409);
    expect(PurchaseDraft::first()->payload['fields']['note'])->toBe('نسخه جدید');
});
it('keeps drafts private to their owner for reads writes and discard',function(){
    ($this->save)()->assertOk();$other=User::factory()->create();$other->assignRole('Owner');$this->actingAs($other);
    $this->getJson(route('purchases.drafts.latest'))->assertOk()->assertJsonPath('draft',null);
    $this->getJson(route('purchases.drafts.show',$this->token))->assertNotFound();
    ($this->save)(1)->assertNotFound();
    $this->deleteJson(route('purchases.drafts.discard',$this->token),['revision'=>1])->assertNotFound();
});
it('enforces purchase creation permission on every draft endpoint',function(){
    ($this->save)()->assertOk();$other=User::factory()->create();$this->actingAs($other);
    $this->getJson(route('purchases.drafts.latest'))->assertForbidden();
    $this->getJson(route('purchases.drafts.show',$this->token))->assertForbidden();
    ($this->save)()->assertForbidden();
    $this->deleteJson(route('purchases.drafts.discard',$this->token),['revision'=>1])->assertForbidden();
});
it('discards only drafts and refuses late saves from resurrecting them',function(){
    ($this->save)()->assertOk();
    $this->deleteJson(route('purchases.drafts.discard',$this->token),['revision'=>1])->assertOk()->assertJsonPath('draft.status','discarded');
    ($this->save)(2)->assertStatus(409);
    $this->getJson(route('purchases.drafts.latest'))->assertJsonPath('draft',null);
    expect(PurchaseDraft::first()->payload)->toBeNull()->and(Purchase::count())->toBe(0);
});
it('rejects corrupt and oversized snapshots without storing them',function(){
    foreach(['{broken',json_encode(['version'=>5,'cards'=>[]]),str_repeat('x',2097153)] as $json)$this->postJson(route('purchases.drafts.save'),['token'=>$this->token,'revision'=>0,'draft_json'=>$json])->assertUnprocessable();
    expect(PurchaseDraft::count())->toBe(0);
});
it('keeps every row of a large draft without PHP input truncation',function(){
    $draft=$this->draft;$draft['cards'][0]['items']=array_map(fn($n)=>['variant_id'=>$n,'quantity'=>(string)$n,'buy_price'=>1070000],range(1,500));
    ($this->save)(0,null,$draft)->assertOk();
    expect(PurchaseDraft::first()->payload['cards'][0]['items'])->toHaveCount(500);
});
function draftFinalPayload($t){
    $supplier=Supplier::create(['name'=>'تأمین‌کننده آزمایش پیش‌نویس']);$category=Category::create(['name'=>'گروه تست']);
    $product=Product::withoutEvents(fn()=>Product::create(['name'=>'کالای تست','sku'=>'DRAFT-ITEM','code'=>'DRAFT-ITEM','category_id'=>$category->id,'price'=>1200000,'uniform_sale_price'=>1200000,'is_sellable'=>true]));
    $variant=ProductVariant::create(['product_id'=>$product->id,'variant_name'=>'سفید','variant_code'=>'DRAFT-V','variety_code'=>'0000','buy_price'=>800000,'sell_price'=>1200000,'is_active'=>true]);
    return ['submission_token'=>$t->token,'supplier_id'=>(string)$supplier->id,'items'=>[['product_id'=>$product->id,'variant_id'=>$variant->id,'quantity'=>3,'buy_price'=>800000,'sell_price'=>1200000,'expected_current_sell_price'=>1200000]]];
}
it('records finalization atomically and never duplicates a purchase even after cache expiry',function(){
    ($this->save)()->assertOk();$payload=draftFinalPayload($this);
    $this->post(route('purchases.store'),$payload)->assertSessionHasNoErrors();
    $draft=PurchaseDraft::first();expect($draft->status)->toBe('submitted')->and($draft->payload)->toBeNull()->and($draft->purchase_id)->toBe(Purchase::first()->id);
    $counts=[Purchase::count(),StockMovement::count(),SupplierLedger::count()];Cache::flush();$this->travel(2)->days();
    $this->post(route('purchases.store'),['submission_token'=>$this->token])->assertRedirect(route('purchases.index'))->assertSessionHasNoErrors();
    expect([Purchase::count(),StockMovement::count(),SupplierLedger::count()])->toBe($counts);
    ($this->save)($draft->revision)->assertStatus(409)->assertJsonPath('draft.status','submitted');
    $this->getJson(route('purchases.drafts.show',$this->token))->assertJsonPath('draft.purchase_id',$draft->purchase_id);
});
it('rolls back draft finalization along with a failed purchase and allows a corrected retry',function(){
    ($this->save)()->assertOk();$payload=draftFinalPayload($this);
    $bad=$payload;$bad['items'][0]['sell_price']=1400000;
    $this->post(route('purchases.store'),$bad)->assertSessionHasErrors();
    expect(Purchase::count())->toBe(0)->and(PurchaseDraft::first()->status)->toBe('active')->and(PurchaseDraft::first()->revision)->toBe(1);
    $this->post(route('purchases.store'),$payload)->assertSessionHasNoErrors();
    expect(Purchase::count())->toBe(1)->and(PurchaseDraft::first()->status)->toBe('submitted');
});
