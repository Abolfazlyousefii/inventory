<?php

use App\Http\Requests\StoreSalesReturnRequest;
use App\Services\SalesReturnNewProductPayloadNormalizer;

function normalizedSalesReturnPayload(array $payload): array
{
    $request = StoreSalesReturnRequest::create('/', 'POST', [
        'items' => [['new_product_payload' => $payload]],
    ]);
    $method = new ReflectionMethod($request, 'prepareForValidation');
    $method->invoke($request);

    return $request->input('items.0.new_product_payload');
}

it('normalizes every supported true representation', function ($value) {
    $payload = normalizedSalesReturnPayload([
        'schema_version' => '2',
        'is_sellable' => $value,
        'use_models' => $value,
        'use_designs' => $value,
    ]);

    expect($payload['is_sellable'])->toBeTrue()
        ->and($payload['use_models'])->toBeTrue()
        ->and($payload['use_designs'])->toBeTrue();
})->with([true, 1, '1', 'true', 'TRUE', 'yes', 'on']);

it('normalizes every supported false representation', function ($value) {
    $payload = normalizedSalesReturnPayload([
        'schema_version' => 2,
        'is_sellable' => $value,
        'use_models' => $value,
        'use_designs' => $value,
    ]);

    expect($payload['is_sellable'])->toBeFalse()
        ->and($payload['use_models'])->toBeFalse()
        ->and($payload['use_designs'])->toBeFalse();
})->with([false, 0, '0', 'false', 'FALSE', 'no', 'off']);

it('uses safe schema two defaults and never turns invalid text on', function () {
    $defaults = normalizedSalesReturnPayload(['schema_version' => 2]);
    $invalid = normalizedSalesReturnPayload([
        'schema_version' => 2,
        'is_sellable' => 'invalid',
        'use_models' => 'invalid',
        'use_designs' => 'invalid',
    ]);

    expect($defaults['is_sellable'])->toBeTrue()
        ->and($defaults['use_models'])->toBeFalse()
        ->and($defaults['use_designs'])->toBeFalse()
        ->and($invalid['is_sellable'])->toBeFalse()
        ->and($invalid['use_models'])->toBeFalse()
        ->and($invalid['use_designs'])->toBeFalse();
});

it('normalizes booleans again at the service boundary', function () {
    $payload = app(SalesReturnNewProductPayloadNormalizer::class)->normalize([
        'schema_version' => '2',
        'is_sellable' => 'false',
        'use_models' => 'false',
        'use_designs' => 'true',
        'category_path_snapshot' => [],
        'model_list_ids' => [],
    ]);

    expect($payload['is_sellable'])->toBeFalse()
        ->and($payload['sales_enabled'])->toBeFalse()
        ->and($payload['use_models'])->toBeFalse()
        ->and($payload['use_designs'])->toBeTrue();
});

it('keeps the frontend base model and design cartesian fallbacks', function () {
    $view = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

    expect($view)->toContain("const models=useM?[...np.selectedModels].map(id=>npModelById(id)).filter(Boolean):[null]")
        ->and($view)->toContain("const designs=useD?npDesigns():[{index:0,name:''}]")
        ->and($view)->toContain("is_sellable:$('#npSales').value==='1'?1:0")
        ->and($view)->toContain("use_models:$('#npUseModels').checked?1:0")
        ->and($view)->toContain("use_designs:$('#npUseDesigns').checked?1:0");
});
