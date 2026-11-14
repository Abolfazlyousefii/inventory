<?php

use App\Models\{PreinvoiceOrder,PreinvoiceOrderItem,Product,User};
use App\Services\PreinvoiceDiscountHydrator;
use App\Support\SalesDocumentTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function(){
    $this->withoutVite();
    view()->share('errors',new Illuminate\Support\ViewErrorBag());
    app('request')->setLaravelSession(app('session')->driver());
    $actor=User::factory()->create();$actor->assignRole(Role::findOrCreate('Owner','web'));$this->actingAs($actor);
    $this->order=new PreinvoiceOrder(['uuid'=>'02506','status'=>'pending_finance','shipping_price'=>40000,'invoice_discount_type'=>'percent','invoice_discount_value'=>10,'invoice_discount_amount'=>630000,'discount_amount'=>1330000,'discount_allocation_mode'=>'product_lines']);
    $this->order->setRelation('creator',$actor)->setRelation('shippingMethod',null)->setRelation('customer',null);
    $items=collect();
    foreach([[10,2,1000000,200000],[10,3,1000000,300000],[20,1,2000000,200000]] as $i=>[$pid,$qty,$price,$discount]){
        $item=new PreinvoiceOrderItem(['product_id'=>$pid,'quantity'=>$qty,'price'=>$price,'line_discount_amount'=>$discount]);$item->id=$i+1;
        $item->setRelation('product',new Product(['name'=>'کالای نمونه '.$pid]))->setRelation('variant',null);$items->push($item);
    }
    $this->order->setRelation('items',$items);
    $this->renderFinance=function(){
        $state=app(PreinvoiceDiscountHydrator::class)->hydrateForEditing($this->order);
        return $this->view('preinvoice.finance-edit',['order'=>$this->order,'discountEditorState'=>$state,'zeroPriceItems'=>[],'customerBalance'=>0,'totals'=>SalesDocumentTotals::calculate($this->order->items,630000,40000)]);
    };
});

it('renders real grouped and final amounts before any browser JavaScript runs',function(){
    $html=(string)($this->renderFinance)();
    if(getenv('FINANCE_REVIEW_PREVIEW'))file_put_contents(base_path('.vscode/finance-review-fixed-preview.html'),$html);
    expect($html)->toContain('data-group-gross>5,000,000 ریال')
        ->toContain('data-group-final>4,500,000 ریال')
        ->toContain('data-summary-gross>7,000,000 ریال')
        ->toContain('data-summary-product-discount>700,000 ریال')
        ->toContain('data-summary-invoice-discount>630,000 ریال')
        ->toContain('data-summary-grand-total>5,710,000 ریال')
        ->toContain('data-summary-remaining>5,710,000 ریال');
});

it('renders restored discount inputs and staged payments consistently after validation errors',function(){
    session()->flashInput(['product_discounts'=>[['type'=>'percent','value'=>'۲۰'],['type'=>'amount','value'=>'۲۰۰٬۰۰۰']],'invoice_discount_type'=>'none','invoice_discount_value'=>'0','payments'=>[['amount'=>'۱٬۰۰۰٬۰۰۰','type'=>'cash']]]);
    $html=(string)($this->renderFinance)();
    expect($html)->toContain('data-group-final>4,000,000 ریال')
        ->toContain('data-summary-grand-total>5,840,000 ریال')
        ->toContain('data-summary-remaining>4,840,000 ریال');
});

it('versions finance scripts by their content so stale cached code cannot survive a release',function(){
    $html=(string)($this->renderFinance)();
    foreach(['sales-discount-math','finance-review','finance-review-payments','finance-preinvoice-payload'] as $name){
        expect($html)->toContain('js/'.$name.'.js?v='.substr(hash_file('sha256',public_path('js/'.$name.'.js')),0,12));
    }
});

it('shows a genuine zero total without substituting a catalog price or changing document items',function(){
    foreach($this->order->items as $item){$item->price=0;$item->line_discount_amount=0;}
    $this->order->shipping_price=0;
    $html=(string)($this->renderFinance)();
    expect($html)->toContain('data-summary-grand-total>0 ریال')
        ->toContain('data-group-gross>0 ریال')
        ->and($this->order->items->pluck('price')->all())->toBe([0,0,0]);
});
