<?php

namespace App\Services;

use App\Models\{Product,ProductCnyPricing,Purchase,PurchaseItem};
use App\Support\{ActivityLogger,PermissionCatalog};
use App\Services\Sync\SiteStockChangeMarker;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class UniformPurchasePriceService
{
    public function version(Product $product,?Collection $variants=null): string
    {
        $variants??=$product->variants()->orderBy('id')->get();
        return hash('sha256',json_encode([$product->uniform_buy_price,$product->uniform_sale_price,
            $variants->sortBy('id')->map(fn($v)=>[(int)$v->id,(int)$v->buy_price,(int)$v->sell_price])->values()->all()],JSON_THROW_ON_ERROR));
    }

    public function assertVersion(Product $product,Collection $variants,Collection $rows,array $data): void
    {
        if(empty($data['uniform_product_prices']))return;
        $version=$this->version($product,$variants);
        foreach($rows as $row)if(!is_string($row['product_price_version']??null) || !hash_equals($version,$row['product_price_version'])){
            throw ValidationException::withMessages(['items'=>'قیمت یا تنوع‌های کالا پس از بازشدن فرم تغییر کرده است؛ صفحه را تازه کنید و دوباره تأیید کنید.']);
        }
    }

    /** Preserve mixed historical snapshots only when the operator has not edited their prices. */
    public function validateRows(array $data,?Purchase $purchase): void
    {
        if($purchase && empty($data['uniform_product_prices']))return;
        $old=$purchase?->items()->get()->keyBy('id')??collect();
        foreach(collect($data['items'])->where('quantity','>',0)->groupBy('product_id') as $rows){
            foreach(['buy_price','sell_price'] as $field){
                if($rows->pluck($field)->unique()->count()===1)continue;
                $changed=!$purchase || $rows->contains(function($row)use($old,$field){
                    $item=$old->get($row['id']??0);
                    return !$item || (int)$item->$field!==(int)$row[$field];
                });
                if($changed)throw ValidationException::withMessages(['items'=>'برای هر کالا فقط یک قیمت خرید و یک قیمت فروش وارد کنید؛ قیمت تمام تنوع‌ها باید یکسان باشد.']);
            }
        }
    }

    /** Called inside the existing purchase transaction, before inventory and ledger changes. */
    public function apply(Purchase $purchase,array $data,bool $editing=false): array
    {
        if(($data['purchase_origin']??'domestic')==='china')return $data;
        if($editing && empty($data['uniform_product_prices']))return $data;
        $old=$editing?$purchase->items()->get()->keyBy('id'):collect();
        foreach(collect($data['items'])->where('quantity','>',0)->groupBy('product_id')->sortKeys() as $id=>$rows){
            $product=Product::whereKey($id)->lockForUpdate()->firstOrFail();
            $variants=$product->variants()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $buy=(int)$rows->first()['buy_price'];$sale=(int)$rows->first()['sell_price'];
            $buyEdited=!$editing || $rows->contains(fn($r)=>!$old->has($r['id']??0) || (int)$old->get($r['id'])->buy_price!==(int)$r['buy_price']);
            $saleEdited=!$editing || $rows->contains(fn($r)=>!$old->has($r['id']??0) || (int)$old->get($r['id'])->sell_price!==(int)$r['sell_price']);
            if(!$buyEdited && !$saleEdited)continue;
            $buyChanges=$buyEdited && ($variants->contains(fn($v)=>(int)$v->buy_price!==$buy) || ($product->uniform_buy_price!==null && (int)$product->uniform_buy_price!==$buy));
            $saleChanges=$saleEdited && $sale>0 && ($variants->contains(fn($v)=>(int)$v->sell_price!==$sale) || ($product->uniform_sale_price!==null && (int)$product->uniform_sale_price!==$sale));
            $modeChanges=!$editing && ProductCnyPricing::where('product_id',$id)->where('enabled',true)->exists();
            // A historical snapshot correction still requires explicit approval, even when it matches today's price.
            $approveSale=$saleEdited && ($saleChanges || $editing || $modeChanges);
            if($approveSale){
                abort_unless(PermissionCatalog::userHasPermission(auth()->user(),'products.price_changes.apply'),403,'مجوز تغییر قیمت فروش کالا را ندارید.');
                if(empty($data['sale_price_change_confirmed']) || trim($data['sale_price_change_reason']??'')==='')throw ValidationException::withMessages(['sale_price_change_confirmed'=>'تغییر قیمت فروش کالا نیازمند تأیید صریح و ثبت دلیل است.']);
                if($sale<1)throw ValidationException::withMessages(['items'=>'قیمت فروش کالا باید بیشتر از صفر باشد.']);
                foreach($rows as $row){
                    $variant=$variants->get((int)$row['variant_id']);
                    if(!$variant || !isset($row['expected_current_sell_price']) || (int)$row['expected_current_sell_price']!==(int)$variant->sell_price)throw ValidationException::withMessages(['items'=>'قیمت جاری کالا تغییر کرده است؛ فرم را تازه و دوباره تأیید کنید.']);
                }
            }
            if($buyChanges || $saleChanges || $modeChanges)$this->assertVersion($product,$variants,$rows,$data);
            if($editing && ($buyChanges || $saleChanges) && PurchaseItem::where('product_id',$id)->where('purchase_id','!=',$purchase->id)->whereHas('purchase',fn($q)=>$q->where('purchased_at','>',$purchase->purchased_at)->orWhere(fn($q)=>$q->where('purchased_at',$purchase->purchased_at)->where('id','>',$purchase->id)))->exists()){
                throw ValidationException::withMessages(['items'=>'خرید جدیدتری برای این کالا وجود دارد؛ سند قدیمی نمی‌تواند قیمت روز آن را تغییر دهد. از سند تغییر قیمت استفاده کنید.']);
            }
            if(!$editing && !empty($data['uniform_product_prices']) && $sale<1)throw ValidationException::withMessages(['items'=>'قیمت فروش کالا باید بیشتر از صفر باشد.']);
            $productValues=[];$variantValues=[];
            if($buyEdited){$productValues['uniform_buy_price']=$buy;$variantValues['buy_price']=$buy;}
            if($saleEdited && $sale>0){$productValues['uniform_sale_price']=$sale;$productValues['price']=$sale;$variantValues['sell_price']=$sale;}
            if($productValues){
                $before=$variants->map(fn($v)=>['id'=>(int)$v->id,'buy'=>(int)$v->buy_price,'sell'=>(int)$v->sell_price])->values()->all();
                $product->forceFill($productValues)->save();
                $product->variants()->update($variantValues+['updated_at'=>now()]);
                if($saleChanges || $modeChanges)app(CnyPricingService::class)->pauseForManualPrice((int)$id);
                if($saleChanges)SiteStockChangeMarker::mark((int)$id);
                ActivityLogger::log('purchase_product_prices_changed',$product,'ثبت قیمت خرید و فروش در سطح کالا از سند خرید.',[
                    'purchase_id'=>$purchase->id,'before'=>$before,'new_buy_price'=>$product->uniform_buy_price,'new_sell_price'=>$product->uniform_sale_price,
                    'reason'=>$data['sale_price_change_reason']??null,'catalog_sale_changed'=>$saleChanges,'yuan_pricing_paused'=>$modeChanges]);
            }
        }
        $data['_product_prices_applied']=true;return $data;
    }
}
