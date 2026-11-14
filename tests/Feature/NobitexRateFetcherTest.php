<?php

use App\Models\CnyExchangeRate;
use App\Services\CnyPricingService;
use App\Services\ExchangeRates\NobitexRateFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'nobitex.enabled' => true,
        'cny_pricing.enabled' => true,
        'cny_pricing.allow_sample' => false,
        'cny_pricing.production_mode' => true,
        'navasan.enabled' => false,
    ]);
});

function nobitexOrderbook(?int $seconds = null, int $bid = 2800000, int $ask = 2880000): array
{
    return [
        'status' => 'ok', 'lastUpdate' => ($seconds ?? now()->subMinute()->timestamp) * 1000,
        'bids' => [[(string) $bid, '10']], 'asks' => [[(string) $ask, '10']],
    ];
}


function frankfurterCnyRate(?string $date = null, float $rate = 7.1): array
{
    return ['date' => $date ?? now('UTC')->format('Y-m-d'),
        'base' => 'USD', 'quote' => 'CNY', 'rate' => $rate];
}

function stubCnyFeeds(array $orderbook, ?array $reference = null, int $marketStatus = 200, int $fxStatus = 200): void
{
    Http::fake([
        'api.frankfurter.dev/*' => Http::response($reference ?? frankfurterCnyRate(), $fxStatus),
        'apiv2.nobitex.ir/*' => Http::response($orderbook, $marketStatus),
    ]);
}

it('imports a properly timestamped proxy rate without changing purchases or prices', function () {
    $time = now()->subMinutes(2)->timestamp;
    stubCnyFeeds(nobitexOrderbook($time));
    $result = app(NobitexRateFetcher::class)->fetch();
    expect($result['status'])->toBe('stored')
        ->and($result['rate']->provider)->toBe('nobitex_usdt_proxy')
        ->and($result['rate']->symbol)->toBe('cny')
        ->and($result['rate']->rate_rial)->toBe(400000)
        ->and($result['rate']->quoted_at->timestamp)->toBe($time)
        ->and($result['rate']->fetched_at)->not->toBeNull()
        ->and(DB::table('cny_price_revisions')->count())->toBe(0)
        ->and(app(CnyPricingService::class)->currentRate()->id)->toBe($result['rate']->id);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->url() === 'https://api.frankfurter.dev/v2/rate/usd/cny'
        && $request->method() === 'GET' && !$request->hasHeader('Authorization'));
    Http::assertSent(fn ($request) => $request->url() === 'https://apiv2.nobitex.ir/v3/orderbook/USDTIRT'
        && $request->method() === 'GET' && !$request->hasHeader('Authorization')
        && !$request->hasHeader('Nobitex-Key'));
});

it('does not reinsert an unchanged source timestamp', function () {
    stubCnyFeeds(nobitexOrderbook());
    $fetcher = app(NobitexRateFetcher::class);
    expect($fetcher->fetch()['status'])->toBe('stored')
        ->and($fetcher->fetch()['status'])->toBe('unchanged')
        ->and(CnyExchangeRate::count())->toBe(1);
});

it('rejects unsafe conversion settings before making an HTTP request', function ($settings) {
    config($settings);
    Http::fake();
    expect(fn () => app(NobitexRateFetcher::class)->fetch())->toThrow(RuntimeException::class);
    Http::assertNothingSent();
})->with([
    [['nobitex.fx_url' => 'http://example.com']],
    [['nobitex.max_fx_age_hours' => 999]],
    [['nobitex.url' => 'http://apiv2.nobitex.ir/v3/orderbook/USDTIRT']],
    [['nobitex.market' => 'CNHTIRT']],
]);

