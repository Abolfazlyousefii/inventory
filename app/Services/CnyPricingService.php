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
        $rate=CnyExchangeRate::orderByDesc('quoted_at')->orderByDesc('id')->first();
        if(!$rate || $rate->rate_rial<1 || $rate->rate_rial>100000000 || $rate->quoted_at->lt(now()->subMinutes(config('cny_pricing.max_rate_age_minutes',30))) || $rate->quoted_at->gt(now()->addMinute()))$this->fail('نرخ یوان موجود نیست یا قدیمی/نامعتبر است؛ ابتدا نرخ را به‌روز کنید.');
        if(!in_array($rate->provider,['sample','navasan'],true) || ($rate->provider==='sample' && !config('cny_pricing.allow_sample')))$this->fail('نرخ نمونه برای این محیط مجاز نیست.');
        if($expectedId!==null && $expectedId!==$rate->id)$this->fail('نرخ یوان پس از بازشدن فرم تغییر کرده است؛ صفحه را تازه کنید و قیمت را دوباره تأیید کنید.');
        return $rate;
    }

    public function previewRate(): ?array
    {
        try{$rate=$this->currentRate();return ['id'=>$rate->id,'rate_rial'=>$rate->rate_rial,'quoted_at'=>$rate->quoted_at->toIso8601String(),'sample'=>$rate->provider==='sample'];}
        catch(ValidationException){return null;}
    }

    public function prepare(array $data,?Purchase $purchase=null): array
    {
        $origin=$data['purchase_origin']??'domestic';
        if($purchase && $origin!==($purchase->purchase_origin??'domestic'))$this->fail('مبنای سند خرید پس از ثبت قابل تغییر نیست.');
        if($origin!=='china')return $data;
        if($purchase){
            $stored=$purchase->items()->get()->keyBy('id');
            foreach($data['items'] as &$row){
                $item=$stored->get($row['id']??0);
                if(!$item || (int)$item->product_id!==(int)$row['product_id'])$this->fail('افزودن کالا به خرید یوانیِ قبلی را در سند خرید جدید ثبت کنید.');
                try{$units=isset($row['buy_cny'])?app(CnyPriceCalculator::class)->units($row['buy_cny']):(int)$item->buy_cny_units;}
                catch(InvalidArgumentException $e){$this->fail($e->getMessage());}
                if($units!==(int)$item->buy_cny_units)$this->fail('مبنای یوانی سند ثبت‌شده ثابت است؛ برای مبنای جدید، خرید جدید ثبت کنید.');
                $row['buy_cny_units']=(int)$item->buy_cny_units;$row['cny_policy']=$item->cny_policy;
                $row['buy_price']=(int)$item->buy_price;$row['sell_price']=(int)$item->sell_price;
                $row['product_buy_price']=$row['buy_price'];$row['product_sell_price']=$row['sell_price'];
            }
            unset($row);$data['cny_rate_id']=$purchase->cny_rate_id;return $data;
        }
        if(empty($data['cny_rate_id']))$this->fail('نرخ نمایش‌داده‌شده باید همراه سند ارسال شود.');
        $rate=$this->currentRate((int)$data['cny_rate_id']);$bases=[];
        foreach($data['items'] as &$row){
            try{$quote=app(CnyPriceCalculator::class)->calculate((string)($row['buy_cny']??''),$rate->rate_rial);}
            catch(InvalidArgumentException $e){$this->fail($e->getMessage());}
            if($quote['sell_rial']<1)$this->fail('قیمت محاسبه‌شده پس از گردکردن باید بیشتر از صفر باشد.');
            $id=(int)$row['product_id'];
            if(isset($bases[$id]) && $bases[$id]!==$quote['buy_cny_units'])$this->fail('مبنای یوانی تمام تنوع‌های یک کالا باید یکسان باشد.');
            $bases[$id]=$quote['buy_cny_units'];
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
            app(UniformPurchasePriceService::class)->assertVersion($product,$variants,$rows,$data);
            foreach($rows as $row){
                $variant=$variants->get((int)$row['variant_id']);
                if(!$variant || !isset($row['expected_current_sell_price']) || (int)$row['expected_current_sell_price']!==(int)$variant->sell_price)$this->fail('قیمت کالا پس از بازشدن فرم تغییر کرده است؛ صفحه را تازه و مجدداً تأیید کنید.');
            }
            $row=$rows->first();
            $this->writePrice($product,$rate,(int)$row['buy_cny_units'],'purchase',$actor->id);
            $product->forceFill(['uniform_buy_price'=>(int)$row['buy_price']])->save();
            $product->variants()->update(['buy_price'=>(int)$row['buy_price'],'updated_at'=>now()]);
            ProductCnyPricing::updateOrCreate(['product_id'=>$id],['buy_cny_units'=>$row['buy_cny_units'],'enabled'=>true,'applied_rate_id'=>$rate->id,'approved_by'=>$actor->id,'policy'=>CnyPriceCalculator::POLICY]);
        }
    }

    public function reprice(): int
    {
        $rate=$this->currentRate();$changed=0;
        ProductCnyPricing::where('enabled',true)->orderBy('id')->chunkById(100,function($policies)use($rate,&$changed){
            foreach($policies as $policy)$changed+=DB::transaction(function()use($policy,$rate){
                $product=Product::whereKey($policy->product_id)->lockForUpdate()->first();
                if(!$product)return 0;
                $locked=ProductCnyPricing::whereKey($policy->id)->lockForUpdate()->firstOrFail();
                if(!$locked->enabled || $locked->policy!==CnyPriceCalculator::POLICY || (int)$locked->applied_rate_id===$rate->id)return 0;
                $previous=CnyExchangeRate::findOrFail($locked->applied_rate_id);
                if($previous->quoted_at->gt($rate->quoted_at) || abs($rate->rate_rial-$previous->rate_rial)<10000)return 0;
                // Do not re-apply a stale worker's quote after another worker received a newer quote.
                $this->currentRate($rate->id);
                $this->writePrice($product,$rate,$locked->buy_cny_units,'rate',$locked->approved_by);
                $locked->update(['applied_rate_id'=>$rate->id]);return 1;
            },3);
        });
        return $changed;
    }

    private function writePrice(Product $product,CnyExchangeRate $rate,int $units,string $reason,int $actorId): void
    {
        $quote=app(CnyPriceCalculator::class)->calculate(app(CnyPriceCalculator::class)->amount($units),$rate->rate_rial);
        if($quote['sell_rial']<1)$this->fail('قیمت نهایی معتبر نیست.');
        DB::table('cny_price_revisions')->insert(['product_id'=>$product->id,'buy_cny_units'=>$units,'rate_id'=>$rate->id,'old_price'=>(int)$product->price,'new_price'=>$quote['sell_rial'],'policy'=>CnyPriceCalculator::POLICY,'reason'=>$reason,'approved_by'=>$actorId,'created_at'=>now(),'updated_at'=>now()]);
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
