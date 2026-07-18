<?php

use App\Http\Requests\FinanceUpdatePreinvoiceRequest;

it('normalizes formatted Persian Arabic and comma separated money inputs', function () {
    $request = new FinanceUpdatePreinvoiceRequest();
    $method = new ReflectionMethod($request, 'normalizeMoney');
    $method->setAccessible(true);

    expect($method->invoke($request, '۲٬۱۰۰٬۰۰۰'))->toBe(2100000)
        ->and($method->invoke($request, '2,100,000'))->toBe(2100000)
        ->and($method->invoke($request, '۲ ۱۰۰ ۰۰۰ ریال'))->toBe(2100000)
        ->and($method->invoke($request, '٢١٠٠٠٠٠'))->toBe(2100000);
});
