<?php
// No Composer dependencies: php tests/standalone/nobitex-quote-parser.php
require __DIR__.'/../../app/Services/ExchangeRates/NobitexQuoteParser.php';

use App\Services\ExchangeRates\NobitexQuoteParser;

function rejectsNobitex(callable $run): void
{
    try { $run(); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException('Unsafe Nobitex quote was incorrectly accepted');
}

$parser = new NobitexQuoteParser();
$now = 1794400000;
$timestampMs = ($now - 120) * 1000;
$payload = [
    'status' => 'ok',
    'lastUpdate' => $timestampMs,
    'bids' => [['2800000', '30']],
    'asks' => [['2880000', '20']],
];
// Midpoint 2,840,000 IRR/USDT / 7.1 USD/CNY = 400,000 IRR/CNY.
assert($parser->parse($payload, '7.1', gmdate('c', $now - 300), $now, 900, 86400, 4)
    === ['rate_rial' => 400000, 'timestamp' => $now - 120]);
$bad = $payload;
$bad['bids'][0][0] = '0';
rejectsNobitex(fn () => $parser->parse($bad, '7.1', gmdate('c', $now - 300), $now, 900, 86400, 4));
$bad = $payload;
$bad['lastUpdate'] = ($now - 2000) * 1000;
rejectsNobitex(fn () => $parser->parse($bad, '7.1', gmdate('c', $now - 300), $now, 900, 86400, 4));
$bad = $payload;
$bad['asks'][0][0] = '3500000';
rejectsNobitex(fn () => $parser->parse($bad, '7.1', gmdate('c', $now - 300), $now, 900, 86400, 4));
rejectsNobitex(fn () => $parser->parse($payload, '7.1', gmdate('c', $now - 90000), $now, 900, 86400, 4));
rejectsNobitex(fn () => $parser->parse($payload, '0', gmdate('c', $now - 300), $now, 900, 86400, 4));
rejectsNobitex(fn () => $parser->parse($payload, '7.1', '', $now, 900, 86400, 4));
rejectsNobitex(fn () => $parser->parse($payload, '7.1', gmdate('c', $now + 60), $now, 900, 86400, 4));
// Frankfurter's USD/CNY response is a daily reference rate, not a USDT quote.
$fx = ['date' => '2026-11-13', 'base' => 'USD', 'quote' => 'CNY', 'rate' => 7.1];
$checkAt = strtotime('2026-11-14T12:00:00Z');
assert($parser->parseFxReference($fx, $checkAt, 120 * 3600)['ratio'] === '7.100000');
foreach ([
    ['date' => '2026-11-13', 'base' => 'EUR', 'quote' => 'CNY', 'rate' => 7.1],
    ['date' => '2026-11-13', 'base' => 'USD', 'quote' => 'CNY', 'rate' => 0],
    ['date' => '2026-11-20', 'base' => 'USD', 'quote' => 'CNY', 'rate' => 7.1],
    ['date' => '2026-01-01', 'base' => 'USD', 'quote' => 'CNY', 'rate' => 7.1],
] as $invalidFx) {
    rejectsNobitex(fn () => $parser->parseFxReference($invalidFx, $checkAt, 120 * 3600));
}
echo "Nobitex proxy and automatic FX parser checks passed\n";
