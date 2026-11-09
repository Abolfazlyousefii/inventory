<?php

use App\Models\{Category, Product, ProductVariant, User, Warehouse, WarehouseStock, WarehouseTransfer};
use App\Support\WarehouseVoucherAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('browses distinct products then all subvariants of only the chosen product',function(){
    $other=Product::withoutEvents(fn()=>Product::create(['name'=>'کالای دوم','sku'=>'SECOND','category_id'=>$this->category->id,'price'=>1000,'is_sellable'=>true]));
    $variant=ProductVariant::create(['product_id'=>$other->id,'variant_name'=>'مدل دوم','variety_name'=>'زیرتنوع بزرگ','variety_code'=>'0002','variant_code'=>'SECOND-2','sell_price'=>1000,'is_active'=>true]);
    WarehouseStock::create(['warehouse_id'=>$this->central->id,'product_id'=>$other->id,'product_variant_id'=>$variant->id,'quantity'=>4]);
    $this->getJson(route('vouchers.showroom.catalog',['view'=>'products','category_id'=>$this->category->id]))->assertOk()->assertJsonCount(2,'data')->assertJsonPath('data.0.name',$this->product->name);
    $this->getJson(route('vouchers.showroom.catalog',['product_id'=>$other->id]))->assertOk()->assertJsonCount(1,'data')
        ->assertJsonPath('data.0.id',$variant->id)->assertJsonPath('data.0.subvariant_title','زیرتنوع بزرگ');
    $this->getJson(route('vouchers.showroom.catalog',['product_id'=>$this->product->id]))->assertOk()->assertJsonCount(3,'data');
});

beforeEach(function () {
    $this->withoutVite();
    $this->actor=User::factory()->create();$this->actor->assignRole(Role::findOrCreate('Owner','web'));$this->actingAs($this->actor);
    $this->central=Warehouse::where('type','central')->firstOrFail();
    $this->showroom=Warehouse::firstOrCreate(['type'=>'showroom'],['name'=>'انبار شوروم','is_active'=>true]);
    $this->category=Category::create(['name'=>'شیرآلات']);
    $this->product=Product::withoutEvents(fn()=>Product::create(['name'=>'شیر روشویی نمونه','sku'=>'SHOW-100','code'=>'SHOW-100','category_id'=>$this->category->id,'stock'=>999,'price'=>1000,'is_sellable'=>true]));
    $this->variants=collect();
    foreach(['کروم','سفید','مشکی'] as $i=>$name){
        $v=ProductVariant::create(['product_id'=>$this->product->id,'variant_name'=>$name,'variant_code'=>'COLOR-'.($i+1),'stock'=>999,'sell_price'=>1000,'is_active'=>true,'sales_enabled'=>true]);
        WarehouseStock::create(['warehouse_id'=>$this->central->id,'product_id'=>$this->product->id,'product_variant_id'=>$v->id,'quantity'=>10]);
        $this->variants->push($v);
    }
});

function showroomBulkPayload($test):array {
    return ['from_warehouse_id'=>$test->central->id,'to_warehouse_id'=>$test->showroom->id,'request_token'=>(string)Str::uuid(),
        'transferred_at_jalali'=>'1405/08/18','items'=>$test->variants->take(2)->map(fn($v)=>['category_id'=>$test->category->id,'product_id'=>$test->product->id,'variant_id'=>$v->id,'quantity'=>'۲'])->all()];
}

it('transfers only the chosen subvariant without consuming its sibling',function(){
    $this->variants[0]->update(['variant_name'=>'مدل مشترک','variety_name'=>'کوچک','variety_code'=>'0001']);
    $this->variants[1]->update(['variant_name'=>'مدل مشترک','variety_name'=>'بزرگ','variety_code'=>'0002']);
    $response=$this->getJson(route('vouchers.showroom.catalog',['product_id'=>$this->product->id]))->assertOk();
    expect($response->json('data.0.group_key'))->toBe($response->json('data.1.group_key'));
    $payload=showroomBulkPayload($this);$payload['items']=[$payload['items'][1]];
    $this->post(route('vouchers.section.store','showroom'),$payload)->assertSessionHasNoErrors();
    expect(WarehouseStock::where('warehouse_id',$this->central->id)->where('product_variant_id',$this->variants[0]->id)->value('quantity'))->toBe(10)
        ->and(WarehouseStock::where('warehouse_id',$this->central->id)->where('product_variant_id',$this->variants[1]->id)->value('quantity'))->toBe(8);
});

