<?php

use App\Http\Requests\PurchaseIndexRequest;
use App\Support\JalaliDate;
use Illuminate\Support\Facades\Validator;

function preparedPurchaseIndexRequest(array $input): PurchaseIndexRequest
{
    $request = PurchaseIndexRequest::create('/purchases', 'GET', $input);
    (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

    return $request;
}

it('converts english persian and arabic jalali digits to the same gregorian date', function ($value): void {
    expect(JalaliDate::toGregorianDate($value))->toBe('2026-07-19');
})->with([
    '1405/04/28',
    '۱۴۰۵/۰۴/۲۸',
    '١٤٠٥/٠٤/٢٨',
    '1405-04-28',
]);

it('renders the purchase datetime in jalali and renders null as an em dash', function (): void {
    expect(JalaliDate::dateTime('2026-07-19 10:19:00'))->toBe('1405/04/28 10:19')
        ->and(JalaliDate::dateTime(null, '—'))->toBe('—');
});

it('normalizes jalali filter fields before validation', function (): void {
    $request = preparedPurchaseIndexRequest([
        'date_from_fa' => '۱۴۰۵/۰۴/۲۸',
        'date_to_fa' => '1405/04/29',
    ]);

    expect($request->input('date_from'))->toBe('2026-07-19')
        ->and($request->input('date_to'))->toBe('2026-07-20');
});

it('rejects invalid jalali dates with a persian validation error', function (): void {
    $request = preparedPurchaseIndexRequest(['date_from_fa' => '1405/13/50']);
    $validator = Validator::make($request->all(), $request->rules(), $request->messages());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('date_from'))->toBe('تاریخ واردشده معتبر نیست.');
});

it('rejects a start date after the end date', function (): void {
    $request = preparedPurchaseIndexRequest([
        'date_from_fa' => '1405/04/29',
        'date_to_fa' => '1405/04/28',
    ]);
    $validator = Validator::make($request->all(), $request->rules(), $request->messages());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('date_from'))->toBe('تاریخ شروع نمی‌تواند بعد از تاریخ پایان باشد.');
});

it('uses date-only jalali fields and preserves pagination and export query strings', function (): void {
    $view = file_get_contents(resource_path('views/purchases/index.blade.php'));

    expect($view)->toContain('name="date_from_fa"')
        ->and($view)->toContain('name="date_to_fa"')
        ->and($view)->toContain('name="date_from"')
        ->and($view)->toContain('name="date_to"')
        ->and($view)->toContain('data-jdp-only-date')
        ->and($view)->not->toContain('type="date" name="date_from"')
        ->and($view)->toContain("route('purchases.export', request()->query())")
        ->and($view)->toContain("appends(request()->except('page'))");
});

it('applies full-day boundaries in list and excel queries', function (): void {
    $controller = file_get_contents(app_path('Http/Controllers/PurchaseController.php'));
    $export = file_get_contents(app_path('Exports/PurchasesExport.php'));

    expect($controller)->toContain("Carbon::parse(\$dateFrom)->startOfDay()")
        ->and($controller)->toContain("Carbon::parse(\$dateTo)->endOfDay()")
        ->and($export)->toContain("Carbon::parse(\$this->filters['date_from'])->startOfDay()")
        ->and($export)->toContain("Carbon::parse(\$this->filters['date_to'])->endOfDay()");
});
