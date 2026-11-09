<?php

use App\Services\CnyPriceCalculator;

it('keeps automatic and sample pricing disabled even when a host is named local',function(){
    $script='require "vendor/autoload.php"; $_ENV["APP_ENV"]="local"; unset($_ENV["CNY_PRICING_ENABLED"],$_SERVER["CNY_PRICING_ENABLED"],$_ENV["CNY_ALLOW_SAMPLE"],$_SERVER["CNY_ALLOW_SAMPLE"]); $config=require "config/cny_pricing.php"; echo json_encode([$config["enabled"],$config["allow_sample"],$config["schedule_enabled"]]);';
    $process=new \Symfony\Component\Process\Process([PHP_BINARY,'-r',$script],base_path(),['CNY_PRICING_ENABLED'=>false,'CNY_ALLOW_SAMPLE'=>false,'CNY_REPRICE_SCHEDULE_ENABLED'=>false]);
    $process->mustRun();
    expect(json_decode($process->getOutput(),true))->toBe([false,false,false]);
});

it('calculates purchase and sale with a true thirty percent margin and rounds only the final sale',function(){
    $quote=(new CnyPriceCalculator)->calculate('۵',400000);
    expect($quote['buy_rial'])->toBe(2000000)->and($quote['sell_rial'])->toBe(2910000)
        ->and($quote['sale_cny'])->toBe('7.285714')->and($quote['buy_cny_units'])->toBe(50000);
});

it('preserves fractional yuan and rounds half upward to the nearest thousand toman',function(){
    $quote=(new CnyPriceCalculator)->calculate('۲٫۵۱۲۵',400000);
    expect($quote['buy_rial'])->toBe(1005000)->and($quote['sell_rial']%10000)->toBe(0);
    expect((new CnyPriceCalculator)->calculate('3.5',100000)['sell_rial'])->toBe(510000);
});

it('rejects invalid or excessive amounts and nonpositive rates',function($amount,$rate){
    expect(fn()=>(new CnyPriceCalculator)->calculate($amount,$rate))->toThrow(InvalidArgumentException::class);
})->with([['0',400000],['-5',400000],['5abc',400000],['5.12345',400000],['100000.0001',400000],['5',0],['5',100000001]]);