it('searches globally across products and variants with canonical central and showroom quantities',function(){
    $v=$this->variants->first();
    WarehouseStock::create(['warehouse_id'=>$this->showroom->id,'product_id'=>$this->product->id,'product_variant_id'=>$v->id,'quantity'=>3]);
    $before=WarehouseStock::count();
    $this->getJson(route('vouchers.showroom.catalog',['q'=>'شیر روشویی']))->assertOk()->assertJsonCount(3,'data')
        ->assertJsonPath('data.0.available',10)->assertJsonPath('data.0.showroom_quantity',3);
    $this->getJson(route('vouchers.showroom.catalog',['q'=>'COLOR-۱']))->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$v->id);
    expect(WarehouseStock::count())->toBe($before)->and($v->fresh()->stock)->toBe(999);
});

it('paginates variants and supports optional category filtering including descendants',function(){
    $first=$this->getJson(route('vouchers.showroom.catalog',['limit'=>2]))->assertOk()->assertJsonCount(2,'data')->assertJsonPath('meta.has_more',true);
    $this->getJson(route('vouchers.showroom.catalog',['limit'=>2,'cursor'=>$first->json('meta.next_cursor')]))->assertOk()->assertJsonCount(1,'data');
    $parent=Category::create(['name'=>'والد']);$this->category->update(['parent_id'=>$parent->id]);
    $this->getJson(route('vouchers.showroom.catalog',['category_id'=>$parent->id]))->assertOk()->assertJsonCount(3,'data');
    $other=Category::create(['name'=>'دیگر']);$this->getJson(route('vouchers.showroom.catalog',['category_id'=>$other->id]))->assertOk()->assertJsonCount(0,'data');
});

it('excludes inactive variants and optionally exposes zero stock as unselectable',function(){
    $this->variants[0]->update(['is_active'=>false]);
    WarehouseStock::where('product_variant_id',$this->variants[1]->id)->update(['quantity'=>0]);
    $this->getJson(route('vouchers.showroom.catalog'))->assertOk()->assertJsonCount(1,'data');
    $result=$this->getJson(route('vouchers.showroom.catalog',['available_only'=>0]))->assertOk()->assertJsonCount(2,'data');
    expect(collect($result->json('data'))->firstWhere('id',$this->variants[1]->id)['selectable'])->toBeFalse();
});

it('retains selected items and shows row errors when stock changes or duplicate lines are submitted',function(){
    $payload=showroomBulkPayload($this);$payload['items'][0]['quantity']=11;
    $url=route('vouchers.section.create','showroom');
    $this->from($url)->post(route('vouchers.section.store','showroom'),$payload)->assertRedirect($url)->assertSessionHasErrors('items.0.quantity');
    $this->get($url)->assertOk()->assertSee('sr-editor-config')->assertSee('موجودی فعلی')
        ->assertViewHas('selectedRows',fn($rows)=>$rows->first()['variant_title']==='کروم' && $rows->first()['quantity']==='11');
    $payload=showroomBulkPayload($this);$payload['items'][]=$payload['items'][0];
    $this->from($url)->post(route('vouchers.section.store','showroom'),$payload)->assertRedirect($url)->assertSessionHasErrors();
    expect(WarehouseTransfer::where('voucher_type',WarehouseTransfer::TYPE_SHOWROOM)->count())->toBe(0);
});

it('accepts Persian quantities and keeps the existing atomic transfer and replay protection',function(){
    $payload=showroomBulkPayload($this);
    WarehouseStock::create(['warehouse_id'=>$this->showroom->id,'product_id'=>$this->product->id,'product_variant_id'=>$this->variants[0]->id,'quantity'=>3]);
    $this->post(route('vouchers.section.store','showroom'),$payload)->assertSessionHasNoErrors()->assertRedirect(route('vouchers.section.index','showroom'));
    $this->post(route('vouchers.section.store','showroom'),$payload)->assertSessionHasNoErrors();
    expect(WarehouseTransfer::where('voucher_type',WarehouseTransfer::TYPE_SHOWROOM)->count())->toBe(1);
    foreach($this->variants->take(2) as $v){
        expect(WarehouseStock::where('warehouse_id',$this->central->id)->where('product_variant_id',$v->id)->value('quantity'))->toBe(8)
            ->and(WarehouseStock::where('warehouse_id',$this->showroom->id)->where('product_variant_id',$v->id)->value('quantity'))->toBe($v->id===$this->variants[0]->id?5:2);
    }
    $this->getJson(route('vouchers.showroom.catalog',['ids'=>[$this->variants[0]->id]]))->assertOk()
        ->assertJsonPath('data.0.available',8)->assertJsonPath('data.0.showroom_quantity',5);
});

it('rejects reversed showroom routes and insufficient stock without partially moving any line',function(){
    $payload=showroomBulkPayload($this);$payload['items'][1]['quantity']=11;
    $this->post(route('vouchers.section.store','showroom'),$payload)->assertSessionHasErrors();
    $payload=showroomBulkPayload($this);$payload['from_warehouse_id']=$this->showroom->id;$payload['to_warehouse_id']=$this->central->id;
    $this->post(route('vouchers.section.store','showroom'),$payload)->assertSessionHasErrors();
    expect(WarehouseTransfer::count())->toBe(0)
        ->and(WarehouseStock::where('warehouse_id',$this->central->id)->whereNotNull('product_variant_id')->sum('quantity'))->toBe(30)
        ->and(WarehouseStock::where('warehouse_id',$this->showroom->id)->whereNotNull('product_variant_id')->sum('quantity'))->toBe(0);
});

