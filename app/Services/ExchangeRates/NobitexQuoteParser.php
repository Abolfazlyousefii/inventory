<?php

namespace App\Services\ExchangeRates;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Converts a Nobitex USDT/IRR orderbook midpoint into an *estimated* IRR/CNY.
 * The USD/CNY reference is fetched automatically from Frankfurter, not Nobitex.
 * This is a proxy (USDT is not guaranteed to equal USD), not a bank/remittance quote.
 */
final class NobitexQuoteParser
{
    /** @return array{rate_rial:int,timestamp:int} */
    public function parse(array $body, string $cnyPerUsdt, string $verifiedAt, int $now,
        int $maxAgeSeconds, int $maxFxAgeSeconds, int $maxSpreadPercent): array
    {
        $ratioUnits = $this->validatedRatioUnits($cnyPerUsdt, $verifiedAt, $now, $maxFxAgeSeconds);
        if (($body['status'] ?? null) !== 'ok'
            || !isset($body['bids'][0][0], $body['asks'][0][0])
            || !is_array($body['bids'][0]) || !is_array($body['asks'][0])) {
            throw new InvalidArgumentException('nobitex_orderbook_invalid');
        }
        $bid = $this->positivePrice($body['bids'][0][0]);
        $ask = $this->positivePrice($body['asks'][0][0]);
        if ($ask < $bid || $maxSpreadPercent < 1 || $maxSpreadPercent > 10) {
            throw new InvalidArgumentException('nobitex_orderbook_crossed_or_unsafe');
        }
        $midpoint = intdiv($bid + $ask + 1, 2);
        if (($ask - $bid) * 100 > $midpoint * $maxSpreadPercent) {
            throw new InvalidArgumentException('nobitex_orderbook_spread_too_wide');
        }
        $rawTimestamp = $body['lastUpdate'] ?? null;
        if (!(is_int($rawTimestamp) || is_string($rawTimestamp))
            || !preg_match('/^[0-9]{13}$/D', (string) $rawTimestamp)) {
            throw new InvalidArgumentException('nobitex_orderbook_timestamp_invalid');
        }
        $timestamp = intdiv((int) $rawTimestamp, 1000);
        if ($maxAgeSeconds < 1 || $timestamp > $now || $timestamp < $now - $maxAgeSeconds) {
            throw new InvalidArgumentException('nobitex_orderbook_stale_or_future');
        }
        // All calculations are integer-only (round half up). The orderbook prices
        // are IRR/USDT, not toman, and must not be multiplied by another factor of 10.
        $rate = intdiv($midpoint * 1000000 + intdiv($ratioUnits, 2), $ratioUnits);
        if ($rate < 1 || $rate > 100000000) {
            throw new InvalidArgumentException('nobitex_cny_rate_out_of_range');
        }
        return ['rate_rial' => $rate, 'timestamp' => $timestamp];
    }

    /**
     * Verify a keyless Frankfurter v2 USD/CNY reference. The provider supplies only
     * a publication DATE; treat 00:00 UTC as its conservative age anchor.
     * @return array{ratio:string,verified_at:string,date:string}
     */
    public function parseFxReference(array $body, int $now, int $maxAgeSeconds): array
    {
        if (($body['base'] ?? null) !== 'USD' || ($body['quote'] ?? null) !== 'CNY'
            || !is_string($body['date'] ?? null)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $body['date'])
            || !is_numeric($body['rate'] ?? null)
            || !is_int($body['rate']) && !is_float($body['rate']) && !is_string($body['rate'])) {
            throw new InvalidArgumentException('fx_reference_invalid');
        }
        $sourceDate = DateTimeImmutable::createFromFormat('!Y-m-d', $body['date'], new DateTimeZone('UTC'));
        if (!$sourceDate || $sourceDate->format('Y-m-d') !== $body['date']
            || $sourceDate->getTimestamp() > $now
            || $maxAgeSeconds < 1 || $now - $sourceDate->getTimestamp() > $maxAgeSeconds) {
            throw new InvalidArgumentException('fx_reference_stale_or_future');
        }
        $raw = (float) $body['rate'];
        if (!is_finite($raw) || $raw < 4 || $raw > 12) {
            throw new InvalidArgumentException('fx_reference_rate_out_of_range');
        }
        $ratio = number_format($raw, 6, '.', '');
        $verifiedAt = $sourceDate->format('Y-m-d\TH:i:sP');
        $this->validatedRatioUnits($ratio, $verifiedAt, $now, $maxAgeSeconds);
        return ['ratio' => $ratio, 'verified_at' => $verifiedAt, 'date' => $body['date']];
    }

    /** Validate the independently fetched FX ratio. */
    public function validatedRatioUnits(string $cnyPerUsdt, string $verifiedAt, int $now, int $maxFxAgeSeconds): int
    {
        if (!preg_match('/^[0-9]{1,2}(?:\.[0-9]{1,6})?$/D', $cnyPerUsdt)) {
            throw new InvalidArgumentException('nobitex_fx_ratio_invalid');
        }
        [$whole, $fraction] = array_pad(explode('.', $cnyPerUsdt, 2), 2, '');
        $ratioUnits = (int) $whole * 1000000 + (int) str_pad($fraction, 6, '0');
        if ($ratioUnits < 4000000 || $ratioUnits > 12000000) {
            throw new InvalidArgumentException('nobitex_fx_ratio_out_of_range');
        }
        // Require a timezone-qualified ISO-8601 instant to avoid silent timezone shifts.
        if (!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:Z|[+-]\d\d:\d\d)$/D', $verifiedAt)) {
            throw new InvalidArgumentException('nobitex_fx_verification_missing');
        }
        $verified = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $verifiedAt);
        if (!$verified || $verified->getTimestamp() > $now
            || $verified->getTimestamp() < $now - $maxFxAgeSeconds || $maxFxAgeSeconds < 1) {
            throw new InvalidArgumentException('nobitex_fx_verification_stale');
        }
        return $ratioUnits;
    }

    private function positivePrice(mixed $raw): int
    {
        if (!(is_int($raw) || is_string($raw))
            || !preg_match('/^[0-9]{1,10}$/D', (string) $raw)) {
            throw new InvalidArgumentException('nobitex_orderbook_price_invalid');
        }
        $price = (int) $raw;
        if ($price < 1000 || $price > 100000000) {
            throw new InvalidArgumentException('nobitex_orderbook_price_out_of_range');
        }
        return $price;
    }
}
