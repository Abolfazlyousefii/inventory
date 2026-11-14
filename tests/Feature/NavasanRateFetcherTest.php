<?php

use App\Models\CnyExchangeRate;
use App\Services\ExchangeRates\NavasanRateFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'navasan.enabled' => true,
        'navasan.api_key' => 'unit-test-key-only',
        'navasan.response_unit' => 'rial',
        'navasan.monthly_rolling_limit' => 90,
        'navasan.max_quote_age_minutes' => 660,
        'navasan.max_change_percent' => 15,
    ]);
});

function fakeNavasanQuote(int $value, ?int $time = null): void
{
    Http::fake(['api.navasan.tech/*' => Http::response([
        'cny' => ['value' => (string) $value, 'timestamp' => $time ?? now()->timestamp],
    ], 200)]);
}

it('imports real CNY source timestamp in rial, without touching existing product or purchase data', function () {
    // Capture once: calling now() twice can cross a second boundary on slower hosts.
    $sourceTime = now()->subMinutes(5)->timestamp;
    fakeNavasanQuote(400000, $sourceTime);
    $result = app(NavasanRateFetcher::class)->fetch();
    expect($result['status'])->toBe('stored')->and($result['rate']->provider)->toBe('navasan')
        ->and($result['rate']->symbol)->toBe('cny')->and($result['rate']->rate_rial)->toBe(400000)
        ->and($result['rate']->quoted_at->timestamp)->toBe($sourceTime)
        ->and($result['rate']->fetched_at)->not->toBeNull()
        ->and(DB::table('cny_price_revisions')->count())->toBe(0)
        ->and(DB::table('navasan_api_requests')->count())->toBe(1);
    Http::assertSentCount(1);
});

it('does not invent a new quote timestamp or duplicate rate history for a repeated source quote', function () {
    fakeNavasanQuote(400000, now()->subMinutes(2)->timestamp);
    expect(app(NavasanRateFetcher::class)->fetch()['status'])->toBe('stored')
        ->and(app(NavasanRateFetcher::class)->fetch()['status'])->toBe('unchanged')
        ->and(CnyExchangeRate::count())->toBe(1)
        ->and(DB::table('navasan_api_requests')->count())->toBe(2);
});

it('rejects unverified units before sending a network request', function () {
    config(['navasan.response_unit' => 'unverified']);
    Http::fake();
    expect(fn () => app(NavasanRateFetcher::class)->fetch())->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('preserves the last accepted quote on missing values, stale timestamps, and large jumps', function ($case) {
    $existing = CnyExchangeRate::create([
        'provider' => 'navasan', 'symbol' => 'cny', 'rate_rial' => 400000,
        'quoted_at' => now()->subMinutes(20), 'fetched_at' => now()->subMinutes(19),
    ]);
    if ($case === 'jump') fakeNavasanQuote(700000);
    elseif ($case === 'stale') fakeNavasanQuote(410000, now()->subDay()->timestamp);
    else Http::fake(['api.navasan.tech/*' => Http::response(['usd' => ['value' => '400000', 'timestamp' => now()->timestamp]])]);
    expect(fn () => app(NavasanRateFetcher::class)->fetch())->toThrow(RuntimeException::class);
    expect(CnyExchangeRate::count())->toBe(1)->and($existing->fresh()->rate_rial)->toBe(400000)
        ->and(DB::table('navasan_api_requests')->where('status', 'rejected')->count())->toBe(1);
})->with(['jump', 'stale', 'symbol']);

it('respects the free rolling quota even after requests failed', function () {
    config(['navasan.monthly_rolling_limit' => 1]);
    Http::fake(['api.navasan.tech/*' => Http::response(['error' => 'limited'], 429)]);
    expect(fn () => app(NavasanRateFetcher::class)->fetch())->toThrow(RuntimeException::class);
    expect(fn () => app(NavasanRateFetcher::class)->fetch())->toThrow(RuntimeException::class, 'navasan_rolling_quota_exhausted');
    expect(DB::table('navasan_api_requests')->count())->toBe(1);
    Http::assertSentCount(1);
});

it('refuses plaintext HTTP endpoint without contacting it', function () {
    config(['navasan.url' => 'http://api.navasan.tech/latest/']);
    Http::fake();
    expect(fn () => app(NavasanRateFetcher::class)->fetch())->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('converts a confirmed toman quote to IRR before persisting it', function () {
    config(['navasan.response_unit' => 'toman']);
    fakeNavasanQuote(40000, now()->subMinute()->timestamp);
    $rate = app(NavasanRateFetcher::class)->fetch()['rate'];
    expect($rate->rate_rial)->toBe(400000);
});

it('rejects out of order and conflicting quotes without replacing an accepted quote', function ($case) {
    $sourceTime = now()->subMinutes(10)->timestamp;
    // Import the accepted quote via the same production pathway as the second
    // quote. Manually inserted datetime fixtures can hide timezone round trips.
    Http::fakeSequence()
        ->push(['cny' => ['value' => '400000', 'timestamp' => $sourceTime]], 200)
        ->push(['cny' => [
            'value' => $case === 'conflicting' ? '410000' : '400000',
            'timestamp' => $case === 'out_of_order' ? $sourceTime - 60 : $sourceTime,
        ]], 200);

    $fetcher = app(NavasanRateFetcher::class);
    $accepted = $fetcher->fetch();
    expect($accepted['status'])->toBe('stored')
        ->and($accepted['rate']->quoted_at->timestamp)->toBe($sourceTime);

    expect(fn () => $fetcher->fetch())->toThrow(RuntimeException::class)
        ->and(CnyExchangeRate::count())->toBe(1)
        ->and(CnyExchangeRate::sole()->rate_rial)->toBe(400000)
        ->and(DB::table('navasan_api_requests')->where('status', 'rejected')->count())->toBe(1);
    Http::assertSentCount(2);
})->with(['out_of_order', 'conflicting']);

it('counts provider 503 responses as quota failures without changing rates', function () {
    Http::fake(['api.navasan.tech/*' => Http::response(['message' => 'quota reached'], 503)]);
    expect(fn () => app(NavasanRateFetcher::class)->fetch())->toThrow(RuntimeException::class);
    expect(CnyExchangeRate::count())->toBe(0)
        ->and(DB::table('navasan_api_requests')->where('failure_code', 'quota_or_rate_limited')->count())->toBe(1);
});