it('blocks unauthorized and restricted warehouse roles from the showroom catalog',function(){
    $this->actingAs(User::factory()->create())->getJson(route('vouchers.showroom.catalog'))->assertForbidden();
    $user=User::factory()->create();$user->assignRole(Role::findOrCreate(WarehouseVoucherAccess::ROLE,'web'));
    $user->permissions()->attach(\Illuminate\Support\Facades\DB::table('permissions')->where('key','page.warehouse.issues')->value('id'));
    $this->actingAs($user)->getJson(route('vouchers.showroom.catalog'))->assertForbidden();
});

it('rejects more than one hundred lines without changing stock',function(){
    $payload=showroomBulkPayload($this);$payload['items']=array_fill(0,101,$payload['items'][0]);
    $this->post(route('vouchers.section.store','showroom'),$payload)->assertSessionHasErrors('items');
    expect(WarehouseTransfer::count())->toBe(0);
});

it('refreshes up to one hundred selected models including newly unavailable models',function(){
    $ids=$this->variants->pluck('id')->all();$this->variants[0]->update(['is_active'=>false]);
    WarehouseStock::where('product_variant_id',$ids[1])->update(['quantity'=>0]);
    $result=$this->getJson(route('vouchers.showroom.catalog',['ids'=>$ids]))->assertOk()->assertJsonCount(3,'data');
    expect(collect($result->json('data'))->where('selectable',false)->count())->toBe(2);
});

it('restores malformed submitted quantities without breaking the editor',function(){
    $payload=showroomBulkPayload($this);$payload['items'][0]['quantity']=['bad'];$url=route('vouchers.section.create','showroom');
    $this->from($url)->post(route('vouchers.section.store','showroom'),$payload)->assertSessionHasErrors('items.0.quantity');
    $this->get($url)->assertOk()->assertSee('حواله ثبت نشد');
});

it('registers one hundred distinct models atomically without changing financial ledgers',function(){
    for($i=4;$i<=100;$i++){
        $v=ProductVariant::create(['product_id'=>$this->product->id,'variant_name'=>'مدل '.$i,'variant_code'=>'BULK-'.$i,'stock'=>5,'sell_price'=>1000,'is_active'=>true,'sales_enabled'=>true]);
        WarehouseStock::create(['warehouse_id'=>$this->central->id,'product_id'=>$this->product->id,'product_variant_id'=>$v->id,'quantity'=>5]);$this->variants->push($v);
    }
    $payload=showroomBulkPayload($this);$payload['items']=$this->variants->map(fn($v)=>['category_id'=>$this->category->id,'product_id'=>$this->product->id,'variant_id'=>$v->id,'quantity'=>1])->all();
    $this->getJson(route('vouchers.showroom.catalog',['ids'=>$this->variants->pluck('id')->all()]))->assertOk()->assertJsonCount(100,'data');
    $this->post(route('vouchers.section.store','showroom'),$payload)->assertSessionHasNoErrors()->assertRedirect();
    $doc=WarehouseTransfer::where('voucher_type',WarehouseTransfer::TYPE_SHOWROOM)->sole();
    expect($doc->items()->count())->toBe(100)->and($doc->items()->sum('quantity'))->toBe(100)
        ->and(WarehouseStock::where('warehouse_id',$this->showroom->id)->whereNotNull('product_variant_id')->sum('quantity'))->toBe(100)
        ->and(\App\Models\CustomerLedger::count())->toBe(0)->and(\App\Models\SupplierLedger::count())->toBe(0);
});

it('searches separate model and color names and allows the ordinary voucher form permission',function(){
    $color=\App\Models\Color::create(['name'=>'رنگ اختصاصی','code'=>'99']);
    $model=\App\Models\ModelList::create(['brand'=>'آریا','model_name'=>'مدل اختصاصی','code'=>'990']);
    $this->variants[0]->update(['color_id'=>$color->id,'model_list_id'=>$model->id]);
    $user=User::factory()->create();$user->permissions()->attach(\Illuminate\Support\Facades\DB::table('permissions')->where('key','page.warehouse.issues')->value('id'));
    $this->actingAs($user)->getJson(route('vouchers.showroom.catalog',['q'=>'رنگ اختصاصی']))->assertOk()->assertJsonCount(1,'data');
    $this->getJson(route('vouchers.showroom.catalog',['q'=>'مدل اختصاصی']))->assertOk()->assertJsonCount(1,'data');
});
