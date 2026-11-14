<?php

namespace App\Services\ExchangeRates;

use InvalidArgumentException;

/** Strictly parse the documented latest quote without guessing its monetary unit. */
final class NavasanQuoteParser
{
    /** @return array{rate_rial:int,timestamp:int} */
    public function parse(array $payload, string $symbol, string $unit, int $now, int $maxAgeSeconds): array
    {
        if ($symbol !== 'cny' || !in_array($unit, ['rial', 'toman'], true)) {
            throw new InvalidArgumentException('navasan_symbol_or_unit_unconfirmed');
        }

        // Navasan documents {symbol:{value,timestamp}}, but item= may return
        // {value,timestamp} on some subscriptions. Never accept another symbol.
        if ((isset($payload['symbol']) && $payload['symbol'] !== 'cny')
            || (isset($payload['item']) && $payload['item'] !== 'cny')) {
            throw new InvalidArgumentException('navasan_symbol_mismatch');
        }
        if (array_key_exists('cny', $payload)) {
            $payload = $payload['cny'];
        } elseif (!array_key_exists('value', $payload)) {
            throw new InvalidArgumentException('navasan_symbol_missing');
        } else {
            // A direct item=cny response may contain rate metadata, but never
            // another currency. Reject mixed or ambiguous response envelopes.
            foreach (array_keys($payload) as $field) {
                if (!in_array($field, ['value', 'timestamp', 'date', 'change', 'item', 'symbol'], true)) {
                    throw new InvalidArgumentException('navasan_unexpected_response_field');
                }
            }
        }
        if (!is_array($payload) || ((isset($payload['item']) && $payload['item'] !== 'cny')
            || (isset($payload['symbol']) && $payload['symbol'] !== 'cny'))) {
            throw new InvalidArgumentException('navasan_symbol_mismatch');
        }

        $raw = $payload['value'] ?? null;
        if (!(is_int($raw) || is_string($raw)) || !preg_match('/^[0-9]{1,10}$/D', (string) $raw)) {
            throw new InvalidArgumentException('navasan_invalid_value');
        }
        $value = (int) $raw;
        $rate = $unit === 'toman' ? $value * 10 : $value;
        if ($rate < 1 || $rate > 100000000) {
            throw new InvalidArgumentException('navasan_rate_out_of_range');
        }

        $rawTimestamp = $payload['timestamp'] ?? null;
        if (!(is_int($rawTimestamp) || is_string($rawTimestamp)) || !preg_match('/^[0-9]{10}$/D', (string) $rawTimestamp)) {
            throw new InvalidArgumentException('navasan_invalid_timestamp');
        }
        $timestamp = (int) $rawTimestamp;
        if ($timestamp > $now || $timestamp < $now - $maxAgeSeconds) {
            throw new InvalidArgumentException('navasan_stale_or_future_quote');
        }

        return ['rate_rial' => $rate, 'timestamp' => $timestamp];
    }
}
