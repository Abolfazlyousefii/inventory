<?php

namespace App\Services;

use InvalidArgumentException;

final class CnyPriceCalculator
{
    public const POLICY = 'margin30-buffer2-ceil1000-v2';

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

    public function marginFromPolicy(?string $policy): ?string
    {
        if ($policy === 'margin30-buffer2-round1000-v1') return '30';
        if (!preg_match('/^margin((?:0|[1-9]\d?)(?:\.\d{1,2})?)-buffer2-ceil1000-v2$/D', $policy ?? '', $match)) return null;
        try { $this->marginBasisPoints($match[1]); } catch (InvalidArgumentException) { return null; }
        return $match[1];
    }

    private function marginBasisPoints(string $margin): int
    {
        $text = strtr(trim($margin), array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩٫', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789.')));
        if (!preg_match('/^\+?(?:0|[1-9]\d?)(?:\.\d{1,2})?$/D', $text)) throw new InvalidArgumentException('درصد سود باید بیشتر از صفر و حداکثر ۹۹ با دو رقم اعشار باشد.');
        [$whole, $fraction] = array_pad(explode('.', ltrim($text, '+')), 2, '');
        $points = (int)$whole * 100 + (int)str_pad($fraction, 2, '0');
        if ($points < 1 || $points > 9900) throw new InvalidArgumentException('درصد سود باید بیشتر از صفر و حداکثر ۹۹ باشد.');
        return $points;
    }

    public function calculate(string $amount,int $rateRial,string $margin='30'): array
    {
        $units=$this->units($amount);
        if($rateRial<1 || $rateRial>100000000)throw new InvalidArgumentException('نرخ یوان معتبر نیست.');
        $basisPoints=$this->marginBasisPoints($margin);
        $denominator=10000-$basisPoints;
        $base=$units*$rateRial;
        $saleMicro=$this->roundedRatio($units,1020000,$denominator);
        $percent=intdiv($basisPoints,100).($basisPoints%100 ? '.'.rtrim(str_pad((string)($basisPoints%100),2,'0',STR_PAD_LEFT),'0') : '');
        $policy='margin'.$percent.'-buffer2-ceil1000-v2';
        return ['buy_cny_units'=>$units,'buy_cny'=>$this->amount($units),'sale_cny'=>intdiv($saleMicro,1000000).'.'.str_pad((string)($saleMicro%1000000),6,'0',STR_PAD_LEFT),
            'buy_rial'=>$this->roundedRatio($base,1,10000),
            'sell_rial'=>$this->ceilingRatio($base,10200,10000*$denominator*10000)*10000,'policy'=>$policy];
    }

    private function roundedRatio(int $number,int $multiplier,int $divisor): int
    {
        // Split before multiplication so supported inputs never overflow or use floating point.
        return intdiv($number,$divisor)*$multiplier+intdiv(($number%$divisor)*$multiplier+intdiv($divisor,2),$divisor);
    }

    private function ceilingRatio(int $number,int $multiplier,int $divisor): int
    {
        // ceil(number * multiplier / divisor) without floating point/overflow.
        return intdiv($number,$divisor)*$multiplier
            +intdiv(($number%$divisor)*$multiplier+$divisor-1,$divisor);
    }
}
