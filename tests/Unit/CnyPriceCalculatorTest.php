<?php

use App\Services\CnyPriceCalculator;

it('requires explicit CNY pricing opt-in even when the host is named local', function () {
    // Assert the opt-out state using explicit environment values in the child.
    // A developer can enable CNY_PRICING_ENABLED in their .env; that must not
    // change this isolated test or be mistaken for the default setting.
    $script = <<<'PHP'
require "vendor/autoload.php";
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'local';
putenv('APP_ENV=local');
foreach (['CNY_PRICING_ENABLED', 'CNY_ALLOW_SAMPLE', 'CNY_REPRICE_SCHEDULE_ENABLED'] as $key) {
    $_ENV[$key] = $_SERVER[$key] = 'false';
    putenv($key . '=false');
}
$config = require 'config/cny_pricing.php';
echo json_encode([$config['enabled'], $config['allow_sample'], $config['schedule_enabled']], JSON_THROW_ON_ERROR);
PHP;

    $process = new \Symfony\Component\Process\Process(
        [PHP_BINARY, '-r', $script],
        base_path(),
        [
            'APP_ENV' => 'local',
            'CNY_PRICING_ENABLED' => 'false',
            'CNY_ALLOW_SAMPLE' => 'false',
            'CNY_REPRICE_SCHEDULE_ENABLED' => 'false',
        ]
    );
    $process->mustRun();

    expect(json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR))
        ->toBe([false, false, false]);
});

it('calculates purchase and sale with a true thirty percent margin and ceil-rounds only the final sale',function(){
    $quote=(new CnyPriceCalculator)->calculate('۵',400000);
    expect($quote['buy_rial'])->toBe(2000000)->and($quote['sell_rial'])->toBe(2920000)
        ->and($quote['sale_cny'])->toBe('7.285714')->and($quote['buy_cny_units'])->toBe(50000);
});

it('preserves fractional yuan and rounds the final selling price upward to the next thousand toman',function(){
    $quote=(new CnyPriceCalculator)->calculate('۲٫۵۱۲۵',400000);
    expect($quote['buy_rial'])->toBe(1005000)->and($quote['sell_rial']%10000)->toBe(0);
    expect((new CnyPriceCalculator)->calculate('3.5',100000)['sell_rial'])->toBe(510000);
});

it('rejects invalid or excessive amounts and nonpositive rates',function($amount,$rate){
    expect(fn()=>(new CnyPriceCalculator)->calculate($amount,$rate))->toThrow(InvalidArgumentException::class);
})->with([['0',400000],['-5',400000],['5abc',400000],['5.12345',400000],['100000.0001',400000],['5',0],['5',100000001]]);

it('always ceilings the final selling price to the next 1000 toman, keeping purchase cost precise', function(){
    $quote=(new CnyPriceCalculator)->calculate('5',400000);
    expect($quote['sell_rial'])->toBe(2920000)->and($quote['buy_rial'])->toBe(2000000);
    expect((new CnyPriceCalculator)->calculate('5',390000)['sell_rial'])->toBe(2850000);
    expect((new CnyPriceCalculator)->calculate('2.5125',400000)['sell_rial'])->toBe(1470000);
});
