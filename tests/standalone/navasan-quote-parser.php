<?php
// Dependency-free contract tests for the Navasan parser; php tests/standalone/navasan-quote-parser.php
require __DIR__.'/../../app/Services/ExchangeRates/NavasanQuoteParser.php';

use App\Services\ExchangeRates\NavasanQuoteParser;

function expectQuoteError(callable $action): void {
    try { $action(); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException('Expected unsafe Navasan quote to be rejected');
}

$parser = new NavasanQuoteParser();
$now = 1794400000;
$quote = ['cny' => ['value' => '400000', 'timestamp' => $now - 120]];
assert($parser->parse($quote, 'cny', 'rial', $now, 39600) === ['rate_rial' => 400000, 'timestamp' => $now - 120]);
assert($parser->parse(['value' => 40000, 'timestamp' => $now - 120], 'cny', 'toman', $now, 39600)['rate_rial'] === 400000);
foreach ([
    ['usd' => ['value' => '400000', 'timestamp' => $now - 120]],
    ['cny' => ['value' => '0', 'timestamp' => $now - 120]],
    ['cny' => ['value' => '-1', 'timestamp' => $now - 120]],
    ['cny' => ['value' => '400,000', 'timestamp' => $now - 120]],
    ['cny' => ['value' => '400000', 'timestamp' => $now + 120]],
    ['cny' => ['value' => '400000', 'timestamp' => $now + 1]],
    ['cny' => ['value' => '400000', 'timestamp' => $now - 40000]],
    ['cny' => ['value' => '400000']],
    ['value' => '400000', 'timestamp' => $now - 120, 'usd' => ['value' => '123']],
    ['cny' => ['value' => '400000', 'timestamp' => $now - 120], 'symbol' => 'usd'],
] as $bad) { expectQuoteError(fn () => $parser->parse($bad, 'cny', 'rial', $now, 39600)); }
expectQuoteError(fn () => $parser->parse($quote, 'cny', 'unverified', $now, 39600));
expectQuoteError(fn () => $parser->parse($quote, 'cny_hav', 'rial', $now, 39600));
echo "Navasan parser contract tests passed\n";
