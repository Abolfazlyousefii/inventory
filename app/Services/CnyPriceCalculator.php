<?php

namespace App\Services;

use InvalidArgumentException;

final class CnyPriceCalculator
{
    public const POLICY = 'margin30-buffer2-round1000-v1';

    public function units(string $amount): int
    {
        $text=strtr(trim($amount),array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩٫',-1,PREG_SPLIT_NO_EMPTY),str_split('01234567890123456789.')));
        if(!preg_match('/^\d{1,6}(?:\.\d{1,4})?$/D',$text)) throw new InvalidArgumentException('قیمت یوانی باید عدد مثبت با حداکثر چهار رقم اعشار باشد.');
        [$whole,$fraction]=array_pad(explode('.',$text),2,'');
        $units=(int)$whole*10000+(int)str_pad($fraction,4,'0');
        if($units<1 || $units>1000000000)throw new InvalidArgumentException('قیمت یوانی باید بیشتر از صفر و حداکثر ۱۰۰٬۰۰۰ یوان باشد.');
        return $units;
    }

    public function amount(int $units): string
    {
        return intdiv($units,10000).'.'.str_pad((string)($units%10000),4,'0',STR_PAD_LEFT);
    }

    public function calculate(string $amount,int $rateRial): array
    {
        $units=$this->units($amount);
        if($rateRial<1 || $rateRial>100000000)throw new InvalidArgumentException('نرخ یوان معتبر نیست.');
        $base=$units*$rateRial;
        $saleMicro=$this->roundedRatio($units,10200,70);
        return ['buy_cny_units'=>$units,'buy_cny'=>$this->amount($units),'sale_cny'=>intdiv($saleMicro,1000000).'.'.str_pad((string)($saleMicro%1000000),6,'0',STR_PAD_LEFT),
            'buy_rial'=>$this->roundedRatio($base,1,10000),
            'sell_rial'=>$this->roundedRatio($base,102,10000*70*10000)*10000,'policy'=>self::POLICY];
    }

    private function roundedRatio(int $number,int $multiplier,int $divisor): int
    {
        // Split before multiplication so supported inputs never overflow or use floating point.
        return intdiv($number,$divisor)*$multiplier+intdiv(($number%$divisor)*$multiplier+intdiv($divisor,2),$divisor);
    }
}
