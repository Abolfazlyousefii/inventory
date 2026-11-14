<?php

namespace App\Services\ExchangeRates;

use App\Models\CnyExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Never changes catalog prices: only imports validated, historicized CNY quotes. */
final class NavasanRateFetcher
{
    /** @return array{status:string,rate:CnyExchangeRate} */
    public function fetch(): array
    {
        if (!config('navasan.enabled')) {
            throw new RuntimeException('navasan_disabled');
        }
        $apiKey = config('navasan.api_key');
        $url = config('navasan.url');
        $unit = config('navasan.response_unit');
        // Prevent exposing the key to an arbitrary host or over unencrypted HTTP.
        if (!is_string($apiKey) || $apiKey === '' || !is_string($url)
            || !in_array(parse_url($url, PHP_URL_HOST), ['api.navasan.tech', 'api.navasan.net'], true)
            || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_PATH) !== '/latest/'
            || parse_url($url, PHP_URL_QUERY) !== null
            || config('navasan.symbol') !== 'cny'
            || !in_array($unit, ['rial', 'toman'], true)
            || (int) config('navasan.max_quote_age_minutes') < 1
            || (int) config('navasan.monthly_rolling_limit') < 1
            || (int) config('navasan.monthly_rolling_limit') > 90
        ) {
            throw new RuntimeException('navasan_configuration_unconfirmed');
        }

        $lock = Cache::lock('exchange-rates:navasan:cny', 40);
        if (!$lock->get()) {
            throw new RuntimeException('navasan_fetch_already_running');
        }

        try {
            $attemptId = $this->reserveRequest();
            try {
                // No automatic HTTP retry: every failed call may count against free quota.
                // The request URL (which includes the API key) must never be logged.
                $response = Http::acceptJson()->connectTimeout(3)->timeout(8)
                    ->withOptions(['allow_redirects' => false])
                    ->get($url, ['api_key' => $apiKey, 'item' => 'cny']);
            } catch (Throwable) {
                $this->finishAttempt($attemptId, 'failed', 'transport');
                Log::warning('NAVASAN_FETCH_FAILED', ['code' => 'transport']);
                throw new RuntimeException('navasan_transport_failure');
            }

            if (!$response->successful()) {
                $code = in_array($response->status(), [429, 503], true) ? 'quota_or_rate_limited' : 'http_failure';
                $this->finishAttempt($attemptId, 'failed', $code);
                Log::warning('NAVASAN_FETCH_FAILED', ['code' => $code]);
                throw new RuntimeException('navasan_'.$code);
            }

            try {
                $body = $response->json();
                if (!is_array($body)) {
                    throw new InvalidArgumentException('navasan_invalid_json');
                }
                $quote = app(NavasanQuoteParser::class)->parse(
                    $body, 'cny', $unit, now()->timestamp,
                    (int) config('navasan.max_quote_age_minutes') * 60
                );
                $result = $this->storeQuote($quote);
                $this->finishAttempt($attemptId, 'success', null);
                return $result;
            } catch (InvalidArgumentException $e) {
                // Parser exception messages are fixed internal codes, not provider content.
                $this->finishAttempt($attemptId, 'rejected', 'invalid_or_unsafe_quote');
                Log::warning('NAVASAN_RATE_REJECTED', ['code' => $e->getMessage()]);
                throw new RuntimeException('navasan_quote_rejected');
            } catch (RuntimeException $e) {
                $this->finishAttempt($attemptId, 'rejected', 'invalid_or_unsafe_quote');
                Log::warning('NAVASAN_RATE_REJECTED', ['code' => $e->getMessage()]);
                throw new RuntimeException('navasan_quote_rejected');
            }
        } finally {
            $lock->release();
        }
    }

    private function reserveRequest(): int
    {
        // Reserve BEFORE the HTTP call. A timed-out request may still consume quota.
        // The single locked row makes rolling-window quota checks atomic on MySQL/Postgres.
        return DB::transaction(function (): int {
            DB::table('navasan_api_guards')->where('provider', 'navasan')->lockForUpdate()->first()
                ?? throw new RuntimeException('navasan_migration_missing');
            $used = DB::table('navasan_api_requests')
                ->where('attempted_at', '>', now()->subDays(31))->count();
            if ($used >= (int) config('navasan.monthly_rolling_limit')) {
                throw new RuntimeException('navasan_rolling_quota_exhausted');
            }
            return (int) DB::table('navasan_api_requests')->insertGetId([
                'attempted_at' => now(), 'status' => 'started',
            ]);
        }, 3);
    }

    private function finishAttempt(int $id, string $status, ?string $code): void
    {
        DB::table('navasan_api_requests')->where('id', $id)->update([
            'status' => $status, 'failure_code' => $code,
        ]);
    }

    /** @param array{rate_rial:int,timestamp:int} $quote */
    private function storeQuote(array $quote): array
    {
        return DB::transaction(function () use ($quote): array {
            // Match Laravel's configured date serialization timezone, preserving
            // the original Unix instant when Eloquent reads quoted_at again.
            $quotedAt = CarbonImmutable::createFromTimestamp($quote['timestamp'], config('app.timezone', 'UTC'));
            DB::table('navasan_api_guards')->where('provider', 'navasan')->lockForUpdate()->first()
                ?? throw new RuntimeException('navasan_migration_missing');
            $latest = CnyExchangeRate::where('provider', 'navasan')->where('symbol', 'cny')
                ->orderByDesc('quoted_at')->orderByDesc('id')->lockForUpdate()->first();
            if ($latest && $latest->quoted_at->timestamp > $quote['timestamp']) {
                throw new RuntimeException('navasan_out_of_order_quote');
            }
            if ($latest && $latest->quoted_at->timestamp === $quote['timestamp']) {
                if ((int) $latest->rate_rial !== $quote['rate_rial']) {
                    throw new RuntimeException('navasan_conflicting_quote');
                }
                return ['status' => 'unchanged', 'rate' => $latest];
            }
            $limit = (int) config('navasan.max_change_percent');
            if ($limit < 1 || $limit > 100) {
                throw new RuntimeException('navasan_invalid_change_guard');
            }
            if ($latest && abs($quote['rate_rial'] - (int) $latest->rate_rial) * 100
                    > (int) $latest->rate_rial * $limit) {
                throw new RuntimeException('navasan_abnormal_rate_change');
            }
            // Unique index + guard row prevent the same source quote being inserted
            // twice by separate workers. Ignore only uniqueness conflicts.
            $inserted = DB::table('cny_exchange_rates')->insertOrIgnore([
                'provider' => 'navasan', 'symbol' => 'cny',
                'rate_rial' => $quote['rate_rial'], 'quoted_at' => $quotedAt,
                'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $rate = CnyExchangeRate::where('provider', 'navasan')->where('symbol', 'cny')
                ->where('quoted_at', $quotedAt)->first();
            if (!$rate || (int) $rate->rate_rial !== $quote['rate_rial']) {
                throw new RuntimeException('navasan_conflicting_quote');
            }
            return ['status' => $inserted ? 'stored' : 'unchanged', 'rate' => $rate];
        }, 3);
    }
}
