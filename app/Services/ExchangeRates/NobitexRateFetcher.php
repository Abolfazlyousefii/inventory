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

/** Read-only Nobitex market fetch; CNY conversion is a flagged USD/CNY proxy. */
final class NobitexRateFetcher
{
    private const PROVIDER = 'nobitex_usdt_proxy';
    private const URL = 'https://apiv2.nobitex.ir/v3/orderbook/USDTIRT';
    private const FX_URL = 'https://api.frankfurter.dev/v2/rate/usd/cny';

    /** @return array{status:string,rate:CnyExchangeRate} */
    public function fetch(): array
    {
        if (!config('nobitex.enabled')) {
            throw new RuntimeException('nobitex_disabled');
        }
        // Never allow a configured alternate host/market to silently change units.
        if (config('nobitex.url') !== self::URL || config('nobitex.market') !== 'USDTIRT'
            || config('nobitex.fx_url') !== self::FX_URL
            || (int) config('nobitex.max_quote_age_minutes') < 1
            || (int) config('nobitex.max_quote_age_minutes') > 30
            || (int) config('nobitex.max_fx_age_hours') < 1
            || (int) config('nobitex.max_fx_age_hours') > 168
            || (int) config('nobitex.max_spread_percent') < 1
            || (int) config('nobitex.max_spread_percent') > 10) {
            throw new RuntimeException('nobitex_configuration_unconfirmed');
        }

        $lock = Cache::lock('exchange-rates:nobitex:usdtirt', 40);
        if (!$lock->get()) {
            throw new RuntimeException('nobitex_fetch_already_running');
        }
        try {
            $parser = app(NobitexQuoteParser::class);
            // Use a second free/public feed for USD/CNY. Nobitex itself has no
            // matching CNY market; never reuse a cached operator-entered ratio.
            $fxBody = $this->getJson(self::FX_URL, 'fx');
            try {
                $fx = $parser->parseFxReference($fxBody, now()->timestamp,
                    (int) config('nobitex.max_fx_age_hours') * 3600);
            } catch (InvalidArgumentException $e) {
                Log::warning('NOBITEX_FX_REJECTED', ['code' => $e->getMessage()]);
                throw new RuntimeException('nobitex_fx_reference_rejected');
            }
            $body = $this->getJson(self::URL, 'market');
            try {
                $quote = $parser->parse($body, $fx['ratio'], $fx['verified_at'],
                    now()->timestamp,
                    (int) config('nobitex.max_quote_age_minutes') * 60,
                    (int) config('nobitex.max_fx_age_hours') * 3600,
                    (int) config('nobitex.max_spread_percent'));
                $result = $this->storeQuote($quote);
                // Keep the source basis observable without rewriting rate history.
                Log::info('NOBITEX_CNY_RATE_SOURCE', [
                    'fx_source' => 'frankfurter', 'fx_date' => $fx['date'],
                    'usd_cny' => $fx['ratio'], 'orderbook_time' => $quote['timestamp'],
                    'rate_id' => $result['rate']->id, 'status' => $result['status'],
                ]);
                return $result;
            } catch (InvalidArgumentException $e) {
                Log::warning('NOBITEX_QUOTE_REJECTED', ['code' => $e->getMessage()]);
                throw new RuntimeException('nobitex_quote_rejected');
            }
        } finally {
            $lock->release();
        }
    }

    /** Fetch a public JSON response, without ever logging response bodies or URLs. */
    private function getJson(string $url, string $source): array
    {
        try {
            $response = Http::acceptJson()->withHeaders(['User-Agent' => 'Inventory-CNY/1.0'])
                ->connectTimeout(3)->timeout(8)->withOptions(['allow_redirects' => false])
                ->get($url);
        } catch (Throwable) {
            Log::warning('CNY_RATE_FETCH_FAILED', ['source' => $source, 'code' => 'transport']);
            throw new RuntimeException('cny_'.$source.'_transport_failure');
        }
        if (!$response->successful()) {
            $code = $response->status() === 429 ? 'rate_limited' : 'http_failure';
            Log::warning('CNY_RATE_FETCH_FAILED', ['source' => $source, 'code' => $code]);
            throw new RuntimeException('cny_'.$source.'_'.$code);
        }
        $json = $response->json();
        if (!is_array($json)) {
            throw new RuntimeException('cny_'.$source.'_json_invalid');
        }
        return $json;
    }

    /** @param array{rate_rial:int,timestamp:int} $quote */
    private function storeQuote(array $quote): array
    {
        return DB::transaction(function () use ($quote): array {
            $quotedAt = CarbonImmutable::createFromTimestamp($quote['timestamp'], config('app.timezone', 'UTC'));
            $latest = CnyExchangeRate::where('provider', self::PROVIDER)->where('symbol', 'cny')
                ->orderByDesc('quoted_at')->orderByDesc('id')->lockForUpdate()->first();
            if ($latest && $latest->quoted_at->timestamp > $quote['timestamp']) {
                throw new RuntimeException('nobitex_out_of_order_quote');
            }
            if ($latest && $latest->quoted_at->timestamp === $quote['timestamp']) {
                if ((int) $latest->rate_rial !== $quote['rate_rial']) {
                    throw new RuntimeException('nobitex_conflicting_quote');
                }
                return ['status' => 'unchanged', 'rate' => $latest];
            }
            $limit = (int) config('nobitex.max_change_percent');
            if ($limit < 1 || $limit > 100) {
                throw new RuntimeException('nobitex_invalid_change_guard');
            }
            if ($latest && abs($quote['rate_rial'] - (int) $latest->rate_rial) * 100
                > (int) $latest->rate_rial * $limit) {
                throw new RuntimeException('nobitex_abnormal_rate_change');
            }
            // Reuse the existing unique provider/symbol/timestamp index. Old rates
            // and purchases retain their historical provider and original rate IDs.
            $inserted = DB::table('cny_exchange_rates')->insertOrIgnore([
                'provider' => self::PROVIDER, 'symbol' => 'cny',
                'rate_rial' => $quote['rate_rial'], 'quoted_at' => $quotedAt,
                'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $rate = CnyExchangeRate::where('provider', self::PROVIDER)->where('symbol', 'cny')
                ->where('quoted_at', $quotedAt)->first();
            if (!$rate || (int) $rate->rate_rial !== $quote['rate_rial']) {
                throw new RuntimeException('nobitex_conflicting_quote');
            }
            return ['status' => $inserted ? 'stored' : 'unchanged', 'rate' => $rate];
        }, 3);
    }
}
