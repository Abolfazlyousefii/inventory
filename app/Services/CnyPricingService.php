<?php

namespace App\Services;

use App\Models\{CnyExchangeRate,Product,ProductCnyPricing,Purchase,User};
use App\Services\Sync\SiteStockChangeMarker;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CnyPricingService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['cny_pricing'=>$message]);
    }

    public function currentRate(?int $expectedId=null): CnyExchangeRate
    {
        if(!config('cny_pricing.enabled') || !Schema::hasTable('cny_exchange_rates'))$this->fail('قیمت‌گذاری یوانی فعال نیست.');
        $rates=CnyExchangeRate::query();
        // Never substitute an old Navasan/sample quote when Nobitex mode is on.
        if(config('nobitex.enabled'))$rates->where('provider','nobitex_usdt_proxy')->where('symbol','cny');
        $rate=$rates->orderByDesc('quoted_at')->orderByDesc('id')->first();
        if(!$rate || $rate->rate_rial<1 || $rate->rate_rial>100000000 || $rate->quoted_at->lt(now()->subMinutes(config('cny_pricing.max_rate_age_minutes',30))) || $rate->quoted_at->gt(now()->addMinute()))$this->fail('نرخ یوان موجود نیست یا قدیمی/نامعتبر است؛ ابتدا نرخ را به‌روز کنید.');
        if(!in_array($rate->provider,['sample','navasan','nobitex_usdt_proxy'],true) || ($rate->provider==='sample' && (!config('cny_pricing.allow_sample') || config('cny_pricing.production_mode') || !app()->environment(['local','testing']) || config('navasan.enabled') || config('nobitex.enabled'))))$this->fail('نرخ نمونه برای این محیط مجاز نیست.');
        if($rate->provider==='nobitex_usdt_proxy' && !config('nobitex.enabled'))$this->fail('منبع نرخ نوبیتکس غیرفعال است.');
        if($expectedId!==null && $expectedId!==$rate->id)$this->fail('نرخ یوان پس از بازشدن فرم تغییر کرده است؛ صفحه را تازه کنید و قیمت را دوباره تأیید کنید.');
        return $rate;
    }

    public function previewRate(): ?array
    {
        try{$rate=$this->currentRate();return ['id'=>$rate->id,'rate_rial'=>$rate->rate_rial,'quoted_at'=>$rate->quoted_at->toIso8601String(),'sample'=>$rate->provider==='sample','estimated'=>$rate->provider==='nobitex_usdt_proxy'];}
        catch(ValidationException){return null;}
    }

    public function prepare(array $data,?Purchase $purchase=null): array
    {
        $origin=$data['purchase_origin']??'domestic';
        if($purchase && $origin!==($purchase->purchase_origin??'domestic'))$this->fail('مبنای سند خرید پس از ثبت قابل تغییر نیست.');
        if($origin!=='china')return $data;
        if($purchase){
            $stored=$purchase->items()->get()->keyBy('id');
            $rate=CnyExchangeRate::find($purchase->cny_rate_id);
            $calculator=app(CnyPriceCalculator::class);$bases=[];
            foreach($data['items'] as &$row){
                $item=$stored->get($row['id']??0);
                if(!$item || (int)$item->product_id!==(int)$row['product_id'])$this->fail('افزودن کالا به خرید یوانیِ قبلی را در سند خرید جدید ثبت کنید.');
                try{
                    $amount=(string)(array_key_exists('buy_cny',$row)?$row['buy_cny']:$calculator->amount((int)$item->buy_cny_units));
                    $margin=(string)(array_key_exists('cny_margin',$row)?$row['cny_margin']:($calculator->marginFromPolicy($item->cny_policy)??''));
                    $units=$calculator->units($amount);
                    // Do not recalculate an unchanged historical snapshot on quantity-only edits.
                    $sameBase=$units===(int)$item->buy_cny_units && $margin===$calculator->marginFromPolicy($item->cny_policy);
                    if(!$sameBase){
                        if(!$rate || $rate->rate_rial<1 || $rate->rate_rial>100000000)$this->fail('نرخ ثبت‌شدهٔ این سند معتبر نیست.');
                        $quote=$calculator->calculate($amount,(int)$rate->rate_rial,$margin);
                        if((int)$quote['sell_rial']!==(int)$item->sell_price){
                            abort_unless(PermissionCatalog::userHasPermission(auth()->user(),'products.price_changes.apply'),403,'مجوز تغییر قیمت فروش را ندارید.');
                            if(empty($data['sale_price_change_confirmed']) || trim($data['sale_price_change_reason']??'')==='')$this->fail('اصلاح قیمت فروش سند به تأیید قیمت و ثبت دلیل نیاز دارد.');
                        }
                        $row['buy_cny_units']=$quote['buy_cny_units'];$row['cny_policy']=$quote['policy'];
                        $row['buy_price']=$quote['buy_rial'];$row['sell_price']=$quote['sell_rial'];
                    }else{
                        $row['buy_cny_units']=(int)$item->buy_cny_units;$row['cny_policy']=$item->cny_policy;
                        $row['buy_price']=(int)$item->buy_price;$row['sell_price']=(int)$item->sell_price;
                    }
                }catch(InvalidArgumentException $e){$this->fail($e->getMessage());}
                $id=(int)$row['product_id'];$base=[$row['buy_cny_units'],$row['cny_policy']];
                if(isset($bases[$id]) && $bases[$id]!==$base)$this->fail('مبنای یوانی و درصد سود تمام تنوع‌های یک کالا باید یکسان باشد.');
                $bases[$id]=$base;
                $row['product_buy_price']=$row['buy_price'];$row['product_sell_price']=$row['sell_price'];
            }
            unset($row);$data['cny_rate_id']=$purchase->cny_rate_id;return $data;
        }
        if(empty($data['cny_rate_id']))$this->fail('نرخ نمایش‌داده‌شده باید همراه سند ارسال شود.');
        $rate=$this->currentRate((int)$data['cny_rate_id']);$bases=[];
        foreach($data['items'] as &$row){
            try{$quote=app(CnyPriceCalculator::class)->calculate((string)($row['buy_cny']??''),$rate->rate_rial,(string)($row['cny_margin']??''));}
            catch(InvalidArgumentException $e){$this->fail($e->getMessage());}
            if($quote['sell_rial']<1)$this->fail('قیمت محاسبه‌شده پس از گردکردن باید بیشتر از صفر باشد.');
            $id=(int)$row['product_id'];
            if(isset($bases[$id]) && $bases[$id]!==[$quote['buy_cny_units'],$quote['policy']])$this->fail('مبنای یوانی و درصد سود تمام تنوع‌های یک کالا باید یکسان باشد.');
            $bases[$id]=[$quote['buy_cny_units'],$quote['policy']];
            $row['buy_cny_units']=$quote['buy_cny_units'];$row['cny_policy']=$quote['policy'];
            $row['buy_price']=$quote['buy_rial'];$row['sell_price']=$quote['sell_rial'];
            $row['product_buy_price']=$row['buy_price'];$row['product_sell_price']=$row['sell_price'];
        }
        unset($row);return $data;
    }

    /** Inside the purchase transaction; the parent lock serializes all catalog price writes. */
    public function activate(array $data,User $actor): void
    {
        if(($data['purchase_origin']??'domestic')!=='china')return;
        abort_unless(PermissionCatalog::userHasPermission($actor,'products.price_changes.apply'),403,'مجوز فعال‌کردن قیمت‌گذاری یوانی را ندارید.');
        if(empty($data['sale_price_change_confirmed']) || trim($data['sale_price_change_reason']??'')==='')$this->fail('فعال‌کردن قیمت‌گذاری خودکار یوانی نیازمند تأیید قیمت و ثبت دلیل است.');
        $rate=$this->currentRate((int)$data['cny_rate_id']);
        foreach(collect($data['items'])->groupBy('product_id')->sortKeys() as $id=>$rows){
            $product=Product::whereKey($id)->lockForUpdate()->firstOrFail();
            $variants=$product->variants()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            // Yuan activation must always check the catalog version, even if the client
            // tampers with uniform_product_prices=0.
            app(UniformPurchasePriceService::class)->assertVersion($product,$variants,$rows,['uniform_product_prices'=>true]);
            foreach($rows as $row){
                $variant=$variants->get((int)$row['variant_id']);
                if(!$variant || !isset($row['expected_current_sell_price']) || (int)$row['expected_current_sell_price']!==(int)$variant->sell_price)$this->fail('قیمت کالا پس از بازشدن فرم تغییر کرده است؛ صفحه را تازه و مجدداً تأیید کنید.');
            }
            $row=$rows->first();
            $this->writePrice($product,$rate,(int)$row['buy_cny_units'],'purchase',$actor->id,$row['cny_policy']);
            $product->forceFill(['uniform_buy_price'=>(int)$row['buy_price']])->save();
            $product->variants()->update(['buy_price'=>(int)$row['buy_price'],'updated_at'=>now()]);
            ProductCnyPricing::updateOrCreate(['product_id'=>$id],['buy_cny_units'=>$row['buy_cny_units'],'enabled'=>true,'applied_rate_id'=>$rate->id,'approved_by'=>$actor->id,'policy'=>$row['cny_policy']]);
        }
    }

    public function reprice(bool $dryRun = false): int
    {
        $rate=$this->currentRate();$changed=0;
        ProductCnyPricing::where('enabled',true)->orderBy('id')->chunkById(100,function($policies)use($rate,&$changed,$dryRun){
            foreach($policies as $policy)$changed+=DB::transaction(function()use($policy,$rate,$dryRun){
                $product=Product::whereKey($policy->product_id)->lockForUpdate()->first();
                if(!$product)return 0;
                $locked=ProductCnyPricing::whereKey($policy->id)->lockForUpdate()->firstOrFail();
                if(!$locked->enabled || app(CnyPriceCalculator::class)->marginFromPolicy($locked->policy)===null || (int)$locked->applied_rate_id===$rate->id)return 0;
                $previous=CnyExchangeRate::findOrFail($locked->applied_rate_id);
                if($previous->quoted_at->gt($rate->quoted_at) || abs($rate->rate_rial-$previous->rate_rial)<10000)return 0;
                // Do not re-apply a stale worker's quote after another worker received a newer quote.
                $this->currentRate($rate->id);
                // Preview uses the same eligibility rules but never writes prices or site markers.
                if ($dryRun) return 1;
                $this->writePrice($product,$rate,$locked->buy_cny_units,'rate',$locked->approved_by,$locked->policy);
                $locked->update(['applied_rate_id'=>$rate->id,'policy'=>($locked->policy==='margin30-buffer2-round1000-v1'?CnyPriceCalculator::POLICY:$locked->policy)]);return 1;
            },3);
        });
        return $changed;
    }

    private function writePrice(Product $product,CnyExchangeRate $rate,int $units,string $reason,int $actorId,string $policy): void
    {
        $margin=app(CnyPriceCalculator::class)->marginFromPolicy($policy);
        if ($margin===null)$this->fail('سیاست قیمت‌گذاری یوانی معتبر نیست.');
        $quote=app(CnyPriceCalculator::class)->calculate(app(CnyPriceCalculator::class)->amount($units),$rate->rate_rial,$margin);
        if($quote['sell_rial']<1)$this->fail('قیمت نهایی معتبر نیست.');
        DB::table('cny_price_revisions')->insert(['product_id'=>$product->id,'buy_cny_units'=>$units,'rate_id'=>$rate->id,'old_price'=>(int)$product->price,'new_price'=>$quote['sell_rial'],'policy'=>$quote['policy'],'reason'=>$reason,'approved_by'=>$actorId,'created_at'=>now(),'updated_at'=>now()]);
        $product->forceFill(['uniform_sale_price'=>$quote['sell_rial'],'price'=>$quote['sell_rial']])->save();
        $product->variants()->orderBy('id')->lockForUpdate()->get();
        $product->variants()->update(['sell_price'=>$quote['sell_rial'],'updated_at'=>now()]);
        SiteStockChangeMarker::mark((int)$product->id);
    }

    public function pauseForManualPrice(int $productId): void
    {
        if(Schema::hasTable('product_cny_pricings'))ProductCnyPricing::where('product_id',$productId)->where('enabled',true)->update(['enabled'=>false,'updated_at'=>now()]);
    }
}