it('rejects malformed, stale, crossed or overly wide orderbooks', function ($body) {
    stubCnyFeeds($body);
    expect(fn () => app(NobitexRateFetcher::class)->fetch())->toThrow(RuntimeException::class)
        ->and(CnyExchangeRate::count())->toBe(0);
})->with([
    [['status' => 'failed']],
    [nobitexOrderbook(time() - 7200)],
    [nobitexOrderbook(time() + 3600)],
    [nobitexOrderbook(null, 2900000, 2800000)],
    [nobitexOrderbook(null, 2000000, 2900000)],
]);

it('rejects large jumps while retaining the prior accepted rate', function () {
    $time = now()->subMinutes(4)->timestamp;
    Http::fake([
        'api.frankfurter.dev/*' => Http::response(frankfurterCnyRate()),
        'apiv2.nobitex.ir/*' => Http::sequence()
            ->push(nobitexOrderbook($time))
            ->push(nobitexOrderbook($time + 120, 4200000, 4280000)),
    ]);
    $fetcher = app(NobitexRateFetcher::class);
    $fetcher->fetch();
    expect(fn () => $fetcher->fetch())->toThrow(RuntimeException::class)
        ->and(CnyExchangeRate::count())->toBe(1)
        ->and(CnyExchangeRate::sole()->rate_rial)->toBe(400000);
});

it('does not fall back to newer Navasan quotes in Nobitex mode', function () {
    CnyExchangeRate::create(['provider' => 'navasan', 'symbol' => 'cny', 'rate_rial' => 100000,
        'quoted_at' => now()]);
    stubCnyFeeds(nobitexOrderbook(now()->subMinute()->timestamp));
    $result = app(NobitexRateFetcher::class)->fetch();
    expect(app(CnyPricingService::class)->currentRate()->id)->toBe($result['rate']->id);
});

it('does not accept Navasan as a fallback when Nobitex has not fetched anything', function () {
    CnyExchangeRate::create(['provider' => 'navasan', 'symbol' => 'cny', 'rate_rial' => 400000,
        'quoted_at' => now()]);
    expect(fn () => app(CnyPricingService::class)->currentRate())->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('keeps rate history unchanged on HTTP 429', function () {
    stubCnyFeeds(['code' => 'TooManyRequests'], null, 429);
    expect(fn () => app(NobitexRateFetcher::class)->fetch())->toThrow(RuntimeException::class)
        ->and(CnyExchangeRate::count())->toBe(0);
});

it('fails closed on missing, reversed or very old USD/CNY reference data', function ($reference) {
    stubCnyFeeds(nobitexOrderbook(), $reference);
    expect(fn () => app(NobitexRateFetcher::class)->fetch())->toThrow(RuntimeException::class)
        ->and(CnyExchangeRate::count())->toBe(0);
    // The market price is not read after unsafe FX data.
    Http::assertSentCount(1);
})->with([
    [['date' => '2020-01-01', 'base' => 'USD', 'quote' => 'CNY', 'rate' => 7.1]],
    [['date' => now('UTC')->format('Y-m-d'), 'base' => 'CNY', 'quote' => 'USD', 'rate' => 0.14]],
    [['date' => now('UTC')->format('Y-m-d'), 'base' => 'USD', 'quote' => 'CNY', 'rate' => -5]],
]);

it('does not overwrite prior valid quotes if the FX API fails', function () {
    // One fake registration with sequential FX responses: success, then HTTP 429.
    // Re-registering Http::fake() can leave earlier matching stubs in place.
    Http::fake([
        'api.frankfurter.dev/*' => Http::sequence()
            ->push(frankfurterCnyRate(), 200)
            ->push(['error' => 'rate_limited'], 429),
        'apiv2.nobitex.ir/*' => Http::response(nobitexOrderbook(), 200),
    ]);

    $fetcher = app(NobitexRateFetcher::class);
    expect($fetcher->fetch()['status'])->toBe('stored');

    expect(fn () => $fetcher->fetch())->toThrow(RuntimeException::class, 'cny_fx_rate_limited')
        ->and(CnyExchangeRate::count())->toBe(1);
    // First fetch reads FX and market; the second stops after the rejected FX reply.
    Http::assertSentCount(3);
});
