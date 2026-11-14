<?php

namespace App\Console\Commands;

use App\Services\ExchangeRates\NobitexRateFetcher;
use Illuminate\Console\Command;
use RuntimeException;

final class CnyFetchRateCommand extends Command
{
    protected $signature = 'cny:fetch-rate';
    protected $description = 'Import one validated Nobitex-derived CNY estimate (never reprices products)';

    public function handle(NobitexRateFetcher $fetcher): int
    {
        try {
            $result = $fetcher->fetch();
            $this->info('Nobitex-derived CNY estimate: '.$result['status'].'; ID: '.$result['rate']->id);
            return self::SUCCESS;
        } catch (RuntimeException $e) {
            // Errors contain only curated codes, never requests, URLs or secrets.
            $this->error('Nobitex fetch failed: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
